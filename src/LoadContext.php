<?php

declare(strict_types=1);

namespace Quillstack\Orm;

use Quillstack\Orm\Metadata\AssociationMetadata;

/**
 * The result set a group of entities came from, and what makes one query per row impossible.
 *
 * Every entity read together shares one of these. A relation is not loaded for the entity
 * whose property was touched: it is loaded for every entity in the set at once, with a single
 * `WHERE ... IN (...)`, and handed to all of them. Touching the other forty-nine costs
 * nothing, because there is nothing left to fetch.
 *
 * The rows that come back are hydrated into a context of their own, so walking further —
 * users, their posts, the comments on those posts — is three queries whatever the sizes.
 */
class LoadContext
{
    /**
     * Which owner values are waiting on each relation, keyed by the relation.
     *
     * @var array<string, array<int, int|string>>
     */
    private array $expected = [];

    /**
     * What each relation found, keyed by the relation and then by the value it matched on.
     *
     * @var array<string, array<string, array<int, object>>>
     */
    private array $loaded = [];

    public function __construct(private readonly Orm $orm)
    {
        //
    }

    /**
     * Says that one more entity will want this relation. Called while rows are hydrated, so
     * by the time anything is touched the whole set is known.
     */
    public function expect(AssociationMetadata $association, mixed $ownerValue): void
    {
        if ($ownerValue === null) {
            return;
        }

        if (is_int($ownerValue) || is_string($ownerValue)) {
            $this->expected[$association->key()][] = $ownerValue;
        }
    }

    /**
     * What this relation holds for one owner. The first call loads it for everybody.
     *
     * @return array<int, object>
     */
    public function relatedTo(AssociationMetadata $association, mixed $ownerValue): array
    {
        $key = $association->key();

        if (!isset($this->loaded[$key])) {
            $this->loaded[$key] = $this->load($association);
        }

        if (!is_int($ownerValue) && !is_string($ownerValue)) {
            return [];
        }

        return $this->loaded[$key][(string) $ownerValue] ?? [];
    }

    /**
     * Whether this relation has been fetched already. Tests read this; nothing else needs to.
     */
    public function isLoaded(AssociationMetadata $association): bool
    {
        return isset($this->loaded[$association->key()]);
    }

    /**
     * One query for every owner in the set, and the rows grouped by what they matched on.
     *
     * @return array<string, array<int, object>>
     */
    private function load(AssociationMetadata $association): array
    {
        $key = $association->key();
        $values = array_values(array_unique($this->expected[$key] ?? []));

        if ($values === []) {
            return [];
        }

        $target = $this->orm->metadata($association->target);

        // A relation pointing away from here matches the other entity's id, which is only
        // known once that entity has been read.
        $column = $association->targetColumn !== ''
            ? $association->targetColumn
            : $target->id->column;

        $rows = $this->orm->connection()
            ->table($target->table)
            ->whereIn($column, $values)
            ->get();

        // Everything fetched here shares one context of its own, so touching a relation on
        // any of them loads it for all of them too.
        $context = new self($this->orm);
        $grouped = [];

        foreach ($rows as $row) {
            $entity = $this->orm->hydrate($target, $row, $context);
            $matched = $row[$column] ?? null;

            if (is_int($matched) || is_string($matched)) {
                $grouped[(string) $matched][] = $entity;
            }
        }

        return $grouped;
    }
}
