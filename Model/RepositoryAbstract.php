<?php

abstract class MO_Model_RepositoryAbstract implements IteratorAggregate, Countable, ArrayAccess  //, SeekableIterator
{

    /**
     *
     * @var Zend_Db_Adapter_Abstract
     */
    protected $_db     = NULL;
    protected $_log    = NULL;
    protected $_models = array();

    /**
     *
     * @var array
     */
    protected $_bind = array();

    /**
     *
     * @var ReflectionClass
     */
    protected $_reflection;
    protected $_table   = NULL;
    protected $_primary = NULL;

    /**
     *
     * @var MO_EntityManager
     */
    protected $_em;

    const DB_QUERY_LIMIT     = 50;
    const DB_FK_REMOVE_ERROR = "Cannot delete or update a parent row: a foreign key constraint fails";

    protected $_errorMessages = array(
        'DB_FK_REMOVE' => self::DB_FK_REMOVE_ERROR
    );

    /**
     * @var string
     */
    protected $_entityName;
    protected $_class;

    public function __construct($entityName = null)
    {
        $entityName = (is_null($entityName)) ? (get_class($this)) : $entityName;

        $this->_log = new Zend_Log_Writer_Null;
        $this->_entityName = str_replace('Repository', '', $entityName);
        $this->_reflection = new ReflectionClass($this->_entityName);
        $this->_bind = $this->_generateBind();
        $this->_em = Zend_Registry::get('em');
        $this->_db = $this->_em->getConnection();
    }
    
    /**
     * Finds an entity by its primary key / identifier.
     *
     * @param integer $id
     * @param boolean $deep
     * @return object The entity.
     */
    public function find($id, $deep = FALSE)
    {
        // assume that primary is always set as "id"
//        return $this->findOneBy(array("{$this->getPrimary()} = ?" => $id), $deep);
        return $this->findOneBy(array("id = ?" => $id), $deep);
    }

    /**
     * Finds all entities in the repository.
     *
     * @return array The entities.
     */
    public function findAll()
    {
        return $this->findBy(array());
    }

    protected function _pregReplace($property, $dbColumn, $string)
    {
        return preg_replace('/' . $property . ' /', $dbColumn . ' ', $string);
    }

    protected function _processWhere(array $where)
    {
        $mappedWhere = array();

        if ((!empty($where))
                and (is_array($where))
        ) {
            foreach ($where as $clause => $value) {
                foreach ($this->_bind as $property => $dbColumn) {
                    $clause               = $this->_pregReplace($property, $dbColumn, $clause);
                    $value                = $this->_pregReplace($property, $dbColumn, $value);
                }
                $mappedWhere[$clause] = $value;
            }
        }

        return $mappedWhere;
    }

    protected function _processUpdate(array $bind)
    {
        $mappedBind = array();

        if ((!empty($bind))
                and (is_array($bind))
        ) {
            foreach ($bind as $clause => $value) {
                $clause              = str_replace($clause, $this->_bind[$clause], $clause);
                $mappedBind[$clause] = $value;
            }
        }

        return $mappedBind;
    }

    protected function _processOrderBy(array $orderBy)
    {
        $mappedOrderBy = array();

        if ((!empty($orderBy))
                and (is_array($orderBy))
        ) {
            foreach ($orderBy as $orderString) {
                foreach ($this->_bind as $property => $dbColumn) {
                    $orderString     = $this->_pregReplace($property, $dbColumn, $orderString);
                }
                $mappedOrderBy[] = $orderString;
            }
        }

        return $mappedOrderBy;
    }

    /**
     * Finds entities by a set of criteria.
     *
     * @param array $criteria
     * @param bool $deep
     * @param array|null $orderBy  Each element of the array is a string naming a column. Optionally with the ASC DESC keyword following it, separated by a space.
     * @param int|null $limit
     * @param int|null $offset
     * @return array The objects.
     */
    public function findBy(array $criteria, $deep = false, array $orderBy = array(), $limit = null, $offset = null)
    {
        $table = $this->getTable();


        $criteria = $this->_processWhere($criteria);
        $orderBy  = $this->_processOrderBy($orderBy);

        $select = $this->_db->select()
                ->from($table, $this->_bind)
                ->order($orderBy)
                ->limit((empty($limit)) ? (self::DB_QUERY_LIMIT) : ($limit), (empty($offset)) ? (NULL ) : ($offset))
        ;

        foreach ($criteria as $where => $value) {
            $select->where($where, $value);
        }

        $rowset = $select->query(Zend_Db::FETCH_ASSOC)->fetchAll();

        $models = array();

        foreach ($rowset as $row) {
            $model = new $this->_entityName($row);
            if ($deep) {
                $this->_processRelationships($model);
            }
            $models[] = $model;
        }

        $this->_models = (count($models) > 0) ? ($models) : array();


        return $this;
    }

//    protected function _getDbColumn($property = NULL)
//    {
//        $reflectedProperty = $this->_reflection->getProperty('_'.$property);
//    }

    protected function _generateBind($ignoreManyToMany = NULL, $className = NULL)
    {
        if (is_null($className)) {
            $className = $this->_entityName;
        }
        $bind = array();

        foreach ($this->_reflection->getProperties(ReflectionProperty::IS_PROTECTED) as $protectedProperty) {
            $cleanName = str_replace('_', '', $protectedProperty->getName());

            $annotations = $protectedProperty->getDocComment();

            // we've got a model relationship here
            if ((strpos($annotations, 'targetEntity') !== FALSE)
                    and (!strpos($annotations, '@ManyToMany'))
                    and (!strpos($annotations, '@OneToMany'))
            ) {

                //extract relationship details

                $matches = array();
                // find target entity
                //preg_match('/@OneToOne\(targetEntity=["\']?([^"\']*)/i', $annotations, $matches);
                preg_match('/@\w+To\w+\(targetEntity=["\']?([^"\']*)/i', $annotations, $matches);
                if (!empty($matches[1])) {
                    $targetEntity = $matches[1];
                } else {

                    // @todo: come back!

                    throw new MO_Exception(__CLASS__ .
                            ': property "' . $protectedProperty->getName() .
                            '" declared as FK but targetEntity not defined');
                }

                //extract local db column name
                $matches = array();
                preg_match('/@JoinColumn\(name=["\']?([^"\']*)/i', $annotations, $matches);
                if (!empty($matches[1])) {
                    $dbColumn = $matches[1];

                    // set as "{property}Id" as this is the convention for keys
                    $bind[$cleanName . 'Id'] = $dbColumn;
                }
//                else {
//                    throw new MO_Exception(__CLASS__ .
//                            ': property "' . $protectedProperty->getName() .
//                            '" not mapped to a db column');
//                }
            }
            // just get the table column name -- if one exists
            else {
                $matches = array();
                // find table column name
                preg_match('/@Column\(name=["\']?([^"\']*)/i', $annotations, $matches);
                if (!empty($matches[1])) {
                    $bind[$cleanName] = $matches[1];
                }
            }
        }
        return $bind;
    }

    protected function _processRelationships(&$model)
    {

        foreach ($this->_reflection->getProperties(ReflectionProperty::IS_PROTECTED) as $protectedProperty) {
            $cleanName = str_replace('_', '', $protectedProperty->getName());
            $setter    = 'set' . ucfirst($cleanName);
            $getter    = 'get' . ucfirst($cleanName);

            $annotations = $protectedProperty->getDocComment();

            // we've got a model relationship here
            if (strpos($annotations, 'targetEntity') !== FALSE) {

                $matches = array();
                // check if this is a foreign key
                preg_match_all('/(\w+)=["\']?([^"\']*)/i', $annotations, $matches);
                $bind = array_combine($matches[1], $matches[2]);

                if ((strpos($annotations, '@OneToOne') !== FALSE)
                        or (strpos($annotations, '@ManyToOne') !== FALSE)
                ) {
                    preg_match('/@JoinColumn\(name=["\']?([^"\']*)/i', $annotations, $matches);
                    if (!empty($matches[1])) {
                        $dbColumn = $matches[1];
                    } else {

                        // find target entity
                        preg_match('/@\w+To\w+\(targetEntity=["\']?([^"\']*)/i', $annotations, $matches);
                        $targetEntity           = $matches[1];
                        $targetEntityRepository = $this->_em->getRepository($targetEntity);


                        //if (str_replace('\\', '_', $targetEntity) === str_replace('Application_Model_', '', $this->getEntityName())) {

                        $matches = array();
                        preg_match('/@JoinColumn\(name=["\']?([^"\']*)/i', $annotations, $matches);
                        if (!empty($matches[1])) {
                            $dbColumn = $matches[1];

                            // set as "{property}Id" as this is the convention for keys
                            $extras = $targetEntityRepository
                                    ->findBy(array("{$dbColumn} = ?" => $model->getId()))
                                    ->getModels();
                        } else {
                            // this is an inverse relationship defined in the target entity
                            $targetPropertyAnnotations = $targetEntityRepository->fetchPropertyByTargetEntity($this->getEntityName())
                                    ->getDocComment();

                            $matches = array();
                            preg_match('/@JoinColumn\(name=["\']?([^"\']*)/i', $targetPropertyAnnotations, $matches);
                            $dbColumn = $matches[1];
                            // Find target entity by local entity FK
                            $extras   = $targetEntityRepository->findOneBy(array("{$dbColumn} = ?" => $model->getId()));
                        }

                        // @todo: set this here and continue -- come back rewrite this maze with unit tests...
                        if (!is_null($extras)) {
                            $model->$setter($extras);
                        }

                        //}
                        continue;
                    }

                    if (is_object($model->$getter())) {
                        $extras = $this->_em->getRepository($bind['targetEntity'])
                                ->find($model->$getter()->getId());
                    } else {
                        $extras = null;
                    }
                } elseif (strpos($annotations, '@OneToMany') !== FALSE) {

                    preg_match('/OneToMany\(targetEntity=["\']?([^"\']*)/i', $annotations, $matches);

                    $targetEntityRepository = $this->_em->getRepository($matches[1]);


                    foreach ($targetEntityRepository->_reflection->getProperties(ReflectionProperty::IS_PROTECTED) as $protectedProperty) {

                        $annotations = $protectedProperty->getDocComment();

                        // we've got a model relationship here
                        if (strpos($annotations, 'targetEntity') !== FALSE) {

                            //extract relationship details
                            $matches = array();

                            // find target entity
                            preg_match('/@\w+To\w+\(targetEntity=["\']?([^"\']*)/i', $annotations, $matches);
                            $targetEntity = $matches[1];


                            if (str_replace('\\', '_', $targetEntity) === str_replace(MO_EntityManager::MODEL_CLASS_PREFIX, '', $this->getEntityName())) {

                                $matches = array();
                                preg_match('/@JoinColumn\(name=["\']?([^"\']*)/i', $annotations, $matches);
                                if (!empty($matches[1])) {
                                    $dbColumn = $matches[1];

                                    // set as "{property}Id" as this is the convention for keys
                                    $setter = 'set' . ucfirst($cleanName);
                                    $extras = $targetEntityRepository
                                            ->findBy(array("{$dbColumn} = ?" => $model->getId()))
                                            ->getModels();
                                    break;
                                }
                            }
                        }
                    }
                } elseif (strpos($annotations, '@ManyToMany') !== FALSE) {

                    preg_match('/joinColumns={@JoinColumn\(name="' . $this->getPrimary() . '", referencedColumnName="(\w+)"/i', $annotations, $refColumn);

                    $inValues = $this->_db->select()
                                    ->from($bind['name'], "{$bind['referencedColumnName']}")
                                    ->where("{$refColumn[1]} = ?", $model->getId())
                                    ->query(Zend_Db::FETCH_COLUMN)->fetchAll();

                    $extras = $this->findManyWhereIdIn($inValues, $bind['targetEntity']);
                }

                if (!empty($extras)) {
                    $model->$setter($extras);
                }
            }
        }
    }

    public function findManyWhereIdIn(array $array, $modelName = NULL)
    {
        if (is_null($modelName)) {
            $modelName = $this->_entityName;
        }

        if (empty($array)) {
            return null;
        }

        $repo = $this->_em->getRepository($modelName);

        return $repo->findBy(array("{$repo->getPrimary()} IN (" . implode(',', $array) . ")" => NULL), FALSE)->getModels();
    }

    /**
     * Finds a single entity by a set of criteria.
     *
     * @param array $criteria
     * @return MVC_Model
     */
    public function findOneBy(array $criteria, $deep = FALSE, array $orderBy = array())
    {
        $this->findBy($criteria, $deep, $orderBy, 1);
        if (is_null($this->_models) || sizeof($this->_models) == 0) {
            return null;
        }

        return $this->_models[0];
    }

    protected function _getNameColumn()
    {
        foreach ($this->_reflection->getProperties(ReflectionProperty::IS_PROTECTED) as $protectedProperty) {
            if ('_name' === $protectedProperty->getName()) {
                $annotations = $protectedProperty->getDocComment();
                $matches     = array();
                // find table column name
                preg_match('/@Column\(name=["\']?([^"\']*)/i', $annotations, $matches);

                if (!empty($matches[1])) {
                    return $matches[1];
                } else {
                    return null;
                }
            }
        }
    }

    /**
     * @return string
     */
    protected function getEntityName()
    {
        return $this->_entityName;
    }

    /**
     * @return EntityManager
     */
    protected function getEntityManager()
    {
        return $this->_em;
    }

    /**
     * @return Mapping\ClassMetadata
     */
    protected function getClassMetadata()
    {
        return $this->_class;
    }

    

    protected function _getTable()
    {
        preg_match('/name=["\']?([^"\']*)/i', $this->_reflection->getDocComment(), $match);
        if (empty($match)) {
            throw new MO_Exception('Table name not defined in model: ' . $this->_reflection->getName());
        }

        return $match[1];
    }

    public function getTable()
    {
        if (empty($this->_table)) {
            $this->_table = $this->_getTable();
        }

        return $this->_table;
    }

    protected function _getPrimary()
    {
        foreach ($this->_reflection->getProperties(ReflectionProperty::IS_PROTECTED) as $protectedProperty) {
            $annotations = $protectedProperty->getDocComment();

            $matches = array();
            preg_match_all('/(\w+)=["\']?([^"\']*)/i', $annotations, $matches);
            // find numeric key of "name"
            $key = array_search('name', $matches[1], TRUE);
            if (is_null($key)) {
                throw new MO_Exception(__CLASS__ . ': property "' . $protectedProperty->getName() . '" not mapped to a db_column');
            }

            if (strpos($annotations, '@Id') !== FALSE) {
                return $matches[2][$key];
            }
        }

        return NULL;
    }

    public function getPrimary()
    {
        if (empty($this->_primary)) {
            $this->_primary = $this->_getPrimary();
        }

        if (empty($this->_primary)) {
            throw new MO_Exception("Primary key could not be found in {$this->_entityName} class!");
        }

        return $this->_primary;
    }

    protected function _getModelName()
    {
        return str_replace('Repository', '', get_class());
    }

    /**
     *
     * @return array
     */
    public function getModels()
    {
        return $this->_models;
    }

    /**
     *
     * @param array $models
     * @return MO_Model_RepositoryAbstract
     */
    public function setModels($models)
    {
        $array = array();

        foreach ($models as $model) {
            $array[$model->getId()] = $model;
        }

        $this->_models = $array();

        return $this;
    }

    public function addModel(MO_Model $model)
    {
        $this->_models[] = $model;
        return $this;
    }

    /**
     *
     * @param string $key
     * @param string $value
     * @return array
     */
    public function toKeyValueArray($key = 'id', $value = 'name')
    {
        $result = array();
        foreach ($this->_models as $model) {
            $array                = $model->toArray();
            $result[$array[$key]] = $array[$value];
        }
        return $result;
    }

    public function toArray()
    {
        foreach ($this->_models as $model) {
            $result[$model->getId()] = $model->toArray();
        }
        return $result;
    }

    public function save(MO_Model &$model, $beginTransaction = TRUE)
    {
        $result = array('status' => TRUE);

        if ((!$model->hasBeenModified())
                and ($model->hasId())
        ) {
            return $result;
        }


        $bind = array();
        $oneToOne = array();

        foreach ($this->_reflection->getProperties(ReflectionProperty::IS_PROTECTED) as $protectedProperty) {
            $cleanName = trim($protectedProperty->getName(), '_');

            // skip originalValues
            if ($cleanName !== 'originalValues') {

                // check if getter exists for property
                if (method_exists($model, 'get' . ucwords($cleanName))) {
                    $getter = 'get' . ucwords($cleanName);
                } else
                // no "get" found check if there is a method with the same name as the property
                if (method_exists($model, $cleanName)) {
                    $getter = $cleanName;
                }

                // if no getter or getter returns null, ignore this property
                if ((!empty($getter))
                        and !is_null($model->$getter())
                ) {

                    $annotations = $protectedProperty->getDocComment();

                    //check if this is a direct map to a table column
                    $columnName = array();
                    preg_match('/@Column\(name=["\']?([^"\']*)/i', $annotations, $columnName);

                    if (!empty($columnName[1])) {

                        // this is the primary key, don't include it in the bind array
                        if (strpos($annotations, '@Id') !== FALSE) {
                            $primaryKey = array('key' => $columnName[1], 'value' => $model->$getter());
                        } else {
                            $columnValue = $model->$getter();
                            // if instance of MVC_Date fetch the timestamp
                            // @todo check the type from the annotation
                            if ($columnValue instanceof MVC_Date) {
                                $columnValue = $columnValue->getTimestamp();
                            }

                            // if a boolean convert to integer
                            $bind[$columnName[1]] = (is_bool($columnValue)) ? ((int) $columnValue) : ($columnValue);
                        }
                    } else {

                        $matches = array();
                        preg_match('/@(\w+To\w+)\(targetEntity=["\']?([^"\']*)/i', $annotations, $matches);

                        // if there is no match, we're looking at a getter that doesn't relate to db properties so skip
                        if (count($matches) > 0) {
                            $relation     = $matches[1];
                            $targetEntity = $matches[2];
                            $targetModel  = $model->$getter();


                            switch ($relation) {

                                case 'OneToOne':
                                    // try to save targetEntity if it's been modified and not in the inverse side
                                    // of the bidirectional relationship
                                    if ((strpos($annotations, 'inversedBy') === FALSE)
                                            and (strpos($annotations, '@JoinColumn') === FALSE)
                                    ) {
                                        $oneToOne[$targetEntity] = $targetModel;
                                    } else {
                                        $columnName = array();
                                        preg_match('/@JoinColumn\(name=["\']?([^"\']*)/i', $annotations, $columnName);
                                        // add the target entity's id to the bind array
                                        $bind[$columnName[1]] = $targetModel->getId();
                                    }
                                    break;

                                case 'ManyToOne':

                                    $columnName = array();
                                    preg_match('/@JoinColumn\(name=["\']?([^"\']*)/i', $annotations, $columnName);
                                    // add the target entity's id to the bind array
                                    $bind[$columnName[1]] = $targetModel->getId();
                                    break;

                                case 'OneToMany':
                                    // this kind of relation should result in an array if unidirectional
                                    if (count($targetModel) > 0) {
                                        $oneToMany = array($targetEntity => $model->$getter());
                                    }
                                    break;

                                case 'ManyToMany':

                                    // find the mapping table
                                    $tableName = array();
                                    preg_match('/@JoinTable\(name=["\']?([^"\']*)/i', $annotations, $tableName);
                                    if (empty($tableName[1])) {
                                        throw new MO_Exception("{$cleanName} defined as ManyToMany but no mapping table found!");
                                    }

                                    // find local referenced column
                                    $localReferencedColumnName = array();
                                    preg_match('/joinColumns={@JoinColumn\(name="' . $this->getPrimary() . '", referencedColumnName=["\']?([^"\']*)/i', $annotations, $localReferencedColumnName);

                                    if (empty($localReferencedColumnName[1])) {
                                        throw new MO_Exception("{$cleanName} defined as ManyToMany but referenced columns not found!");
                                    }

                                    $targetEntityRepo            = $this->_em->getRepository($targetEntity);
                                    // find foreign referenced column
                                    $foreignReferencedColumnName = array();
                                    preg_match('/inverseJoinColumns={@JoinColumn\(name="' . $targetEntityRepo->getPrimary() . '", referencedColumnName=["\']?([^"\']*)/i', $annotations, $foreignReferencedColumnName);

                                    if (empty($foreignReferencedColumnName[1])) {
                                        throw new MO_Exception("{$cleanName} defined as ManyToMany but referenced columns not found!");
                                    }

                                    $manyToMany = array(
                                        'tableName'  => $tableName[1],
                                        'localref'   => $localReferencedColumnName[1],
                                        'foreignref' => $foreignReferencedColumnName[1]
                                    );

                                    foreach ($targetModel as $foreignModel) {
                                        $manyToMany['foreignIds'][] = $foreignModel->getId();
                                    }
                                    break;
                            }
                        }
                    }
                }
            }
        }

        $result = array('status' => TRUE);

        if ($beginTransaction === TRUE) {
            $this->_db->beginTransaction();
        }

        try {

            $table = $this->getTable();

            if ($model->hasId()) {
                $this->_db->update($table, $bind, array("{$primaryKey['key']} = ?" => $primaryKey['value']));
            } else {

                $this->_db->insert($table, $bind);
                $id = $this->_db->lastInsertId();
                $model->setId($id);
            }

            // process OneToOne
            foreach ($oneToOne as $targetEntity => $targetModel) {
                $targetRepo = $this->_em->getRepository($targetEntity);
                $setter     = $this->fetchSetterForProperty(
                                $targetRepo->fetchPropertyByTargetEntity($this->getEntityName())->getName()
                        ) . 'Id';
                $targetRepo->save($targetModel->$setter($model->getId()), FALSE);
            }

            // process OneToMany -- unidirectional
            if (isset($oneToMany)) {
                foreach ($oneToMany as $targetEntity => $contents) {
                    $modelId = $model->getId();

                    //find reverse relation
                    $targetEntityRepo = $this->_em->getRepository($targetEntity);
                    foreach ($targetEntityRepo->_reflection->getProperties(ReflectionProperty::IS_PROTECTED) as $protectedProperty) {
                        $annotations = $protectedProperty->getDocComment();
                        $modelName   = str_replace('_', '\\', str_replace(MO_EntityManager::MODEL_CLASS_PREFIX, '', get_class($model)));
                        // find the setter
                        if (strpos($annotations, "@ManyToOne(targetEntity=\"{$modelName}") !== FALSE) {
                            $setter = $this->fetchSetterForProperty($protectedProperty->getName()) . 'Id';
                            break;
                        };
                        //$matches = array();
                        //preg_match('/@(\w+To\w+)\(targetEntity=["\']?([^"\']*)/i', $annotations, $matches);
                    }

                    foreach ($contents as $content) {
                        // use the setter to set the inverse foreign key
                        $content->$setter($model->getId());
                        $targetEntityRepo->save($content, FALSE);
                    }
                }
            }
            // process ManyToMany
            if (isset($manyToMany)) {
                // clear mapping table for the specific local model
                $this->_db->delete($manyToMany['tableName'], array("{$manyToMany['localref']} = ?" => $model->getId()));
                foreach ($manyToMany['foreignIds'] as $value) {
                    // loop through the foreign entities and insert in mapping table
                    $this->_db->insert(
                            $manyToMany['tableName'], array(
                        $manyToMany['localref']   => $model->getId(),
                        $manyToMany['foreignref'] => $value
                            )
                    );
                }
            }

            if ($beginTransaction === TRUE) {
                $this->_db->commit();
            }
        }
        catch (Zend_Db_Exception $e) {
            //var_dump($e);exit;

            if (strpos($e->getMessage(), 'Duplicate') !== FALSE) {
                $result['message'] = 'Duplicate values are not allowed';
            }

            if ($beginTransaction === TRUE) {
                $this->_db->rollBack();
            }
            $result['status'] = FALSE;
        }


        return $result;
    }

    public function remove($id)
    {
        $result = array('status' => TRUE);

        try {
            $this->_db->delete($this->getTable(), array("{$this->getPrimary()} = ?" => $id));
        }
        catch (Zend_Db_Exception $e) {

            $result['status']  = FALSE;
            $result['message'] = $this->_errorMessages['DB_FK_REMOVE'];
        }
        return $result;
    }

    /**
     * count method
     *
     * @param array $criteria
     * @return integer
     */
    public function countWhere(array $criteria = array())
    {
        $table    = $this->getTable();
        $criteria = $this->_processWhere($criteria);
        $select   = $this->_db->select()
                ->from(array('bt' => $table), array(
            'count' => 'count(' . $this->getPrimary() . ')'
                ))
        ;

        foreach ($criteria as $where => $value) {
            $select->where($where, $value);
        }

        $rowset = $select->query(Zend_Db::FETCH_ASSOC)->fetchAll();
        return $rowset[0]['count'];
    }

    public function fetchPropertyByTargetEntity($targetEntity)
    {
        foreach ($this->_reflection->getProperties(ReflectionProperty::IS_PROTECTED) as $protectedProperty) {
            $annotations = $protectedProperty->getDocComment();

            if (strpos($annotations, $targetEntity)) {
                return $protectedProperty;
            }
        }
    }

    public function fetchSetterForProperty($protectedProperty)
    {
        return 'set' . ucwords(trim($protectedProperty, '_'));
    }

// return iterator
    public function getIterator()
    {
        return new ArrayIterator($this->getModels());
    }

    public function count()
    {
        return count($this->getModels());
    }

    public function offsetSet($offset, $value)
    {
        if (is_null($offset)) {
            $this->_models[] = $value;
        } else {
            $this->_models[$offset] = $value;
        }
    }

    public function offsetExists($offset)
    {
        return isset($this->_models[$offset]);
    }

    public function offsetUnset($offset)
    {
        unset($this->_models[$offset]);
    }

    public function offsetGet($offset)
    {
        return isset($this->_models[$offset]) ? $this->_models[$offset] : null;
    }

}