<?php

declare(strict_types=1);

namespace Quillstack\Orm\Schema;

/**
 * How one database says what every database means.
 */
interface SchemaGrammar
{
    public function createTable(TableSchema $table): string;

    public function addColumn(string $table, ColumnSchema $column): string;

    public function createIndex(string $table, IndexSchema $index): string;

    /**
     * A foreign key added to a table which already exists, or null where the database cannot
     * do that — SQLite cannot, and says so rather than pretending.
     */
    public function addForeignKey(string $table, ForeignKeySchema $key): ?string;
}
