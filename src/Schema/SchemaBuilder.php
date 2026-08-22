<?php

declare(strict_types=1);

namespace Quillstack\Orm\Schema;

use BackedEnum;
use DateTimeInterface;
use Quillstack\Orm\Attributes\Column;
use Quillstack\Orm\Metadata\AssociationMetadata;
use Quillstack\Orm\Metadata\ColumnMetadata;
use Quillstack\Orm\Metadata\EntityMetadata;
use Quillstack\Orm\Metadata\MetadataFactory;
use ReflectionClass;
use ReflectionEnum;
use ReflectionNamedType;

/**
 * Works out what the database should look like from what the entities say.
 *
 * Nobody writes an index or a foreign key: a relation is a declaration that one column holds
 * another table's id, and that is all the information needed to index it and constrain it.
 * Both are added because a relation without an index is a table scan on every lookup, and
 * that is precisely the kind of thing nobody remembers until it is slow.
 */
class SchemaBuilder
{
    public function __construct(private readonly MetadataFactory $metadata = new MetadataFactory())
    {
        //
    }

    /**
     * @param array<int, class-string> $entities
     *
     * @return array<string, TableSchema>
     */
    public function for(array $entities): array
    {
        $tables = [];

        foreach ($entities as $class) {
            $metadata = $this->metadata->for($class);
            $tables[$metadata->table] = $this->table($metadata);
        }

        // A relation names a column on whichever table holds it, which may not be the one
        // that declared the relation. Both directions are walked once every table is known.
        foreach ($entities as $class) {
            $tables = $this->withRelations($tables, $this->metadata->for($class));
        }

        return $tables;
    }

    private function table(EntityMetadata $metadata): TableSchema
    {
        $columns = [];
        $indexes = [];

        foreach ($metadata->columns as $column) {
            $columns[$column->column] = $this->column($metadata, $column);

            $attribute = $this->columnAttribute($metadata->class, $column->property);

            if ($attribute !== null && ($attribute->unique || $attribute->index)) {
                $name = IndexSchema::nameFor($metadata->table, [$column->column], $attribute->unique);
                $indexes[$name] = new IndexSchema($name, [$column->column], $attribute->unique);
            }
        }

        return new TableSchema($metadata->table, $columns, $metadata->id->column, $indexes);
    }

    /**
     * Puts an index and a foreign key on the column each relation matches on.
     *
     * @param array<string, TableSchema> $tables
     *
     * @return array<string, TableSchema>
     */
    private function withRelations(array $tables, EntityMetadata $metadata): array
    {
        foreach ($metadata->associations as $association) {
            $target = $this->metadata->for($association->target);

            if ($association->kind === AssociationMetadata::BELONGS_TO_MANY) {
                $tables = $this->withJoiningTable($tables, $metadata, $target, $association);

                continue;
            }

            // The column holding the other table's id is on the many side, whichever side
            // declared the relation.
            [$table, $column, $references, $referencesColumn] =
                $association->kind === AssociationMetadata::BELONGS_TO
                    ? [$metadata->table, $association->ownerColumn, $target->table, $target->id->column]
                    : [$target->table, $association->targetColumn, $metadata->table, $metadata->id->column];

            if (!isset($tables[$table]) || !isset($tables[$table]->columns[$column])) {
                // The other entity is not among the ones asked about, so there is nothing
                // here to add the key to.
                continue;
            }

            $indexName = IndexSchema::nameFor($table, [$column]);
            $keyName = ForeignKeySchema::nameFor($table, $column);

            $tables[$table] = $tables[$table]->with(
                indexes: $tables[$table]->indexes + [
                    $indexName => new IndexSchema($indexName, [$column]),
                ],
                foreignKeys: $tables[$table]->foreignKeys + [
                    $keyName => new ForeignKeySchema($keyName, $column, $references, $referencesColumn),
                ]
            );
        }

        return $tables;
    }

    /**
     * The table in between, which no entity describes and nobody should have to write: two
     * columns, an index and a foreign key on each, and a pair which cannot repeat.
     *
     * @param array<string, TableSchema> $tables
     *
     * @return array<string, TableSchema>
     */
    private function withJoiningTable(
        array $tables,
        EntityMetadata $metadata,
        EntityMetadata $target,
        AssociationMetadata $association
    ): array {
        $name = (string) $association->through;
        $ownerColumn = (string) $association->throughOwnerColumn;
        $targetColumn = (string) $association->throughTargetColumn;

        // Both sides may declare the same relation; the table is the same table. And where
        // the other entity is not among the ones asked about, there is nothing to point the
        // second key at, so the table in between is not this migration's business either.
        if (isset($tables[$name]) || !isset($tables[$target->table])) {
            return $tables;
        }

        $pairName = IndexSchema::nameFor($name, [$ownerColumn, $targetColumn], true);
        $ownerKey = ForeignKeySchema::nameFor($name, $ownerColumn);
        $targetKey = ForeignKeySchema::nameFor($name, $targetColumn);

        $tables[$name] = new TableSchema(
            $name,
            [
                $ownerColumn => new ColumnSchema($ownerColumn, $this->typeOf($metadata->id->type)),
                $targetColumn => new ColumnSchema($targetColumn, $this->typeOf($target->id->type)),
            ],
            // The pair is what tells one row from another here, so there is no id of its own.
            $ownerColumn,
            [
                $pairName => new IndexSchema($pairName, [$ownerColumn, $targetColumn], true),
                IndexSchema::nameFor($name, [$targetColumn]) => new IndexSchema(
                    IndexSchema::nameFor($name, [$targetColumn]),
                    [$targetColumn]
                ),
            ],
            [
                $ownerKey => new ForeignKeySchema($ownerKey, $ownerColumn, $metadata->table, $metadata->id->column),
                $targetKey => new ForeignKeySchema($targetKey, $targetColumn, $target->table, $target->id->column),
            ],
            hasOwnKey: false
        );

        return $tables;
    }

    private function column(EntityMetadata $metadata, ColumnMetadata $column): ColumnSchema
    {
        $attribute = $this->columnAttribute($metadata->class, $column->property);
        $type = $this->typeOf($column->type);

        // A string with no limit asked for is the kind a database keeps apart from short text.
        if ($type === ColumnSchema::STRING && $attribute?->length === 0) {
            $type = ColumnSchema::TEXT;
        }

        return new ColumnSchema(
            $column->column,
            $type,
            // An id is given by the database, so it is never null even where the property is
            // before the row has been written.
            $column->isId ? false : $column->nullable,
            $type === ColumnSchema::STRING ? ($attribute === null ? 255 : $attribute->length ?? 255) : null,
            $column->isId && $column->type === 'int'
        );
    }

    /**
     * What a PHP type is in a database.
     */
    private function typeOf(?string $type): string
    {
        if ($type === null) {
            return ColumnSchema::TEXT;
        }

        return match ($type) {
            'int' => ColumnSchema::INTEGER,
            'float' => ColumnSchema::FLOAT,
            'bool' => ColumnSchema::BOOLEAN,
            'string' => ColumnSchema::STRING,
            default => $this->typeOfObject($type),
        };
    }

    private function typeOfObject(string $type): string
    {
        if (is_a($type, DateTimeInterface::class, true)) {
            return ColumnSchema::DATETIME;
        }

        if (is_a($type, BackedEnum::class, true)) {
            // A backed enum is stored as whatever backs it.
            $backing = (new ReflectionEnum($type))->getBackingType();

            return $backing instanceof ReflectionNamedType && $backing->getName() === 'int'
                ? ColumnSchema::INTEGER
                : ColumnSchema::STRING;
        }

        return ColumnSchema::TEXT;
    }

    /**
     * @param class-string $class
     */
    private function columnAttribute(string $class, string $property): ?Column
    {
        $reflection = new ReflectionClass($class);

        foreach ($reflection->getConstructor()?->getParameters() ?? [] as $parameter) {
            if ($parameter->getName() === $property) {
                $attributes = $parameter->getAttributes(Column::class);

                return isset($attributes[0]) ? $attributes[0]->newInstance() : null;
            }
        }

        if ($reflection->hasProperty($property)) {
            $attributes = $reflection->getProperty($property)->getAttributes(Column::class);

            return isset($attributes[0]) ? $attributes[0]->newInstance() : null;
        }

        return null;
    }
}
