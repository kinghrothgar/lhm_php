<?php

namespace Lhm;

use Phinx\Db\Adapter\AdapterInterface;
use Psr\Log\LoggerInterface;


class Invoker
{

    const LOCK_WAIT_TIMEOUT_DELTA = -2;
    const LOCK_WAIT_TIMEOUT_MAX = 100;

    /**
     * @var Table
     */
    protected $origin;

    /**
     * @var Table
     */
    protected $destination;

    /**
     * @var array
     */
    protected $options;

    /**
     * @var LoggerInterface
     */
    protected $logger;

    /** @var  AdapterInterface */
    protected $adapter;

    /**
     * @param AdapterInterface $adapter
     * @param Table $origin
     * @param array $options
     *                      - `stride` integer
     *                          Size of a chunk (defaults to 2000)
     *                      - `atomic_switch` boolean
     *                          Enable atomic switching (defaults to true)
     *                      - `retry_sleep_time` integer
     *                          How long should the switch wait until retrying ( defaults to 10 )
     *                      - `max_retries` integer
     *                          How many times the switch should be attempted ( defaults to 600 )
     *                      - `archive_name` string
     *                          Name of the archive table ( defaults to 'lhma_' . gmdate('Y_m_d_H_i_s') . "_{$origin->getName()}" )
     */
    public function __construct(AdapterInterface $adapter, \Phinx\Db\Table $origin, array $options = [])
    {
        $this->options = $options;
        $this->adapter = $adapter;
        $this->origin = $origin;
    }

    /**
     * @param LoggerInterface $logger
     */
    public function setLogger(LoggerInterface $logger)
    {
        $this->logger = $logger;
    }

    /**
     * @return LoggerInterface
     */
    protected function getLogger()
    {
        return $this->logger ?: new NullLogger();
    }

    /**
     * @param callable $migration Closure that receives the table to operate on.
     *
     *  <example>
     *  $migration->execute(function($table) {
     *      $table
     *          ->removeColumn('name')
     *          ->save();
     *  });
     *  </example>
     */
    public function execute(callable $migration)
    {
        $this->logger->info("Starting LHM run on table={$this->origin->getName()}");

        $sqlHelper = new SqlHelper($this->adapter);

        if (!isset($options['atomic_switch'])) {

            if ($sqlHelper->supportsAtomicSwitch()) {
                $this->options['atomic_switch'] = true;
            } else {
                $version = $sqlHelper->versionString();
                throw new \RuntimeException("Using mysql {$version}. You must explicitly set `options['atomic_switch']` (re SqlHelper::supportsAtomicSwitch)");
            }

        }

        if (!$this->destination) {
            $this->destination = $this->temporaryTable();
        }

        $this->setSessionLockWaitTimeouts();


        $entangler = new Entangler($this->adapter, $this->origin, $this->destination, $sqlHelper);
        $entangler->setLogger($this->logger);

        if ($this->options['atomic_switch']) {
            $switcher = new AtomicSwitcher($this->adapter, $this->origin, $this->destination, $this->options);
        } else {
            $switcher = new LockedSwitcher($this->adapter, $this->origin, $this->destination, $this->options);
        }

        $switcher->setLogger($this->logger);

        $chunker = new Chunker($this->adapter, $this->origin, $this->destination, $sqlHelper, $this->options);
        $chunker->setLogger($this->logger);

        $migration($this->destination);

        $entangler->run(function () use ($chunker, $switcher) {
            $chunker->run();
            $switcher->run();
        });
    }

    protected function setSessionLockWaitTimeouts()
    {
        $logger = $this->getLogger();
        $logger->debug("Getting mysql session lock wait timeouts");

        //TODO File a bug with Phinx. $adapter->query does not return an array ( returns a PDOStatement )
        $result = $this->adapter->query("SHOW GLOBAL VARIABLES LIKE 'innodb_lock_wait_timeout'")->fetch();
        $globalInnodbLockWaitTimeout = isset($result['Value']) ? $result['Value'] : $result[0];

        $result = $this->adapter->query("SHOW GLOBAL VARIABLES LIKE 'lock_wait_timeout'")->fetch();
        $globalLockWaitTimeout = isset($result['Value']) ? $result['Value'] : $result[0];

        if ($globalInnodbLockWaitTimeout) {
            $value = ((int)$globalInnodbLockWaitTimeout) + static::LOCK_WAIT_TIMEOUT_DELTA;
            $value = min($value, self::LOCK_WAIT_TIMEOUT_MAX);

            $logger->debug("Setting session innodb_lock_wait_timeout to `{$value}`");

            $this->adapter->query("SET SESSION innodb_lock_wait_timeout={$value}");
        }

        if ($globalLockWaitTimeout) {
            $value = ((int)$globalLockWaitTimeout) + static::LOCK_WAIT_TIMEOUT_DELTA;
            $value = min($value, self::LOCK_WAIT_TIMEOUT_MAX);

            $logger->debug("Setting session lock_wait_timeout to `{$value}`");

            $this->adapter->query("SET SESSION lock_wait_timeout={$value}");
        }
    }


    /**
     * @return Table
     * @throws \RuntimeException
     */
    public function temporaryTable()
    {

        if ($this->destination) {
            return $this->destination;
        }

        $temporaryTableName = $this->temporaryTableName();

        if ($this->adapter->hasTable($temporaryTableName)) {
            throw new \RuntimeException("The table `{$temporaryTableName}` already exists.");
        }

        $this->getLogger()->info("Creating temporary table `{$temporaryTableName}` from `{$this->origin->getName()}`");
        $this->adapter->query("CREATE TABLE {$temporaryTableName} LIKE {$this->origin->getName()}");

        return new \Lhm\Table($temporaryTableName, [], $this->adapter);
    }

    /**
     * Returns the temporary table name.
     * @return string
     */
    public function temporaryTableName()
    {
        return "lhmn_{$this->origin->getName()}";
    }
}
