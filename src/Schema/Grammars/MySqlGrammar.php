<?php

declare(strict_types=1);

namespace Quillstack\Orm\Schema\Grammars;

use Quillstack\Db\Dialect;
use Quillstack\Orm\Schema\ColumnSchema;
use Quillstack\Orm\Schema\ForeignKeySchema;
use Quillstack\Orm\Schema\IndexSchema;
use Quillstack\Orm\Schema\SchemaGrammar;
use Quillstack\Orm\Schema\TableSchema;

class MySqlGrammar implements SchemaGrammar
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
            $parts[] = '  ' . $this->column($column);
        }

        if ($table->hasOwnKey) {
            $parts[] = '  PRIMARY KEY (' . $this->dialect->quoteIdentifier($table->primaryKey) . ')';
        }

        foreach ($table->foreignKeys as $key) {
            $parts[] = '  ' . $this->foreignKey($key);
        }

        $name = $this->dialect->quoteIdentifier($table->name);

        return "CREATE TABLE {$name} (\n" . implode(",\n", $parts) . "\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    }

    /**
     * {@inheritDoc}
     */
    public function addColumn(string $table, ColumnSchema $column): string
    {
        return 'ALTER TABLE ' . $this->dialect->quoteIdentifier($table)
            . ' ADD COLUMN ' . $this->column($column);
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
     */
    public function addForeignKey(string $table, ForeignKeySchema $key): ?string
    {
        return 'ALTER TABLE ' . $this->dialect->quoteIdentifier($table)
            . ' ADD CONSTRAINT ' . $this->dialect->quoteIdentifier($key->name)
            . ' FOREIGN KEY (' . $this->dialect->quoteIdentifier($key->column) . ') REFERENCES '
            . $this->dialect->quoteIdentifier($key->referencesTable)
            . ' (' . $this->dialect->quoteIdentifier($key->referencesColumn) . ')'
            . " ON DELETE {$key->onDelete}";
    }

    private function column(ColumnSchema $column): string
    {
        $sql = $this->dialect->quoteIdentifier($column->name) . ' ' . $this->type($column)
            . ($column->nullable ? ' NULL' : ' NOT NULL');

        return $column->autoIncrement ? $sql . ' AUTO_INCREMENT' : $sql;
    }

    private function type(ColumnSchema $column): string
    {
        return match ($column->type) {
            ColumnSchema::INTEGER => 'INT',
            ColumnSchema::BIG_INTEGER => 'BIGINT',
            ColumnSchema::BOOLEAN => 'TINYINT(1)',
            ColumnSchema::FLOAT => 'DOUBLE',
            ColumnSchema::DATETIME => 'DATETIME',
            ColumnSchema::TEXT => 'TEXT',
            default => 'VARCHAR(' . ($column->length ?? 255) . ')',
        };
    }

    private function foreignKey(ForeignKeySchema $key): string
    {
        return 'CONSTRAINT ' . $this->dialect->quoteIdentifier($key->name)
            . ' FOREIGN KEY (' . $this->dialect->quoteIdentifier($key->column) . ') REFERENCES '
            . $this->dialect->quoteIdentifier($key->referencesTable)
            . ' (' . $this->dialect->quoteIdentifier($key->referencesColumn) . ')'
            . " ON DELETE {$key->onDelete}";
    }
}
