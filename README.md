# Quillstack Orm

[![Tests](https://github.com/quillstack/orm/actions/workflows/tests.yml/badge.svg)](https://github.com/quillstack/orm/actions/workflows/tests.yml)
[![Latest Version](https://img.shields.io/packagist/v/quillstack/orm.svg)](https://packagist.org/packages/quillstack/orm)
[![Downloads](https://img.shields.io/packagist/dt/quillstack/orm.svg)](https://packagist.org/packages/quillstack/orm)
[![PHP Version](https://img.shields.io/packagist/php-v/quillstack/orm)](https://packagist.org/packages/quillstack/orm)
[![StyleCI](https://github.styleci.io/repos/1343192548/shield?branch=main)](https://github.styleci.io/repos/1343192548?branch=main)
[![CodeFactor](https://www.codefactor.io/repository/github/quillstack/orm/badge)](https://www.codefactor.io/repository/github/quillstack/orm)
[![Quality Gate](https://sonarcloud.io/api/project_badges/measure?project=quillstack_orm&metric=alert_status)](https://sonarcloud.io/summary/new_code?id=quillstack_orm)
[![Coverage](https://sonarcloud.io/api/project_badges/measure?project=quillstack_orm&metric=coverage)](https://sonarcloud.io/summary/new_code?id=quillstack_orm)
[![Maintainability](https://sonarcloud.io/api/project_badges/measure?project=quillstack_orm&metric=sqale_rating)](https://sonarcloud.io/summary/new_code?id=quillstack_orm)
[![Reliability](https://sonarcloud.io/api/project_badges/measure?project=quillstack_orm&metric=reliability_rating)](https://sonarcloud.io/summary/new_code?id=quillstack_orm)
[![Security](https://sonarcloud.io/api/project_badges/measure?project=quillstack_orm&metric=security_rating)](https://sonarcloud.io/summary/new_code?id=quillstack_orm)
[![License](https://img.shields.io/packagist/l/quillstack/orm)](https://github.com/quillstack/orm/blob/main/LICENSE)

An ORM which cannot be asked one query per row.

Touching one entity's relation loads it for every entity read beside it, in a single
`WHERE ... IN (...)`. There is no `with()` to remember and none to forget.

## Why this exists

Every ORM can produce an N+1 query, and every one of them tells you not to. Doctrine has fetch
joins, Eloquent has `with()`, Cycle has `load()` — each an opt-in you have to remember on the day
you write the loop, and each silent when you forget. **Loading two hundred users and their posts
costs 201 queries in Doctrine and 201 in Eloquent** unless somebody said otherwise; here it costs
two, and there is nothing to say.

That is the whole idea: a relation is loaded for the result set it belongs to, not for the row
you happened to ask first. It is not an optimisation you enable — it is the only way this can
load a relation at all, which is why forgetting is not available.

What it costs is everything a data mapper does that this does not: no unit of work, no change
tracking, no lazy proxies, no query language of its own. Entities are plain typed properties, and
what you read is what the database had.

## Requirements

- PHP 8.1 or newer
- [quillstack/db](https://github.com/quillstack/db)

## Installation

```shell
composer require quillstack/orm
```

## Usage

### An entity

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

### Reading

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

### One query per row is impossible

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

A relation going through a table in between is one step longer and the same promise: the pairs
first, then everything they point at. Twenty posts and their tags is three queries.

```php
#[BelongsToMany(Tag::class, table: 'post_tag', foreignKey: 'post_id', relatedKey: 'tag_id')]
public readonly Related $tags = new Related();
```

```sql
SELECT * FROM "posts"
SELECT "post_id", "tag_id" FROM "post_tag" WHERE "post_id" IN (…)
SELECT * FROM "tags" WHERE "id" IN (…)
```

### Relations are asked, not read

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

The same row read twice is the same object, and that object's relations follow whichever set
is being read now. An entity seen once on its own does not go on loading its relation one
owner at a time when it turns up later among fifty.

### Writing

```php
$user = $users->save(new User(email: 'ada@example.com'));   // inserted, and given its id
$user->email = 'ada2@example.com';
$users->save($user);                                        // updated
$users->delete($user);
```

### Writing many

Saving one at a time is one statement each, and a thousand of them is a thousand round trips
to a database which would have taken them together:

```php
foreach ($rows as $row) {
    $orm->persist(new User(email: $row['email']));
}

$orm->flush();
```

A thousand users is **three statements** — as many as the values need, all in one
transaction, and each entity told the id its row was given. A failure half way through leaves
nothing behind.

Entities are written in the order their relations need, so queueing a post before the user it
belongs to is not a problem: whatever is pointed at goes first, and removals go the other way
round. Something already written is updated rather than written again.

`$orm->remove($entity)` queues a removal; ten of them are one `DELETE`.

Values survive the round trip whatever the driver hands back: `int`, `float`, `bool`, `string`,
`DateTimeImmutable` and backed enums are all brought to the type the property declares. The
same row read twice is the same object, so `===` answers what a person means by it.

### Asking about a relation

Whether a row has anything on the other side is asked as a question about the relation, not
about the columns behind it — which way round the join goes is already written on the entity:

```php
$users->get($users->whereHas('posts'));
$users->get($users->whereHas('posts', fn (Query $q) => $q->where('title', 'LIKE', '%php%')));
$users->get($users->whereDoesntHave('posts'));
```

It works for every kind of relation, the one through a table in between included.

### Pages

```php
$page = $users->page($users->whereHas('posts')->orderBy('email'), page: 2, perPage: 20);

$page->items;      // User[]
$page->total;      // how many there are altogether
$page->pages();
$page->hasMore();
foreach ($page as $user) { … }
```

Two queries however large the page — one to count, one to read — and none at all beyond the
count where there is nothing to read. The entities on a page share a result set, so their
relations still load for the whole page at once: a filtered, sorted, paged list with its
relations walked is three queries.

### The schema comes from the entities

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

The table in between two others is made the same way, from the relation alone: two columns,
an index and a foreign key on each, and a pair which cannot repeat. Nobody writes it.

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

## Benchmark

Two hundred users, each with five posts — a thousand rows in the relation — read back with every
post visited. SQLite, so the database is the same for all of them and nothing is on a network.
Measured with [quillstack/benchmark](https://github.com/quillstack/benchmark); runs are
interleaved and unconcurrent, each figure is the median of five, and PHP is 8.5.7.

| | Version |
| --- | --- |
| quillstack/orm | v0.6.4 |
| doctrine/orm | 3.6.8 |
| illuminate/database (Eloquent) | v11.51.0 |

**The queries each one runs:**

| | Queries |
| --- | --- |
| **quillstack/orm** | **2** |
| Eloquent, written the way it reads naturally | 201 |
| Eloquent, after adding `with('posts')` | 2 |
| Doctrine, written the way it reads naturally | 201 |
| Doctrine, after adding a fetch join | 1 |

Doctrine's single query is better than two, and it is available to anybody who remembers to
write it. **The 201s are what both of them do when nobody does.**

**And what that costs:**

| | Time | Relative |
| --- | --- | --- |
| **quillstack/orm** | **3.72 ms** | — |
| Eloquent with `with('posts')` | 13.58 ms | 3.7× |
| Eloquent naturally | 20.74 ms | 5.6× |
| Doctrine with a fetch join | 25.13 ms | 6.8× |
| Doctrine naturally | 27.46 ms | 7.4× |

**What the numbers do not say**, and it is a great deal: Doctrine is a full data mapper. It has
a unit of work, an identity map, change tracking, lazy proxies, DQL, a migration tool and
support for databases this package has never heard of. Eloquent has scopes, events,
polymorphic relations, soft deletes and an ecosystem. A fifth of the time is what you get for
having none of that.

If your application needs a unit of work, use Doctrine — it is very good, and this is not a
replacement for it. If what your application needs is to read rows and write them back without
ever thinking about N+1, that is what this is.

## Tests

```shell
composer test
composer test:coverage
composer stan
```

The suite runs against a real SQLite database in memory, and counts the statements: the claim
above is a test, not a promise.

MySQL and PostgreSQL are tested against real servers, because that is where databases stop
agreeing with each other — reading an existing schema back, what an index is called, whether
a schema change can be undone at all. Bring them up and the suite picks them up:

```shell
docker compose up -d

QUILLSTACK_PGSQL_DSN='pgsql:host=127.0.0.1;port=55432;dbname=quillstack' \
QUILLSTACK_MYSQL_DSN='mysql:host=127.0.0.1;port=53306;dbname=quillstack' \
QUILLSTACK_DB_USER=quill QUILLSTACK_DB_PASSWORD=secret composer test
```

Without them those tests do not run at all, rather than passing quietly: a suite which never
touched MySQL should not look like one that did. CI runs all three.

## The rest of Quillstack

This is one component of [Quillstack](https://github.com/quillstack), a PHP framework which is
as simple to use as it is strict about what it does.

- [quillstack/db](https://github.com/quillstack/db) — the queries and the connection underneath
- [quillstack/serializer](https://github.com/quillstack/serializer) — what decides which fields leave
- [quillstack/framework](https://github.com/quillstack/framework) — where entities are wired in

## License

MIT — see [LICENSE](https://github.com/quillstack/orm/blob/main/LICENSE).
