<?php


declare(strict_types=1);

namespace Doctrine\ORM\Mapping;

/**
 * A resolver is used to instantiate an entity listener.
 *
 * @since   2.4
 * @author  Fabio B. Silva <fabio.bat.silva@gmail.com>
 */
interface EntityListenerResolver
{
    /**
     * Clear all instances from the set, or a specific class when given.
     *
     * @param string $className The fully-qualified class name
     *
     * @return void
     */
    public function clear($className = null);

    /**
     * Returns a entity listener instance for the given class name.
     *
     * @param string $className The fully-qualified class name
     *
     * @return object An entity listener
     */
    public function resolve($className);

    /**
     * Register a entity listener instance.
     *
     * @param object $object An entity listener
     */
    public function register($object);
}
