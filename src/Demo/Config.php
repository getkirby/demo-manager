<?php

namespace Kirby\Demo;

use Closure;
use Kirby\Exception\InvalidArgumentException;
use Kirby\Http\Response;
use Kirby\Toolkit\Properties;
use Kirby\Toolkit\Str;

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
     * Directory in the demo that is checked for user activity;
     * defaults to the index directory (not recommended, potentially slow)
     *
     * @var string
     */
    protected $activityDirectory = '';

    /**
     * Optional custom config data for the instances
     *
     * @var array
     */
    protected $custom = [];

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
     * URL to redirect to or response callback for the index page
     *
     * @var string|\Closure
     */
    protected $indexResponse;

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
     * URL to redirect to or response callback for the status page;
     * accepts the placeholders {{ type }} and {{ status }}
     *
     * @var string|\Closure
     */
    protected $statusResponse;

    /**
     * URL of the ZIP file that will be downloaded as the template;
     * example: `https://example.com/test.zip#test` will extract the
     * `test` directory from the `test.zip` file
     *
     * @var string|null
     */
    protected $templateUrl;

    /**
     * Secret for the GitHub webhook
     *
     * @var string|null
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
     * Magic caller to access all properties
     *
     * @param string $property
     * @param array $arguments
     * @return mixed
     */
    public function __call(string $property, array $arguments = [])
    {
        return $this->$property ?? null;
    }

    /**
     * Returns the response for the index page
     *
     * @param \Kirby\Demo\Demo $demo App instance
     * @return \Kirby\Http\Response
     */
    public function indexResponse($demo)
    {
        if (is_string($this->indexResponse) === true) {
            return Response::redirect($this->indexResponse, 302);
        } else {
            return call_user_func($this->indexResponse, $demo);
        }
    }

    /**
     * Sets the directory in the demo that is checked for user activity;
     * defaults to the index directory (not recommended, potentially slow)
     *
     * @param string $activityDirectory
     * @return self
     */
    protected function setActivityDirectory(string $activityDirectory = '')
    {
        $this->activityDirectory = $activityDirectory;
        return $this;
    }

    /**
     * Sets the optional custom config data for the instances
     *
     * @param array $custom
     */
    protected function setCustom(array $custom = [])
    {
        $this->custom = $custom;
        return $this;
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
     * Sets the URL to redirect to or response callback for the index page
     *
     * @param string|\Closure $indexResponse
     * @return self
     */
    protected function setIndexResponse($indexResponse)
    {
        if (is_string($indexResponse) !== true && !($indexResponse instanceof Closure)) {
            throw new InvalidArgumentException('indexResponse needs to be a string or Closure');
        }

        $this->indexResponse = $indexResponse;
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
     * Sets the URL to redirect to or response callback for the status page;
     * accepts the placeholders {{ type }} and {{ status }}
     *
     * @param string|\Closure $statusResponse
     * @return self
     */
    protected function setStatusResponse($statusResponse)
    {
        if (is_string($statusResponse) !== true && !($statusResponse instanceof Closure)) {
            throw new InvalidArgumentException('statusResponse needs to be a string or Closure');
        }

        $this->statusResponse = $statusResponse;
        return $this;
    }

    /**
     * Sets the URL of the ZIP file that will be downloaded as the template
     *
     * @param string $templateUrl
     * @return self
     */
    protected function setTemplateUrl(?string $templateUrl = null)
    {
        if (is_string($templateUrl) === true && Str::contains($templateUrl, '#') !== true) {
            throw new InvalidArgumentException('templateUrl needs to include the directory name after a # sign');
        }

        $this->templateUrl = $templateUrl;
        return $this;
    }

    /**
     * Sets the secret for the GitHub webhook
     *
     * @param string|null $webhookSecret
     * @return self
     */
    protected function setWebhookSecret(?string $webhookSecret = null)
    {
        $this->webhookSecret = $webhookSecret;
        return $this;
    }

    /**
     * Returns the response for the status page
     *
     * @param \Kirby\Demo\Demo $demo App instance
     * @param string $type
     * @param string $status
     * @return \Kirby\Http\Response
     */
    public function statusResponse($demo, $type, $status)
    {
        if (is_string($this->statusResponse) === true) {
            $url = Str::template($this->statusResponse, compact('type', 'status'));

            return Response::redirect($url, 302);
        } else {
            return call_user_func($this->indexResponse, $demo, $type, $status);
        }
    }
}
