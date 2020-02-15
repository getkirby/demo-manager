<?php

namespace Kirby\Demo;

use Kirby\Exception\Exception;
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
     * @var int|null
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
     * @var string|null
     */
    protected $ipHash;

    /**
     * Cache for the last activity timestamp
     *
     * @var int
     */
    protected $lastActivity;

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
     * @return int|null
     */
    public function created(): ?int
    {
        return $this->created;
    }

    /**
     * Returns the human-readable instance creation time
     *
     * @return string|null
     */
    public function createdHuman(): ?string
    {
        $created = $this->created();
        if ($created === null) {
            return null;
        }

        $seconds = time() - $this->created();
        $hours   = (int)floor($seconds / 3600);
        $minutes = (int)round(($seconds / 60) % 60);

        if ($seconds < 60) {
            return $seconds . (($seconds === 1)? ' second' : ' seconds');
        }

        $string = '';
        if ($seconds >= 3600) {
            $string .= $hours . (($hours === 1)? ' hour and ' : ' hours and ');
        }

        return $string . $minutes . (($minutes === 1)? ' minute' : ' minutes');
    }

    /**
     * Deletes the current instance
     *
     * @return void
     */
    public function delete(): void
    {
        $this->instances->demo()->runHook($this->root(), 'delete:before', $this);

        Dir::remove($this->root());
        $this->instances->delete($this->id);

        $this->instances->demo()->runHook($this->root(), 'delete:after', $this);
    }

    /**
     * Returns the timestamp when this instance will expire
     *
     * @return int|null
     */
    public function expiry(): ?int
    {
        $demo    = $this->instances->demo();
        $created = $this->created();

        if ($created === null) {
            return null;
        }

        // absolute expiration based on the creation time
        $absoluteExpiry = $created + $demo->config()->expiryAbsolute();

        // inactivity expiration based on content changes
        $inactivityExpiry = $this->lastActivity() + $demo->config()->expiryInactivity();

        // return the shorter time of the two
        return min($absoluteExpiry, $inactivityExpiry);
    }

    /**
     * Returns the human-readable duration when this instance will expire
     *
     * @return string|null
     */
    public function expiryHuman(): ?string
    {
        return static::expiryTimeToHuman($this->expiry());
    }

    /**
     * Returns the timestamp when this instance will definitely expire
     *
     * @return int|null
     */
    public function expiryMax(): ?int
    {
        $created = $this->created();
        if ($created === null) {
            return null;
        }

        // the instance cannot last any longer than the absolute expiry time,
        // no matter the current activity
        return $created + $this->instances->demo()->config()->expiryAbsolute();
    }

    /**
     * Returns the human-readable duration when this instance will definitely expire
     *
     * @return string|null
     */
    public function expiryMaxHuman(): ?string
    {
        return static::expiryTimeToHuman($this->expiryMax());
    }

    /**
     * Converts an expiry timestamp into a human-readable duration
     *
     * @param int|null $timestamp
     * @return string|null
     */
    protected static function expiryTimeToHuman(?int $timestamp): ?string
    {
        if ($timestamp === null) {
            return null;
        }

        $expiry  = $timestamp - time();
        $hours   = (int)floor($expiry / 3600);
        $minutes = (int)round(($expiry / 60) % 60);

        if ($expiry <= 0) {
            return 'any time now';
        }

        $string = 'in ';
        if ($expiry >= 3600) {
            $string .= $hours . (($hours === 1)? ' hour and ' : ' hours and ');
        }

        return $string . $minutes . (($minutes === 1)? ' minute' : ' minutes');
    }

    /**
     * Grabs a prepared instance by assigning it to the current IP address
     *
     * @return void
     */
    public function grab(): void
    {
        if ($this->isPrepared() !== true) {
            throw new Exception('Instance is already taken');
        }

        // grab the instance in the database
        $this->instances->update($this->id, [
            'created' => $this->created = time(),
            'ipHash'  => $this->ipHash  = Instances::ipHash()
        ]);
    }

    /**
     * Checks if the instance has expired
     *
     * @return bool
     */
    public function hasExpired(): bool
    {
        $expiry = $this->expiry();

        return $expiry !== null && time() > $expiry;
    }

    /**
     * Checks if there was any activity in this instance
     * within the last five minutes
     *
     * @return bool
     */
    public function isHot(): bool
    {
        return time() - $this->lastActivity() < 5 * 60;
    }

    /**
     * Checks if the instance is only prepared
     *
     * @return bool
     */
    public function isPrepared(): bool
    {
        return $this->ipHash() === null;
    }

    /**
     * Returns the truncated hash of the creator's IP address
     *
     * @return string|null
     */
    public function ipHash(): ?string
    {
        return $this->ipHash;
    }

    /**
     * Returns the timestamp of the last activity inside the instance
     *
     * @return int
     */
    public function lastActivity(): int
    {
        if ($this->lastActivity) {
            return $this->lastActivity;
        }

        $activityPath = $this->instances->demo()->config()->activityPath();
        $activityRoot = $this->root() . '/' . $activityPath;

        // ensure that the directory (still) exists before trying to access it
        if (file_exists($activityRoot) !== true) {
            return $this->lastActivity = 0;
        }

        // get the modified timestamp of the most recently
        // updated file inside the activity directory
        $lastActivity = Dir::modified($activityRoot);

        // it can never be older than the creation date
        // (important for prepared instances)
        if ($this->created !== null && $lastActivity < $this->created) {
            $lastActivity = $this->created;
        }

        return $this->lastActivity = $lastActivity;
    }

    /**
     * Returns the random instance name in the URL
     *
     * @return string
     */
    public function name(): string
    {
        return $this->name;
    }

    /**
     * Returns the instance's filesystem root
     *
     * @return string
     */
    public function root(): string
    {
        return $this->instances->demo()->config()->root() . '/public/' . $this->name;
    }

    /**
     * Sets the timestamp when the instance was created
     *
     * @param int|null $created
     * @return self
     */
    protected function setCreated(?int $created = null)
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
     * @param string|null $ipHash
     * @return self
     */
    protected function setIpHash(?string $ipHash = null)
    {
        $this->ipHash = $ipHash;
        return $this;
    }

    /**
     * Sets the random instance name in the URL
     *
     * @param string $name
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
