<?php

namespace Mofing\Mongodb\ORM;

use Cake\Utility\Hash;
use MongoDB\Collection;

class MongoFinder
{

    /**
     * connection with db
     *
     * @var \MongoDB\Collection $_connection
     * @access protected
     */
    protected $_connection;

    /**
     * default options for find
     *
     * @var array $_options
     * @access protected
     */
    protected $_options = [
        'fields' => [],
        'where' => [],
    ];

    /**
     * total number of rows
     *
     * @var int $_totalRows
     * @access protected
     */
    protected $_totalRows;

    /**
     * set connection and options to find
     *
     * @param Collection $connection
     * @param array $options
     * @access public
     */
    public function __construct($connection, $options = [])
    {
        $this->connection($connection);
        $this->_options = array_merge_recursive($this->_options, $options);

        if (isset($options['conditions']) && !empty($options['conditions'])) {
            $this->_options['where'] += $options['conditions'];
            unset($this->_options['conditions']);
        }

        if (!empty($this->_options['where'])) {
            $this->__translateNestedArray($this->_options['where']);
            $this->__translateConditions($this->_options['where']);
        }
    }

    /**
     * Convert ['foo' => 'bar', ['baz' => true]]
     * to
     * ['$and' => [['foo', 'bar'], ['$and' => ['baz' => true]]]
     * @param $conditions
     */
    private function __translateNestedArray(&$conditions)
    {
        $and = isset($conditions['$and']) ? (array)$conditions['$and'] : [];
        foreach ($conditions as $key => $value) {
            if (is_numeric($key) && is_array($value)) {
                unset($conditions[$key]);
                $and[] = $value;
            } elseif (is_array($value) && !in_array(strtoupper($key), ['OR', '$OR', 'AND', '$AND'])) {
                $this->__translateNestedArray($conditions[$key]);
            }
        }
        if (!empty($and)) {
            $conditions['$and'] = $and;
            foreach (array_keys($conditions['$and']) as $key) {
                $this->__translateNestedArray($conditions['$and'][$key]);
            }
        }
    }

    /**
     * connection
     *
     * @param Collection $connection
     * @return Collection
     * @access public
     */
    public function connection($connection = null)
    {
        if ($connection === null) {
            return $this->_connection;
        }

        $this->_connection = $connection;
    }

    /**
     * convert sql conditions into mongodb conditions
     *
     * '!=' => '$ne',
     * '>' => '$gt',
     * '>=' => '$gte',
     * '<' => '$lt',
     * '<=' => '$lte',
     * 'IN' => '$in',
     * 'NOT' => '$not',
     * 'NOT IN' => '$nin'
     *
     * @param array $conditions
     * @access private
     * @return array
     */
    private function __translateConditions(&$conditions)
    {
        $operators = '<|>|<=|>=|!=|=|<>|IN|LIKE';
        foreach ($conditions as $key => $value) {
            if (is_numeric($key) && is_array($value)) {
                $this->__translateConditions($conditions[$key]);
            } elseif (preg_match("/^(.+) ($operators)$/", $key, $matches)) {
                list(, $field, $operator) = $matches;
                if (substr($field, -3) === 'NOT') {
                    $field = substr($field, 0, strlen($field) -4);
                    $operator = 'NOT '.$operator;
                }
                $operator = $this->__translateOperator(strtoupper($operator));
                unset($conditions[$key]);
                if (substr($operator, -4) === 'LIKE') {
                    $value = str_replace('%', '.*', $value);
                    $value = str_replace('?', '.', $value);
                    if ($operator === 'NOT LIKE') {
                        $value = "(?!$value)";
                    }
                    $operator = '$regex';
                    $value = new \MongoDB\BSON\Regex("^$value$", "i");
                }
                $conditions[$field][$operator] = $value;
            } elseif (preg_match('/^OR|AND$/i', $key, $match)) {
                $operator = '$' . strtolower($match[0]);
                unset($conditions[$key]);
                foreach ($value as $nestedKey => $nestedValue) {
                    if (!is_array($nestedValue)) {
                        $nestedValue = [$nestedKey => $nestedValue];
                        $conditions[$operator][$nestedKey] = $nestedValue;
                    } else {
                        $conditions[$operator][$nestedKey] = $nestedValue;
                    }
                    $this->__translateConditions($conditions[$operator][$nestedKey]);
                }
            } elseif (preg_match("/^(.+) (<|>|<=|>=|!=|=) (.+)$/", $key, $matches)
                || (is_string($value) && preg_match("/^(.+) (<|>|<=|>=|!=|=) (.+)$/", $value, $matches))
            ) {
                unset($conditions[$key]);
                array_splice($matches, 0, 1);
                $conditions['$where'] = implode(' ', array_map(function ($v) {
                    if (preg_match("/^[\w.]+$/", $v)
                        && substr($v, 0, strlen('this')) !== 'this'
                    ) {
                        $v = "this.$v";
                    }
                    return $v;
                }, $matches));
            } elseif ($key === '_id' && is_string($value)) {
                $conditions[$key] = new \MongoDB\BSON\ObjectId($value);
            }
        }

        return $conditions;
    }

    /**
     * Convert logical operator to MongoDB Query Selectors
     * @param string $operator
     * @return string
     */
    private function __translateOperator($operator)
    {
        switch ($operator) {
            case '<': return '$lt';
            case '<=': return '$lte';
            case '>': return '$gt';
            case '>=': return '$gte';
            case '=': return '$eq';
            case '!=':
            case '<>': return '$ne';
            case 'NOT IN': return '$nin';
            case 'IN': return '$in';
            default: return $operator;
        }
    }

    /**
     * try to find documents
     *
     * @param array $options
     * @return \MongoDB\Driver\Cursor $cursor
     * @access public
     */
    public function find(array $options = [])
    {
        $this->__sortOption($options);
        $this->__limitOption($options);
        $cursor = $this->connection()->find($this->_options['where'], $options);
        if (is_array($cursor) || $cursor instanceof Countable) {
            $this->_totalRows = count($cursor);
        }else{
            $this->_totalRows = 0;
        }

        return $cursor;
    }

    /**
     * return all documents
     *
     * @return \MongoDB\Driver\Cursor
     * @access public
     */
    public function findAll()
    {
        return $this->find();
    }

    /**
     * return all documents
     *
     * @return array
     * @access public
     */
    public function findList()
    {
        $results = [];
        $keyField = isset($this->_options['keyField'])
            ? $this->_options['keyField']
            : '_id'
        ;
        $valueField = isset($this->_options['valueField'])
            ? $this->_options['valueField']
            : 'name'
        ;

        $cursor = $this->find(['projection' => [$keyField => 1, $valueField => 1]]);
        foreach (iterator_to_array($cursor) as $value) {
            $key = (string)Hash::get((array)$value, $keyField, '');
            if ($key) {
                $results[$key] = (string)Hash::get((array)$value, $valueField, '');
            }
        }
        return $results;
    }

    /**
     * return all documents
     *
     * @param array $options
     * @return array|object
     * @access public
     */
    public function findFirst(array $options = [])
    {
        $this->__sortOption($options);
        $result = $this->connection()->findOne($this->_options['where'], $options);
        $this->_totalRows = (int)((bool)$result);
        return $result;
    }

    /**
     * Append sort to options with $this->_options['order']
     * @param array $options
     */
    private function __sortOption(array &$options)
    {
        if (!empty($this->_options['order'])) {
            $options['sort'] = array_map(
                function ($v) {
                    return strtolower((string)$v) === 'desc' ? -1 : 1;
                },
                Hash::get($options, 'sort', [])
                + Hash::normalize((array)$this->_options['order'])
            );
        }
    }

    /**
     * Append limit and skip options
     * @param array $options
     */
    private function __limitOption(array &$options)
    {
        if (!empty($this->_options['limit']) && !isset($options['limit'])) {
            $options['limit'] = $this->_options['limit'];
        }
        if (!empty($this->_options['page']) && $this->_options['page'] > 1
            && !empty($options['limit'])
            && !isset($options['skip'])
        ) {
            $options['skip'] = $options['limit'] * ($this->_options['page'] -1);
        }
    }

    /**
     * return document with _id = $primaKey
     *
     * @param string $primaryKey
     * @return array|object
     * @access public
     */
    public function get($primaryKey)
    {
        $this->_options['where']['_id'] = new \MongoDB\BSON\ObjectId($primaryKey);

        return $this->findFirst();
    }

    /**
     * return number of rows finded
     *
     * @return int
     * @access public
     */
    public function count()
    {
        return $this->_totalRows;
    }
}
