<?php

namespace ManaPHP;

use ManaPHP\Cli\Factory as CliFactory;
use ManaPHP\Mvc\Factory as MvcFactory;

/**
 * Class ManaPHP\Application
 *
 * @package application
 *
 * @property-read \ManaPHP\DotenvInterface        $dotenv
 * @property-read \ManaPHP\ErrorHandlerInterface  $errorHandler
 * @property-read \ManaPHP\AuthorizationInterface $authorization
 * @property-read \ManaPHP\Http\RequestInterface  $request
 */
class Application extends Component implements ApplicationInterface
{
    /**
     * @var string
     */
    protected $_class_file_name;

    /**
     * @var string
     */
    protected $_root_dir;

    /**
     * Application constructor.
     *
     * @param \ManaPHP\Loader $loader
     */
    public function __construct($loader = null)
    {
        $class = static::class;
        $this->_class_file_name = (new \ReflectionClass($class))->getFileName();

        ini_set('html_errors', 'off');
        ini_set('default_socket_timeout', -1);

        $GLOBALS['DI'] = $this->getDi();

        $this->_di->setShared('loader', $loader ?: new Loader());
        $this->_di->setShared('app', $this);

        $rootDir = $this->getRootDir();
        $appDir = $rootDir . '/app';
        $appNamespace = 'App';
        $publicDir = !empty($_SERVER['DOCUMENT_ROOT']) ? $_SERVER['DOCUMENT_ROOT'] : $rootDir . '/public';

        if (strpos($class, 'ManaPHP\\') !== 0) {
            $appDir = dirname($this->_class_file_name);
            $appNamespace = substr($class, 0, strrpos($class, '\\'));
            $publicDir = $rootDir . '/public';
        }

        $this->alias->set('@public', $publicDir);
        $this->alias->set('@app', $appDir);
        $this->alias->set('@ns.app', $appNamespace);
        $this->loader->registerNamespaces([$appNamespace => $appDir]);

        $this->alias->set('@views', $appDir . '/Views');

        $this->alias->set('@root', $rootDir);
        $this->alias->set('@data', $rootDir . '/data');
        $this->alias->set('@tmp', $rootDir . '/tmp');
        $this->alias->set('@config', $rootDir . '/config');

        $web = '';
        if (isset($_SERVER['SCRIPT_NAME']) && ($pos = strrpos($_SERVER['SCRIPT_NAME'], '/')) > 0) {
            $web = substr($_SERVER['SCRIPT_NAME'], 0, $pos);
            if (substr_compare($web, '/public', -7) === 0) {
                $web = substr($web, 0, -7);
            }
        }
        $this->alias->set('@web', $web);
        $this->alias->set('@asset', $web);

        $this->loader->registerFiles('@manaphp/helpers.php');


        defined('MANAPHP_COROUTINE') or define('MANAPHP_COROUTINE', false);
    }

    /**
     * @return string
     */
    public function getRootDir()
    {
        if (!$this->_root_dir) {
            if (strpos(static::class, 'ManaPHP\\') !== 0) {
                $this->_root_dir = dirname(dirname($this->_class_file_name));
            } elseif (!empty($_SERVER['DOCUMENT_ROOT']) && $_SERVER['DOCUMENT_ROOT'] === dirname($_SERVER['SCRIPT_FILENAME'])) {
                $this->_root_dir = dirname($_SERVER['DOCUMENT_ROOT']);
            } else {
                $rootDir = realpath(dirname($_SERVER['SCRIPT_FILENAME']));
                if (is_file($rootDir . '/index.php')) {
                    $rootDir = dirname($rootDir);
                }
                $this->_root_dir = $rootDir;
            }
        }

        return $this->_root_dir;
    }

    /**
     * @param string $rootDir
     *
     * @return static
     */
    public function setRootDir($rootDir)
    {
        $this->_root_dir = $rootDir;
        return $this;
    }

    public function getDi()
    {
        if (!$this->_di) {
            $this->_di = !empty($_SERVER['DOCUMENT_ROOT']) ? new MvcFactory() : new CliFactory();
        }
        return $this->_di;
    }

    /**
     * @param array $listeners
     */
    protected function _loadListeners($listeners)
    {
        foreach ($listeners as $listener) {
            if ($listener === '*') {
                foreach ($this->filesystem->glob('@app/Areas/*/Listeners/*Listener.php') as $item) {
                    $item = str_replace($this->alias->get('@app'), $this->alias->get('@ns.app'), $item);
                    $item = substr(str_replace('/', '\\', $item), 0, -4);
                    $this->eventsManager->addListener($item);
                }

                foreach ($this->filesystem->glob('@app/Listeners/*Listener.php') as $item) {
                    $item = str_replace($this->alias->get('@app'), $this->alias->get('@ns.app'), $item);
                    $item = substr(str_replace('/', '\\', $item), 0, -4);
                    $this->eventsManager->addListener($item);
                }
            } else {
                $this->eventsManager->addListener($listener);
            }
        }
    }

    /**
     * @param array $plugins
     *
     * @throws \ManaPHP\Exception\RuntimeException
     * @throws \ManaPHP\Exception\UnexpectedValueException
     */
    protected function _loadPlugins($plugins)
    {
        $app_plugins = [];
        foreach ($this->filesystem->glob('@app/Plugins/*Plugin.php') as $item) {
            $app_plugins[basename($item, '.php')] = 1;
        }

        foreach ($plugins as $k => $v) {
            $plugin = is_string($k) ? $k : $v;
            if (($pos = strrpos($plugin, 'Plugin')) === false || $pos !== strlen($plugin) - 6) {
                $plugin .= 'Plugin';
            }

            if ($plugin[0] === '!') {
                unset($app_plugins[ucfirst(substr($plugin, 1))]);
                continue;
            }

            $plugin = ucfirst($plugin);

            $pluginClassName = isset($app_plugins[$plugin]) ? $this->alias->get('@ns.app') . "\\Plugins\\$plugin" : "ManaPHP\Plugins\\$plugin";
            unset($app_plugins[$plugin]);

            $plugin = lcfirst($plugin);
            $this->_di->setShared($plugin, is_int($k) ? $pluginClassName : array_merge($v, ['class' => $pluginClassName]))->getShared($plugin);
        }

        foreach ($app_plugins as $plugin => $_) {
            $pluginClassName = $this->alias->get('@ns.app') . "\\Plugins\\$plugin";
            $plugin = lcfirst($plugin);
            $this->_di->setShared($plugin, $pluginClassName)->getShared($plugin);
        }
    }

    /**
     * @param array $components
     *
     * @throws \ManaPHP\Exception\RuntimeException
     * @throws \ManaPHP\Exception\UnexpectedValueException
     */
    protected function _loadComponents($components)
    {
        foreach ($components as $component => $definition) {
            if (is_int($component)) {
                $component = lcfirst(($pos = strrpos($definition, '\\')) ? substr($definition, $pos + 1) : $definition);
                $this->_di->setShared($component, $definition);
            } elseif ($definition === null) {
                $this->_di->remove($component);
            } elseif ($component[0] !== '!' || $this->_di->has($component = substr($component, 1))) {
                $this->_di->setShared($component, $definition);
            }
        }
    }

    /**
     * @param array $services
     */
    protected function _loadServices($services)
    {
        $this->_di->setPattern('*Service', $this->alias->get('@ns.app') . '\\Services\\');

        foreach ($services as $service => $params) {
            $this->_di->setShared($service, $params);
        }
    }

    public function registerServices()
    {
        $configure = $this->configure;

        if ($configure->timezone) {
            date_default_timezone_set($configure->timezone);
        }
        $this->_di->setShared('crypt', [$configure->master_key]);

        foreach ($configure->aliases as $alias => $path) {
            $this->_di->alias->set($alias, $path);
        }

        $routerClass = $this->alias->get('@ns.app') . '\Router';
        if (class_exists($routerClass)) {
            $this->_di->setShared('router', $routerClass);
        }

        if ($configure->components) {
            $this->_loadComponents($configure->components);
        }

        foreach ($configure->bootstraps as $bootstrap) {
            $this->_di->getShared($bootstrap);
        }

        $this->_loadServices($configure->services);

        if ($configure->plugins) {
            $this->_loadPlugins($configure->plugins);
        }

        if ($configure->listeners) {
            $this->_loadListeners($configure->listeners);
        }
    }

    /**
     * @param \Exception|\Error $exception
     */
    public function handleException($exception)
    {
        $this->errorHandler->handle($exception);
    }

    public function main()
    {
        if ($this->filesystem->fileExists('@root/.env')) {
            $this->dotenv->load();
        }

        if ($this->filesystem->fileExists('@config/app.php')) {
            $this->configure->load();
        }

        $this->registerServices();

        if (!empty($_SERVER['DOCUMENT_ROOT'])) {
            $this->eventsManager->fireEvent('request:begin', $this);
            $this->eventsManager->fireEvent('request:construct', $this);
        }
    }
}