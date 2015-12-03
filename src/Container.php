<?php

/*
 * Gobline Framework
 *
 * (c) Mathieu Decaffmeyer <mdecaffmeyer@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Gobline\Container;

use Gobline\Injector\TypeHintDependencyInjector;

/**
 * @author Mathieu Decaffmeyer <mdecaffmeyer@gmail.com>
 */
class Container implements ContainerInterface
{
    private $injector;
    private $services = [];
    private $factories = [];
    private $initialized = [];
    private $alias = [];

    public function __construct()
    {
        $this->injector = new TypeHintDependencyInjector([$this, 'get']);
    }

    public function get($className)
    {
        if (!isset($this->services[$className])) {
            if (isset($this->alias[$className])) {
                return $this->get($this->alias[$className]);
            }

            return $this->injector->create($className);
        }

        if (
            !is_callable($this->services[$className])
            || isset($this->initialized[$className])
        ) {
            return $this->services[$className];
        }

        $value = $this->services[$className]($this);

        if (isset($this->factories[$className])) {
            return $value;
        }

        $this->services[$className] = $value;
        $this->initialized[$className] = $value;

        return $value;
    }

    public function set($className, $service, $shared = true)
    {
        if (array_key_exists($className, $this->initialized)) {
            throw new \RuntimeException('Cannot override initialized service '.$className);
        }

        if (!$shared) {
            if (is_object($service) && !is_callable($service)) {
                $this->services[$className] = function ($c) use ($service) {
                    return clone $ervice;
                };
            }

            $this->factories[$className] = true;
        }

        $this->services[$className] = $service;
    }

    public function alias($alias, $className)
    {
        $this->alias[$alias] = $className;
    }

    public function extend($className, callable $callable)
    {
        if (!isset($this->services[$className])) {
            throw new \InvalidArgumentException('Service '.$className.' is not defined');
        }

        if (!is_object($this->services[$className])) {
            throw new \InvalidArgumentException('Service '.$className.' to extend must be a Closure or an object');
        }

        $service = $this->services[$className];

        $extended = function ($c) use ($callable, $service) {
            if (!is_callable($service)) {
                return $callable($service, $c);
            }

            return $callable($service($c), $c);
        };

        $this->set($className, $extended, !isset($this->factories[$className]));
    }

    public function register($className, $factory = null, $arguments = [], $shared = true)
    {
        $callable = function ($c) use ($className, $factory, $arguments) {
            if ($factory) {
                if (is_string($factory)) {
                    if (is_subclass_of($factory, ServiceFactoryInterface::class, true)) {
                        $factory = new $factory();
                        $service = $factory->create($c, $arguments);
                    } else {
                        throw new \RuntimeException('Factory "'.$factory.'" must implement '.ServiceFactoryInterface::class);
                    }
                } else {
                    $service = $factory->create($c, $arguments);
                }
            } else {
                if (!$arguments) {
                    $service = $this->injector->create($className);
                } else {
                    $posArguments = array_keys($arguments);

                    $consecutiveKeys = function ($keys) {
                        $nb = count($keys);
                        if ($nb === 0) {
                            return true;
                        }
                        if (!isset($keys[0])) {
                            return false;
                        }
                        for ($i = 0; $i < $nb; ++$i) {
                            if (!isset($keys[$i + 1])) {
                                return true;
                            }
                            if ($keys[$i] + 1 !== $keys[$i + 1]) {
                                return false;
                            }
                        }
                    };
                    if (!$consecutiveKeys($posArguments)) {
                        $range = range(0, max($posArguments));
                        $posMissingArguments = array_diff($range, $posArguments);

                        $missingArguments = $this->injector->resolveDependencies([$className, '__construct'], $posMissingArguments);

                        $arguments = array_merge($arguments, $missingArguments);
                    }

                    $service = new $className(...$arguments);
                }
            }

            return $service;
        };

        $this->set($className, $callable, $shared);

        return $this;
    }

    public function configure($className, $configurator = null, $config = [])
    {
        $callable = function ($service, $c) use ($className, $configurator, $config) {
            if ($configurator) {
                if (is_string($configurator)) {
                    $configurator = new $configurator();
                }
                $service = $configurator->configure($service, $config, $c);
            } else {
                foreach ($config as $name => $value) {
                    $method = 'set'.ucfirst($name);

                    if ($value === '?') {
                        $value = $this->injector->resolveDependencies([$className, $method])[0];
                    }

                    $service->$method($value);
                }
            }

            return $service;
        };

        if (!isset($this->services[$className])) {
            throw new \RuntimeException('Service "'.$className.'" has not been registered');
        }

        $this->extend($className, $callable);

        return $this;
    }

    public function keys()
    {
        return array_keys($this->services);
    }

    public function registerSelf()
    {
        $this->set(self::class, $this);
        $this->alias(ContainerInterface::class, self::class);

        return $this;
    }
}
