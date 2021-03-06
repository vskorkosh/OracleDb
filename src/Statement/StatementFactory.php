<?php
/**
 * Date: 02.12.15
 * Time: 15:38
 *
 * @category
 * @package  OracleDb
 * @author   nightlinus <m.a.ogarkov@gmail.com>
 * @license  http://opensource.org/licenses/MIT MIT
 * @version
 * @link
 */
namespace nightlinus\OracleDb\Statement;

use nightlinus\OracleDb\Config;
use nightlinus\OracleDb\Database;
use nightlinus\OracleDb\Driver\AbstractDriver;
use nightlinus\OracleDb\Profiler\Profiler;

class StatementFactory
{
    /**
     * @var StatementCache
     */
    private $cache;

    /**
     * @var AbstractDriver
     */
    private $driver;

    /**
     * @var Profiler
     */
    private $profiler;

    /**
     * StatementFactory constructor.
     *
     * @param StatementCache  $cache
     * @param  AbstractDriver $driver
     * @param  Profiler       $profiler
     */
    public function __construct(StatementCache $cache, AbstractDriver $driver, Profiler $profiler)
    {
        $this->cache = $cache;
        $this->driver = $driver;
        $this->profiler = $profiler;
    }


    public function make($queryString, Database $db)
    {
        $statementCacheEnabled = $db->config(Config::STATEMENT_CACHE_ENABLED);
        $statementCache = null;
        $statement = null;

        if ($statementCacheEnabled) {
            $statement = $this->cache->get($queryString);
        }

        $statement = $statement ?: new Statement(
            $queryString,
            $db,
            $this->driver,
            $this->profiler,
            $db->config(Config::STATEMENT_RETURN_TYPE),
            $db->config(Config::STATEMENT_AUTOCOMMIT)
        );

        if ($statementCacheEnabled) {
            $this->cache->add($statement);
        }

        return $statement;
    }
}
