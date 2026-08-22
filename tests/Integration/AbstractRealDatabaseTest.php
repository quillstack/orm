<?php

declare(strict_types=1);

namespace Quillstack\Orm\Tests\Integration;

use Quillstack\Db\Connection;
use Quillstack\Orm\Migration\Migrator;
use Quillstack\Orm\Orm;
use Quillstack\Orm\Schema\Introspector;
use Quillstack\Orm\Tests\Mocks\Entities\Comment;
use Quillstack\Orm\Tests\Mocks\Entities\Post;
use Quillstack\Orm\Tests\Mocks\Entities\Profile;
use Quillstack\Orm\Tests\Mocks\Entities\Tag;
use Quillstack\Orm\Tests\Mocks\Entities\User;
use Quillstack\UnitTests\AssertEqual;
use Quillstack\UnitTests\Types\AssertBoolean;

/**
 * Everything the schema does, against a database which is really there.
 *
 * SQLite answers most of it, but not the parts where databases differ most: reading an
 * existing schema back, what a foreign key is called, and whether a schema change can be
 * undone at all.
 */
abstract class AbstractRealDatabaseTest
{
    /**
     * @var array<int, class-string>
     */
    protected const ENTITIES = [User::class, Post::class, Comment::class, Profile::class, Tag::class];

    public function __construct(
        protected AssertEqual $assertEqual,
        protected AssertBoolean $assertBoolean
    ) {
        //
    }

    abstract protected function driver(): string;

    abstract protected function introspector(Connection $connection): Introspector;

    protected function connection(): Connection
    {
        $connection = RealDatabase::connection($this->driver());
        RealDatabase::clean($connection);

        return $connection;
    }

    public function theSchemaIsBuiltFromTheEntities()
    {
        $connection = $this->connection();
        (new Migrator($connection))->migrate(self::ENTITIES);

        $tables = $this->introspector($connection)->tables();
        sort($tables);

        $this->assertEqual->equal(
            ['comments', 'post_tag', 'posts', 'profiles', 'tags', 'users'],
            $tables
        );
    }

    /**
     * The one SQLite could never answer: reading an existing schema back is where every
     * database describes itself differently.
     */
    public function runningItAgainFindsNothingToDo()
    {
        $connection = $this->connection();
        $migrator = new Migrator($connection);
        $migrator->migrate(self::ENTITIES);

        $this->assertBoolean->isTrue($migrator->plan(self::ENTITIES)->isEmpty());
    }

    public function everyRelationIsIndexedAndConstrained()
    {
        $connection = $this->connection();
        (new Migrator($connection))->migrate(self::ENTITIES);
        $introspector = $this->introspector($connection);

        $this->assertEqual->equal(['user_id'], $introspector->foreignKeyColumns('posts'));
        $this->assertEqual->equal(['post_id'], $introspector->foreignKeyColumns('comments'));

        $pivot = $introspector->foreignKeyColumns('post_tag');
        sort($pivot);
        $this->assertEqual->equal(['post_id', 'tag_id'], $pivot);

        $this->assertBoolean->isTrue(
            in_array('posts_user_id_index', $introspector->indexes('posts'), true)
        );
    }

    /**
     * A row pointing at nothing is refused — the constraint is not decoration.
     */
    public function aRowPointingAtNothingIsRefused()
    {
        $connection = $this->connection();
        (new Migrator($connection))->migrate(self::ENTITIES);

        $refused = false;

        try {
            $connection->table('posts')->insert(['user_id' => 999, 'title' => 'Nowhere']);
        } catch (\Throwable) {
            $refused = true;
        }

        $this->assertBoolean->isTrue($refused);
    }

    public function aColumnTheEntitiesGainedIsAdded()
    {
        $connection = $this->connection();
        (new Migrator($connection))->migrate([User::class]);
        $connection->execute(
            'ALTER TABLE ' . $connection->dialect()->quoteIdentifier('users')
            . ' DROP COLUMN ' . $connection->dialect()->quoteIdentifier('active')
        );

        (new Migrator($connection))->migrate([User::class]);

        $this->assertBoolean->isTrue(
            in_array('active', $this->introspector($connection)->columns('users'), true)
        );
    }

    /**
     * Everything the ORM promises, against this database rather than SQLite: ids after a
     * batch write, and one query for a whole set of relations.
     */
    public function writingAndReadingWorkTheSameHere()
    {
        $connection = $this->connection();
        (new Migrator($connection))->migrate(self::ENTITIES);
        $orm = new Orm($connection);

        foreach (range(1, 20) as $i) {
            $orm->persist(new User(email: "user{$i}@example.com"));
        }

        $orm->flush();
        $users = $orm->repository(User::class)->all();

        $this->assertEqual->equal(20, count($users));
        $this->assertEqual->equal(20, count(array_unique(array_map(
            static fn (User $user): ?int => $user->id,
            $users
        ))));

        foreach ($users as $user) {
            $orm->persist(new Post(userId: $user->id, title: "Post of {$user->id}"));
        }

        $orm->flush();
        $orm->identityMap()->clear();

        $before = $connection->queryCount();
        $titles = [];

        foreach ($orm->repository(User::class)->all() as $user) {
            foreach ($user->posts as $post) {
                $titles[] = $post->title;
            }
        }

        $this->assertEqual->equal(20, count($titles));
        $this->assertEqual->equal(2, $connection->queryCount() - $before);
    }
}
