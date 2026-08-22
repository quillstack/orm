<?php

declare(strict_types=1);

namespace Quillstack\Orm\Tests\Unit;

use Quillstack\Db\Connection;
use Quillstack\Orm\Migration\Migrator;
use Quillstack\Orm\Orm;
use Quillstack\Orm\Schema\Introspection\SqliteIntrospector;
use Quillstack\Orm\Tests\Mocks\Entities\Comment;
use Quillstack\Orm\Tests\Mocks\Entities\Post;
use Quillstack\Orm\Tests\Mocks\Entities\Profile;
use Quillstack\Orm\Tests\Mocks\Entities\User;
use Quillstack\UnitTests\AssertEqual;
use Quillstack\UnitTests\Types\AssertBoolean;

class TestMigrations
{
    /**
     * @var array<int, class-string>
     */
    private const BLOG = [User::class, Post::class, Comment::class, Profile::class];

    public function __construct(
        private AssertEqual $assertEqual,
        private AssertBoolean $assertBoolean
    ) {
        //
    }

    private function connection(): Connection
    {
        return new Connection('sqlite::memory:');
    }

    /**
     * No migration file is written and none is kept in order: the entities are the
     * description, and what is missing is worked out by comparing them against what is there.
     */
    public function theTablesComeFromTheEntities()
    {
        $connection = $this->connection();
        (new Migrator($connection))->migrate(self::BLOG);

        $tables = (new SqliteIntrospector($connection))->tables();
        sort($tables);

        $this->assertEqual->equal(['comments', 'posts', 'profiles', 'users'], $tables);
    }

    /**
     * The whole point: nobody writes an index. A relation says one column holds another
     * table's id, which is all the information needed to index it — and a relation without
     * an index is a table scan on every lookup nobody remembers until it is slow.
     */
    public function everyRelationIsIndexedWithoutBeingAskedFor()
    {
        $connection = $this->connection();
        (new Migrator($connection))->migrate(self::BLOG);

        $introspector = new SqliteIntrospector($connection);

        $this->assertBoolean->isTrue(in_array('posts_user_id_index', $introspector->indexes('posts'), true));
        $this->assertBoolean->isTrue(in_array('comments_post_id_index', $introspector->indexes('comments'), true));
        $this->assertBoolean->isTrue(in_array('profiles_user_id_index', $introspector->indexes('profiles'), true));
    }

    /**
     * And nobody writes a foreign key either, so a column holding another table's id is
     * never left free to hold one that is not there.
     */
    public function everyRelationIsConstrainedToo()
    {
        $connection = $this->connection();
        (new Migrator($connection))->migrate(self::BLOG);

        $introspector = new SqliteIntrospector($connection);

        $this->assertEqual->equal(['user_id'], $introspector->foreignKeyColumns('posts'));
        $this->assertEqual->equal(['post_id'], $introspector->foreignKeyColumns('comments'));
    }

    /**
     * The constraint is not decoration: a row pointing at nothing is refused.
     */
    public function aRowPointingAtNothingIsRefused()
    {
        $connection = $this->connection();
        (new Migrator($connection))->migrate(self::BLOG);
        $connection->execute('PRAGMA foreign_keys = ON');

        $refused = false;

        try {
            $connection->table('posts')->insert(['user_id' => 999, 'title' => 'Nowhere']);
        } catch (\Throwable) {
            $refused = true;
        }

        $this->assertBoolean->isTrue($refused);
    }

    /**
     * What the entities describe is what the tables hold, so writing and reading works
     * straight afterwards with nothing else done.
     */
    public function theSchemaIsTheOneTheOrmNeeds()
    {
        $connection = $this->connection();
        (new Migrator($connection))->migrate(self::BLOG);

        $orm = new Orm($connection);
        $users = $orm->repository(User::class);
        $user = $users->save(new User(email: 'ada@example.com'));

        $orm->repository(Post::class)->save(new Post(userId: $user->id, title: 'Hello'));

        $found = $users->all()[0];

        $this->assertEqual->equal('ada@example.com', $found->email);
        $this->assertEqual->equal(['Hello'], array_map(
            static fn (Post $post): string => $post->title,
            $found->posts->all()
        ));
    }

    /**
     * Running it again finds nothing to do, which is what makes it safe to run on every
     * deploy.
     */
    public function runningItTwiceChangesNothing()
    {
        $connection = $this->connection();
        $migrator = new Migrator($connection);
        $migrator->migrate(self::BLOG);

        $this->assertBoolean->isTrue($migrator->plan(self::BLOG)->isEmpty());
        $this->assertEqual->equal(0, $migrator->apply($migrator->plan(self::BLOG)));
    }

    /**
     * A column the entities gained is added to a table which is already there.
     */
    public function aNewColumnIsAdded()
    {
        $connection = $this->connection();
        $connection->execute('CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, email TEXT NOT NULL)');

        $plan = (new Migrator($connection))->migrate([User::class]);

        $this->assertBoolean->isTrue(in_array('active', (new SqliteIntrospector($connection))->columns('users'), true));
        $this->assertBoolean->isTrue(count($plan->statements) > 0);
    }

    /**
     * A column the entities no longer mention is reported and left alone. A renamed property
     * looks exactly like a deleted one, and the difference matters rather a lot when the
     * answer is data.
     */
    public function nothingIsEverRemoved()
    {
        $connection = $this->connection();
        $connection->execute('CREATE TABLE users (
            id INTEGER PRIMARY KEY AUTOINCREMENT, email TEXT NOT NULL,
            active INTEGER NOT NULL, forgotten TEXT NULL)');

        $plan = (new Migrator($connection))->plan([User::class]);

        $this->assertBoolean->isTrue($plan->isEmpty());
        $this->assertEqual->equal(1, count($plan->warnings));
        $this->assertBoolean->isTrue(str_contains($plan->warnings[0], 'users.forgotten'));

        (new Migrator($connection))->apply($plan);

        $this->assertBoolean->isTrue(in_array('forgotten', (new SqliteIntrospector($connection))->columns('users'), true));
    }

    /**
     * SQLite cannot add a foreign key to a table that already exists. Saying so beats
     * running something which quietly does nothing.
     */
    public function whatTheDatabaseCannotDoIsSaidRatherThanPretended()
    {
        $connection = $this->connection();
        $connection->execute('CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, email TEXT NOT NULL, active INTEGER NOT NULL)');
        $connection->execute('CREATE TABLE posts (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, title TEXT NOT NULL)');

        $plan = (new Migrator($connection))->plan([User::class, Post::class]);
        $warnings = implode("\n", $plan->warnings);

        $this->assertBoolean->isTrue(str_contains($warnings, 'posts.user_id'));
        $this->assertBoolean->isTrue(str_contains($warnings, 'foreign key'));
    }
}
