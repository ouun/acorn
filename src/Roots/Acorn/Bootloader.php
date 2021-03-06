<?php

namespace Roots\Acorn;

use Illuminate\Contracts\Foundation\Application as ApplicationContract;
use InvalidArgumentException;
use Roots\Acorn\Application;

use function Roots\add_filters;
use function Roots\env;
use function apply_filters;
use function did_action;
use function doing_action;
use function locate_template;

class Bootloader
{
    /**
     * Application to be instantiated at boot time
     *
     * @var string
     */
    protected $app;

    /**
     * WordPress hooks that will boot application
     *
     * @var string[]
     */
    protected $hooks;

    /**
     * Callbacks to be run when application boots
     *
     * @var callable[]
     */
    protected $queue = [];

    /**
     * Signals that application is ready to boot
     *
     * @var bool
     */
    protected $ready = false;

    /**
     * Base path for the application
     *
     * @var string
     */
    protected $basePath;

    /**
     * Create a new bootloader instance
     *
     * @param  string[] $hooks WordPress hooks to boot application
     * @param  string   $app Application class
     * @return $this
     */
    public function __construct(
        $hooks = ['after_setup_theme', 'rest_api_init'],
        string $app = Application::class
    ) {
        if (! in_array(ApplicationContract::class, class_implements($app, true) ?? [])) {
            throw new InvalidArgumentException(
                sprintf('Second parameter must be class name of type [%s]', ApplicationContract::class)
            );
        }

        $this->app = $app;
        $this->hooks = (array) $hooks;

        add_filters($this->hooks, $this, 5);

        return $app;
    }

    /**
     * Register a service provider with the application.
     *
     * @param  \Illuminate\Support\ServiceProvider|string  $provider
     * @param  bool  $force
     * @return \Roots\Acorn\Bootloader
     */
    public function register($provider, $force = false): Bootloader
    {
        return $this->call(function ($app) use ($provider, $force) {
            $app->register($provider, $force);
        });
    }

    /**
     * Enqueues callback to be loaded with application
     *
     * @param  callable $callback
     * @return static
     */
    public function call(callable $callback): Bootloader
    {
        if (! $this->ready()) {
            $this->queue[] = $callback;

            return $this;
        }

        $this->app()->call($callback);

        return $this;
    }

    /**
     * Determines whether the application is ready to boot
     *
     * @return bool
     */
    public function ready(): bool
    {
        if ($this->ready) {
            return true;
        }

        foreach ($this->hooks as $hook) {
            if (did_action($hook) || doing_action($hook)) {
                return $this->ready = true;
            }
        }

        return $this->ready = !! apply_filters('acorn/ready', false);
    }

    /**
     * Boot the Application
     *
     * @return void
     */
    public function __invoke()
    {
        static $app;

        if (! $this->ready()) {
            return;
        }

        $app = $this->app();

        foreach ($this->queue as $callback) {
            $app->call($callback);
        }

        $this->queue = [];
    }

    /**
     * Get application instance
     *
     * @return ApplicationContract
     */
    protected function app(): ApplicationContract
    {
        static $app;

        if ($app) {
            return $app;
        }

        $bootstrap = $this->bootstrap();
        $basePath = $this->basePath();

        $app = new $this->app($basePath, $this->usePaths());

        $app->bootstrapWith($bootstrap);

        return $app;
    }

    /**
     * Get the application basepath
     *
     * @return string
     */
    protected function basePath(): string
    {
        if ($this->basePath) {
            return $this->basePath;
        }

        $basePath = dirname(locate_template('config') ?: __DIR__ . '/../');

        $basePath = defined('ACORN_BASEPATH') ? \ACORN_BASEPATH : env('ACORN_BASEPATH', $basePath);

        $basePath = apply_filters('acorn/paths.base', $basePath);

        return $this->basePath = $basePath;
    }

    /**
     * Use paths that are configurable by the developer.
     *
     * @return array
     */
    protected function usePaths(): array
    {
        $searchPaths = ['app', 'config', 'storage', 'resources'];
        $paths = [];

        foreach ($searchPaths as $path) {
            $paths[$path] = apply_filters("acorn/paths.{$path}", $this->findPath($path));
        }

        return $paths;
    }

    /**
     * Find a path that is configurable by the developer.
     *
     * @param  string $path
     * @return string
     */
    protected function findPath($path): string
    {
        $path = trim($path, '\\/');

        $searchPaths = [
            $this->basePath() . DIRECTORY_SEPARATOR . $path,
            locate_template($path),
            get_stylesheet_directory() . DIRECTORY_SEPARATOR . $path,
            get_template_directory() . DIRECTORY_SEPARATOR . $path,
            dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . $path,
        ];

        return collect($searchPaths)
            ->map(function ($path) {
                return is_string($path) && is_dir($path) ? $path : null;
            })
            ->filter()
            ->unique()
            ->get(0);
    }

    /**
     * Get the list of application bootstraps
     *
     * @return string[]
     */
    protected function bootstrap(): array
    {
        $bootstrap = [
            \Roots\Acorn\Bootstrap\CaptureRequest::class,
            \Roots\Acorn\Bootstrap\SageFeatures::class,
            \Roots\Acorn\Bootstrap\LoadConfiguration::class,
            \Roots\Acorn\Bootstrap\HandleExceptions::class,
            \Roots\Acorn\Bootstrap\RegisterProviders::class,
            \Roots\Acorn\Bootstrap\RegisterFacades::class,
            \Illuminate\Foundation\Bootstrap\BootProviders::class,
            \Roots\Acorn\Bootstrap\RegisterConsole::class,
        ];

        return apply_filters('acorn/bootstrap', $bootstrap);
    }
}
