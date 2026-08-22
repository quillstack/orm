<?php

declare(strict_types=1);

namespace Quillstack\Orm\Tests\Integration;

use Quillstack\Db\Connection;
use Quillstack\Orm\Schema\Introspection\PostgresIntrospector;
use Quillstack\Orm\Schema\Introspector;

class TestPostgres extends AbstractRealDatabaseTest
{
    protected function driver(): string
    {
        return 'pgsql';
    }

    protected function introspector(Connection $connection): Introspector
    {
        return new PostgresIntrospector($connection);
    }

    /**
     * Postgres can undo a schema change, so a migration which fails part way leaves nothing
     * behind.
     */
    public function aSchemaChangeCanBeUndone()
    {
        $this->assertBoolean->isTrue(
            $this->connection()->dialect()->supportsTransactionalSchema()
        );
    }
}
