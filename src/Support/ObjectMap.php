<?php

namespace NightGuardian\Laravel\Support;

use ArrayAccess;

/**
 * Remembers one value per object without keeping the object alive: a WeakMap on PHP 8, and on older PHP a
 * bounded map keyed by spl_object_hash (the oldest entries are dropped first, so a long-running worker
 * cannot grow it without limit). On old PHP a recycled object hash could in theory match an unrelated
 * object, which is why the class name is stored and compared too.
 */
class ObjectMap implements ArrayAccess
{
    const LIMIT = 200;

    /** @var \WeakMap|null */
    private $weak;

    /** @var array<string, array{0: string, 1: mixed}> */
    private $entries = [];

    public function __construct()
    {
        if (class_exists('WeakMap')) {
            $this->weak = new \WeakMap;
        }
    }

    public function offsetExists($object): bool
    {
        if ($this->weak !== null) {
            return isset($this->weak[$object]);
        }

        $key = spl_object_hash($object);

        return isset($this->entries[$key]) && $this->entries[$key][0] === get_class($object);
    }

    #[\ReturnTypeWillChange]
    public function offsetGet($object)
    {
        if ($this->weak !== null) {
            return $this->weak[$object] ?? null;
        }

        return $this->offsetExists($object) ? $this->entries[spl_object_hash($object)][1] : null;
    }

    public function offsetSet($object, $value): void
    {
        if ($this->weak !== null) {
            $this->weak[$object] = $value;

            return;
        }

        $this->entries[spl_object_hash($object)] = [get_class($object), $value];

        if (count($this->entries) > self::LIMIT) {
            $this->entries = array_slice($this->entries, -self::LIMIT, null, true);
        }
    }

    public function offsetUnset($object): void
    {
        if ($this->weak !== null) {
            unset($this->weak[$object]);

            return;
        }

        unset($this->entries[spl_object_hash($object)]);
    }
}
