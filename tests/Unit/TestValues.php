<?php

declare(strict_types=1);

namespace Quillstack\Orm\Tests\Unit;

use DateTimeImmutable;
use Quillstack\Db\Connection;
use Quillstack\Orm\Metadata\MetadataFactory;
use Quillstack\Orm\Orm;
use Quillstack\Orm\Tests\Mocks\Entities\Article;
use Quillstack\Orm\Tests\Mocks\Entities\Status;
use Quillstack\UnitTests\AssertEqual;
use Quillstack\UnitTests\Types\AssertBoolean;
use Quillstack\UnitTests\Types\AssertObject;

class TestValues
{
    public function __construct(
        private AssertEqual $assertEqual,
        private AssertBoolean $assertBoolean,
        private AssertObject $assertObject
    ) {
        //
    }

    private function orm(): Orm
    {
        $connection = new Connection('sqlite::memory:');
        $connection->execute('CREATE TABLE articles (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            title TEXT NOT NULL,
            views INTEGER NOT NULL,
            rating REAL NOT NULL,
            featured INTEGER NOT NULL,
            status TEXT NULL,
            published_at TEXT NULL)');

        return new Orm($connection);
    }

    /**
     * Drivers are not consistent about what they hand back: the same column comes as an int
     * from one and a string from another, so an entity typed `int` would be right on one
     * database and broken on the next.
     */
    public function everyKindOfValueSurvivesTheRoundTrip()
    {
        $repository = $this->orm()->repository(Article::class);

        $repository->save(new Article(
            title: 'Hello',
            views: 42,
            rating: 4.5,
            featured: true,
            status: Status::Published,
            publishedAt: new DateTimeImmutable('2026-08-22 10:30:00')
        ));

        $article = $repository->find(1);

        $this->assertEqual->equal('Hello', $article?->title);
        $this->assertEqual->equal(42, $article?->views);
        $this->assertEqual->equal(4.5, $article?->rating);
        $this->assertBoolean->isTrue($article?->featured === true);
        $this->assertObject->instanceOf(DateTimeImmutable::class, $article?->publishedAt);
        $this->assertEqual->equal('2026-08-22 10:30:00', $article?->publishedAt?->format('Y-m-d H:i:s'));
        $this->assertEqual->equal(Status::Published, $article?->status);
    }

    /**
     * `false` written as text becomes the empty string, and reading it back would give the
     * wrong answer entirely.
     */
    public function falseComesBackAsFalse()
    {
        $repository = $this->orm()->repository(Article::class);
        $repository->save(new Article(title: 'No', views: 0, rating: 0.0, featured: false));

        $this->assertBoolean->isTrue($repository->find(1)?->featured === false);
    }

    public function nothingStaysNothing()
    {
        $repository = $this->orm()->repository(Article::class);
        $repository->save(new Article(title: 'Empty'));

        $article = $repository->find(1);

        $this->assertBoolean->isTrue($article?->status === null);
        $this->assertBoolean->isTrue($article?->publishedAt === null);
    }

    /**
     * A property with no name of its own takes the column a column is usually called.
     */
    public function aPropertyNameBecomesAColumnName()
    {
        $this->assertEqual->equal('created_at', MetadataFactory::columnName('createdAt'));
        $this->assertEqual->equal('email', MetadataFactory::columnName('email'));
        $this->assertEqual->equal('http_status_code', MetadataFactory::columnName('httpStatusCode'));
    }
}
