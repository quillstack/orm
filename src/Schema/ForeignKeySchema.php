<?php

declare(strict_types=1);

namespace Quillstack\Orm\Schema;

/**
 * A foreign key. Declared by the relation rather than by hand, so a column holding another
 * table's id is never left free to hold one that is not there.
 */
final class ForeignKeySchema
{
    public function __construct(
        public readonly string $name,
        public readonly string $column,
        public readonly string $referencesTable,
        public readonly string $referencesColumn,
        public readonly string $onDelete = 'CASCADE'
    ) {
        //
    }

    public static function nameFor(string $table, string $column): string
    {
        return "{$table}_{$column}_foreign";
    }
}
