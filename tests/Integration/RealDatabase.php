<?php

declare(strict_types=1);

namespace Quillstack\Orm\Tests\Integration;

use Quillstack\Db\Connection;

/**
 * The databases this suite can reach.
 *
 * SQLite needs nothing and is always there. MySQL and PostgreSQL are only tested where one is
 * running: `docker compose up -d` and the environment says where. Without them these tests do
 * not run at all rather than passing quietly, which is why they are listed conditionally.
 */
class RealDatabase
{
    public static function dsn(string $driver): ?string
    {
        $dsn = getenv('QUILLSTACK_' . strtoupper($driver) . '_DSN');

        return is_string($dsn) && $dsn !== '' ? $dsn : null;
    }

    public static function isAvailable(string $driver): bool
    {
        return self::dsn($driver) !== null;
    }

    public static function connection(string $driver): Connection
    {
        $user = getenv('QUILLSTACK_DB_USER');
        $password = getenv('QUILLSTACK_DB_PASSWORD');

        return new Connection(
            (string) self::dsn($driver),
            is_string($user) && $user !== '' ? $user : null,
            is_string($password) && $password !== '' ? $password : null
        );
    }

    /**
     * Nothing left over from the run before, in the order the keys allow.
     */
    public static function clean(Connection $connection): void
    {
        foreach (['post_tag', 'comments', 'posts', 'profiles', 'tags', 'users'] as $table) {
            $name = $connection->dialect()->quoteIdentifier($table);

            try {
                $connection->execute("DROP TABLE IF EXISTS {$name} CASCADE");
            } catch (\Throwable) {
                // MySQL has no CASCADE here; the order above is enough.
                $connection->execute("DROP TABLE IF EXISTS {$name}");
            }
        }
    }
}
