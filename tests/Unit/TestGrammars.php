<?php

declare(strict_types=1);

namespace Quillstack\Orm\Tests\Unit;

use Quillstack\Db\Dialects\MySqlDialect;
use Quillstack\Db\Dialects\PostgresDialect;
use Quillstack\Db\Dialects\SqliteDialect;
use Quillstack\Orm\Schema\ForeignKeySchema;
use Quillstack\Orm\Schema\Grammars\MySqlGrammar;
use Quillstack\Orm\Schema\Grammars\PostgresGrammar;
use Quillstack\Orm\Schema\Grammars\SqliteGrammar;
use Quillstack\Orm\Schema\IndexSchema;
use Quillstack\Orm\Schema\SchemaBuilder;
use Quillstack\Orm\Tests\Mocks\Entities\Account;
use Quillstack\Orm\Tests\Mocks\Entities\Article;
use Quillstack\Orm\Tests\Mocks\Entities\Post;
use Quillstack\Orm\Tests\Mocks\Entities\User;
use Quillstack\UnitTests\AssertEqual;
use Quillstack\UnitTests\Types\AssertBoolean;

/**
 * The suite here has SQLite and nothing else, so what the other two would be told is checked
 * by reading it. A real MySQL and a real PostgreSQL still have to confirm it.
 */
class TestGrammars
{
    public function __construct(
        private AssertEqual $assertEqual,
        private AssertBoolean $assertBoolean
    ) {
        //
    }

    public function everyKindOfValueHasATypeInEachDatabase()
    {
        $tables = (new SchemaBuilder())->for([Article::class]);
        $sql = (new MySqlGrammar(new MySqlDialect()))->createTable($tables['articles']);

        $this->assertBoolean->isTrue(str_contains($sql, '`id` INT NOT NULL AUTO_INCREMENT'));
        $this->assertBoolean->isTrue(str_contains($sql, '`title` VARCHAR(255) NOT NULL'));
        $this->assertBoolean->isTrue(str_contains($sql, '`views` INT NOT NULL'));
        $this->assertBoolean->isTrue(str_contains($sql, '`rating` DOUBLE NOT NULL'));
        $this->assertBoolean->isTrue(str_contains($sql, '`featured` TINYINT(1) NOT NULL'));
        $this->assertBoolean->isTrue(str_contains($sql, '`status` VARCHAR(255) NULL'));
        $this->assertBoolean->isTrue(str_contains($sql, '`published_at` DATETIME NULL'));
        $this->assertBoolean->isTrue(str_contains($sql, 'PRIMARY KEY (`id`)'));
    }

    /**
     * Postgres has no separate auto-increment: the type says it.
     */
    public function postgresSaysAutoIncrementInTheType()
    {
        $tables = (new SchemaBuilder())->for([Article::class]);
        $sql = (new PostgresGrammar(new PostgresDialect()))->createTable($tables['articles']);

        $this->assertBoolean->isTrue(str_contains($sql, '"id" SERIAL NOT NULL'));
        $this->assertBoolean->isTrue(str_contains($sql, '"featured" BOOLEAN NOT NULL'));
        $this->assertBoolean->isTrue(str_contains($sql, '"published_at" TIMESTAMP NULL'));
        $this->assertBoolean->isFalse(str_contains($sql, 'AUTO_INCREMENT'));
    }

    /**
     * SQLite gives out ids only for this exact spelling.
     */
    public function sqliteNeedsItsOwnSpellingForIds()
    {
        $tables = (new SchemaBuilder())->for([Article::class]);
        $sql = (new SqliteGrammar(new SqliteDialect()))->createTable($tables['articles']);

        $this->assertBoolean->isTrue(str_contains($sql, '"id" INTEGER PRIMARY KEY AUTOINCREMENT'));
    }

    public function theForeignKeyIsPartOfTheTable()
    {
        $tables = (new SchemaBuilder())->for([User::class, Post::class]);
        $sql = (new SqliteGrammar(new SqliteDialect()))->createTable($tables['posts']);

        $this->assertBoolean->isTrue(str_contains(
            $sql,
            'FOREIGN KEY ("user_id") REFERENCES "users" ("id") ON DELETE CASCADE'
        ));
    }

    /**
     * SQLite cannot add one to a table that already exists, and says so rather than writing
     * something which quietly does nothing.
     */
    public function sqliteSaysWhatItCannotDo()
    {
        $key = new ForeignKeySchema('posts_user_id_foreign', 'user_id', 'users', 'id');

        $this->assertBoolean->isTrue(
            (new SqliteGrammar(new SqliteDialect()))->addForeignKey('posts', $key) === null
        );
        $this->assertBoolean->isTrue(
            (new MySqlGrammar(new MySqlDialect()))->addForeignKey('posts', $key) !== null
        );
    }

    public function addingAColumnAndAnIndex()
    {
        $tables = (new SchemaBuilder())->for([User::class, Post::class]);
        $grammar = new MySqlGrammar(new MySqlDialect());

        $this->assertEqual->equal(
            'ALTER TABLE `users` ADD COLUMN `email` VARCHAR(255) NOT NULL',
            $grammar->addColumn('users', $tables['users']->columns['email'])
        );
        $this->assertEqual->equal(
            'CREATE INDEX `posts_user_id_index` ON `posts` (`user_id`)',
            $grammar->createIndex('posts', new IndexSchema('posts_user_id_index', ['user_id']))
        );
        $this->assertEqual->equal(
            'CREATE UNIQUE INDEX `users_email_unique` ON `users` (`email`)',
            $grammar->createIndex('users', new IndexSchema('users_email_unique', ['email'], true))
        );
    }

    /**
     * Postgres writes the rest of it its own way too.
     */
    public function postgresWritesTheRestItsOwnWay()
    {
        $tables = (new SchemaBuilder())->for([User::class, Post::class]);
        $grammar = new PostgresGrammar(new PostgresDialect());

        $this->assertEqual->equal(
            'ALTER TABLE "users" ADD COLUMN "email" VARCHAR(255) NOT NULL',
            $grammar->addColumn('users', $tables['users']->columns['email'])
        );
        $this->assertEqual->equal(
            'CREATE INDEX "posts_user_id_index" ON "posts" ("user_id")',
            $grammar->createIndex('posts', new IndexSchema('posts_user_id_index', ['user_id']))
        );
        $this->assertEqual->equal(
            'ALTER TABLE "posts" ADD CONSTRAINT "posts_user_id_foreign" FOREIGN KEY ("user_id")'
            . ' REFERENCES "users" ("id") ON DELETE CASCADE',
            $grammar->addForeignKey('posts', new ForeignKeySchema('posts_user_id_foreign', 'user_id', 'users', 'id'))
        );

        $sql = $grammar->createTable($tables['posts']);

        // The column follows the type the entity declares: `?int $userId` is a relation
        // which may be absent, and the schema says so rather than deciding otherwise.
        $this->assertBoolean->isTrue(str_contains($sql, '"user_id" INTEGER NULL'));
        $this->assertBoolean->isTrue(str_contains(
            $sql,
            'CONSTRAINT "posts_user_id_foreign" FOREIGN KEY ("user_id") REFERENCES "users" ("id")'
        ));
    }

    /**
     * A text column can say how much room it needs, and ask for no two rows to share a value
     * — which also puts an index on it.
     */
    public function aColumnCanAskForRoomAndForUniqueness()
    {
        $tables = (new SchemaBuilder())->for([Account::class]);
        $sql = (new MySqlGrammar(new MySqlDialect()))->createTable($tables['accounts']);

        $this->assertBoolean->isTrue(str_contains($sql, '`handle` VARCHAR(40) NOT NULL'));
        $this->assertBoolean->isTrue(str_contains($sql, '`notes` TEXT NULL'));
        $this->assertEqual->equal(
            ['accounts_handle_unique', 'accounts_email_index'],
            array_keys($tables['accounts']->indexes)
        );
    }
}
