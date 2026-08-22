<?php

declare(strict_types=1);

namespace Quillstack\Orm\Tests\Unit;

use Quillstack\Db\Connection;
use Quillstack\Orm\Exceptions\NotAnEntityException;
use Quillstack\Orm\Exceptions\UnknownColumnException;
use Quillstack\Orm\Metadata\MetadataFactory;
use Quillstack\Orm\Orm;
use Quillstack\Orm\Tests\Mocks\Blog;
use Quillstack\Orm\Tests\Mocks\Entities\NotAnEntity;
use Quillstack\Orm\Tests\Mocks\Entities\Post;
use Quillstack\Orm\Tests\Mocks\Entities\Tag;
use Quillstack\Orm\Tests\Mocks\Entities\User;
use Quillstack\Orm\Tests\Mocks\Entities\WithoutAnId;
use Quillstack\UnitTests\AssertEqual;
use Quillstack\UnitTests\AssertExceptions;
use Quillstack\UnitTests\Types\AssertBoolean;

class TestMetadata
{
    public function __construct(
        private AssertEqual $assertEqual,
        private AssertBoolean $assertBoolean,
        private AssertExceptions $assertExceptions
    ) {
        //
    }

    public function whatIsKnownAboutAnEntity()
    {
        $metadata = (new MetadataFactory())->for(User::class);

        $this->assertEqual->equal('users', $metadata->table);
        $this->assertEqual->equal('id', $metadata->id->column);
        $this->assertEqual->equal(['id', 'email', 'active'], $metadata->columnNames());
        $this->assertEqual->equal(['posts', 'profile'], array_keys($metadata->associations));
    }

    /**
     * A column named on the attribute wins over the one the property would give.
     */
    public function aColumnCanBeNamedItself()
    {
        $metadata = (new MetadataFactory())->for(Post::class);

        $this->assertEqual->equal('userId', $metadata->propertyForColumn('user_id'));
    }

    /**
     * Reflection is not cheap enough to do per row, and an entity does not change while the
     * process runs.
     */
    public function anEntityIsOnlyReadOnce()
    {
        $factory = new MetadataFactory();

        $this->assertBoolean->isTrue($factory->for(User::class) === $factory->for(User::class));
    }

    public function aClassWithoutATableSaysSo()
    {
        $this->assertExceptions->expect(NotAnEntityException::class);

        (new MetadataFactory())->for(NotAnEntity::class);
    }

    public function aClassWithoutAnIdSaysSo()
    {
        $this->assertExceptions->expect(NotAnEntityException::class);

        (new MetadataFactory())->for(WithoutAnId::class);
    }

    public function askingForAColumnThatIsNotThereSaysSo()
    {
        $this->assertExceptions->expect(UnknownColumnException::class);

        (new MetadataFactory())->for(User::class)->propertyForColumn('nonsense');
    }

    /**
     * An entity written with plain properties rather than a constructor works the same way.
     */
    public function anEntityWithoutAConstructor()
    {
        $connection = new Connection('sqlite::memory:');
        $connection->execute('CREATE TABLE tags (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)');

        $repository = (new Orm($connection))->repository(Tag::class);

        $tag = new Tag();
        $tag->name = 'php';
        $repository->save($tag);

        $this->assertEqual->equal(1, $tag->id);
        $this->assertEqual->equal('php', $repository->find(1)?->name);
    }

    public function askingWhetherARelationHoldsAnything()
    {
        $orm = Blog::orm(users: 2, postsEach: 1);
        $users = $orm->repository(User::class)->all();

        $this->assertBoolean->isFalse($users[0]->posts->isEmpty());
        $this->assertBoolean->isTrue($users[0]->profile->isPresent());
        $this->assertEqual->equal(1, $users[0]->posts->count());
    }

    /**
     * A relation with nobody on the other side is empty rather than a failure.
     */
    public function aRelationWithNothingOnTheOtherSide()
    {
        $orm = Blog::orm(users: 1, postsEach: 0);
        $user = $orm->repository(User::class)->all()[0];

        $this->assertBoolean->isTrue($user->posts->isEmpty());
        $this->assertEqual->equal([], $user->posts->all());
    }
}
