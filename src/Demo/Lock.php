<?php

namespace Kirby\Demo;

use Kirby\Exception\Exception;

/**
 * Lock manager
 * Ensures that build and create operations don't run in parallel
 *
 * @package   Kirby Demo
 * @author    Lukas Bestle <lukas@getkirby.com>
 * @link      https://getkirby.com
 * @copyright Bastian Allgeier GmbH
 * @license   https://opensource.org/licenses/MIT
 */
class Lock
{
    /**
     * App instance
     *
     * @var \Kirby\Demo\Demo
     */
    protected $demo;

    /**
     * File handle of the file used for locking
     *
     * @var resource
     */
    protected $handle;

    /**
     * Class constructor
     *
     * @param \Kirby\Demo\Demo $demo
     */
    public function __construct(Demo $demo)
    {
        $this->demo = $demo;

        // open the file used for locking;
        // creates it if it doesn't exist
        $this->handle = fopen($this->demo->config()->root() . '/data/.lock', 'c');
    }

    /**
     * Clears the lock when the instance is destructed
     */
    public function __destruct()
    {
        $this->releaseLock();
        fclose($this->handle);
    }

    /**
     * Acquires an exclusive lock
     * (so that only one thread can build the template)
     *
     * @return void
     */
    public function acquireExclusiveLock(): void
    {
        $this->acquireLock(LOCK_EX);
    }

    /**
     * Acquires a custom lock
     *
     * @param int $operation Operation for flock()
     * @return void
     */
    public function acquireLock(int $operation): void
    {
        if (flock($this->handle, $operation) !== true) {
            throw new Exception('Could not acquire lock');
        }
    }

    /**
     * Acquires a shared lock
     * (so that multiple threads can create instances)
     *
     * @return void
     */
    public function acquireSharedLock(): void
    {
        $this->acquireLock(LOCK_SH);
    }

    /**
     * Releases the active lock
     *
     * @return void
     */
    public function releaseLock(): void
    {
        if (flock($this->handle, LOCK_UN) !== true) {
            throw new Exception('Could not release lock');
        }
    }
}
