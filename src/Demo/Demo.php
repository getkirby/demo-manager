<?php

namespace Kirby\Demo;

use Closure;
use Kirby\Data\Json;
use Kirby\Exception\Exception;
use Kirby\Http\Cookie;
use Kirby\Http\Request;
use Kirby\Http\Response;
use Kirby\Http\Uri;
use Kirby\Toolkit\Dir;
use Kirby\Toolkit\F;
use Kirby\Toolkit\Str;
use ZipArchive;

/**
 * Main app class
 *
 * @package   Kirby Demo
 * @author    Lukas Bestle <lukas@getkirby.com>
 * @link      https://getkirby.com
 * @copyright Bastian Allgeier GmbH
 * @license   https://opensource.org/licenses/MIT
 */
class Demo
{
    /**
     * Config object
     *
     * @var \Kirby\Demo\Config
     */
    protected $config;

    /**
     * Cache of build config data per file
     *
     * @var array
     */
    protected $buildFileCache = [];

    /**
     * Instance manager object
     *
     * @var \Kirby\Demo\Instances
     */
    protected $instances;

    /**
     * Lock manager object
     *
     * @var \Kirby\Demo\Lock
     */
    protected $lock;

    /**
     * Class constructor
     *
     * @param array $props
     */
    public function __construct(array $props = [])
    {
        $this->config = new Config($props);

        // ensure that the data directory is present
        Dir::make($this->config()->root() . '/data');

        // ensure that there is a valid build for us to use
        if (is_dir($this->config()->templateRoot()) !== true) {
            $this->build();
        }
    }

    /**
     * Builds the instance template from scratch
     *
     * @return void
     */
    public function build(): void
    {
        // check if building is enabled
        $url = $this->config()->templateUrl();
        if (is_string($url) !== true) {
            throw new Exception('The template URL that is required for building is not configured');
        }

        // prevent that new instances are created
        // while the template is being rebuilt
        $this->lock()->acquireExclusiveLock();

        // recursively delete the whole old template directory
        Dir::remove($this->config()->templateRoot());

        // initialize the template from ZIP
        $root = $this->config()->templateRoot();
        $this->downloadZip(
            Str::before($url, '#'),
            Str::after($url, '#'),
            $root
        );

        // run the post-build hook if defined
        $this->runHook($root, 'build:after', $this);

        // delete all prepared instances (they are now outdated)
        foreach ($this->instances()->all('ipHash IS NULL') as $instance) {
            $instance->delete();
        }

        // instances can now be created again
        $this->lock()->releaseLock();
    }

    /**
     * Cleans up all expired instances and executes
     * the cleanup hook of the template
     *
     * @return void
     */
    public function cleanup(): void
    {
        $expired = $this->instances()->all()->filterBy('hasExpired', '==', true);

        foreach ($expired as $instance) {
            $instance->delete();
        }

        $this->runHook($this->config()->templateRoot(), 'cleanup', $this);
    }

    /**
     * Returns the config object
     *
     * @return \Kirby\Demo\Config
     */
    public function config()
    {
        return $this->config;
    }

    /**
     * Downloads a ZIP file and extracts it the specified destination
     *
     * @param string $url Download URL
     * @param string $dir Expected directory name of the ZIP's main directory
     * @param string $path Destination path
     * @return void
     */
    protected function downloadZip(string $url, string $dir, string $path): void
    {
        // download the ZIP to a temporary file
        $download = fopen($url, 'r');
        $tmp = tmpfile();
        if ($download === false || stream_copy_to_stream($download, $tmp) === false) {
            throw new Exception('Could not download ZIP from ' . $url);
        }

        // extract the temporary file
        $zip = new ZipArchive();
        if ($zip->open(stream_get_meta_data($tmp)['uri']) !== true) {
            throw new Exception('Could not open ZIP from ' . $url);
        }
        $zip->extractTo($this->config()->root() . '/data/tmp');
        $zip->close();
        fclose($tmp);

        // move the directory to the final destination
        if (is_dir($this->config()->root() . '/data/tmp/' . $dir) !== true) {
            throw new Exception('ZIP file ' . $url . ' does not contain directory ' . $dir);
        }
        if (file_exists($path) === true) {
            throw new Exception('Destination ' . $path . ' for ZIP file ' . $url . ' already exists');
        }
        rename($this->config()->root() . '/data/tmp/' . $dir, $path);

        // delete the temporary directory
        Dir::remove($this->config()->root() . '/data/tmp');
    }

    /**
     * Returns the instance manager
     *
     * @return \Kirby\Demo\Instances
     */
    public function instances()
    {
        if (!$this->instances) {
            $this->instances = new Instances($this);
        }

        return $this->instances;
    }

    /**
     * Returns the lock manager
     *
     * @return \Kirby\Demo\Lock
     */
    public function lock()
    {
        if (!$this->lock) {
            $this->lock = new Lock($this);
        }

        return $this->lock;
    }

    /**
     * Renders an HTTP response for a given path
     *
     * @param string|null $path Request path, defaults to the current one
     * @param string|null $method Request method, defaults to the current one
     * @return \Kirby\Http\Response
     */
    public function render(?string $path = null, ?string $method = null)
    {
        $request = new Request();
        $uri     = $request->url();

        // automatically detect the request path and method if not given
        if ($path === null) {
            $path = (string)$uri->path();
        }
        if ($method === null) {
            $method = $request->method();
        }

        try {
            if ($path === '' && $method === 'POST') {
                // create a new instance

                // check if the client already has an existing instance
                $cookieInstance = Cookie::get('instance');
                if ($cookieInstance !== null) {
                    $instanceUri = new Uri($cookieInstance);
                    $instance = $this->instances()->get(['name' => (string)$instanceUri->path()]);

                    if ($instanceUri->host() === $uri->host() && $instance !== null) {
                        return Response::redirect($instance->url(), 302);
                    }
                }

                // check if there are too many active instances on this server
                if ($this->instances()->count() >= $this->config()->instanceLimit()) {
                    return $this->config()->statusResponse($this, 'error', 'overload');
                }

                // check if the current IP address already has too many active instances
                $countCurrentClient = $this->instances()->count(['ipHash' => Instances::ipHash()]);
                if (
                    $this->config()->maxInstancesPerClient() > 0 &&
                    $countCurrentClient >= $this->config()->maxInstancesPerClient()
                ) {
                    return $this->config()->statusResponse($this, 'error', 'rate-limit');
                }

                $instance = $this->instances()->create();

                // create a cookie for the next request
                Cookie::set('instance', $instance->url(), [
                    'lifetime' => $this->config()->expiryAbsolute() / 60,
                    'secure'   => true,
                    'sameSite' => 'None'
                ]);

                return Response::redirect($instance->url(), 302);
            } elseif ($path === '') {
                // homepage, render the index page redirect or custom response

                return $this->config()->indexResponse($this);
            } elseif ($path === 'stats') {
                // print stats

                return Response::json($this->stats(), 200, true);
            } elseif ($path === 'build') {
                // GitHub webhook to build the template for a new release

                $webhookSecret = $this->config()->webhookSecret();
                if (is_string($webhookSecret) !== true) {
                    return new Response('Webhook secret is not configured', 'text/plain', 500);
                }

                try {
                    // validate that the request came from GitHub
                    $body = $request->body()->contents();
                    $expected = hash_hmac('sha1', $body, $webhookSecret);
                    $signature = $request->header('X-Hub-Signature');
                    if (
                        is_string($signature) !== true ||
                        hash_equals('sha1=' . $expected, $signature) !== true
                    ) {
                        return new Response('Invalid body signature', 'text/plain', 403);
                    }

                    // validate that the hook came from the correct repo and branch
                    $webhookOrigins = $this->config()->webhookOrigins();
                    if ($webhookOrigins !== null) {
                        $data = Json::decode($body);
                        $origin = $data['repository']['full_name'] . '#' . ($data['ref'] ?? 'none');

                        if (in_array($origin, $webhookOrigins) !== true) {
                            return new Response('Not responsible for repo origin ' . $origin, 'text/plain', 200);
                        }
                    }

                    $this->build();
                    return new Response('OK', 'text/plain', 201);
                } catch (\Throwable $e) {
                    error_log($e);
                    return new Response((string)$e, 'text/plain', 500);
                }
            } else {
                // instance that doesn't exist (anymore); redirect to the error page

                return $this->config()->statusResponse($this, 'error', 'not-found');
            }
        } catch (\Throwable $e) {
            // some kind of unhandled error; redirect to the general error page

            error_log($e);
            return $this->config()->statusResponse($this, 'error', 'unexpected');
        }
    }

    /**
     * Runs a hook on an instance or the template
     *
     * @param string $root Root directory of the template/instance
     * @param string $type Hook type
     * @param mixed ...$args Additional hook arguments
     * @return mixed
     */
    public function runHook($root, $type, ...$args)
    {
        $file = $root . '/.hooks.php';
        if (isset($this->buildFileCache[$file]) === true) {
            $buildConfig = $this->buildFileCache[$file];
        } else {
            $this->buildFileCache[$file] = $buildConfig = @include($file) ?? [];
        }

        $result = null;
        if (isset($buildConfig[$type]) === true && $buildConfig[$type] instanceof Closure) {
            $previousDir = getcwd();
            if (is_string($previousDir) !== true) {
                throw new Exception('Current working directory could not be determined');
            }

            chdir($root);

            $result = $buildConfig[$type]($this, ...$args);

            chdir($previousDir);
        }

        return $result;
    }

    /**
     * Returns the stats for debugging
     *
     * @return array
     */
    public function stats(): array
    {
        $stats = $this->instances()->stats();
        $stats['lastBuild'] = date('r', filemtime($this->config()->templateRoot()));

        return $stats;
    }
}
