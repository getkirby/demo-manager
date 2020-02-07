<?php

namespace Kirby\Demo;

use Closure;
use Kirby\Exception\Exception;
use Kirby\Http\Request;
use Kirby\Http\Response;
use Kirby\Http\Uri;
use Kirby\Toolkit\Dir;
use Kirby\Toolkit\F;
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
        if (is_dir($this->config()->root() . '/data/template') !== true) {
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
        $this->lock()->acquireExclusiveLock();

        // recursively delete the whole old template directory
        Dir::remove($this->config()->root() . '/data/template');

        // initialize the template with the Demokit
        $root = $this->config()->root() . '/data/template';
        $this->downloadZip(
            'https://github.com/getkirby/demokit/archive/master.zip',
            'demokit-master',
            $root
        );

        // run the post-install hook of the Demokit
        $this->runHook($root, 'build:after');

        // instances can now be created again
        $this->lock()->releaseLock();
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
        if (stream_copy_to_stream($download, $tmp) === false) {
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
                if ($this->instances()->count() >= $this->config()->instanceLimit()) {
                    return Response::redirect('https://getkirby.com/try/error:overload', 302);
                }

                // check if the current client already has too many active instances
                $countCurrentClient = $this->instances()->count(['ipHash' => Instances::ipHash()]);
                if ($countCurrentClient >= $this->config()->maxInstancesPerClient()) {
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
                    $expected = hash_hmac('sha1', $body, $this->config()->webhookSecret());
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
     * Runs a hook on an instance or the template
     *
     * @param string $root Root of the template/instance
     * @param string $type Hook type
     * @param mixed ...$args Additional hook arguments
     * @return void
     */
    public function runHook($root, $type, ...$args): void
    {
        $buildConfig = @include($root . '/.build.php') ?? [];

        if (isset($buildConfig[$type]) === true && $buildConfig[$type] instanceof Closure) {
            $previousDir = getcwd();
            chdir($this->config()->root() . '/data/template');

            $buildConfig[$type]($this, ...$args);

            chdir($previousDir);
        }
    }

    /**
     * Returns the stats for debugging
     *
     * @return array
     */
    public function stats(): array
    {
        $stats = $this->instances()->stats();
        $stats['lastBuild'] = date('r', filemtime($this->config()->root() . '/data/template'));

        return $stats;
    }
}
