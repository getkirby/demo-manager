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
 * @copyright Bastian Allgeier
 * @license   https://opensource.org/licenses/MIT
 */
class Config
{
	use Properties;

	/**
	 * Path in the demo that is checked for user activity;
	 * defaults to the index directory (not recommended, potentially slow)
	 */
	protected string $activityPath = '';

	/**
	 * Optional custom config data for the instances
	 */
	protected array $custom = [];

	/**
	 * Absolute expiration time based on the instance creation time in seconds
	 */
	protected int $expiryAbsolute = 3 * 60 * 60;

	/**
	 * Inactivity expiration time based on content changes in seconds
	 */
	protected int $expiryInactivity = 60 * 60;

	/**
	 * URL to redirect to or response callback for the index page
	 */
	protected string|Closure $indexResponse;

	/**
	 * Absolute maximum number of simultaneously active instances
	 */
	protected int $instanceLimit = 300;

	/**
	 * Maximum number of simultaneous demo instances per client;
	 * 0 to disable the check (for debugging)
	 */
	protected int $maxInstancesPerClient = 2;

	/**
	 * Application root
	 */
	protected string $root;

	/**
	 * URL to redirect to or response callback for the status page;
	 * accepts the placeholders {{ type }} and {{ status }}
	 */
	protected string|Closure $statusResponse;

	/**
	 * URL of the ZIP file that will be downloaded as the template;
	 * example: `https://example.com/test.zip#test` will extract the
	 * `test` directory from the `test.zip` file;
	 * accepts the placeholder {{ buildId }}
	 */
	protected string|null $templateUrl;

	/**
	 * List of allowed repo origins for the GitHub webhook
	 * of the format <org>/<repo>#ref/heads/<branch>;
	 * if null, all origins are allowed
	 */
	protected array|null $webhookOrigins;

	/**
	 * Secret for the GitHub webhook
	 */
	protected string|null $webhookSecret;

	/**
	 * Class constructor
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
	 */
	public function __call(string $property, array $arguments = [])
	{
		return $this->$property ?? null;
	}

	/**
	 * Returns the response for the index page
	 */
	public function indexResponse(Demo $demo): Response
	{
		if (is_string($this->indexResponse) === true) {
			return Response::redirect($this->indexResponse, 302);
		} else {
			return call_user_func($this->indexResponse, $demo);
		}
	}

	/**
	 * Returns the root where the template is stored
	 */
	public function templateRoot(): string
	{
		return $this->root . '/data/template';
	}

	/**
	 * Sets the path in the demo that is checked for user activity;
	 * defaults to the index directory (not recommended, potentially slow)
	 */
	protected function setActivityPath(string $activityPath = ''): static
	{
		$this->activityPath = $activityPath;
		return $this;
	}

	/**
	 * Sets the optional custom config data for the instances
	 */
	protected function setCustom(array $custom = []): static
	{
		$this->custom = $custom;
		return $this;
	}

	/**
	 * Sets the absolute expiration time based on the instance creation time
	 *
	 * @param int $expiryAbsolute Time in seconds
	 */
	protected function setExpiryAbsolute(int $expiryAbsolute): static
	{
		$this->expiryAbsolute = $expiryAbsolute;
		return $this;
	}

	/**
	 * Sets the URL to redirect to or response callback for the index page
	 */
	protected function setIndexResponse(string|Closure $indexResponse): static
	{
		if (is_string($indexResponse) !== true && !($indexResponse instanceof Closure)) {
			throw new InvalidArgumentException('indexResponse needs to be a string or Closure');
		}

		$this->indexResponse = $indexResponse;
		return $this;
	}

	/**
	 * Sets the absolute maximum number of simultaneously active instances
	 */
	protected function setInstanceLimit(int $instanceLimit): static
	{
		$this->instanceLimit = $instanceLimit;
		return $this;
	}

	/**
	 * Sets the inactivity expiration time based on content changes
	 *
	 * @param int $expiryInactivity Time in seconds
	 */
	protected function setExpiryInactivity(int $expiryInactivity): static
	{
		$this->expiryInactivity = $expiryInactivity;
		return $this;
	}

	/**
	 * Sets the maximum number of simultaneous demo instances per client
	 */
	protected function setMaxInstancesPerClient(int $maxInstancesPerClient): static
	{
		$this->maxInstancesPerClient = $maxInstancesPerClient;
		return $this;
	}

	/**
	 * Sets the application root
	 */
	protected function setRoot(string|null $root = null): static
	{
		$this->root = $root ?? dirname(dirname(__DIR__));
		return $this;
	}

	/**
	 * Sets the URL to redirect to or response callback for the status page;
	 * accepts the placeholders {{ type }} and {{ status }}
	 */
	protected function setStatusResponse(string|Closure $statusResponse): static
	{
		if (is_string($statusResponse) !== true && !($statusResponse instanceof Closure)) {
			throw new InvalidArgumentException('statusResponse needs to be a string or Closure');
		}

		$this->statusResponse = $statusResponse;
		return $this;
	}

	/**
	 * Sets the URL of the ZIP file that will be downloaded as the template;
	 * accepts the placeholder {{ buildId }}
	 */
	protected function setTemplateUrl(string|null $templateUrl = null): static
	{
		if (is_string($templateUrl) === true && Str::contains($templateUrl, '#') !== true) {
			throw new InvalidArgumentException('templateUrl needs to include the directory name after a # sign');
		}

		$this->templateUrl = $templateUrl;
		return $this;
	}

	/**
	 * Sets the list of allowed repo origins for the GitHub
	 * webhook of the format <org>/<repo>#ref/heads/<branch>;
	 * if null, all origins are allowed
	 */
	protected function setWebhookOrigins(array|null $webhookOrigins = null): static
	{
		$this->webhookOrigins = $webhookOrigins;
		return $this;
	}

	/**
	 * Sets the secret for the GitHub webhook
	 */
	protected function setWebhookSecret(string|null $webhookSecret = null): static
	{
		$this->webhookSecret = $webhookSecret;
		return $this;
	}

	/**
	 * Returns the response for the status page
	 */
	public function statusResponse(Demo $demo, string $type, string $status): Response
	{
		if (is_string($this->statusResponse) === true) {
			$url = Str::template($this->statusResponse, compact('type', 'status'));

			return Response::redirect($url, 302);
		} else {
			return call_user_func($this->statusResponse, $demo, $type, $status);
		}
	}
}
