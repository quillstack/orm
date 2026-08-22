<?php

declare(strict_types=1);

namespace Quillstack\Orm\Metadata;

use Quillstack\Orm\Exceptions\UnknownColumnException;

/**
 * Everything the ORM knows about one entity, worked out once and kept.
 */
final class EntityMetadata
{
    /**
     * @param class-string $class
     * @param array<string, ColumnMetadata> $columns keyed by column name
     * @param array<string, AssociationMetadata> $associations keyed by property name
     */
    public function __construct(
        public readonly string $class,
        public readonly string $table,
        public readonly ColumnMetadata $id,
        public readonly array $columns,
        public readonly array $associations
    ) {
        //
    }

    /**
     * The property a column lands on.
     */
    public function propertyForColumn(string $column): string
    {
        if (!isset($this->columns[$column])) {
            throw new UnknownColumnException("`{$this->class}` has no column `{$column}`");
        }

        return $this->columns[$column]->property;
    }

    /**
     * Reads what an entity holds for a column, whatever the property behind it is called.
     */
    public function read(object $entity, string $column): mixed
    {
        $property = $this->propertyForColumn($column);

        /** @var array<string, mixed> $values */
        $values = get_object_vars($entity);

        return $values[$property] ?? null;
    }

    /**
     * The id of an entity, or null where it has not been given one yet.
     */
    public function idOf(object $entity): int|string|null
    {
        $value = $this->read($entity, $this->id->column);

        return is_int($value) || is_string($value) ? $value : null;
    }

    /**
     * @return string[]
     */
    public function columnNames(): array
    {
        return array_keys($this->columns);
    }
}
