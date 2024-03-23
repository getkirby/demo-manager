<?php

namespace Kirby\Demo;

use Kirby\Exception\Exception;
use Kirby\Http\Uri;
use Kirby\Toolkit\Dir;

/**
 * A single demo instance
 *
 * @package   Kirby Demo
 * @author    Lukas Bestle <lukas@getkirby.com>
 * @link      https://getkirby.com
 * @copyright Bastian Allgeier
 * @license   https://opensource.org/licenses/MIT
 */
class Instance
{
	/**
	* Cache for the last activity timestamp
	*/
	protected int $lastActivity;

	/**
	 * Class constructor
	 */
	public function __construct(
		/**
		 * Timestamp when the instance was created
		 */
		protected int|null $created,

		/**
		 * Automatically incrementing instance ID
		 */
		protected int $id,

		/**
		 * Instances object this instance is from
		 */
		protected Instances $instances,

		/**
		 * Truncated hash of the creator's IP address
		 */
		protected string|null $ipHash,

		/**
		 * Random instance name in the URL
		 */
		protected string $name,
	) {
	}

	/**
	 * Returns the timestamp when the instance was created
	 */
	public function created(): int|null
	{
		return $this->created;
	}

	/**
	 * Returns the human-readable instance creation time
	 */
	public function createdHuman(): string|null
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
	 */
	public function expiry(): int|null
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
	 */
	public function expiryHuman(): string|null
	{
		return static::expiryTimeToHuman($this->expiry());
	}

	/**
	 * Returns the timestamp when this instance will definitely expire
	 */
	public function expiryMax(): int|null
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
	 */
	public function expiryMaxHuman(): string|null
	{
		return static::expiryTimeToHuman($this->expiryMax());
	}

	/**
	 * Converts an expiry timestamp into a human-readable duration
	 */
	protected static function expiryTimeToHuman(int|null $timestamp): string|null
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
	 */
	public function hasExpired(): bool
	{
		$expiry = $this->expiry();

		return $expiry !== null && time() > $expiry;
	}

	/**
	 * Checks if there was any activity in this instance
	 * within the last five minutes
	 */
	public function isHot(): bool
	{
		return time() - $this->lastActivity() < 5 * 60;
	}

	/**
	 * Checks if the instance is only prepared
	 */
	public function isPrepared(): bool
	{
		return $this->ipHash() === null;
	}

	/**
	 * Returns the truncated hash of the creator's IP address
	 */
	public function ipHash(): string|null
	{
		return $this->ipHash;
	}

	/**
	 * Returns the timestamp of the last activity inside the instance
	 */
	public function lastActivity(): int
	{
		if (isset($this->lastActivity)) {
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
		// (important for instances that were prepared before the creation)
		if ($this->created !== null && $lastActivity < $this->created) {
			$lastActivity = $this->created;
		}

		return $this->lastActivity = $lastActivity;
	}

	/**
	 * Returns the random instance name in the URL
	 */
	public function name(): string
	{
		return $this->name;
	}

	/**
	 * Returns the instance's filesystem root
	 */
	public function root(): string
	{
		return $this->instances->demo()->config()->root() . '/public/' . $this->name;
	}

	/**
	 * Returns the instance's URL
	 */
	public function url(): string
	{
		return Uri::current()->setPath($this->name)->setSlash(true)->toString();
	}
}
