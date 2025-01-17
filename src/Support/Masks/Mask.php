<?php

namespace Webklex\PHPIMAP\Support\Masks;

use Illuminate\Support\Str;
use Webklex\PHPIMAP\Exceptions\MethodNotFoundException;

class Mask
{
    /**
     * Available attributes.
     */
    protected array $attributes = [];

    /**
     * Parent instance.
     */
    protected mixed $parent;

    /**
     * Mask constructor.
     */
    public function __construct($parent)
    {
        $this->parent = $parent;

        if (method_exists($this->parent, 'getAttributes')) {
            $this->attributes = array_merge($this->attributes, $this->parent->getAttributes());
        }

        $this->boot();
    }

    /**
     * Boot method made to be used by any custom mask.
     */
    protected function boot(): void {}

    /**
     * Call dynamic attribute setter and getter methods and inherit the parent calls.
     *
     * @return mixed
     *
     * @throws MethodNotFoundException
     */
    public function __call(string $method, array $arguments)
    {
        if (strtolower(substr($method, 0, 3)) === 'get') {
            $name = Str::snake(substr($method, 3));

            if (isset($this->attributes[$name])) {
                return $this->attributes[$name];
            }
        } elseif (strtolower(substr($method, 0, 3)) === 'set') {
            $name = Str::snake(substr($method, 3));

            if (isset($this->attributes[$name])) {
                $this->attributes[$name] = array_pop($arguments);

                return $this->attributes[$name];
            }
        }

        if (method_exists($this->parent, $method) === true) {
            return call_user_func_array([$this->parent, $method], $arguments);
        }

        throw new MethodNotFoundException('Method '.self::class.'::'.$method.'() is not supported');
    }

    /**
     * Magic setter.
     *
     * @return mixed
     */
    public function __set($name, $value)
    {
        $this->attributes[$name] = $value;

        return $this->attributes[$name];
    }

    /**
     * Magic getter.
     *
     * @return mixed|null
     */
    public function __get($name)
    {
        if (isset($this->attributes[$name])) {
            return $this->attributes[$name];
        }

        return null;
    }

    /**
     * Get the parent instance.
     */
    public function getParent(): mixed
    {
        return $this->parent;
    }

    /**
     * Get all available attributes.
     */
    public function getAttributes(): array
    {
        return $this->attributes;
    }
}
