<?php

declare(strict_types=1);

namespace Quillstack\Orm\Schema\Grammars;

use Quillstack\Db\Dialect;
use Quillstack\Orm\Schema\ColumnSchema;
use Quillstack\Orm\Schema\ForeignKeySchema;
use Quillstack\Orm\Schema\IndexSchema;
use Quillstack\Orm\Schema\SchemaGrammar;
use Quillstack\Orm\Schema\TableSchema;

class SqliteGrammar implements SchemaGrammar
{
    public function __construct(private readonly Dialect $dialect)
    {
        //
    }

    /**
     * {@inheritDoc}
     */
    public function createTable(TableSchema $table): string
    {
        $parts = [];

        foreach ($table->columns as $column) {
            $parts[] = '  ' . $this->column($column, $column->name === $table->primaryKey);
        }

        foreach ($table->foreignKeys as $key) {
            $parts[] = '  ' . $this->foreignKey($key);
        }

        $name = $this->dialect->quoteIdentifier($table->name);

        return "CREATE TABLE {$name} (\n" . implode(",\n", $parts) . "\n)";
    }

    /**
     * {@inheritDoc}
     */
    public function addColumn(string $table, ColumnSchema $column): string
    {
        return 'ALTER TABLE ' . $this->dialect->quoteIdentifier($table)
            . ' ADD COLUMN ' . $this->column($column, false);
    }

    /**
     * {@inheritDoc}
     */
    public function createIndex(string $table, IndexSchema $index): string
    {
        $columns = implode(', ', array_map(
            fn (string $column): string => $this->dialect->quoteIdentifier($column),
            $index->columns
        ));

        return 'CREATE ' . ($index->unique ? 'UNIQUE ' : '') . 'INDEX '
            . $this->dialect->quoteIdentifier($index->name)
            . ' ON ' . $this->dialect->quoteIdentifier($table) . " ({$columns})";
    }

    /**
     * {@inheritDoc}
     *
     * SQLite cannot add one to a table that already exists. Saying so is better than writing
     * something which quietly does nothing.
     */
    public function addForeignKey(string $table, ForeignKeySchema $key): ?string
    {
        return null;
    }

    private function column(ColumnSchema $column, bool $isPrimary): string
    {
        $name = $this->dialect->quoteIdentifier($column->name);

        if ($isPrimary && $column->autoIncrement) {
            // SQLite gives out ids only for this exact spelling.
            return "{$name} INTEGER PRIMARY KEY AUTOINCREMENT";
        }

        $sql = "{$name} " . $this->type($column);

        if ($isPrimary) {
            $sql .= ' PRIMARY KEY';
        }

        return $sql . ($column->nullable ? ' NULL' : ' NOT NULL');
    }

    private function type(ColumnSchema $column): string
    {
        return match ($column->type) {
            ColumnSchema::INTEGER, ColumnSchema::BIG_INTEGER, ColumnSchema::BOOLEAN => 'INTEGER',
            ColumnSchema::FLOAT => 'REAL',
            default => 'TEXT',
        };
    }

    private function foreignKey(ForeignKeySchema $key): string
    {
        return 'FOREIGN KEY (' . $this->dialect->quoteIdentifier($key->column) . ') REFERENCES '
            . $this->dialect->quoteIdentifier($key->referencesTable)
            . ' (' . $this->dialect->quoteIdentifier($key->referencesColumn) . ')'
            . " ON DELETE {$key->onDelete}";
    }
}
