<?php

declare(strict_types=1);

namespace Quillstack\Orm;

use Quillstack\Db\Connection;
use Quillstack\Orm\Casting\Caster;
use Quillstack\Orm\Metadata\AssociationMetadata;
use Quillstack\Orm\Metadata\EntityMetadata;

/**
 * Work waiting to be written.
 *
 * Saving one entity at a time is one statement each, and a hundred of them is a hundred
 * round trips to a database which would have taken them together. Everything queued here is
 * written in one go: rows of the same kind in a single statement, all of it in one
 * transaction, so a failure half way through leaves nothing behind.
 *
 * Entities are written in the order their relations need: a row pointing at another cannot
 * be written before the one it points at exists.
 */
class UnitOfWork
{
    /**
     * @var array<class-string, array<int, object>>
     */
    private array $pending = [];

    /**
     * @var array<class-string, array<int, object>>
     */
    private array $removals = [];

    public function __construct(private readonly Orm $orm)
    {
        //
    }

    /**
     * Queues an entity to be written.
     */
    public function persist(object $entity): self
    {
        $this->pending[$entity::class][] = $entity;

        return $this;
    }

    /**
     * Queues an entity to be removed.
     */
    public function remove(object $entity): self
    {
        $this->removals[$entity::class][] = $entity;

        return $this;
    }

    public function isEmpty(): bool
    {
        return $this->pending === [] && $this->removals === [];
    }

    /**
     * Writes everything queued, or nothing at all.
     *
     * @return int how many entities were written or removed
     */
    public function flush(): int
    {
        if ($this->isEmpty()) {
            return 0;
        }

        $pending = $this->pending;
        $removals = $this->removals;
        $this->pending = [];
        $this->removals = [];

        return $this->orm->connection()->transaction(
            function () use ($pending, $removals): int {
                $done = 0;

                // What points at something else goes last, and is removed first.
                foreach (array_reverse($this->inOrder(array_keys($removals))) as $class) {
                    $done += $this->removeAll($class, $removals[$class]);
                }

                foreach ($this->inOrder(array_keys($pending)) as $class) {
                    $done += $this->writeAll($class, $pending[$class]);
                }

                return $done;
            }
        );
    }

    /**
     * The classes in the order their relations need: whatever a class points at is written
     * before the class itself, so a foreign key never names a row which is not there yet.
     *
     * @param array<int, class-string> $classes
     *
     * @return array<int, class-string>
     */
    private function inOrder(array $classes): array
    {
        $ordered = [];
        $seen = [];

        foreach ($classes as $class) {
            $this->visit($class, $classes, $ordered, $seen);
        }

        return $ordered;
    }

    /**
     * @param class-string $class
     * @param array<int, class-string> $classes
     * @param array<int, class-string> $ordered
     * @param array<class-string, bool> $seen
     */
    private function visit(string $class, array $classes, array &$ordered, array &$seen): void
    {
        if (isset($seen[$class])) {
            return;
        }

        // Marked before walking on, so a relation pointing both ways stops here rather than
        // going round for ever.
        $seen[$class] = true;

        foreach ($this->orm->metadata($class)->associations as $association) {
            if ($association->kind === AssociationMetadata::BELONGS_TO
                && in_array($association->target, $classes, true)) {
                $this->visit($association->target, $classes, $ordered, $seen);
            }
        }

        $ordered[] = $class;
    }

    /**
     * @param class-string $class
     * @param array<int, object> $entities
     */
    private function writeAll(string $class, array $entities): int
    {
        $metadata = $this->orm->metadata($class);
        $repository = $this->orm->repository($class);
        $new = [];
        $done = 0;

        foreach ($entities as $entity) {
            // Something already written is changed on its own: an update names its own row,
            // so there is nothing to put together.
            if ($metadata->idOf($entity) !== null) {
                $repository->save($entity);
                ++$done;

                continue;
            }

            $new[] = $entity;
        }

        return $done + $this->insertAll($metadata, $new);
    }

    /**
     * @param array<int, object> $entities
     */
    private function insertAll(EntityMetadata $metadata, array $entities): int
    {
        if ($entities === []) {
            return 0;
        }

        $connection = $this->orm->connection();
        $rows = [];

        foreach ($entities as $entity) {
            $row = [];

            foreach ($metadata->columns as $column) {
                if (!$column->isId) {
                    $row[$column->column] = Caster::from($metadata->read($entity, $column->column));
                }
            }

            $rows[] = $row;
        }

        $written = $connection->table($metadata->table)->insertMany($rows);
        $this->assignIds($metadata, $entities, $written);

        return $written;
    }

    /**
     * Tells each entity the id its row was given.
     *
     * A statement writing many rows reports one id, and which one depends on the database:
     * the last of them in SQLite, the first in MySQL. The ids in between are consecutive,
     * which is what both promise for a single statement.
     *
     * @param array<int, object> $entities
     */
    private function assignIds(EntityMetadata $metadata, array $entities, int $written): void
    {
        $reported = $this->orm->connection()->lastInsertId();

        if (!is_numeric($reported) || $written !== count($entities)) {
            return;
        }

        $first = $this->orm->connection()->dialect()->reportsFirstOfBatch()
            ? (int) $reported
            : (int) $reported - $written + 1;

        $property = $metadata->id->property;

        foreach (array_values($entities) as $index => $entity) {
            $entity->{$property} = Caster::to($metadata->id->type, $first + $index);
            $this->orm->identityMap()->put($metadata->class, $first + $index, $entity);
        }
    }

    /**
     * @param class-string $class
     * @param array<int, object> $entities
     */
    private function removeAll(string $class, array $entities): int
    {
        $metadata = $this->orm->metadata($class);
        $ids = [];

        foreach ($entities as $entity) {
            $id = $metadata->idOf($entity);

            if ($id !== null) {
                $ids[] = $id;
                $this->orm->identityMap()->forget($class, $id);
            }
        }

        if ($ids === []) {
            return 0;
        }

        // One statement, whatever the number of them.
        return $this->orm->connection()
            ->table($metadata->table)
            ->whereIn($metadata->id->column, $ids)
            ->delete();
    }
}
