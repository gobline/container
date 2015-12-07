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
            isset($this->initialized[$className])
            || !is_callable($this->services[$className])
        ) {
            return $this->services[$className];
        }

        $value = $this->services[$className]($this);

        if (
            isset($this->factories[$className])
            && $this->factories[$className]
        ) {
            return $value;
        }

        $this->services[$className] = $value;
        $this->initialized[$className] = $value;

        return $value;
    }

    public function has($className)
    {
        return isset($this->services[$className]);
    }

    public function alias($alias, $classNameOrObject)
    {
        if (is_object($classNameOrObject)) {
            $classNameOrObject = get_class($classNameOrObject);
        }
        $this->alias[$alias] = $classNameOrObject;

        return $this;
    }

    public function share($classNameOrObject, array $arguments = null)
    {
        $this->set($classNameOrObject, $arguments, true);

        return $this;
    }

    public function factory($classNameOrObject, array $arguments = null)
    {
        $this->set($classNameOrObject, $arguments, false);

        return $this;
    }

    public function set($classNameOrObject, array $arguments = null, $shared = true)
    {
        if (is_string($classNameOrObject)) {
            $className = $classNameOrObject;
            $value = function ($c) use ($classNameOrObject, $arguments) {
                return $this->createInstance($classNameOrObject, $arguments);
            };
        } else {
            $className = get_class($classNameOrObject);
            $value = $shared ? $classNameOrObject : 
                function ($c) use ($classNameOrObject) {
                    return clone $classNameOrObject;
                };
        }

        if (isset($this->initialized[$className])) {
            throw new \RuntimeException('Cannot override initialized service '.$className);
        }

        $this->services[$className] = $value;

        if (!$shared) {
            $this->factories[$className] = true;
        }

        return $this;
    }

    private function createInstance($className, array $arguments = null)
    {
        if ($arguments === null) {
            return $this->injector->create($className);
        }

        if (!$arguments) {
            return new $className();
        }

        $positions = array_keys($arguments, '?');

        if ($positions) {
            $method = new \ReflectionMethod($className, '__construct');
            $parameters = $method->getParameters();

            foreach ($positions as $position) {
                $parameter = $parameters[$position];
                $arguments[$position] = $this->get($parameter->getClass()->name);
            }
        }

        return new $className(...$arguments);
    }

    public function delegate($className, $factoryClassNameOrObject, array $arguments = null, $shared = true)
    {
        if (is_string($factoryClassNameOrObject)) {
            $value = function ($c) use ($factoryClassNameOrObject, $arguments) {
                $factory = $this->createInstance($factoryClassNameOrObject, $arguments);

                return $factory->create();
            };
        } else {
            $value = function ($c) use ($factoryClassNameOrObject) {
                return $factoryClassNameOrObject->create();
            };
        }

        if (isset($this->initialized[$className])) {
            throw new \RuntimeException('Cannot override initialized service '.$className);
        }

        $this->services[$className] = $value;

        if (!$shared) {
            $this->factories[$className] = true;
        }

        return $this;
    }

    public function configure($className, $configurator = null, array $config = [])
    {
        if (!$configurator) {
            $callable = function ($service, $c) use ($className, $config) {
                foreach ($config as $name => $value) {
                    $method = 'set'.ucfirst($name);

                    if ($value === '?') {
                        $method = new \ReflectionMethod($className, $method);
                        $parameter = $method->getParameters()[0];
                        $value = $this->get($parameter->getClass()->name);
                    }

                    $service->$method($value);
                }

                return $service;
            };
        } else {
            $callable = function ($service, $c) use ($configurator, $config) {
                if (is_string($configurator)) {
                    $configurator = $this->createInstance($configurator);
                }

                return $configurator->configure($service, $config);
            };
        }

        $this->extend($className, $callable);

        return $this;
    }

    private function extend($className, callable $callable)
    {
        if (!isset($this->services[$className])) {
            throw new \InvalidArgumentException('Service '.$className.' is not defined');
        }

        $service = $this->services[$className];

        $extended = function ($c) use ($callable, $service) {
            if (!is_callable($service)) {
                return $callable($service, $c);
            }

            return $callable($service($c), $c);
        };

        $this->services[$className] = $extended;
    }

    public function keys()
    {
        return array_keys($this->services);
    }

    public function registerSelf()
    {
        $this->set($this);
        $this->alias(ContainerInterface::class, self::class);

        return $this;
    }
}
