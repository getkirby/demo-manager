<?php

namespace Kirby\Demo;

use Kirby\Http\Uri;
use Kirby\Toolkit\Dir;
use Kirby\Toolkit\Properties;

/**
 * A single demo instance
 *
 * @package   Kirby Demo
 * @author    Lukas Bestle <lukas@getkirby.com>
 * @link      https://getkirby.com
 * @copyright Bastian Allgeier GmbH
 * @license   https://opensource.org/licenses/MIT
 */
class Instance
{
    use Properties;

    /**
     * Timestamp when the instance was created
     *
     * @var int
     */
    protected $created;

    /**
     * Automatically incrementing instance ID
     *
     * @var int
     */
    protected $id;

    /**
     * Instances object this instance is from
     *
     * @var \Kirby\Demo\Instances
     */
    protected $instances;

    /**
     * Truncated hash of the creator's IP address
     *
     * @var string
     */
    protected $ipHash;

    /**
     * Random instance name in the URL
     *
     * @var string
     */
    protected $name;

    /**
     * Class constructor
     *
     * @param array $props
     */
    public function __construct(array $props = [])
    {
        $this->setProperties($props);
    }

    /**
     * Returns the timestamp when the instance was created
     *
     * @return int
     */
    public function created(): int
    {
        return $this->created;
    }

    /**
     * Returns the human-readable instance creation time
     *
     * @return string
     */
    public function createdHuman(): string
    {
        $created = time() - $this->created();
        $hours   = floor($created / 3600);
        $minutes = round(($created / 60) % 60);

        if ($created < 60) {
            return $created . ($created === 1)? ' second' : ' seconds';
        }

        $string = '';
        if ($created >= 3600) {
            $string .= $hours . ($hours === 1)? ' hour and ' : ' hours and ';
        }

        return $string . $minutes . ($minutes === 1)? ' minute' : 'minutes';
    }

    /**
     * Deletes the current instance
     *
     * @return void
     */
    public function delete(): void
    {
        Dir::remove($this->root());
        $this->instances->delete($this->id);
    }

    /**
     * Returns the timestamp when this instance will expire
     *
     * @return int
     */
    public function expiry(): int
    {
        $demo = $this->instances->demo();

        // absolute expiration based on the creation time
        $absoluteExpiry = $this->created + $demo->expiryAbsolute();

        // inactivity expiration based on content changes
        $inactivityExpiry = Dir::modified($this->root() . '/content') + $demo->expiryInactivity();

        // return the shorter time of the two
        return min($absoluteExpiry, $inactivityExpiry);
    }

    /**
     * Returns the human-readable duration when this instance will expire
     *
     * @return string
     */
    public function expiryHuman(): string
    {
        return static::expiryTimeToHuman($this->expiry());
    }

    /**
     * Returns the timestamp when this instance will definitely expire
     *
     * @return int
     */
    public function expiryMax(): int
    {
        // the instance cannot last any longer than the absolute expiry time,
        // no matter the current activity
        return $this->created + $this->instances->demo()->expiryAbsolute();
    }

    /**
     * Returns the human-readable duration when this instance will definitely expire
     *
     * @return string
     */
    public function expiryMaxHuman(): string
    {
        return static::expiryTimeToHuman($this->expiryMax());
    }

    /**
     * Converts an expiry timestamp into a human-readable duration
     *
     * @param int $timestamp
     * @return string
     */
    protected static function expiryTimeToHuman(int $timestamp): string
    {
        $expiry  = $timestamp - time();
        $hours   = floor($expiry / 3600);
        $minutes = round(($expiry / 60) % 60);

        if ($expiry <= 0) {
            return 'any time now';
        }

        $string = 'in roughly ';
        if ($created >= 3600) {
            $string .= $hours . ($hours === 1)? ' hour and ' : ' hours and ';
        }

        return $string . $minutes . ($minutes === 1)? ' minute' : 'minutes';
    }

    /**
     * Checks if the instance has expired
     *
     * @return bool
     */
    public function hasExpired(): bool
    {
        return time() > $this->expiry();
    }

    /**
     * Returns the truncated hash of the creator's IP address
     *
     * @return string
     */
    public function ipHash(): string
    {
        return $this->ipHash;
    }

    /**
     * Returns the instance's filesystem root
     *
     * @return string
     */
    public function root(): string
    {
        return $this->instances->demo()->root() . '/public/' . $this->name;
    }

    /**
     * Sets the timestamp when the instance was created
     *
     * @param int $created
     * @return self
     */
    protected function setCreated(int $created)
    {
        $this->created = $created;
        return $this;
    }

    /**
     * Sets the automatically incrementing instance ID
     *
     * @param int $id
     * @return self
     */
    protected function setId(int $id)
    {
        $this->id = $id;
        return $this;
    }

    /**
     * Sets the Instances object this instance is from
     *
     * @param \Kirby\Demo\Instances $instances
     * @return self
     */
    protected function setInstances(Instances $instances)
    {
        $this->instances = $instances;
        return $this;
    }

    /**
     * Sets the truncated hash of the creator's IP address
     *
     * @param int $ipHash
     * @return self
     */
    protected function setIpHash(string $ipHash)
    {
        $this->ipHash = $ipHash;
        return $this;
    }

    /**
     * Sets the random instance name in the URL
     *
     * @param int $name
     * @return self
     */
    protected function setName(string $name)
    {
        $this->name = $name;
        return $this;
    }

    /**
     * Returns the instance's URL
     *
     * @return string
     */
    public function url(): string
    {
        return Uri::current()->setPath($this->name)->setSlash(true)->toString();
    }
}
