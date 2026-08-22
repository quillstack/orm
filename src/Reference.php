<?php

declare(strict_types=1);

namespace Quillstack\Orm;

use Quillstack\Orm\Exceptions\EntityNotManagedException;
use Quillstack\Orm\Metadata\AssociationMetadata;

/**
 * The one row on the other side of a relation, where there is only one of them.
 *
 * `get()` rather than the entity itself, because reading it may go to the database and a
 * property access that quietly does that is how an application ends up with a thousand
 * queries nobody wrote. Here it goes once for everybody read alongside.
 *
 * @template T of object
 */
final class Reference
{
    public function __construct(
        private readonly ?LoadContext $context = null,
        private readonly ?AssociationMetadata $association = null,
        private readonly mixed $ownerValue = null
    ) {
        //
    }

    /**
     * @return ?T
     */
    public function get(): ?object
    {
        if ($this->context === null || $this->association === null) {
            throw new EntityNotManagedException(
                'This entity was not read from the database, so its relations have nothing to load from'
            );
        }

        /** @var array<int, T> $entities */
        $entities = $this->context->relatedTo($this->association, $this->ownerValue);

        return $entities[0] ?? null;
    }

    public function isPresent(): bool
    {
        return $this->get() !== null;
    }
}
