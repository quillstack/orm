<?php

declare(strict_types=1);

namespace Quillstack\Orm;

use ArrayIterator;
use Countable;
use IteratorAggregate;
use Quillstack\Orm\Exceptions\EntityNotManagedException;
use Quillstack\Orm\Metadata\AssociationMetadata;
use Traversable;

/**
 * The rows on the other side of a relation.
 *
 * Reading one of these loads it for every entity read alongside this one, in a single query.
 * There is no `with()` to remember and none to forget: asking is what loads the batch.
 *
 * The type has no bound on purpose. An entity gives its relations an empty one as a default,
 * and `new Related()` cannot say what it will hold — with a bound, that default is
 * `Related<object>` and no entity declaring `Related<Post>` passes static analysis, which
 * would make the recommended way of writing an entity the one that does not check.
 *
 * @template T
 *
 * @implements IteratorAggregate<int, T>
 */
final class Related implements IteratorAggregate, Countable
{
    /**
     * Built with nothing where an entity was made by hand rather than read. Such an entity
     * has no result set behind it, so there is nothing its relations could load from — which
     * it says, rather than quietly answering that there is nothing there.
     */
    public function __construct(
        private ?LoadContext $context = null,
        private readonly ?AssociationMetadata $association = null,
        private readonly mixed $ownerValue = null
    ) {
        //
    }

    /**
     * Points this at another result set.
     *
     * The same row read twice is the same object, so an entity read once on its own and then
     * again among fifty would otherwise keep the smaller set it arrived in — and load its
     * relation for one owner rather than fifty. Whatever it was pointing at before does not
     * matter: the larger set is the one worth asking in.
     */
    public function rebind(LoadContext $context): void
    {
        if ($this->association !== null) {
            $this->context = $context;
        }
    }

    /**
     * @return array<int, T>
     */
    public function all(): array
    {
        if ($this->context === null || $this->association === null) {
            throw new EntityNotManagedException(
                'This entity was not read from the database, so its relations have nothing to load from'
            );
        }

        /** @var array<int, T> $entities */
        $entities = $this->context->relatedTo($this->association, $this->ownerValue);

        return $entities;
    }

    /**
     * The native type says `mixed` where the docblock says what it really is. Static
     * analysis reads the docblock and knows exactly; PHP checks nothing, which is the price
     * of a relation being able to start out empty.
     *
     * @return ?T
     */
    public function first(): mixed
    {
        return $this->all()[0] ?? null;
    }

    public function isEmpty(): bool
    {
        return $this->all() === [];
    }

    /**
     * {@inheritDoc}
     */
    public function count(): int
    {
        return count($this->all());
    }

    /**
     * {@inheritDoc}
     */
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->all());
    }
}
