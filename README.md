# Quillstack Orm

An ORM which cannot be asked one query per row.

Touching one entity's relation loads it for every entity read beside it, in a single
`WHERE ... IN (...)`. There is no `with()` to remember and none to forget.

## Requirements

- PHP 8.1 or newer
- [quillstack/db](https://github.com/quillstack/db)

## Installing

```shell
composer require quillstack/orm
```

## An entity

Plain typed properties, mapped with attributes. No magic reads, so static analysis sees every
field an entity has.

```php
use Quillstack\Orm\Attributes\{Table, Id, Column, HasMany, HasOne};
use Quillstack\Orm\{Related, Reference};

#[Table('users')]
final class User
{
    /**
     * @param Related<Post> $posts
     * @param Reference<Profile> $profile
     */
    public function __construct(
        #[Id] public ?int $id = null,
        #[Column] public string $email = '',
        #[Column] public bool $active = true,
        #[HasMany(Post::class, 'user_id')] public readonly Related $posts = new Related(),
        #[HasOne(Profile::class, 'user_id')] public readonly Reference $profile = new Reference(),
    ) {
    }
}
```

A property with no column of its own takes the name a column usually has: `createdAt` reads
`created_at`.

Entities work written the other way round too, as plain public properties with no constructor.

## Reading

```php
$orm = new Orm($connection);
$users = $orm->repository(User::class);

$users->find(7);
$users->findMany([1, 2, 3]);        // one query, not three
$users->all();
$users->get($users->query()->where('active', '=', true)->orderBy('email'));
```

The query is [quillstack/db](https://github.com/quillstack/db)'s, so everything it does is
available and every value is bound.

## One query per row is impossible

Everything read together shares the result set it came from. A relation is not loaded for the
entity whose property was touched — it is loaded for all of them, and handed to all of them:

```php
foreach ($orm->repository(User::class)->all() as $user) {
    foreach ($user->posts as $post) {
        foreach ($post->comments as $comment) {
            echo $comment->body;
        }
    }
}
```

Twenty users, forty posts, a hundred and twenty comments — **three queries**:

```sql
SELECT * FROM "users"
SELECT * FROM "posts" WHERE "user_id" IN (1, 2, …, 20)
SELECT * FROM "comments" WHERE "post_id" IN (1, 2, …, 40)
```

An ORM asking one per row would have run sixty-one. Nothing above asks for anything to be
loaded ahead of time: reading the first relation is what loads the batch, and the rest cost
nothing because there is nothing left to fetch.

It works the same in the other direction — forty posts and the author of each is two queries,
not forty-one — and for a relation with one row on the other side.

## Relations are asked, not read

`$user->posts` is a `Related`, `$post->user` a `Reference`:

```php
$user->posts->all();          // Post[]
$user->posts->first();
$user->posts->count();
$user->posts->isEmpty();
foreach ($user->posts as $post) { … }

$post->user->get();           // ?User
$post->user->isPresent();
```

`get()` rather than the entity itself, because reading it may go to the database, and a
property access which quietly does that is how an application ends up with a thousand queries
nobody wrote. Both carry their target type, so `@param Related<Post> $posts` is all static
analysis needs.

An entity built by hand has no result set behind it. Its relations say so rather than quietly
answering that there is nothing there.

## Writing

```php
$user = $users->save(new User(email: 'ada@example.com'));   // inserted, and given its id
$user->email = 'ada2@example.com';
$users->save($user);                                        // updated
$users->delete($user);
```

Values survive the round trip whatever the driver hands back: `int`, `float`, `bool`, `string`,
`DateTimeImmutable` and backed enums are all brought to the type the property declares. The
same row read twice is the same object, so `===` answers what a person means by it.

## The schema comes from the entities

There are no migration files to write and none to keep in order. The entities are the
description; what is missing is worked out by comparing them against what is there.

```php
$migrator = new Migrator($connection);

$plan = $migrator->plan([User::class, Post::class, Comment::class, Profile::class]);
$migrator->apply($plan);
```

**Nobody writes an index, and nobody writes a foreign key.** A relation is a declaration that
one column holds another table's id — which is all the information needed to index it and to
constrain it. Both are added, because a relation without an index is a table scan on every
lookup, and that is exactly the kind of thing nobody remembers until it is slow.

```sql
CREATE TABLE "posts" (
  "id" INTEGER PRIMARY KEY AUTOINCREMENT,
  "user_id" INTEGER NULL,
  "title" TEXT NOT NULL,
  FOREIGN KEY ("user_id") REFERENCES "users" ("id") ON DELETE CASCADE
)
CREATE INDEX "posts_user_id_index" ON "posts" ("user_id")
```

A column can ask for more: `#[Column(length: 40, unique: true)]`, `#[Column(index: true)]`,
`#[Column(length: 0)]` for text with no limit. Everything else follows the property's own
type, nullability included.

Running it again finds nothing to do, which is what makes it safe on every deploy.

### Nothing is ever removed

A column the entities no longer mention is reported and left alone:

```
users.forgotten is in the database and not in the entity — left alone, because a renamed
property looks exactly like a deleted one
```

The difference between those two matters rather a lot when the answer is data. The same goes
for anything a database cannot do: SQLite will not add a foreign key to a table which already
exists, and the plan says so rather than running something which quietly does nothing.

`plan()` and `apply()` are two steps because a migration is worth looking at before it
happens. `migrate()` does both where that is what is wanted.

## Unit tests

```shell
composer test
composer test:coverage
composer stan
```

The suite runs against a real SQLite database in memory, and counts the statements: the claim
above is a test, not a promise.

MySQL and PostgreSQL have grammars of their own, checked by reading the SQL they write. What
they cannot be checked on here is reading an existing schema back, which goes through
`information_schema` — that part still wants a real server to confirm it.

## License

MIT. See [LICENSE](LICENSE).
