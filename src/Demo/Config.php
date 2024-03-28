<?php

namespace Kirby\Demo;

use Closure;
use Kirby\Exception\InvalidArgumentException;
use Kirby\Http\Response;
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
	/**
	 * Class constructor
	 */
	public function __construct(
		/**
		 * URL to redirect to or response callback for the index page
		 */
		protected string|Closure $indexResponse,

		/**
		 * Application root (by default determined relative to this code file)
		 */
		protected string $root,

		/**
		 * URL to redirect to or response callback for the status page;
		 * accepts the placeholders `{{ type }}` and `{{ status }}`
		 */
		protected string|Closure $statusResponse,

		/**
		 * URL of the ZIP file that will be downloaded as the template;
		 * example: `https://example.com/test.zip#test` will extract the
		 * `test` directory from the `test.zip` file;
		 * accepts the placeholder `{{ buildId }}`
		 */
		protected string|null $templateUrl,

		/**
		 * Path in the demo that is checked for user activity;
		 * defaults to the index directory (not recommended, potentially slow)
		 */
		protected string $activityPath = '',

		/**
		 * Origins that are allowed in the HTTP `Referer` header
		 * when creating new instances; not checked if not set
		 */
		protected array|null $allowedReferrers = null,

		/**
		 * Optional custom config data for the instances
		 */
		protected array $custom = [],

		/**
		 * Absolute expiration time based on the instance creation time in seconds
		 */
		protected int $expiryAbsolute = 3 * 60 * 60,

		/**
		 * Inactivity expiration time based on content changes in seconds
		 */
		protected int $expiryInactivity = 60 * 60,

		/**
		 * Absolute maximum number of simultaneously active instances
		 */
		protected int $instanceLimit = 300,

		/**
		 * Maximum number of simultaneous demo instances per client;
		 * 0 to disable the check (for debugging)
		 */
		protected int $maxInstancesPerClient = 2,

		/**
		 * List of allowed repo origins for the GitHub webhook
		 * of the format <org>/<repo>#ref/heads/<branch>;
		 * if `null`, all origins are allowed
		 */
		protected array|null $webhookOrigins = null,

		/**
		 * Secret for the GitHub webhook
		 */
		protected string|null $webhookSecret = null,
	) {
		if (is_string($templateUrl) === true && Str::contains($templateUrl, '#') !== true) {
			throw new InvalidArgumentException('templateUrl needs to include the directory name after a # sign');
		}
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
	 * Creates a class instance with fallback to the config file
	 */
	public static function instance(array $props): static
	{
		$props['root'] ??= dirname(dirname(__DIR__));

		// load custom config (optional)
		$config = @include($props['root'] . '/data/config.php');
		if (is_array($config) !== true) {
			$config = [];
		}

		// the passed props override the general config
		return new static(...array_merge($config, $props));
	}

	/**
	 * Returns the root where the template is stored
	 */
	public function templateRoot(): string
	{
		return $this->root . '/data/template';
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
