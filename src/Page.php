<?php

declare(strict_types=1);

namespace Quillstack\Orm;

use ArrayIterator;
use Countable;
use IteratorAggregate;
use Traversable;

/**
 * One page of entities, and enough about the rest to say where this one sits.
 *
 * @template T of object
 *
 * @implements IteratorAggregate<int, T>
 */
final class Page implements IteratorAggregate, Countable
{
    /**
     * @param array<int, T> $items
     */
    public function __construct(
        public readonly array $items,
        public readonly int $page,
        public readonly int $perPage,
        public readonly int $total
    ) {
        //
    }

    public function pages(): int
    {
        return $this->perPage < 1 ? 0 : (int) ceil($this->total / $this->perPage);
    }

    public function hasMore(): bool
    {
        return $this->page < $this->pages();
    }

    public function isEmpty(): bool
    {
        return $this->items === [];
    }

    /**
     * {@inheritDoc}
     */
    public function count(): int
    {
        return count($this->items);
    }

    /**
     * {@inheritDoc}
     */
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->items);
    }
}
