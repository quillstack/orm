<?php

declare(strict_types=1);

namespace Quillstack\Orm\Tests\Unit;

use Quillstack\Orm\Tests\Mocks\Blog;
use Quillstack\Orm\Tests\Mocks\Entities\Post;
use Quillstack\Orm\Tests\Mocks\Entities\User;
use Quillstack\UnitTests\AssertEqual;

/**
 * The point of the whole thing.
 *
 * Nothing here calls `with()`, or asks for anything to be loaded ahead of time. Touching one
 * entity's relation is what loads it for every entity read beside it.
 */
class TestNoQueryPerRow
{
    public function __construct(private AssertEqual $assertEqual)
    {
        //
    }

    /**
     * Twenty users, and the posts of one of them touched: two queries. An ORM asking one per
     * row would have run twenty-one.
     */
    public function touchingOneRelationLoadsItForEverybody()
    {
        $orm = Blog::orm(users: 20, postsEach: 2);
        $connection = $orm->connection();
        $users = $orm->repository(User::class)->all();

        $before = $connection->queryCount();
        $posts = $users[0]->posts->all();

        $this->assertEqual->equal(20, count($users));
        $this->assertEqual->equal(2, count($posts));
        $this->assertEqual->equal(1, $connection->queryCount() - $before);
    }

    /**
     * And the other nineteen cost nothing, because there is nothing left to fetch.
     */
    public function theRestAreAlreadyThere()
    {
        $orm = Blog::orm(users: 20, postsEach: 2);
        $connection = $orm->connection();
        $users = $orm->repository(User::class)->all();

        $users[0]->posts->all();
        $after = $connection->queryCount();

        $titles = [];

        foreach ($users as $user) {
            foreach ($user->posts as $post) {
                $titles[] = $post->title;
            }
        }

        $this->assertEqual->equal(40, count($titles));
        $this->assertEqual->equal(0, $connection->queryCount() - $after);
    }

    /**
     * Walking further does not multiply: users, their posts, and the comments on those posts
     * is three queries whatever the sizes. One per row would have been 20 + 40 + 1 = 61.
     */
    public function walkingTwoRelationsDeepIsStillThreeQueries()
    {
        $orm = Blog::orm(users: 20, postsEach: 2, commentsEach: 3);
        $connection = $orm->connection();

        $before = $connection->queryCount();
        $bodies = [];

        foreach ($orm->repository(User::class)->all() as $user) {
            foreach ($user->posts as $post) {
                foreach ($post->comments as $comment) {
                    $bodies[] = $comment->body;
                }
            }
        }

        $this->assertEqual->equal(120, count($bodies));
        $this->assertEqual->equal(3, $connection->queryCount() - $before);
    }

    /**
     * A relation pointing the other way batches the same: forty posts, and the user of each
     * one, is two queries.
     */
    public function aRelationPointingBackBatchesToo()
    {
        $orm = Blog::orm(users: 20, postsEach: 2);
        $connection = $orm->connection();

        $before = $connection->queryCount();
        $emails = [];

        foreach ($orm->repository(Post::class)->all() as $post) {
            $emails[] = $post->user->get()?->email;
        }

        $this->assertEqual->equal(40, count($emails));
        $this->assertEqual->equal(20, count(array_unique($emails)));
        $this->assertEqual->equal(2, $connection->queryCount() - $before);
    }

    /**
     * One row on the other side is the same shape, so it batches the same way.
     */
    public function aRelationWithOneRowOnTheOtherSideBatchesToo()
    {
        $orm = Blog::orm(users: 20);
        $connection = $orm->connection();
        $users = $orm->repository(User::class)->all();

        $before = $connection->queryCount();
        $bios = [];

        foreach ($users as $user) {
            $bios[] = $user->profile->get()?->bio;
        }

        $this->assertEqual->equal(20, count(array_filter($bios)));
        $this->assertEqual->equal(1, $connection->queryCount() - $before);
    }

    /**
     * The list of ids is asked for once, however many entities want it.
     */
    public function theSameSetIsNotAskedForTwice()
    {
        $orm = Blog::orm(users: 5, postsEach: 2);
        $connection = $orm->connection();
        $users = $orm->repository(User::class)->all();

        $users[0]->posts->all();
        $after = $connection->queryCount();

        $users[1]->posts->all();
        $users[2]->posts->count();
        $users[3]->posts->first();

        $this->assertEqual->equal(0, $connection->queryCount() - $after);
    }

    /**
     * The one that got away.
     *
     * The same row read twice is the same object. An entity read once on its own kept the
     * result set it arrived in, so reading fifty of them later handed back objects which each
     * loaded their relation for one owner — ten users written and then listed was eleven
     * queries, in the very feature meant to make that impossible. Relations now follow
     * whichever set is being read.
     */
    public function anEntityAlreadyKnownJoinsTheSetItIsReadIn()
    {
        $orm = Blog::orm(users: 10, postsEach: 2);
        $connection = $orm->connection();
        $repository = $orm->repository(User::class);

        // Read one at a time first, so every one of them is already known.
        foreach (range(1, 10) as $id) {
            $repository->find($id);
        }

        $before = $connection->queryCount();
        $titles = [];

        foreach ($repository->all() as $user) {
            foreach ($user->posts as $post) {
                $titles[] = $post->title;
            }
        }

        $this->assertEqual->equal(20, count($titles));
        $this->assertEqual->equal(2, $connection->queryCount() - $before);
    }

    /**
     * And the same for entities which have just been written rather than read.
     */
    public function entitiesJustWrittenJoinTheSetToo()
    {
        $orm = Blog::orm(users: 0);
        $connection = $orm->connection();
        $users = $orm->repository(User::class);
        $posts = $orm->repository(Post::class);

        foreach (range(1, 10) as $i) {
            $user = $users->save(new User(email: "user{$i}@example.com"));
            $posts->save(new Post(userId: $user->id, title: "First of {$i}"));
            $posts->save(new Post(userId: $user->id, title: "Second of {$i}"));
        }

        $before = $connection->queryCount();
        $titles = [];

        foreach ($users->all() as $user) {
            foreach ($user->posts as $post) {
                $titles[] = $post->title;
            }
        }

        $this->assertEqual->equal(20, count($titles));
        $this->assertEqual->equal(2, $connection->queryCount() - $before);
    }

    /**
     * Writing a row is one statement. It used to be two: the row was read back to get an
     * entity whose relations worked, which everything a row holds had only just been said.
     */
    public function writingSomethingIsOneStatement()
    {
        $orm = Blog::orm(users: 0);
        $connection = $orm->connection();

        $before = $connection->queryCount();
        $orm->repository(User::class)->save(new User(email: 'ada@example.com'));

        $this->assertEqual->equal(1, $connection->queryCount() - $before);
    }
}
