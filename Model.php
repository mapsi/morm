<?php

/**
 * Base class for Model classes.
 *
 * Currently provides convenient constructor for populating class field e.g. from a db sourced array.
 */
abstract class MO_Model
{

    protected $_originalValues = array();

    /**
     * Converts passed array into values on this object, using array key values as property names.
     *
     * @param array $params
     */
    public function __construct(array $params = NULL)
    {
        $this->setParams($params);

        // save the original values so that we can check if this model needs saving or not
        $this->_originalValues = $this->toArray(FALSE);
    }

    public function setParams(array $params = NULL)
    {
        if ((isset($params)) and (is_array($params))) {
            foreach ($params as $argKey => $argVal) {
                $setter = 'set' . ucfirst($argKey);

                // if $argVal is empty, skip and use the model default values
                if ((method_exists($this, $setter)) and (!is_null($argVal)) and ($argVal !== '')) {
                    // if there is an array, let's try to add its contents as application models
                    if (is_array($argVal)) {
                        $singular  = rtrim($argKey, 's');
                        $addMethod = 'add' . ucfirst($singular);
                        if (method_exists($this, $addMethod)) {
                            // instatiate a reflection class
                            $method      = new ReflectionMethod($this, $addMethod);
                            $parameters  = $method->getParameters();
                            // the "add" methods should only have one type hinted param so let's grab it
                            $targetModel = $parameters[0]->getClass()->name;

                            foreach ($argVal as $row) {
                                $this->$addMethod(new $targetModel($row));
                            }
                        } else {
                            $this->$setter($argVal);
                        }
                    } else {
                        $this->$setter($argVal);
                    }
                } elseif (strpos($argKey, 'is') !== FALSE) {
                    // if the property starts with "is" then it's a boolean so use the is method
                    if (method_exists($this, $argKey)) {
                        $this->$argKey($argVal);
                    }
                }
            }
        }

        return $this;
    }

    /**
     * Avoid implicit creation of class fields
     *
     * @param string $name
     * @param mixed $value
     * @throws Exception
     */
    public function __set($name, $value)
    {
        throw new Exception('Only explicit sets of pre-declared class fields allowed.');
    }

    public function getId()
    {
        return $this->_id;
    }

    public function setId($id)
    {
        if (!empty($id)) {
            $this->_id = (int) $id;
        }
        return $this;
    }

    /**
     * Converts the object to an array using ReflectionClass. Only the properties with a getter are returned.
     *
     * @param bool $deep
     * @return array
     */
    public function toArray($deep = TRUE)
    {
        // instatiate a reflection class
        $reflection = new ReflectionClass($this);
        // get all properties -- reminder, MO_Models should only have protected/private properties!

        $result = array();

        // only go through the protected properties
        // we should have no public ones and the private ones should remain private!
        foreach ($reflection->getProperties(ReflectionProperty::IS_PROTECTED) as $property) {

            $propertyName = trim($property->getName(), '_');

            // check if getter exists for property
            if (method_exists($this, 'get' . ucwords($propertyName))) {
                $getter = 'get' . ucwords($propertyName);
            } else
            // no "get" found check if there is a method with the same name as the property
            if (method_exists($this, $propertyName)) {
                $getter = $propertyName;
            }
            // bail -- ignore this property
            else {
                break;
            }

            $var = $this->$getter();
            if (is_object($var)) {
                if ($var instanceof \Zend_Date) {
                    $result[$propertyName] = $var->__toString();
                } else {
                    if (!$deep) {
                        $result[$propertyName . 'Id'] = $var->getId();
                    } else {
                        $result[$propertyName] = $var->toArray();
                    }
                }
            } else {

                if (is_array($var)) {
                    $relations = array();
                    foreach ($var as $key => $relation) {
                        // there is a ManyToMany relation
                        if ($relation instanceof MO_Model) {
                            $relations[] = $relation->toArray();
                        } else {
                            $relations[$key]       = $relation;
                        }
                    }
                    $var                   = $relations;
                }
                $result[$propertyName] = $var;
            }
        }
        return $result;
    }

    /**
     *
     * @return boolean
     */
    public function hasId()
    {
        $id = $this->getId();
        if (empty($id)) {
            return FALSE;
        } else {
            return TRUE;
        }
    }

    /**
     * clear object Id
     *
     * @return MO_Model
     */
    public function clearId()
    {
        $this->_id = NULL;
        return $this;
    }

    /**
     *
     * @return boolean
     */
    public function hasBeenModified()
    {
        return (count(array_diff_assoc($this->_originalValues, $this->toArray(FALSE))) > 0) ? (TRUE) : (FALSE);
    }

}