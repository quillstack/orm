<?php

declare(strict_types=1);

namespace Quillstack\Orm\Schema\Introspection;

use Quillstack\Db\Connection;
use Quillstack\Orm\Schema\Introspector;

/**
 * What MySQL and PostgreSQL describe the same way, which is tables, columns and keys.
 *
 * Indexes they do not: an index which is not a constraint appears in neither's
 * `table_constraints`, so each says that in its own place and each subclass answers it.
 */
abstract class InformationSchemaIntrospector implements Introspector
{
    public function __construct(
        protected readonly Connection $connection,
        protected readonly string $schema
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
    protected function names(array $rows, string $key): array
    {
        $names = [];

        foreach ($rows as $row) {
            // MySQL upper-cases these in some configurations, PostgreSQL does not.
            $value = $row[$key] ?? $row[strtoupper($key)] ?? null;

            if (is_scalar($value)) {
                $names[] = (string) $value;
            }
        }

        return $names;
    }
}
