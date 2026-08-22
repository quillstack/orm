<?php

declare(strict_types=1);

namespace Quillstack\Orm\Tests\Unit;

use Quillstack\Db\Connection;
use Quillstack\Orm\Migration\Migrator;
use Quillstack\Orm\Orm;
use Quillstack\Orm\Schema\Introspection\SqliteIntrospector;
use Quillstack\Orm\Tests\Mocks\Entities\Post;
use Quillstack\Orm\Tests\Mocks\Entities\Tag;
use Quillstack\Orm\Tests\Mocks\Entities\User;
use Quillstack\UnitTests\AssertEqual;
use Quillstack\UnitTests\Types\AssertBoolean;

class TestManyToMany
{
    public function __construct(
        private AssertEqual $assertEqual,
        private AssertBoolean $assertBoolean
    ) {
        //
    }

    /**
     * @param int $posts how many posts, each carrying `php` and one of the other two
     */
    private function orm(int $posts = 20): Orm
    {
        $connection = new Connection('sqlite::memory:');
        (new Migrator($connection))->migrate([User::class, Post::class, Tag::class]);

        foreach (['php', 'sql', 'http'] as $name) {
            $connection->table('tags')->insert(['name' => $name]);
        }

        $connection->table('users')->insert(['email' => 'ada@example.com', 'active' => 1]);

        for ($post = 1; $post <= $posts; ++$post) {
            $connection->table('posts')->insert(['user_id' => 1, 'title' => "Post {$post}"]);
            $connection->table('post_tag')->insert(['post_id' => $post, 'tag_id' => 1]);
            $connection->table('post_tag')->insert(['post_id' => $post, 'tag_id' => ($post % 2) + 2]);
        }

        return new Orm($connection);
    }

    /**
     * The table in between is nothing an entity describes, and nothing anybody should have
     * to write: the relation is enough to say what it holds.
     */
    public function theTableInBetweenIsMadeFromTheRelation()
    {
        $connection = new Connection('sqlite::memory:');
        (new Migrator($connection))->migrate([User::class, Post::class, Tag::class]);

        $introspector = new SqliteIntrospector($connection);
        $columns = $introspector->columns('post_tag');
        sort($columns);

        $this->assertBoolean->isTrue(in_array('post_tag', $introspector->tables(), true));
        $this->assertEqual->equal(['post_id', 'tag_id'], $columns);
    }

    /**
     * Both of its columns are indexed and constrained, and the pair cannot repeat.
     */
    public function bothSidesAreIndexedAndConstrained()
    {
        $connection = new Connection('sqlite::memory:');
        (new Migrator($connection))->migrate([User::class, Post::class, Tag::class]);

        $introspector = new SqliteIntrospector($connection);
        $keys = $introspector->foreignKeyColumns('post_tag');
        sort($keys);

        $this->assertEqual->equal(['post_id', 'tag_id'], $keys);
        $this->assertBoolean->isTrue(
            in_array('post_tag_post_id_tag_id_unique', $introspector->indexes('post_tag'), true)
        );
        $this->assertBoolean->isTrue(
            in_array('post_tag_tag_id_index', $introspector->indexes('post_tag'), true)
        );
    }

    public function theSamePairCannotBeWrittenTwice()
    {
        $connection = new Connection('sqlite::memory:');
        (new Migrator($connection))->migrate([User::class, Post::class, Tag::class]);
        $connection->table('tags')->insert(['name' => 'php']);
        $connection->table('users')->insert(['email' => 'ada@example.com', 'active' => 1]);
        $connection->table('posts')->insert(['user_id' => 1, 'title' => 'Hello']);
        $connection->table('post_tag')->insert(['post_id' => 1, 'tag_id' => 1]);

        $refused = false;

        try {
            $connection->table('post_tag')->insert(['post_id' => 1, 'tag_id' => 1]);
        } catch (\Throwable) {
            $refused = true;
        }

        $this->assertBoolean->isTrue($refused);
    }

    /**
     * One step longer than the others, and the same promise: the pairs first, then everything
     * they point at. Twenty posts and their tags is three queries, not twenty-one.
     */
    public function goingThroughATableInBetweenStillBatches()
    {
        $orm = $this->orm(posts: 20);
        $connection = $orm->connection();

        $before = $connection->queryCount();
        $names = [];

        foreach ($orm->repository(Post::class)->all() as $post) {
            foreach ($post->tags as $tag) {
                $names[] = $tag->name;
            }
        }

        $this->assertEqual->equal(40, count($names));
        $this->assertEqual->equal(3, $connection->queryCount() - $before);
    }

    public function theTagsAreTheRightOnes()
    {
        $orm = $this->orm(posts: 3);
        $posts = $orm->repository(Post::class)->all();

        $first = array_map(static fn (Tag $tag): string => $tag->name, $posts[0]->tags->all());
        $second = array_map(static fn (Tag $tag): string => $tag->name, $posts[1]->tags->all());
        sort($first);
        sort($second);

        $this->assertEqual->equal(['http', 'php'], $first);
        $this->assertEqual->equal(['php', 'sql'], $second);
    }

    /**
     * The same tag on two posts is the same object, so the identity map holds across the
     * table in between as well.
     */
    public function aTagSharedByTwoPostsIsOneObject()
    {
        $orm = $this->orm(posts: 3);
        $posts = $orm->repository(Post::class)->all();

        $this->assertBoolean->isTrue($posts[0]->tags->all()[0] === $posts[1]->tags->all()[0]);
    }

    public function aPostWithNoTagsHasNone()
    {
        $connection = new Connection('sqlite::memory:');
        (new Migrator($connection))->migrate([User::class, Post::class, Tag::class]);
        $connection->table('users')->insert(['email' => 'ada@example.com', 'active' => 1]);
        $connection->table('posts')->insert(['user_id' => 1, 'title' => 'Hello']);

        $post = (new Orm($connection))->repository(Post::class)->all()[0];

        $this->assertBoolean->isTrue($post->tags->isEmpty());
    }
}
