<?php

declare(strict_types=1);

namespace Quillstack\Orm\Migration;

use Quillstack\Db\Connection;
use Quillstack\Orm\Schema\Grammars\MySqlGrammar;
use Quillstack\Orm\Schema\Grammars\PostgresGrammar;
use Quillstack\Orm\Schema\Grammars\SqliteGrammar;
use Quillstack\Orm\Schema\Introspection\InformationSchemaIntrospector;
use Quillstack\Orm\Schema\Introspection\SqliteIntrospector;
use Quillstack\Orm\Schema\Introspector;
use Quillstack\Orm\Schema\SchemaBuilder;
use Quillstack\Orm\Schema\SchemaGrammar;
use Quillstack\Orm\Schema\TableSchema;

/**
 * Brings the database to what the entities say it should be.
 *
 * There are no migration files to write and none to keep in order. The entities are the
 * description; what is missing is worked out by comparing them against what is there.
 *
 * Nothing is ever removed. A column the entities no longer mention is reported and left
 * alone: a renamed property looks exactly like a deleted one, and the difference matters
 * rather a lot when the answer is data.
 */
class Migrator
{
    private ?SchemaGrammar $grammar;

    private ?Introspector $introspector;

    public function __construct(
        private readonly Connection $connection,
        ?SchemaGrammar $grammar = null,
        ?Introspector $introspector = null,
        private readonly SchemaBuilder $builder = new SchemaBuilder()
    ) {
        // Which one to use is known only once the database says what it is, and asking that
        // opens the connection. Building a migrator must not: an application registering one
        // and never migrating would connect on every request for nothing.
        $this->grammar = $grammar;
        $this->introspector = $introspector;
    }

    private function grammar(): SchemaGrammar
    {
        return $this->grammar ??= self::grammarFor($this->connection);
    }

    private function introspector(): Introspector
    {
        return $this->introspector ??= self::introspectorFor($this->connection);
    }

    /**
     * What would be run to bring the database to what these entities describe.
     *
     * @param array<int, class-string> $entities
     */
    public function plan(array $entities): Plan
    {
        $plan = new Plan();
        $tables = $this->builder->for($entities);
        $existing = $this->introspector()->tables();

        foreach ($tables as $table) {
            $plan = in_array($table->name, $existing, true)
                ? $this->planChanges($plan, $table)
                : $this->planTable($plan, $table);
        }

        return $plan;
    }

    /**
     * Runs the plan, all of it or none of it.
     *
     * @return int how many statements were run
     */
    public function apply(Plan $plan): int
    {
        if ($plan->isEmpty()) {
            return 0;
        }

        return $this->connection->transaction(function (Connection $connection) use ($plan): int {
            foreach ($plan->statements as $statement) {
                $connection->execute($statement);
            }

            return count($plan->statements);
        });
    }

    /**
     * Works out what is missing and does it, in one step, for where that is what is wanted.
     *
     * @param array<int, class-string> $entities
     */
    public function migrate(array $entities): Plan
    {
        $plan = $this->plan($entities);
        $this->apply($plan);

        return $plan;
    }

    private function planTable(Plan $plan, TableSchema $table): Plan
    {
        $plan = $plan->with($this->grammar()->createTable($table));

        foreach ($table->indexes as $index) {
            // The primary key is already an index; a second one on the same column is waste.
            if ($index->columns === [$table->primaryKey]) {
                continue;
            }

            $plan = $plan->with($this->grammar()->createIndex($table->name, $index));
        }

        return $plan;
    }

    private function planChanges(Plan $plan, TableSchema $table): Plan
    {
        $columns = $this->introspector()->columns($table->name);
        $indexes = $this->introspector()->indexes($table->name);
        $keyed = $this->introspector()->foreignKeyColumns($table->name);

        foreach ($table->columns as $column) {
            if (!in_array($column->name, $columns, true)) {
                $plan = $plan->with($this->grammar()->addColumn($table->name, $column));
            }
        }

        foreach ($table->indexes as $index) {
            if ($index->columns === [$table->primaryKey] || in_array($index->name, $indexes, true)) {
                continue;
            }

            $plan = $plan->with($this->grammar()->createIndex($table->name, $index));
        }

        foreach ($table->foreignKeys as $key) {
            if (in_array($key->column, $keyed, true)) {
                continue;
            }

            $sql = $this->grammar()->addForeignKey($table->name, $key);

            $plan = $sql === null
                ? $plan->warning(
                    "`{$table->name}.{$key->column}` should have a foreign key to "
                    . "`{$key->referencesTable}`, which this database cannot add to a table "
                    . 'that already exists'
                )
                : $plan->with($sql);
        }

        foreach ($columns as $column) {
            if (!isset($table->columns[$column])) {
                $plan = $plan->warning(
                    "`{$table->name}.{$column}` is in the database and not in the entity — "
                    . 'left alone, because a renamed property looks exactly like a deleted one'
                );
            }
        }

        return $plan;
    }

    private static function grammarFor(Connection $connection): SchemaGrammar
    {
        $dialect = $connection->dialect();

        return match ($dialect->name()) {
            'mysql' => new MySqlGrammar($dialect),
            'pgsql' => new PostgresGrammar($dialect),
            default => new SqliteGrammar($dialect),
        };
    }

    private static function introspectorFor(Connection $connection): Introspector
    {
        return match ($connection->dialect()->name()) {
            'sqlite' => new SqliteIntrospector($connection),
            'pgsql' => new InformationSchemaIntrospector($connection),
            default => new InformationSchemaIntrospector($connection, ''),
        };
    }
}
