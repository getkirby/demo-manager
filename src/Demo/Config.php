<?php

namespace Kirby\Demo;

use Kirby\Toolkit\Properties;

/**
 * Config container
 *
 * @package   Kirby Demo
 * @author    Lukas Bestle <lukas@getkirby.com>
 * @link      https://getkirby.com
 * @copyright Bastian Allgeier GmbH
 * @license   https://opensource.org/licenses/MIT
 */
class Config
{
    use Properties;

    /**
     * Absolute expiration time based on the instance creation time in seconds
     *
     * @var int
     */
    protected $expiryAbsolute = 3 * 60 * 60;

    /**
     * Inactivity expiration time based on content changes in seconds
     *
     * @var int
     */
    protected $expiryInactivity = 60 * 60;

    /**
     * Absolute maximum number of simultaneously active instances
     *
     * @var integer
     */
    protected $instanceLimit = 300;

    /**
     * Maximum number of simultaneous demo instances per client
     *
     * @var int
     */
    protected $maxInstancesPerClient = 2;

    /**
     * Application root
     *
     * @var string
     */
    protected $root;

    /**
     * Configured secret for the GitHub webhook
     *
     * @var string
     */
    protected $webhookSecret;

    /**
     * Class constructor
     *
     * @param array $props
     */
    public function __construct(array $props = [])
    {
        // only set the root for now, the rest is set below
        $this->setProperty('root', $props['root'] ?? null);

        // load custom config (optional)
        $config = @include_once($this->root() . '/data/config.php');
        if (is_array($config) !== true) {
            $config = [];
        }

        // set all properties; the passed props override the general config
        $this->setProperties(array_merge($config, $props));
    }

    /**
     * Returns the absolute expiration time based on the instance creation time
     *
     * @return int Time in seconds
     */
    public function expiryAbsolute(): int
    {
        return $this->expiryAbsolute;
    }

    /**
     * Returns the inactivity expiration based on content changes
     *
     * @return int Time in seconds
     */
    public function expiryInactivity(): int
    {
        return $this->expiryInactivity;
    }

    /**
     * Returns the absolute maximum number of simultaneously active instances
     *
     * @return int
     */
    public function instanceLimit(): int
    {
        return $this->instanceLimit;
    }

    /**
     * Returns the maximum number of simultaneous demo instances per client
     *
     * @return int
     */
    public function maxInstancesPerClient(): int
    {
        return $this->maxInstancesPerClient;
    }

    /**
     * Returns the application root
     *
     * @return string
     */
    public function root(): string
    {
        return $this->root;
    }

    /**
     * Sets the absolute expiration time based on the instance creation time
     *
     * @param int $expiryAbsolute Time in seconds
     * @return self
     */
    protected function setExpiryAbsolute(int $expiryAbsolute)
    {
        $this->expiryAbsolute = $expiryAbsolute;
        return $this;
    }

    /**
     * Sets the absolute maximum number of simultaneously active instances
     *
     * @param int $instanceLimit
     * @return self
     */
    protected function setInstanceLimit(int $instanceLimit)
    {
        $this->instanceLimit = $instanceLimit;
        return $this;
    }

    /**
     * Sets the inactivity expiration time based on content changes
     *
     * @param int $expiryInactivity Time in seconds
     * @return self
     */
    protected function setExpiryInactivity(int $expiryInactivity)
    {
        $this->expiryInactivity = $expiryInactivity;
        return $this;
    }

    /**
     * Sets the maximum number of simultaneous demo instances per client
     *
     * @param int $maxInstancesPerClient
     * @return self
     */
    protected function setMaxInstancesPerClient(int $maxInstancesPerClient)
    {
        $this->maxInstancesPerClient = $maxInstancesPerClient;
        return $this;
    }

    /**
     * Sets the application root
     *
     * @param string|null $root
     * @return self
     */
    protected function setRoot(string $root = null)
    {
        $this->root = $root ?? dirname(dirname(__DIR__));
        return $this;
    }

    /**
     * Sets the configured secret for the GitHub webhook
     *
     * @param string $webhookSecret
     * @return self
     */
    protected function setWebhookSecret(string $webhookSecret)
    {
        $this->webhookSecret = $webhookSecret;
        return $this;
    }

    /**
     * Returns the configured secret for the GitHub webhook
     *
     * @return string
     */
    public function webhookSecret(): string
    {
        return $this->webhookSecret;
    }
}
