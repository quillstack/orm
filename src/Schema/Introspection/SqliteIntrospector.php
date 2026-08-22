<?php

declare(strict_types=1);

namespace Quillstack\Orm\Schema\Introspection;

use Quillstack\Db\Connection;
use Quillstack\Orm\Schema\Introspector;

class SqliteIntrospector implements Introspector
{
    public function __construct(private readonly Connection $connection)
    {
        //
    }

    /**
     * {@inheritDoc}
     */
    public function tables(): array
    {
        return $this->names(
            $this->connection->select(
                "SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%'"
            ),
            'name'
        );
    }

    /**
     * {@inheritDoc}
     */
    public function columns(string $table): array
    {
        // PRAGMA takes no parameters, so the name is quoted rather than bound — it comes from
        // an entity, never from a request.
        $quoted = $this->quote($table);

        return $this->names($this->connection->select("PRAGMA table_info({$quoted})"), 'name');
    }

    /**
     * {@inheritDoc}
     */
    public function indexes(string $table): array
    {
        $quoted = $this->quote($table);

        return $this->names($this->connection->select("PRAGMA index_list({$quoted})"), 'name');
    }

    /**
     * {@inheritDoc}
     */
    public function foreignKeyColumns(string $table): array
    {
        $quoted = $this->quote($table);

        return $this->names($this->connection->select("PRAGMA foreign_key_list({$quoted})"), 'from');
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
            $value = $row[$key] ?? null;

            if (is_scalar($value)) {
                $names[] = (string) $value;
            }
        }

        return $names;
    }

    private function quote(string $name): string
    {
        return "'" . str_replace("'", "''", $name) . "'";
    }
}
