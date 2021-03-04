<?php

namespace Kirby\Demo;

use Kirby\Database\Database;
use Kirby\Exception\Exception;
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
            'database' => $demo->config()->root() . '/data/instances.sqlite',
            'type'     => 'sqlite'
        ]);

        // always throw exceptions
        $this->database->fail();

        // ensure that the table is initialized
        if ($this->database->validateTable('instances') !== true) {
            $this->database->createTable('instances', [
                'id'      => ['type' => 'id'],
                'name'    => ['type' => 'varchar', 'null' => false, 'unique' => true],
                'created' => ['type' => 'timestamp', 'null' => true],
                'ipHash'  => ['type' => 'varchar', 'null' => true, 'key' => 'ipHash']
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
     * @param bool $prepare Whether the instance should be created in prepare mode
     * @return \Kirby\Demo\Instance
     */
    public function create(bool $prepare = false)
    {
        // ensure that no other instance is created while we
        // determine ours
        $this->database->execute('BEGIN IMMEDIATE TRANSACTION');

        // check if there is a prepared instance we can use
        if ($prepare === false) {
            if ($instance = $this->get('ipHash IS NULL')) {
                $instance->grab();
                $this->database->execute('END TRANSACTION');

                return $instance;
            }
        }

        // generate a new unique name
        $table = $this->database->table('instances');
        do {
            $name = Str::random(8, 'alphaNum');
        } while ($table->where(['name' => $name])->count() > 0);

        // insert it into the DB and unlock again
        $props = [
            'name'    => $name,
            'created' => $prepare === true ? null : time(),
            'ipHash'  => $prepare === true ? null : static::ipHash()
        ];
        $id = $table->insert($props);
        $this->database->execute('END TRANSACTION');

        // prevent that the template gets rebuilt while
        // the instance is created
        $this->demo()->lock()->acquireSharedLock();

        // create the actual instance
        $root = $this->demo()->config()->root() . '/public/' . $name;
        exec(
            'cp -a ' .
            escapeshellarg($this->demo()->config()->templateRoot()) . ' ' .
            escapeshellarg($root),
            $output,
            $return
        );
        if ($return !== 0) {
            throw new Exception('Could not copy instance directory, got return value ' . $return);
        }

        $props['id'] = $id;
        $props['instances'] = $this;
        $instance = new Instance($props);

        $this->demo()->runHook($root, 'create:after', $instance);

        // the template can now be rebuilt again
        $this->demo()->lock()->releaseLock();

        return $instance;
    }

    /**
     * Finds the currently running instance by URL
     *
     * @return \Kirby\Demo\Instance|null
     */
    public function current()
    {
        $path = Str::before(ltrim($_SERVER['REQUEST_URI'], '/'), '/');
        return $this->get(['name' => $path]);
    }

    /**
     * Deletes a specified instance from the database
     *
     * @param int $id Instance ID
     * @return void
     */
    public function delete(int $id): void
    {
        $this->database->table('instances')->delete(['id' => $id]);
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
     * Returns the first instance matching a WHERE clause
     *
     * @param ...mixed WHERE clause
     * @return \Kirby\Demo\Instance
     */
    public function get()
    {
        return $this->all(...func_get_args())->first();
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
     * Ensures that the right number of instances are prepared
     *
     * @param int|null $num Custom number of instances to prepare; defaults to auto
     * @return void
     */
    public function prepare(?int $num = null): void
    {
        $instanceLimit = $this->demo()->config()->instanceLimit();

        // gather stats
        $countActive   = $this->count('ipHash IS NOT NULL');
        $countPrepared = $this->count('ipHash IS NULL');

        // determine how many prepared instances we need
        if ($num !== null) {
            // use the supplied number, but don't go over the limit
            $target = min($num, $instanceLimit - $countActive);
        } else {
            $target = min(
                max($countActive * 0.1, 10),   // 10 % of active, minimum 10
                $instanceLimit * 0.1,          // but not more than 10 % of limit
                $instanceLimit - $countActive  // and never go over the limit
            );
        }
        $remaining = $target - $countPrepared;

        // create the prepared instances
        if ($remaining > 0) {
            for ($i = 1; $i <= $remaining; $i++) {
                $this->create(true);
            }
        }
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
        $allActive = $all->filterBy('isPrepared', '==', false);

        // collect stats
        $numTotal    = $sequence->select('SEQ')->first();
        $numActive   = $allActive->count();
        $numHot      = $allActive->filterBy('isHot', '==', true)->count();
        $numExpired  = $allActive->filterBy('hasExpired', '==', true)->count();
        $numPrepared = $all->filterBy('isPrepared', '==', true)->count();
        $numClients  = (int)$this->database
                                ->query('SELECT COUNT(DISTINCT ipHash) as num FROM instances')
                                ->first()->num();
        $clientAvg   = ($numClients > 0)? $numActive / $numClients : null;
        $oldest      = $allActive->first();
        $latest      = $allActive->last();

        // determine the health status and report it to find potential bugs;
        // ordered by severity!
        $status = 'OK';
        if ($numActive >= $this->demo()->config()->instanceLimit()) {
            $status = 'CRITICAL:overload';
        } elseif ($numActive >= $this->demo()->config()->instanceLimit() * 0.7) {
            $status = 'WARN:overload-nearing';
        } elseif ($oldest && time() - $oldest->created() > $this->demo()->config()->expiryAbsolute() + 30 * 60) {
            $status = 'WARN:too-old-expired';
        } elseif ($numActive > 0 && $numExpired / $numActive > 0.4 && $numExpired > 20) {
            $status = 'WARN:too-many-expired';
        } elseif ($clientAvg && $clientAvg > $this->demo()->config()->maxInstancesPerClient()) {
            $status = 'WARN:too-many-per-client';
        } elseif ($numPrepared < 3) {
            $status = 'WARN:too-few-prepared';
        } else {
            $templateStatus = $this->demo()->runHook(
                $this->demo()->config()->templateRoot(),
                'status',
                $this->demo()
            );

            if ($templateStatus) {
                $status = $templateStatus;
            }
        }

        return [
            'status'      => $status,
            'numTotal'    => ($numTotal)? (int)$numTotal->seq() : 0,
            'numActive'   => $numActive,
            'numClients'  => $numClients,
            'numHot'      => $numHot,
            'numExpired'  => $numExpired,
            'numPrepared' => $numPrepared,
            'clientAvg'   => $clientAvg,
            'oldest'      => ($oldest)? date('r', $oldest->created()) : null,
            'latest'      => ($latest)? date('r', $latest->created()) : null
        ];
    }

    /**
     * Updates a specified instance in the database
     *
     * @param int $id Instance ID
     * @param array $values Data to update in the database
     * @return void
     */
    public function update(int $id, array $values): void
    {
        $this->database->table('instances')->update($values, ['id' => $id]);
    }
}
