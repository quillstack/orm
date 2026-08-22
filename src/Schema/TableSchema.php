<?php

declare(strict_types=1);

namespace Quillstack\Orm\Schema;

/**
 * One table, as the entities say it should be.
 */
final class TableSchema
{
    /**
     * @param array<string, ColumnSchema> $columns keyed by name
     * @param array<string, IndexSchema> $indexes keyed by name
     * @param array<string, ForeignKeySchema> $foreignKeys keyed by name
     */
    public function __construct(
        public readonly string $name,
        public readonly array $columns,
        public readonly string $primaryKey,
        public readonly array $indexes = [],
        public readonly array $foreignKeys = []
    ) {
        //
    }

    /**
     * @param ?array<string, ColumnSchema> $columns
     * @param ?array<string, IndexSchema> $indexes
     * @param ?array<string, ForeignKeySchema> $foreignKeys
     */
    public function with(
        ?array $columns = null,
        ?array $indexes = null,
        ?array $foreignKeys = null
    ): self {
        return new self(
            $this->name,
            $columns ?? $this->columns,
            $this->primaryKey,
            $indexes ?? $this->indexes,
            $foreignKeys ?? $this->foreignKeys
        );
    }
}
