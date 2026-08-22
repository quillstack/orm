<?php

declare(strict_types=1);

namespace Quillstack\Orm;

/**
 * One row, one object. Reading the same row twice gives the same instance back, so comparing
 * two entities with `===` answers what a person means by it.
 */
class IdentityMap
{
    /**
     * @var array<string, object>
     */
    private array $entities = [];

    public function get(string $class, int|string $id): ?object
    {
        return $this->entities[self::key($class, $id)] ?? null;
    }

    public function put(string $class, int|string $id, object $entity): void
    {
        $this->entities[self::key($class, $id)] = $entity;
    }

    public function forget(string $class, int|string $id): void
    {
        unset($this->entities[self::key($class, $id)]);
    }

    public function clear(): void
    {
        $this->entities = [];
    }

    private static function key(string $class, int|string $id): string
    {
        return "{$class}#{$id}";
    }
}
