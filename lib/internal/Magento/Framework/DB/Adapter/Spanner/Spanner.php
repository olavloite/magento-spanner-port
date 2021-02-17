<?php
namespace Magento\Framework\DB\Adapter\Spanner;

use Google\Cloud\Spanner\SpannerClient;
use Google\Cloud\Spanner\Transaction;
use Magento\Framework\DB\Adapter\Spanner\SpannerInterface;

/**
 * Cloud Spanner database adapter
 *
 */

class Spanner implements SpannerInterface
{
    /**
     * Google cloud project id
     * @var string
     */
    private $project_id = 'mag-project';

    /**
     * Google cloud instance name
     * @var string
     */
    private $instance  = 'mag-instance';

    /**
     * Cloud spanner database name
     * @var string
     */
    private $database  = 'magentocs';

    /**
     * Is cloud spanner emulator
     * @var bool
     */
    private $is_emulator = true;

    /**
     * Connection Object
     * Magento\Framework\DB\Adapter\Spanner\SpannerInterface
     */
    private $_connection = null;

    /**
     * Constructor
     * init connection
     */

    public function __construct() {
        $this->_connect();
    }

    /**
     * Creates a Spanner object and connects to the database.
     *
     * @return void
     */
    protected function _connect()
    {
        if ($this->is_emulator) {
            putenv('SPANNER_EMULATOR_HOST=localhost:9010');
        }
        if ($this->_connection) {
            return;
        }
        $spanner = new SpannerClient([ 'projectId' => $this->project_id ]);
        $instance = $spanner->instance($this->instance);
        $this->_connection = $instance->database($this->database);
    }

    /**
     * Run raw Query
     *
     * @param string $sql
     * @return mixed|null
     */
    public function rawQuery(string $sql)
    {
        $result = $this->query($sql);
        return $result;
    }

    /**
     * Run row query and Fetch data
     *
     * @param string $sql
     * @param string $field
     * @return mixed|null
     */
    public function rawFetchRow(string $sql, string $field = null)
    {
        $result = $this->rawQuery($sql);
        if (!$result) {
            return false;
        }

        $row = $this->fetch($result);
        if (!$row) {
            return false;
        }

        if (empty($field)) {
            return $row;
        } else {
            return $row[$field] ?? false;
        }
    }

    /**
     * Returns first row
     *
     * @param array $data
     * @return object
     */
    public function fetchOne(array $data)
    {
        return $data->rows()->current();
    }

    /**
     * Returns all rows
     *
     * @param array $data
     * @return array
     */
    public function fetch(array $data)
    {
        return iterator_to_array($data->rows());
    }

    /**
     * Fetch all rows
     *
     * @param string $sql
     * @return array
     */
    public function fetchAll(string $sql)
    {
        $result = $this->query($sql);
        return $this->fetch($result);
    }

    /**
     * query
     *
     * @param string $sql
     * @return mixed|null
     */
    public function query(string $sql)
    {
        $results = $this->_connection->execute($sql);
        return $results;
    }

    /**
     * Allows multiple queries
     *
     * @param string $sql
     * @return mixed|null
     */
    public function multiQuery(string $sql)
    {
        return $this->query($sql);
    }

    /**
     * Unquote raw string (use for auto-bind)
     *
     * @param string $string
     * @return string
     */
    protected function unQuote(string $string)
    {
        $translate = [
            "\\000" => "\000",
            "\\n"   => "\n",
            "\\r"   => "\r",
            "\\\\"  => "\\",
            "\'"    => "'",
            "\\\""  => "\"",
            "\\032" => "\032",
        ];
        return strtr($string, $translate);
    }

    /**
     * Insert multiple rows in multiple tables
     * @param string $table
     * @param array $data
     * @return Commit timestamp
     */
    public function insertArray(string $table, array $data) 
    {
        $session = $this->_connection->transaction(['singleUse' => true]);
        for ($i = 0; $i <= count($table); $i++) {
            $session->insertBatch($table[$i], $data[$i]);
        }
        $results = $session->commit();
        return $results;
    }

    /**
     * Insert multiple rows in single table
     * @param string $table
     * @param array $data
     * @return Commit timestamp
     */
    public function insert(string $table, array $data) 
    {
        $results = $this->_connection->transaction(['singleUse' => true])
                    ->insertBatch($table, $data)
                    ->commit();
        return $results;
    }

    /**
     * Single col update in the table
     * @param string $table
     * @param string $bindCol
     * @param string $bind
     * @param string $whereCol
     * @param string $where
     * @param array $data
     * @return Commit timestamp
     */
    public function update(string $table, string $bindCol, string $bind, string $whereCol, string $where) 
    {

        $results = $this->_connection->transaction(['singleUse' => true])
                    ->updateBatch($table, [
                        [$whereCol => $where, $bindCol => $bind]
                    ])
                    ->commit();
        return $results;
    }

    /**
     * Delete from table
     * @param string $table
     * @param string $where
     * @return Commit timestamp
     */
    public function delete(string $table, string $where) 
    {
        $sql = "DELETE FROM ".$table." WHERE ".$where;
        $results = $this->_connection->runTransaction(function (Transaction $t) use ($sql) {
            $rowCount = $t->executeUpdate($sql);
            $t->commit();
        });
        return $results;
    }

    /**
     * Format Date to T and Z iso format
     * @param string $date
     * @return string
     */
    public function formatDate(string $date)
    {
        return str_replace('+00:00', '.000Z', gmdate('c', strtotime($date)));
    }

    /**
     * Generate UUID
     * @return string
     */
    public function getAutoIncrement() 
    {
        if (function_exists('com_create_guid') === true) {
            return trim(com_create_guid(), '{}');
        }

        return sprintf('%04X%04X-%04X-%04X-%04X-%04X%04X%04X', mt_rand(0, 65535), mt_rand(0, 65535), mt_rand(0, 65535), mt_rand(16384, 20479), mt_rand(32768, 49151), mt_rand(0, 65535), mt_rand(0, 65535), mt_rand(0, 65535));    
    }

    /**
     * Returns the single row
     * @param string $sql
     * @return object
     */
    public function fetchRow(string $sql) 
    {
        $result = $this->query($sql);
        return $this->fetchOne($result);
    }

    /**
     * Cast the column with type
     * @param string $sql
     * @param string $col
     * @param string $type
     * @return string 
     */
    public function addCast(string $sql, string $col, string $type) 
    {
       $cast = "cast(".$col." as ".$type.")";
       return str_replace($col, $cast, $sql);
    }
    
    /**
     * Closes the connection.
     * @return void
     */
    public function closeConnection()
    {
        if ($this->_connection) {
            $this->_connection->close();
        }
    }
}
