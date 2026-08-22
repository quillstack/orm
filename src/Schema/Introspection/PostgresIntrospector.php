<?php

declare(strict_types=1);

namespace Quillstack\Orm\Schema\Introspection;

use Quillstack\Db\Connection;

class PostgresIntrospector extends InformationSchemaIntrospector
{
    public function __construct(Connection $connection, string $schema = 'public')
    {
        parent::__construct($connection, $schema);
    }

    /**
     * {@inheritDoc}
     *
     * An index which is not a constraint is nowhere in `information_schema`; Postgres keeps
     * all of them in `pg_indexes` instead.
     */
    public function indexes(string $table): array
    {
        return $this->names($this->connection->select(
            'SELECT indexname FROM pg_indexes WHERE schemaname = :schema AND tablename = :table',
            ['schema' => $this->schema, 'table' => $table]
        ), 'indexname');
    }
}
