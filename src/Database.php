<?php
/**
 * Class that include database functions and configuration
 *
 * PHP version 5.5
 *
 * @category Database
 * @package  nightlinus\OracleDb
 * @author   Ogarkov Mikhail <m.a.ogarkov@gmail.com>
 * @license  http://opensource.org/licenses/MIT MIT
 * @version  0.1.0
 * @link     https://github.com/nightlinus/OracleDb
 */
namespace nightlinus\OracleDb;

/**
 * Class Database
 *
 * @package nightlinus\OracleDb
 */
class Database
{

    /**
     * Profiler for db instance
     *
     * @type Profiler
     */
    public $profiler;

    /**
     * Configuration storage
     *
     * @type Config
     */
    protected $config;

    /**
     * @type resource connection resource
     */
    protected $connection;

    /**
     * @type string
     */
    protected $connectionString;

    /**
     * last executed statement
     *
     * @type Statement | null
     */
    protected $lastStatement;

    /**
     * @type string password for db connection
     */
    protected $password;

    /**
     * @type StatementCache
     */
    protected $statementCache;

    /**
     * @type string username for db connection
     */
    protected $userName;

    /**
     * Consttructor for Database class implements
     * base parametrs checking
     *
     * @param string $userName
     * @param string $password
     * @param string $connectionString
     *
     * @param        $config
     *
     * @throws Exception
     */
    public function __construct(
        $userName,
        $password,
        $connectionString,
        $config = null
    ) {
        if (!isset($userName) || !isset($password) || !isset($connectionString)) {
            throw new Exception("One of connection parameters is null or not set");
        }
        $this->config = new Config($config);
        $this->userName = $userName;
        $this->password = $password;
        $this->connectionString = $connectionString;
        $this->config = $config;
    }

    /**
     *  Освобождаем ресурсы в деструкторе
     */
    public function __destruct()
    {
        $this->disconnect();
    }

    /**
     * @param string $sqlText
     * @param int    $returnSize
     * @param null   $bindings
     * @param null   $mode
     *
     * @return mixed
     */
    public function call($sqlText, $returnSize = 4000, $bindings = null, $mode = null)
    {
        $returnName = $this->getUniqueAlias('z__');
        $bindings[ $returnName ] = [ null, $returnSize ];
        $sqlText = "BEGIN :$returnName := $sqlText; END;";
        $statement = $this->query($sqlText, $bindings, $mode);

        return $statement->bindings[ $returnName ];
    }

    /**
     * Commit session changes to server
     *
     * @throws Exception
     * @return $this
     */
    public function commit()
    {
        $commitResult = oci_commit($this->connection);
        if ($commitResult === false) {
            $error = $this->getOCIError();
            throw new Exception($error);
        }

        return $this;
    }

    /**
     * General function to get and set
     * configuration values
     *
     * @param string     $name
     * @param null|mixed $value
     *
     * @throws Exception
     * @return mixed
     */
    public function config($name, $value = null)
    {
        if (func_num_args() === 1) {
            if (is_array($name)) {
                $this->config->set($name);
            } else {
                return $this->config->get($name);
            }
        } else {
            $this->config->set($name, $value);
        }

        return $value;
    }

    /**
     * Method to connect to database
     * It performs base connection checking
     * and client identifiers init.
     *
     * @return $this
     * @throws Exception
     */
    public function connect()
    {
        if ($this->connection) {
            return $this;
        }
        $this->setUpSessionBefore();
        if ($this->config(Config::CONNECTION_PERSISTENT)) {
            $connectFunction = 'oci_pconnect';
        } else {
            $connectFunction = $this->config(Config::CONNECTION_CACHE) ? 'oci_connect' : 'oci_new_connect';
        }
        $this->connection = $connectFunction(
            $this->userName,
            $this->password,
            $this->connectionString,
            $this->config(Config::CONNECTION_CHARSET),
            $this->config(Config::CONNECTION_PRIVILEGED)
        );
        if ($this->connection === false) {
            $error = $this->getOCIError();
            throw new Exception($error);
        }
        $this->setUpSessionAfter();

        return $this;
    }

    /**
     * Method to stop measuring profile
     *
     * @return $this
     */
    public function endProfile()
    {
        if ($this->config(Config::PROFILER_ENABLED)) {
            $this->profiler->end();
        }

        return $this;
    }

    /**
     * Function to access current connection
     *
     * @return resource
     */
    public function getConnection()
    {
        return $this->connection;
    }

    /**
     * @return Statement
     */
    public function getLastStatement()
    {
        return $this->lastStatement;
    }

    /**
     * Setter for lastStatement
     *
     * @see $lastStatement
     *
     * @param $statement
     *
     * @return $this
     */
    public function setLastStatement($statement)
    {
        $this->lastStatement = $statement;

        return $this;
    }

    /**
     * Get oracle RDBMS version
     *
     * @return string
     * @throws Exception
     */
    public function getServerVersion()
    {
        $this->connect();
        $version = oci_server_version($this->connection);
        if ($version === false) {
            $error = $this->getOCIError();
            throw new Exception($error);
        }

        return $version;
    }

    /**
     * Methods to prepare Database statement
     * object from raw queryString
     *
     * @param string $sqlText
     *
     * @return Statement
     * @throws Exception
     */
    public function prepare($sqlText)
    {
        $this->connect();
        $statement = $this->getStatement($sqlText);
        $statement->prepare();

        return $statement;
    }

    /**
     * Shortcut method to prepare and fetch
     * statement.
     *
     * @param string     $sqlText
     * @param array|null &$bindings
     * @param null       $mode
     *
     * @return Statement
     * @throws Exception
     */
    public function query($sqlText, $bindings = null, $mode = null)
    {
        $statement = $this->prepare($sqlText);
        $statement->bind($bindings);
        $statement->execute($mode);

        return $statement;
    }

    /**
     * @param $variable
     *
     * @return string
     */
    public function quote($variable)
    {
        if (!is_array($variable)) {
            str_replace("'", "''", $variable);
            $variable = "'" . $variable . "'";
        } else {
            foreach ($variable as &$var) {
                $var = $this->quote($var);
            }

            $variable = implode(',', $variable);
        }

        return $variable;
    }

    /**
     * Rollback changes within session
     *
     * @return $this
     * @throws Exception
     */
    public function rollback()
    {
        $rollbackResult = oci_rollback($this->connection);
        if ($rollbackResult === false) {
            throw new Exception("Can't rollback");
        }

        return $this;
    }

    /**
     * Method for batch running «;» delimited queries
     *
     * @param $scriptText
     *
     * @throws Exception
     * @return $this
     */
    public function runScript($scriptText)
    {
        $queries = explode(";", $scriptText);
        $exceptions = [ ];
        $exceptionMessage = '';
        foreach ($queries as $query) {
            try {
                $query = trim($query);
                $len = strlen($query);
                if ($len > 0) {
                    $this->query($query);
                }
            } catch (Exception $e) {
                $exceptions[ ] = $e;
                $exceptionMessage .= $e->getMessage() . PHP_EOL;
            }
        }

        if (count($exceptions)) {
            throw new Exception($exceptionMessage);
        }

        return $this;
    }

    /**
     * @param $profileId
     *
     * @return $this
     */
    public function startFetchProfile($profileId)
    {
        if ($this->config(Config::PROFILER_ENABLED)) {
            return $this->profiler->startFetch($profileId);
        }

        return null;
    }

    /**
     * @param $sql
     * @param $bindings
     *
     * @return $this
     */
    public function startProfile($sql, $bindings = null)
    {
        if ($this->config(Config::PROFILER_ENABLED)) {
            return $this->profiler->start($sql, $bindings);
        }

        return null;
    }

    /**
     * @param $profileId
     *
     * @return $this
     */
    public function stopFetchProfile($profileId)
    {
        if ($this->config(Config::PROFILER_ENABLED)) {
            return $this->profiler->stopFetch($profileId);
        }

        return null;
    }

    /**
     * Get current Oracle client version
     *
     * @return mixed
     */
    public function version()
    {
        return oci_client_version();
    }

    /**
     * Method to set session variables via ALTER SESSION SET variable = value
     *
     * @param array $variables
     *
     * @return $this
     */
    protected function alterSession($variables)
    {
        if (count($variables) === 0) {
            return $this;
        }
        $sql = "ALTER SESSION SET ";
        foreach ($variables as $key => $value) {
            $sql .= "$key = '$value' ";
        }
        $this->query($sql);

        return $this;
    }

    /**
     * Cleaning memory by dissposing connection
     * handlers
     *
     * @return $this
     * @throws Exception
     */
    protected function disconnect()
    {
        if (!$this->connection) {
            return $this;
        }
        if (!oci_close($this->connection)) {
            throw new Exception("Can't close connection");
        }

        return $this;
    }

    /**
     * Method to fetch OCI8 error
     * Returns associative array with
     * "code" and "message" keys.
     *
     * @return array
     */
    protected function getOCIError()
    {
        $ociConnection = $this->connection;

        return is_resource($ociConnection) ?
            oci_error($ociConnection) :
            oci_error();
    }

    /**
     * @param $sql
     *
     * @return Statement
     * @throws Exception
     */
    protected function getStatement($sql)
    {
        $statementCacheEnabled = $this->config(Config::STATEMENT_CACHE_ENABLED);
        $statementCache = null;

        if ($statementCacheEnabled) {
            $statementCache = $this->statementCache->get($sql);
        }

        $statement = $statementCache ?: new Statement($this, $sql);

        if ($statementCacheEnabled && $statementCache === null) {
            $trashStatements = $this->statementCache->add($statement);
            $iter = $this->statementCache->getIterator();
            while ($trashStatements) {
                /**
                 * @type Statement $trashStatement
                 */
                $trashStatement = $iter->current();
                if ($trashStatement->canBeFreed()) {
                    $trashStatement->free();
                    if (--$trashStatements) {
                        break;
                    }
                }
                $iter->next();
            }
        }

        return $statement;
    }

    /**
     * Generate unique alias for naming
     * host variables or aliases
     *
     * @param string $prefix
     *
     * @return string
     */
    protected function getUniqueAlias($prefix)
    {
        $hash = uniqid($prefix, true);
        $hash = str_replace('.', '', $hash);

        return $hash;
    }

    /**
     * Setup session after connection is estabilished
     *
     * @return $this
     */
    protected function setUpSessionAfter()
    {
        //Set up profiler
        if ($this->config(Config::PROFILER_ENABLED)) {
            $class = $this->config(Config::PROFILER_CLASS);
            $this->profiler = is_string($class) ? new $class() : $class;
        }

        //Set up cache
        if ($this->config(Config::STATEMENT_CACHE_ENABLED)) {
            $class = $this->config(Config::STATEMENT_CACHE_CLASS);
            $cacheSize = $this->config(Config::STATEMENT_CACHE_SIZE);
            $this->statementCache = is_string($class) ? new $class($cacheSize) : $class;
        }

        oci_set_client_identifier($this->connection, $this->config(Config::CLIENT_IDENTIFIER));
        oci_set_client_info($this->connection, $this->config(Config::CLIENT_INFO));
        oci_set_module_name($this->connection, $this->config(Config::CLIENT_MODULENAME));
        $setUp = [ ];
        if ($this->config(Config::SESSION_DATE_FORMAT)) {
            $setUp[ 'NLS_DATE_FORMAT' ] = $this->config(Config::SESSION_DATE_FORMAT);
        }
        if ($this->config(Config::SESSION_DATE_LANGUAGE)) {
            $setUp[ 'NLS_DATE_LANGUAGE' ] = $this->config(Config::SESSION_DATE_LANGUAGE);
        }
        if ($this->config(Config::SESSION_SCHEMA)) {
            $setUp[ 'CURRENT_SCHEMA' ] = $this->config(Config::SESSION_SCHEMA);
        }
        $this->alterSession($setUp);

        return $this;
    }

    /**
     * Method to set up connection before call of oci_connect
     *
     * @return $this
     * @throws Exception
     */
    protected function setUpSessionBefore()
    {
        $connectionClass = $this->config(Config::CONNECTION_CLASS);
        if ($connectionClass) {
            ini_set('oci8.connection_class', $connectionClass);
        }
        $edition = $this->config(Config::CONNECTION_EDITION);
        if ($edition) {
            $result = oci_set_edition($edition);
            if ($result === false) {
                throw new Exception("Edition setup failed «{$edition}».");
            }
        }

        return $this;
    }
}
