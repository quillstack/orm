<?php

declare(strict_types=1);

namespace Quillstack\Orm\Metadata;

/**
 * One relation, described the same way whichever direction it points.
 *
 * `ownerColumn` is read from the entity holding the relation, `targetColumn` is matched
 * against it on the other table. A relation is loaded by collecting every owner's value and
 * asking for all of them at once, which is the same shape for all three kinds.
 */
final class AssociationMetadata
{
    public const HAS_MANY = 'has-many';
    public const HAS_ONE = 'has-one';
    public const BELONGS_TO = 'belongs-to';
    public const BELONGS_TO_MANY = 'belongs-to-many';

    /**
     * @param class-string $target
     */
    /**
     * @param class-string $target
     * @param ?string $through the table in between, where the relation goes through one
     * @param ?string $throughOwnerColumn the column there holding this entity's id
     * @param ?string $throughTargetColumn the column there holding the other entity's id
     */
    public function __construct(
        public readonly string $property,
        public readonly string $target,
        public readonly string $kind,
        public readonly string $ownerColumn,
        public readonly string $targetColumn,
        public readonly ?string $through = null,
        public readonly ?string $throughOwnerColumn = null,
        public readonly ?string $throughTargetColumn = null
    ) {
        //
    }

    /**
     * Whether one row answers this relation, rather than a set of them.
     */
    public function isToOne(): bool
    {
        return $this->kind === self::HAS_ONE || $this->kind === self::BELONGS_TO;
    }

    /**
     * Names this relation apart from every other, so a load context knows what it has
     * already fetched.
     */
    public function key(): string
    {
        return "{$this->target}::{$this->property}::{$this->ownerColumn}::{$this->targetColumn}::{$this->through}";
    }
}
