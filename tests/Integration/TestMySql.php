<?php

declare(strict_types=1);

namespace Quillstack\Orm\Tests\Integration;

use Quillstack\Db\Connection;
use Quillstack\Orm\Migration\Migrator;
use Quillstack\Orm\Schema\Introspection\MySqlIntrospector;
use Quillstack\Orm\Schema\Introspector;

class TestMySql extends AbstractRealDatabaseTest
{
    protected function driver(): string
    {
        return 'mysql';
    }

    protected function introspector(Connection $connection): Introspector
    {
        return new MySqlIntrospector($connection, $this->database($connection));
    }

    private function database(Connection $connection): string
    {
        $row = $connection->selectOne('SELECT DATABASE() AS name');
        $name = $row['name'] ?? '';

        return is_scalar($name) ? (string) $name : '';
    }

    /**
     * MySQL commits whatever is open the moment a table is created, so a migration there is
     * not one thing that either happens or does not. Wrapping it in a transaction would say
     * otherwise; the plan says so instead.
     */
    public function aSchemaChangeCannotBeUndoneAndTheSchemaSaysSo()
    {
        $connection = $this->connection();

        $this->assertBoolean->isFalse($connection->dialect()->supportsTransactionalSchema());

        $plan = (new Migrator($connection))->plan(self::ENTITIES);
        $warnings = implode("\n", $plan->warnings);

        $this->assertBoolean->isTrue(str_contains($warnings, 'commits each schema change'));
    }
}
