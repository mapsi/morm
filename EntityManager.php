<?php

final class MO_EntityManager
{

    /**
     * The database connection used by the EntityManager.
     *
     * @var Zend_Db_Adapter_Abstract
     */
    protected $_db          = NULL;
    protected $_log         = NULL;
    protected $_repository;
    protected $_lastMessage = NULL;

    const MODEL_CLASS_PREFIX = 'App\Model\\';

    /**
     * Starts a transaction on the underlying database connection.
     */
    public function beginTransaction()
    {
        $this->_db->beginTransaction();
    }

    public function clear()
    {

    }

    /**
     * Commits a transaction on the underlying database connection.
     */
    public function commit()
    {
        $this->_db->commit();
    }

    /**
     * The metadata factory, used to retrieve the ORM metadata of entity classes.
     *
     */
    private $metadataFactory;

    /**
     * The EntityRepository instances.
     *
     * @var array
     */
    private $repositories = array();

    /**
     * Creates a new EntityManager that operates on an instance of Zend_Db_Adapter_Abstract
     *
     * @param Zend_Db_Adapter_Abstract $db
     */
    public function __construct(Zend_Db_Adapter_Abstract $db)
    {
        $this->_db = $db;
        $this->_log = new Zend_Log_Writer_Null();
    }

    /**
     * Gets the database connection object used by the EntityManager.
     *
     * @return Zend_Db_Adapter
     */
    public function getConnection()
    {
        return $this->_db;
    }

    /**
     * Executes a function in a transaction.
     *
     * The function gets passed this EntityManager instance as an (optional) parameter.
     *
     * {@link flush} is invoked prior to transaction commit.
     *
     * If an exception occurs during execution of the function or flushing or transaction commit,
     * the transaction is rolled back, the EntityManager closed and the exception re-thrown.
     *
     * @param Closure $func The function to execute transactionally.
     */
//    public function transactional(Closure $func)
//    {
//        $this->_db->beginTransaction();
//
//        try {
//            $return = $func($this);
//
//            $this->flush();
//            $this->_db->commit();
//
//            return $return ? : true;
//        }
//        catch (Exception $e) {
//            $this->close();
//            $this->_db->rollback();
//
//            throw $e;
//        }
//    }

    /**
     * Performs a rollback on the underlying database connection.
     *
     * @deprecated Use {@link getConnection}.rollback().
     */
    public function rollback()
    {
        $this->_db->rollback();
    }

    /**
     * Finds an Entity by its identifier.
     *
     * This is just a convenient shortcut for getRepository($entityName)->find($id).
     *
     * @param string $entityName
     * @param mixed $identifier
     * @param boolean $deep -- this has been depracated -- DON'T USE!
     * @return object
     */
    public function find($entityName, $identifier, $deep = FALSE)
    {
        return $this->getRepository($entityName)->find($identifier, $deep);
    }

    /**
     * Refreshes the persistent state of an entity from the database,
     * overriding any local changes that have not yet been persisted.
     *
     * @param object $entity The entity to refresh.
     */
    public function refresh($entity)
    {
        if (!$entity instanceof MO_Model) {
            throw new MO_Exception('Was expecting an instance of MO_Model');
        }
        $this->errorIfClosed();
        $this->unitOfWork->refresh($entity);
    }

    /**
     * Gets the repository for an entity class.
     *
     * @param string $modelName The name of the model.
     * @return MO_Model_Repository The repository class.
     */
    public function getRepository($modelName)
    {
        $repository = self::MODEL_CLASS_PREFIX . "{$modelName}Repository";
        if (class_exists($repository)) {
            return new $repository();
        } else {
            return new MO_Model_Repository($repository);
        }
    }

    public function persist(MO_Model &$model)
    {

        if (!$model instanceof MO_Model) {
            throw new MO_Exception('Was expecting an instance of MO_Model');
        }

        $modelName = str_replace('_', '\\', str_replace(self::MODEL_CLASS_PREFIX, '', get_class($model)));

        $result = $this->getRepository($modelName)->save($model, TRUE);
        if ($result['status'] === TRUE) {
            $this->_lastMessage = NULL;
            return TRUE;
        } else {
            $this->_lastMessage = $result['message'];
            return FALSE;
        }
    }

    public function remove(MO_Model $model)
    {
        if (!$model instanceof MO_Model) {
            throw new MO_Exception('Was expecting an instance of MO_Model');
        }

        $modelName = str_replace('_', '\\', str_replace(self::MODEL_CLASS_PREFIX, '', get_class($model)));

        $result = $this->getRepository($modelName)->remove($model->getId());
        if ($result['status'] === TRUE) {
            $this->_lastMessage = NULL;
            return TRUE;
        } else {
            $this->_lastMessage = $result['message'];
            return FALSE;
        }
    }

    /**
     * This method returns a count for ALL records in the table, for a filtered count use $repo->findBy()->count()
     *
     * @param type $modelName
     * @return type
     */
    public function count($modelName)
    {

        $repo = $this->getRepository($modelName);

        $db = $this->_db;

        return $db->select()
                        ->from($repo->getTable(), array('counter' => "COUNT({$repo->getPrimary()})"))
                        ->query()->fetchColumn();
    }

    /**
     * Function for stripping out malicious bits
     * @see http://css-tricks.com/snippets/php/sanitize-database-inputs/
     *
     * @todo Should this be used instead of gm_scrubString()?
     *
     * @param string $input
     * @return string
     */
    public static function cleanInput($input)
    {

        $search = array(
            // Strip out javascript
            '@<script[^>]*?>.*?</script>@si',
            // Strip out HTML tags
            '@<[\/\!]*?[^<>]*?>@si',
            // Strip style tags properly
            '@<style[^>]*?>.*?</style>@siU',
            // Strip multi-line comments
            '@<![\s\S]*?--[ \t\n\r]*>@'
        );

        $output = preg_replace($search, '', $input);
        return $output;
    }

    /**
     * Takes a string and converts newlines to <br> elements and urls to anchor tags
     *
     * @param string $stringToConvert
     * @return string
     */
    public static function convertStringToHTML($stringToConvert)
    {
        //Convert URLs
        $delimiters      = '\\s"\\.\',';
        $schemes         = 'https?|ftps?';
        $pattern         = sprintf('#(^|[%s])((?:%s)://\\S+[^%1$s])([%1$s]?)#i', $delimiters, $schemes);
        $replacement     = '$1<a href="$2" target="_blank">$2</a>$3';
        $stringToConvert = preg_replace($pattern, $replacement, $stringToConvert);

        //Convert newlines
        $stringToConvert = str_replace(chr(13) . chr(10), "<br>", $stringToConvert);
        $stringToConvert = str_replace(chr(13), "<br>", $stringToConvert);

        return $stringToConvert;
    }

    public function getLastMessage()
    {
        return $this->_lastMessage;
    }

    /**
     * Log a message to the audit trail
     *
     * @param integer $auditType
     * @param string $moduleName
     * @param integer $dataId
     * @param string $auditMessage
     *
     * @see GM_DB_AUD_TYPE_* in gm_global for audit types
     */
    public function saveAuditEntry($auditType, $moduleName, $dataId, $auditMessage)
    {
        gm_db_logAuditEvent($auditType, "{$moduleName} - ({$dataId}) {$auditMessage}");
    }

}