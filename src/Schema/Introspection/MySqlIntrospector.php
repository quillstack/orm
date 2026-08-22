<?php

declare(strict_types=1);

namespace Quillstack\Orm\Schema\Introspection;

use Quillstack\Db\Connection;

class MySqlIntrospector extends InformationSchemaIntrospector
{
    public function __construct(Connection $connection, string $schema = '')
    {
        parent::__construct($connection, $schema);
    }

    /**
     * {@inheritDoc}
     *
     * MySQL lists every index in `statistics`, one row per column of it.
     */
    public function indexes(string $table): array
    {
        return $this->names($this->connection->select(
            'SELECT DISTINCT index_name FROM information_schema.statistics
             WHERE table_schema = :schema AND table_name = :table',
            ['schema' => $this->schema, 'table' => $table]
        ), 'index_name');
    }
}
