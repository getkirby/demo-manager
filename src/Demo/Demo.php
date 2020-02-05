<?php

namespace Kirby\Demo;

use Closure;
use Kirby\Exception\Exception;
use Kirby\Http\Request;
use Kirby\Http\Response;
use Kirby\Http\Uri;
use Kirby\Toolkit\Dir;
use Kirby\Toolkit\F;
use Kirby\Toolkit\Properties;
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
     * Instance manager object
     *
     * @var \Kirby\Demo\Instances
     */
    protected $instances;

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

        // ensure that the data directory is present
        Dir::make($this->root() . '/data');

        // load custom config (optional)
        $config = @include_once($this->root() . '/data/config.php');
        if (is_array($config) !== true) {
            $config = [];
        }

        // set all properties; the passed props override the general config
        $this->setProperties(array_merge($config, $props));

        // ensure that there is a valid build for us to use
        if (is_dir($this->root() . '/data/template') !== true) {
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
        // prevent that new instances are created
        // while the template is being rebuilt
        $this->instances()->lock();

        // recursively delete the whole old template directory
        Dir::remove($this->root() . '/data/template');

        // initialize the template with the Demokit
        $this->downloadZip(
            'https://github.com/getkirby/demokit/archive/master.zip',
            'demokit-master',
            $this->root() . '/data/template'
        );

        // run the post-install hook of the Demokit
        $buildConfig = require($this->root() . '/data/template/.build.php');
        if (isset($buildConfig['hook']) === true && $buildConfig['hook'] instanceof Closure) {
            $previousDir = getcwd();
            chdir($this->root() . '/data/template');

            $buildConfig['hook']($this);

            chdir($previousDir);
        }

        // instances can now be created again
        $this->instances()->unlock();
    }

    /**
     * Cleans up all expired instances
     *
     * @return void
     */
    public function cleanup(): void
    {
        foreach ($this->instances()->all() as $instance) {
            if ($instance->hasExpired() === true) {
                $instance->delete();
            }
        }
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
        if (stream_copy_to_stream($download, $tmp) === false) {
            throw new Exception('Could not download ZIP from ' . $url);
        }

        // extract the temporary file
        $zip = new ZipArchive();
        if ($zip->open(stream_get_meta_data($tmp)['uri']) !== true) {
            throw new Exception('Could not open ZIP from ' . $url);
        }
        $zip->extractTo($this->root() . '/data/tmp');
        $zip->close();
        fclose($tmp);

        // move the directory to the final destination
        if (is_dir($this->root() . '/data/tmp/' . $dir) !== true) {
            throw new Exception('ZIP file ' . $url . ' does not contain directory ' . $dir);
        }
        if (file_exists($path) === true) {
            throw new Exception('Destination ' . $path . ' for ZIP file ' . $url . ' already exists');
        }
        rename($this->root() . '/data/tmp/' . $dir, $path);

        // delete the temporary directory
        Dir::remove($this->root() . '/data/tmp');
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
     * Returns the maximum number of simultaneous demo instances per client
     *
     * @return int
     */
    public function maxInstancesPerClient(): int
    {
        return $this->maxInstancesPerClient;
    }

    /**
     * Renders a HTTP response for a given path
     *
     * @param string|null $path Request path, defaults to the current one
     * @param string|null $method Request method, defaults to the current one
     * @return \Kirby\Http\Response
     */
    public function render(?string $path = null, ?string $method = null)
    {
        $request = new Request();

        // automatically detect the request path and method if not given
        if ($path === null) {
            $path = (string)Uri::current()->path();
        }
        if ($method === null) {
            $method = $request->method();
        }

        try {
            if ($path === '' && $method === 'POST') {
                // create a new instance

                // check if there are too many active instances on this server
                if ($this->instances()->count() >= $this->instanceLimit()) {
                    return Response::redirect('https://getkirby.com/try/error:overload', 302);
                }

                // check if the current client already has too many active instances
                $countCurrentClient = $this->instances()->count(['ipHash' => Instances::ipHash()]);
                if ($countCurrentClient >= $this->maxInstancesPerClient()) {
                    return Response::redirect('https://getkirby.com/try/error:rate-limit', 302);
                }

                $instance = $this->instances()->create();
                return Response::redirect($instance->url(), 302);
            } elseif ($path === '') {
                // homepage, redirect to Try page

                return Response::redirect('https://getkirby.com/try', 302);
            } elseif ($path === 'stats') {
                // print stats

                return Response::json($this->stats(), 200, true);
            } elseif ($path === 'build') {
                // GitHub webhook to build the template for a new release

                try {
                    // validate that the request came from GitHub
                    $body = $request->body()->contents();
                    $expected = hash_hmac('sha1', $body, $this->webhookSecret());
                    $signature = $request->header('X-Hub-Signature');
                    if (hash_equals('sha1=' . $expected, $signature) !== true) {
                        return new Response('Invalid body signature', 'text/plain', 403);
                    }

                    $this->build();
                    return new Response('OK', 'text/plain', 200);
                } catch (\Throwable $e) {
                    error_log($e);
                    return new Response((string)$e, 'text/plain', 500);
                }
            } else {
                // instance that doesn't exist (anymore); redirect to the error page

                return Response::redirect('https://getkirby.com/try/error:not-found', 302);
            }
        } catch (\Throwable $e) {
            // some kind of unhandled error; redirect to the general error page

            error_log($e);
            return Response::redirect('https://getkirby.com/try/error:unexpected', 302);
        }
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
     * Returns the stats for debugging
     *
     * @return array
     */
    public function stats(): array
    {
        $stats = $this->instances()->stats();
        $stats['lastBuild'] = date('r', filemtime($this->root() . '/data/template'));

        return $stats;
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
