<?php

declare(strict_types=1);

namespace Quillstack\Orm\Tests\Unit;

use Quillstack\Orm\Exceptions\EntityNotManagedException;
use Quillstack\Orm\Tests\Mocks\Blog;
use Quillstack\Orm\Tests\Mocks\Entities\User;
use Quillstack\UnitTests\AssertEqual;
use Quillstack\UnitTests\AssertExceptions;
use Quillstack\UnitTests\Types\AssertBoolean;

class TestRepository
{
    public function __construct(
        private AssertEqual $assertEqual,
        private AssertBoolean $assertBoolean,
        private AssertExceptions $assertExceptions
    ) {
        //
    }

    public function findingOneById()
    {
        $user = Blog::orm(users: 3)->repository(User::class)->find(2);

        $this->assertEqual->equal(2, $user?->id);
        $this->assertEqual->equal('user2@example.com', $user?->email);
    }

    public function findingNothing()
    {
        $this->assertBoolean->isTrue(
            Blog::orm(users: 1)->repository(User::class)->find(99) === null
        );
    }

    /**
     * A few by id is one query rather than one each.
     */
    public function findingSeveralAtOnce()
    {
        $orm = Blog::orm(users: 5);
        $connection = $orm->connection();

        $before = $connection->queryCount();
        $users = $orm->repository(User::class)->findMany([1, 3, 5]);

        $this->assertEqual->equal(3, count($users));
        $this->assertEqual->equal(1, $connection->queryCount() - $before);
    }

    /**
     * The same row read twice is the same object, so `===` answers what a person means by it.
     */
    public function theSameRowIsTheSameObject()
    {
        $repository = Blog::orm(users: 2)->repository(User::class);

        $this->assertBoolean->isTrue($repository->find(1) === $repository->find(1));
    }

    public function askingWithAQueryOfYourOwn()
    {
        $orm = Blog::orm(users: 4);
        $repository = $orm->repository(User::class);

        $users = $repository->get(
            $repository->query()->where('email', 'LIKE', 'user1%')->orderBy('id')
        );

        $this->assertEqual->equal(1, count($users));
        $this->assertEqual->equal('user1@example.com', $users[0]->email);
    }

    public function writingSomethingNew()
    {
        $orm = Blog::orm(users: 1);
        $repository = $orm->repository(User::class);

        $user = $repository->save(new User(email: 'ada@example.com'));

        $this->assertEqual->equal(2, $user->id);
        $this->assertEqual->equal('ada@example.com', $repository->find(2)?->email);
        $this->assertEqual->equal(2, $repository->count());
    }

    public function changingSomethingThatIsThere()
    {
        $orm = Blog::orm(users: 2);
        $repository = $orm->repository(User::class);

        $user = $repository->find(1);
        $user->email = 'changed@example.com';
        $repository->save($user);

        $orm->identityMap()->clear();

        $this->assertEqual->equal('changed@example.com', $repository->find(1)?->email);
        $this->assertEqual->equal(2, $repository->count());
    }

    public function removingSomething()
    {
        $orm = Blog::orm(users: 2);
        $repository = $orm->repository(User::class);
        $user = $repository->find(1);

        $this->assertBoolean->isTrue($repository->delete($user));
        $this->assertEqual->equal(1, $repository->count());
        $this->assertBoolean->isTrue($repository->find(1) === null);
    }

    public function removingSomethingNeverWrittenDoesNothing()
    {
        $repository = Blog::orm(users: 1)->repository(User::class);

        $this->assertBoolean->isFalse($repository->delete(new User(email: 'nobody@example.com')));
        $this->assertEqual->equal(1, $repository->count());
    }

    /**
     * An entity built by hand has no result set behind it, so its relations have nothing to
     * load from. Saying so beats quietly answering that there is nothing there.
     */
    public function relationsOfAnUnmanagedEntitySaySo()
    {
        $this->assertExceptions->expect(EntityNotManagedException::class);

        (new User(email: 'ada@example.com'))->posts->all();
    }

    public function aReferenceOfAnUnmanagedEntitySaysSoToo()
    {
        $this->assertExceptions->expect(EntityNotManagedException::class);

        (new User(email: 'ada@example.com'))->profile->get();
    }
}
