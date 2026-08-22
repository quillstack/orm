<?php

declare(strict_types=1);

namespace Quillstack\Orm\Tests\Mocks;

use Quillstack\Db\Connection;
use Quillstack\Orm\Orm;

/**
 * A small blog held in memory: users, what they wrote, and what people said about it.
 */
class Blog
{
    public static function orm(int $users = 3, int $postsEach = 2, int $commentsEach = 2): Orm
    {
        $connection = new Connection('sqlite::memory:');

        $connection->execute('CREATE TABLE users (
            id INTEGER PRIMARY KEY AUTOINCREMENT, email TEXT NOT NULL, active INTEGER NOT NULL DEFAULT 1)');
        $connection->execute('CREATE TABLE posts (
            id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, title TEXT NOT NULL)');
        $connection->execute('CREATE TABLE comments (
            id INTEGER PRIMARY KEY AUTOINCREMENT, post_id INTEGER NOT NULL, body TEXT NOT NULL)');
        $connection->execute('CREATE TABLE profiles (
            id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, bio TEXT NOT NULL)');

        for ($user = 1; $user <= $users; ++$user) {
            $connection->table('users')->insert(['email' => "user{$user}@example.com", 'active' => 1]);
            $connection->table('profiles')->insert(['user_id' => $user, 'bio' => "About user {$user}"]);

            for ($post = 1; $post <= $postsEach; ++$post) {
                $connection->table('posts')->insert(['user_id' => $user, 'title' => "Post {$post} of {$user}"]);
                $postId = (int) $connection->lastInsertId();

                for ($comment = 1; $comment <= $commentsEach; ++$comment) {
                    $connection->table('comments')->insert([
                        'post_id' => $postId,
                        'body' => "Comment {$comment}",
                    ]);
                }
            }
        }

        return new Orm($connection);
    }
}
