<?php

declare(strict_types=1);

namespace Quillstack\Orm\Schema\Introspection;

use Quillstack\Db\Connection;
use Quillstack\Orm\Schema\Introspector;

/**
 * MySQL and PostgreSQL both describe themselves through `information_schema`, so both are
 * read the same way.
 *
 * Written against the standard rather than against a running server: the suite here has
 * SQLite and nothing else, so this is the part of the package a real database has to confirm.
 */
class InformationSchemaIntrospector implements Introspector
{
    public function __construct(
        private readonly Connection $connection,
        private readonly string $schema = 'public'
    ) {
        //
    }

    /**
     * {@inheritDoc}
     */
    public function tables(): array
    {
        return $this->names($this->connection->select(
            'SELECT table_name FROM information_schema.tables WHERE table_schema = :schema',
            ['schema' => $this->schema]
        ), 'table_name');
    }

    /**
     * {@inheritDoc}
     */
    public function columns(string $table): array
    {
        return $this->names($this->connection->select(
            'SELECT column_name FROM information_schema.columns
             WHERE table_schema = :schema AND table_name = :table',
            ['schema' => $this->schema, 'table' => $table]
        ), 'column_name');
    }

    /**
     * {@inheritDoc}
     */
    public function indexes(string $table): array
    {
        return $this->names($this->connection->select(
            'SELECT constraint_name AS name FROM information_schema.table_constraints
             WHERE table_schema = :schema AND table_name = :table',
            ['schema' => $this->schema, 'table' => $table]
        ), 'name');
    }

    /**
     * {@inheritDoc}
     */
    public function foreignKeyColumns(string $table): array
    {
        return $this->names($this->connection->select(
            'SELECT k.column_name FROM information_schema.table_constraints AS c
             JOIN information_schema.key_column_usage AS k
               ON k.constraint_name = c.constraint_name AND k.table_schema = c.table_schema
             WHERE c.constraint_type = :type AND c.table_schema = :schema AND c.table_name = :table',
            ['type' => 'FOREIGN KEY', 'schema' => $this->schema, 'table' => $table]
        ), 'column_name');
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     *
     * @return string[]
     */
    private function names(array $rows, string $key): array
    {
        $names = [];

        foreach ($rows as $row) {
            // MySQL upper-cases these, PostgreSQL does not.
            $value = $row[$key] ?? $row[strtoupper($key)] ?? null;

            if (is_scalar($value)) {
                $names[] = (string) $value;
            }
        }

        return $names;
    }
}
