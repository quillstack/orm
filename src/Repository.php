<?php

declare(strict_types=1);

namespace Quillstack\Orm;

use Quillstack\Db\Query\Expression;
use Quillstack\Db\Query\Query;
use Quillstack\Orm\Casting\Caster;
use Quillstack\Orm\Exceptions\UnknownRelationException;
use Quillstack\Orm\Metadata\AssociationMetadata;
use Quillstack\Orm\Metadata\EntityMetadata;

/**
 * Everything to do with one entity.
 *
 * Every method returning more than one entity reads them into a single context, so a relation
 * touched on any of them is fetched for all of them at once.
 *
 * @template T of object
 */
class Repository
{
    private readonly EntityMetadata $metadata;

    /**
     * @param class-string<T> $class
     */
    public function __construct(
        private readonly Orm $orm,
        private readonly string $class
    ) {
        $this->metadata = $orm->metadata($class);
    }

    /**
     * @return ?T
     */
    public function find(int|string $id): ?object
    {
        $row = $this->query()->where($this->metadata->id->column, '=', $id)->first();

        if ($row === null) {
            return null;
        }

        /** @var T $entity */
        $entity = $this->orm->hydrate($this->metadata, $row, new LoadContext($this->orm));

        return $entity;
    }

    /**
     * A few rows by id, in one query rather than one each.
     *
     * @param array<int, int|string> $ids
     *
     * @return array<int, T>
     */
    public function findMany(array $ids): array
    {
        return $this->hydrateAll(
            $this->query()->whereIn($this->metadata->id->column, $ids)->get()
        );
    }

    /**
     * @return array<int, T>
     */
    public function all(): array
    {
        return $this->hydrateAll($this->query()->get());
    }

    /**
     * The rows a query finds, as entities.
     *
     * @return array<int, T>
     */
    public function get(Query $query): array
    {
        return $this->hydrateAll($query->get());
    }

    /**
     * @return ?T
     */
    public function one(Query $query): ?object
    {
        return $this->get($query->limit(1))[0] ?? null;
    }

    /**
     * One page of entities, and how many there are altogether.
     *
     * Two queries however large the page: one to count, one to read. The entities on it share
     * a result set, so their relations still load for the whole page at once.
     *
     * @return Page<T>
     */
    public function page(?Query $query = null, int $page = 1, int $perPage = 20): Page
    {
        $query ??= $this->query();
        $page = max(1, $page);
        $perPage = max(1, $perPage);

        $total = $query->count();
        $items = $total === 0
            ? []
            : $this->get($query->limit($perPage)->offset(($page - 1) * $perPage));

        return new Page($items, $page, $perPage, $total);
    }

    /**
     * Keeps the rows which have something on the other side of a relation.
     *
     * Asked as a question about the relation rather than about the columns behind it: what
     * the join is, and which way round it goes, is already written on the entity.
     *
     * ```php
     * $users->get($users->whereHas('posts'));
     * $users->get($users->whereHas('posts', fn (Query $q) => $q->where('title', 'LIKE', 'a%')));
     * ```
     *
     * @param ?callable(Query): Query $filter narrows what counts as having one
     */
    public function whereHas(string $relation, ?callable $filter = null, ?Query $query = null): Query
    {
        return ($query ?? $this->query())->whereExists($this->existenceOf($relation, $filter));
    }

    /**
     * The other way round: the rows which have nothing on the other side.
     *
     * @param ?callable(Query): Query $filter
     */
    public function whereDoesntHave(string $relation, ?callable $filter = null, ?Query $query = null): Query
    {
        return ($query ?? $this->query())->whereNotExists($this->existenceOf($relation, $filter));
    }

    /**
     * The query asking whether one row has anything on the other side of a relation.
     *
     * @param ?callable(Query): Query $filter
     */
    private function existenceOf(string $relation, ?callable $filter): Query
    {
        if (!isset($this->metadata->associations[$relation])) {
            throw new UnknownRelationException(
                "`{$this->class}` has no relation called `{$relation}`"
            );
        }

        $association = $this->metadata->associations[$relation];
        $target = $this->orm->metadata($association->target);
        $connection = $this->orm->connection();

        if ($association->kind === AssociationMetadata::BELONGS_TO_MANY) {
            $through = (string) $association->through;
            $exists = $connection->table($through)
                ->select(new Expression('1'))
                ->join(
                    $target->table,
                    "{$target->table}.{$target->id->column}",
                    '=',
                    "{$through}.{$association->throughTargetColumn}"
                )
                ->whereColumn(
                    "{$through}.{$association->throughOwnerColumn}",
                    '=',
                    "{$this->metadata->table}.{$this->metadata->id->column}"
                );

            return $filter === null ? $exists : $filter($exists);
        }

        // Which column matches which is already written on the relation; one side is on this
        // table and the other is on the target's, whichever way the relation points.
        $targetColumn = $association->targetColumn !== ''
            ? $association->targetColumn
            : $target->id->column;

        $exists = $connection->table($target->table)
            ->select(new Expression('1'))
            ->whereColumn(
                "{$target->table}.{$targetColumn}",
                '=',
                "{$this->metadata->table}.{$association->ownerColumn}"
            );

        return $filter === null ? $exists : $filter($exists);
    }

    /**
     * A query against this entity's table, to be built on.
     */
    public function query(): Query
    {
        return $this->orm->connection()->table($this->metadata->table);
    }

    public function count(): int
    {
        return $this->query()->count();
    }

    /**
     * Writes an entity, inserting it where it has no id yet and updating it where it has.
     *
     * @param T $entity
     *
     * @return T
     */
    public function save(object $entity): object
    {
        $id = $this->metadata->idOf($entity);
        $values = $this->valuesOf($entity);

        unset($values[$this->metadata->id->column]);

        if ($id === null) {
            $this->query()->insert($values);

            // The database decides the id, so the entity is told what it was given — the
            // caller is holding this object and will want to know.
            $entity->{$this->metadata->id->property} = Caster::to(
                $this->metadata->id->type,
                $this->orm->connection()->lastInsertId()
            );
            $id = $this->metadata->idOf($entity);
        } else {
            $this->query()->where($this->metadata->id->column, '=', $id)->update($values);
        }

        return $id === null ? $entity : $this->managed($id, $values, $entity);
    }

    /**
     * The entity as the ORM knows it.
     *
     * An entity built by hand has no result set behind it, so its relations have nothing to
     * load from. Putting that object into the identity map would hand it back on the next
     * read with relations that cannot answer, so a managed one is made from what was just
     * written — no second query, because everything a row holds has only now been said.
     * Where the entity is already the managed one, there is nothing to do.
     *
     * @param array<string, mixed> $values
     * @param T $entity
     *
     * @return T
     */
    private function managed(int|string $id, array $values, object $entity): object
    {
        if ($this->orm->identityMap()->get($this->class, $id) === $entity) {
            return $entity;
        }

        /** @var T $managed */
        $managed = $this->orm->hydrate(
            $this->metadata,
            $values + [$this->metadata->id->column => $id],
            new LoadContext($this->orm)
        );

        return $managed;
    }

    /**
     * @param T $entity
     */
    public function delete(object $entity): bool
    {
        $id = $this->metadata->idOf($entity);

        if ($id === null) {
            return false;
        }

        $this->orm->identityMap()->forget($this->class, $id);

        return $this->query()->where($this->metadata->id->column, '=', $id)->delete() > 0;
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     *
     * @return array<int, T>
     */
    private function hydrateAll(array $rows): array
    {
        // One context for the whole set: that is what makes touching a relation on any of
        // them one query rather than one each.
        $context = new LoadContext($this->orm);
        $entities = [];

        foreach ($rows as $row) {
            /** @var T $entity */
            $entity = $this->orm->hydrate($this->metadata, $row, $context);
            $entities[] = $entity;
        }

        return $entities;
    }

    /**
     * @param T $entity
     *
     * @return array<string, mixed>
     */
    private function valuesOf(object $entity): array
    {
        $values = [];

        foreach ($this->metadata->columns as $column) {
            $values[$column->column] = Caster::from(
                $this->metadata->read($entity, $column->column)
            );
        }

        return $values;
    }
}
