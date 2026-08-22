<?php

declare(strict_types=1);

namespace Quillstack\Orm\Tests\Unit;

use Quillstack\Db\Query\Query;
use Quillstack\Orm\Exceptions\UnknownRelationException;
use Quillstack\Orm\Tests\Mocks\Blog;
use Quillstack\Orm\Tests\Mocks\Entities\Post;
use Quillstack\Orm\Tests\Mocks\Entities\User;
use Quillstack\UnitTests\AssertEqual;
use Quillstack\UnitTests\AssertExceptions;
use Quillstack\UnitTests\Types\AssertBoolean;

class TestReading
{
    public function __construct(
        private AssertEqual $assertEqual,
        private AssertBoolean $assertBoolean,
        private AssertExceptions $assertExceptions
    ) {
        //
    }

    /**
     * Two queries however large the page: one to count, one to read.
     */
    public function onePageAndHowManyThereAre()
    {
        $orm = Blog::orm(users: 25);
        $connection = $orm->connection();
        $repository = $orm->repository(User::class);

        $before = $connection->queryCount();
        $page = $repository->page(page: 2, perPage: 10);

        $this->assertEqual->equal(2, $connection->queryCount() - $before);
        $this->assertEqual->equal(10, count($page->items));
        $this->assertEqual->equal(25, $page->total);
        $this->assertEqual->equal(3, $page->pages());
        $this->assertBoolean->isTrue($page->hasMore());
        $this->assertEqual->equal('user11@example.com', $page->items[0]->email);
    }

    public function theLastPageHasNoMoreAfterIt()
    {
        $page = Blog::orm(users: 25)->repository(User::class)->page(page: 3, perPage: 10);

        $this->assertEqual->equal(5, count($page->items));
        $this->assertBoolean->isFalse($page->hasMore());
    }

    /**
     * Nothing to count is nothing to read: no second query for a page that cannot have
     * anything on it.
     */
    public function anEmptyTableIsOneQuery()
    {
        $orm = Blog::orm(users: 0);
        $connection = $orm->connection();

        $before = $connection->queryCount();
        $page = $orm->repository(User::class)->page();

        $this->assertEqual->equal(1, $connection->queryCount() - $before);
        $this->assertBoolean->isTrue($page->isEmpty());
        $this->assertEqual->equal(0, $page->pages());
    }

    /**
     * The entities on a page share a result set, so their relations still load for the whole
     * page at once.
     */
    public function relationsStillBatchAcrossAPage()
    {
        $orm = Blog::orm(users: 50, postsEach: 2);
        $connection = $orm->connection();
        $page = $orm->repository(User::class)->page(perPage: 20);

        $before = $connection->queryCount();
        $titles = [];

        foreach ($page as $user) {
            foreach ($user->posts as $post) {
                $titles[] = $post->title;
            }
        }

        $this->assertEqual->equal(40, count($titles));
        $this->assertEqual->equal(1, $connection->queryCount() - $before);
    }

    /**
     * Asked as a question about the relation rather than about the columns behind it: which
     * way round the join goes is already written on the entity.
     */
    public function keepingOnlyWhatHasSomething()
    {
        $orm = Blog::orm(users: 3, postsEach: 0);
        $connection = $orm->connection();
        $connection->table('posts')->insert(['user_id' => 2, 'title' => 'Only one']);

        $repository = $orm->repository(User::class);

        $this->assertEqual->equal(
            ['user2@example.com'],
            array_map(
                static fn (User $user): string => $user->email,
                $repository->get($repository->whereHas('posts'))
            )
        );
    }

    public function narrowingWhatCounts()
    {
        $orm = Blog::orm(users: 0);
        $connection = $orm->connection();

        foreach ([1, 2] as $id) {
            $connection->table('users')->insert(['email' => "user{$id}@example.com", 'active' => 1]);
        }

        $connection->table('posts')->insert(['user_id' => 1, 'title' => 'About php']);
        $connection->table('posts')->insert(['user_id' => 2, 'title' => 'About cats']);

        $repository = $orm->repository(User::class);
        $found = $repository->get($repository->whereHas(
            'posts',
            static fn (Query $q): Query => $q->where('title', 'LIKE', '%php%')
        ));

        $this->assertEqual->equal(['user1@example.com'], array_map(
            static fn (User $user): string => $user->email,
            $found
        ));
    }

    public function theOtherWayRound()
    {
        $orm = Blog::orm(users: 3, postsEach: 0);
        $orm->connection()->table('posts')->insert(['user_id' => 2, 'title' => 'Only one']);

        $repository = $orm->repository(User::class);
        $without = $repository->get($repository->whereDoesntHave('posts')->orderBy('id'));

        $this->assertEqual->equal(
            ['user1@example.com', 'user3@example.com'],
            array_map(static fn (User $user): string => $user->email, $without)
        );
    }

    /**
     * A relation pointing the other way is asked the same way.
     */
    public function aRelationPointingBackIsAskedTheSameWay()
    {
        $orm = Blog::orm(users: 2, postsEach: 1);
        $orm->connection()->table('posts')->insert(['user_id' => 99, 'title' => 'Orphan']);

        $repository = $orm->repository(Post::class);

        $this->assertEqual->equal(2, count($repository->get($repository->whereHas('user'))));
        $this->assertEqual->equal(1, count($repository->get($repository->whereDoesntHave('user'))));
    }

    /**
     * Through a table in between, the same question.
     */
    public function throughATableInBetween()
    {
        $orm = Blog::orm(users: 1, postsEach: 3);
        $connection = $orm->connection();
        $connection->execute('CREATE TABLE tags (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)');
        $connection->execute('CREATE TABLE post_tag (post_id INTEGER NOT NULL, tag_id INTEGER NOT NULL)');
        $connection->table('tags')->insert(['name' => 'php']);
        $connection->table('post_tag')->insert(['post_id' => 2, 'tag_id' => 1]);

        $repository = $orm->repository(Post::class);
        $tagged = $repository->get($repository->whereHas('tags'));

        $this->assertEqual->equal(1, count($tagged));
        $this->assertEqual->equal(2, $tagged[0]->id);
    }

    public function askingAboutARelationThatIsNotThere()
    {
        $repository = Blog::orm(users: 1)->repository(User::class);

        $this->assertExceptions->expect(UnknownRelationException::class);

        $repository->whereHas('nonsense');
    }

    /**
     * The two go together: a page of only what has something.
     */
    public function aPageOfOnlyWhatHasSomething()
    {
        $orm = Blog::orm(users: 30, postsEach: 0);
        $connection = $orm->connection();

        foreach (range(1, 12) as $id) {
            $connection->table('posts')->insert(['user_id' => $id, 'title' => "Post {$id}"]);
        }

        $repository = $orm->repository(User::class);
        $page = $repository->page($repository->whereHas('posts')->orderBy('id'), page: 2, perPage: 5);

        $this->assertEqual->equal(12, $page->total);
        $this->assertEqual->equal(5, count($page->items));
        $this->assertEqual->equal('user6@example.com', $page->items[0]->email);
    }
}
