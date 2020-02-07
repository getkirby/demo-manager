<?php

namespace Kirby\Demo;

use Kirby\Exception\Exception;
use Kirby\Database\Database;
use Kirby\Toolkit\Dir;
use Kirby\Toolkit\Str;

/**
 * Instance manager
 *
 * @package   Kirby Demo
 * @author    Lukas Bestle <lukas@getkirby.com>
 * @link      https://getkirby.com
 * @copyright Bastian Allgeier GmbH
 * @license   https://opensource.org/licenses/MIT
 */
class Instances
{
    /**
     * App instance
     *
     * @var \Kirby\Demo\Demo
     */
    protected $demo;

    /**
     * Database connection to the SQLite DB
     *
     * @var \Kirby\Database\Database
     */
    protected $database;

    /**
     * Class constructor
     *
     * @param \Kirby\Demo\Demo $demo
     */
    public function __construct(Demo $demo)
    {
        $this->demo = $demo;

        // open the database, create it if it doesn't exist
        $this->database = new Database([
            'database' => $demo->root() . '/data/instances.sqlite',
            'type'     => 'sqlite'
        ]);

        // always throw exceptions
        $this->database->fail();

        // ensure that the table is initialized
        if ($this->database->validateTable('instances') !== true) {
            $this->database->createTable('instances', [
                'id'      => ['type' => 'id'],
                'name'    => ['type' => 'varchar', 'null' => false, 'unique' => true],
                'created' => ['type' => 'timestamp', 'null' => false],
                'ipHash'  => ['type' => 'varchar', 'null' => false, 'key' => 'ipHash']
            ]);
        }
    }

    /**
     * Returns all currently active instances or a filtered subset
     *
     * @param ...mixed Optional WHERE clause
     * @return \Kirby\Toolkit\Collection Collection of \Kirby\Demo\Instance objects
     */
    public function all()
    {
        $query = $this->database->table('instances')->fetch(function ($props) {
            $props['instances'] = $this;
            return new Instance($props);
        });

        if (func_num_args() > 0) {
            $query = $query->where(...func_get_args());
        }

        return $query->all();
    }

    /**
     * Returns the number of currently active instances
     * optionally filtered by a WHERE clause
     *
     * @param ...mixed Optional WHERE clause
     * @return int
     */
    public function count(): int
    {
        $query = $this->database->table('instances');

        if (func_num_args() > 0) {
            $query = $query->where(...func_get_args());
        }

        return $query->count();
    }

    /**
     * Creates a new instance
     *
     * @return \Kirby\Demo\Instance
     */
    public function create()
    {
        // prevent that the template gets rebuilt while
        // the instance is created
        $this->demo()->lock()->acquireSharedLock();

        // generate a unique instance name;
        // ensure that no other process uses the same name
        $this->database->execute('BEGIN IMMEDIATE TRANSACTION');
        $table = $this->database->table('instances');
        do {
            $name = Str::random(8, 'alphaNum');
        } while ($table->where(['name' => $name])->count() > 0);

        // insert it into the DB and unlock again
        $props = [
            'name'    => $name,
            'created' => time(),
            'ipHash'  => static::ipHash()
        ];
        $id = $table->insert($props);
        $this->database->execute('END TRANSACTION');

        // create the actual instance
        exec(
            'cp -r ' .
            escapeshellarg($this->demo()->root() . '/data/template') . ' ' .
            escapeshellarg($this->demo()->root() . '/public/' . $name),
            $output,
            $return
        );
        if ($return !== 0) {
            throw new Exception('Could not copy instance directory, got return value ' . $return);
        }

        // the template can now be rebuilt again
        $this->demo()->lock()->releaseLock();

        $props['id'] = $id;
        $props['instances'] = $this;
        return new Instance($props);
    }

    /**
     * Finds the currently running instance by URL
     *
     * @return \Kirby\Demo\Instance|null
     */
    public function current()
    {
        $path = Str::before(ltrim($_SERVER['REQUEST_URI'], '/'), '/');
        return $this->all(['name' => $path])->first();
    }

    /**
     * Deletes a specified instance from the database
     *
     * @param int $id Instance ID
     * @return void
     */
    public function delete(int $id): void
    {
        $this->database->table('instances')->where(['id' => $id])->delete();
    }

    /**
     * Returns the app instance
     *
     * @return \Kirby\Demo\Demo
     */
    public function demo()
    {
        return $this->demo;
    }

    /**
     * Returns the IP address hash of the current client
     *
     * @return string
     */
    public static function ipHash(): string
    {
        $hash = hash('sha256', getenv('REMOTE_ADDR'));

        // only use the first 50 chars to ensure privacy
        return substr($hash, 0, 50);
    }

    /**
     * Returns the stats for debugging
     *
     * @return array
     */
    public function stats(): array
    {
        // base resources
        $sequence  = $this->database->table('sqlite_sequence');
        $instances = $this->database->table('instances');
        $all       = $this->all()->sortBy('created', SORT_ASC);

        // collect stats
        $numTotal   = $sequence->select('SEQ')->first();
        $numActive  = $all->count();
        $numExpired = $all->filterBy('hasExpired', '==', true)->count();
        $numClients = (int)$this->database
                                ->query('SELECT COUNT(DISTINCT ipHash) as num FROM instances')
                                ->first()->num();
        $clientAvg  = ($numClients > 0)? $numActive / $numClients : null;
        $oldest     = $all->first();
        $latest     = $all->last();

        // determine the health status and report it to find potential bugs;
        // ordered by severity!
        $status = 'OK';
        if ($numActive >= $this->demo()->instanceLimit()) {
            $status = 'CRITICAL:overload';
        } elseif ($numActive >= $this->demo()->instanceLimit() * 0.7) {
            $status = 'WARN:overload-nearing';
        } elseif ($oldest && time() - $oldest->created() > $this->demo()->expiryAbsolute() + 30 * 60) {
            $status = 'WARN:too-old-expired';
        } elseif ($numActive > 0 && $numExpired / $numActive > 0.1 && $numExpired > 10) {
            $status = 'WARN:too-many-expired';
        } elseif ($clientAvg && $clientAvg > $this->demo()->maxInstancesPerClient()) {
            $status = 'WARN:too-many-per-client';
        }

        return [
            'status'     => $status,
            'numTotal'   => ($numTotal)? (int)$numTotal->seq() : 0,
            'numActive'  => $numActive,
            'numExpired' => $numExpired,
            'numClients' => $numClients,
            'clientAvg'  => $clientAvg,
            'oldest'     => ($oldest)? date('r', $oldest->created()) : null,
            'latest'     => ($latest)? date('r', $latest->created()) : null
        ];
    }
}
