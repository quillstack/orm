<?php

declare(strict_types=1);

namespace Quillstack\Orm\Tests\Unit;

use Quillstack\Db\Connection;
use Quillstack\Orm\Migration\Migrator;
use Quillstack\Orm\Orm;
use Quillstack\Orm\Tests\Mocks\Entities\Post;
use Quillstack\Orm\Tests\Mocks\Entities\Profile;
use Quillstack\Orm\Tests\Mocks\Entities\User;
use Quillstack\UnitTests\AssertEqual;
use Quillstack\UnitTests\Types\AssertBoolean;
use RuntimeException;

class TestUnitOfWork
{
    public function __construct(
        private AssertEqual $assertEqual,
        private AssertBoolean $assertBoolean
    ) {
        //
    }

    private function orm(): Orm
    {
        $connection = new Connection('sqlite::memory:');
        (new Migrator($connection))->migrate([User::class, Post::class, Profile::class]);

        return new Orm($connection);
    }

    /**
     * A hundred entities saved one at a time is a hundred round trips to a database which
     * would have taken them together.
     */
    public function manyEntitiesAreWrittenInOneStatement()
    {
        $orm = $this->orm();
        $connection = $orm->connection();

        foreach (range(1, 100) as $i) {
            $orm->persist(new User(email: "user{$i}@example.com"));
        }

        $before = $connection->queryCount();

        $this->assertEqual->equal(100, $orm->flush());

        // Two values a row, nine hundred to a statement: all hundred of them at once.
        $this->assertEqual->equal(1, $connection->queryCount() - $before);
        $this->assertEqual->equal(100, $orm->repository(User::class)->count());
    }

    /**
     * Each of them is told the id its row was given.
     */
    public function everyOneOfThemLearnsItsId()
    {
        $orm = $this->orm();
        $users = [];

        foreach (range(1, 5) as $i) {
            $users[] = $user = new User(email: "user{$i}@example.com");
            $orm->persist($user);
        }

        $orm->flush();

        $this->assertEqual->equal([1, 2, 3, 4, 5], array_map(
            static fn (User $user): ?int => $user->id,
            $users
        ));
    }

    /**
     * A row pointing at another cannot be written before the one it points at exists, so the
     * order the work is queued in does not have to be the order it is written in.
     */
    public function whatIsPointedAtIsWrittenFirst()
    {
        $orm = $this->orm();
        $user = new User(email: 'ada@example.com');

        // Queued the wrong way round on purpose.
        $orm->persist($user);
        $orm->flush();

        $orm->persist(new Post(userId: $user->id, title: 'Hello'));
        $orm->persist(new User(email: 'grace@example.com'));

        $this->assertEqual->equal(2, $orm->flush());
        $this->assertEqual->equal(1, $orm->repository(Post::class)->count());
        $this->assertEqual->equal(2, $orm->repository(User::class)->count());
    }

    /**
     * All of it or none of it: a failure half way through leaves nothing behind.
     */
    public function aFailureLeavesNothingBehind()
    {
        $orm = $this->orm();
        $connection = $orm->connection();

        $orm->persist(new User(email: 'ada@example.com'));
        $orm->persist(new Post(userId: 999, title: 'Points at nobody'));

        $connection->execute('PRAGMA foreign_keys = ON');
        $failed = false;

        try {
            $orm->flush();
        } catch (\Throwable) {
            $failed = true;
        }

        $this->assertBoolean->isTrue($failed);
        $this->assertEqual->equal(0, $orm->repository(User::class)->count());
        $this->assertEqual->equal(0, $connection->transactionDepth());
    }

    /**
     * Something already written is changed rather than written again.
     */
    public function whatIsAlreadyThereIsUpdated()
    {
        $orm = $this->orm();
        $users = $orm->repository(User::class);

        $orm->persist(new User(email: 'ada@example.com'));
        $orm->flush();

        $user = $users->find(1);
        $user->email = 'changed@example.com';
        $orm->persist($user)->flush();

        $orm->identityMap()->clear();

        $this->assertEqual->equal(1, $users->count());
        $this->assertEqual->equal('changed@example.com', $users->find(1)?->email);
    }

    /**
     * Removing many is one statement too, and what points at something else goes first.
     */
    public function manyAreRemovedInOneStatement()
    {
        $orm = $this->orm();
        $connection = $orm->connection();

        foreach (range(1, 10) as $i) {
            $orm->persist(new User(email: "user{$i}@example.com"));
        }

        $orm->flush();
        $users = $orm->repository(User::class)->all();

        foreach ($users as $user) {
            $orm->remove($user);
        }

        $before = $connection->queryCount();

        $this->assertEqual->equal(10, $orm->flush());
        $this->assertEqual->equal(1, $connection->queryCount() - $before);
        $this->assertEqual->equal(0, $orm->repository(User::class)->count());
    }

    public function flushingNothingDoesNothing()
    {
        $orm = $this->orm();
        $connection = $orm->connection();
        $before = $connection->queryCount();

        $this->assertEqual->equal(0, $orm->flush());
        $this->assertEqual->equal(0, $connection->queryCount() - $before);
    }

    /**
     * What was written is not written twice.
     */
    public function flushingTwiceWritesOnce()
    {
        $orm = $this->orm();
        $orm->persist(new User(email: 'ada@example.com'));

        $this->assertEqual->equal(1, $orm->flush());
        $this->assertEqual->equal(0, $orm->flush());
        $this->assertEqual->equal(1, $orm->repository(User::class)->count());
    }
}
