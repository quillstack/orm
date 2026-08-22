<?php

declare(strict_types=1);

namespace Quillstack\Orm;

use Quillstack\Db\Connection;
use Quillstack\Orm\Casting\Caster;
use Quillstack\Orm\Metadata\AssociationMetadata;
use Quillstack\Orm\Metadata\EntityMetadata;
use Quillstack\Orm\Metadata\MetadataFactory;
use ReflectionClass;

/**
 * The way in. Holds the connection, what is known about each entity, and which rows have
 * already become objects.
 */
class Orm
{
    private readonly MetadataFactory $metadata;

    private readonly IdentityMap $identityMap;

    public function __construct(
        private readonly Connection $connection,
        ?MetadataFactory $metadata = null,
        ?IdentityMap $identityMap = null
    ) {
        $this->metadata = $metadata ?? new MetadataFactory();
        $this->identityMap = $identityMap ?? new IdentityMap();
    }

    /**
     * Everything to do with one entity: reading it, writing it, asking for a few of them.
     *
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return Repository<T>
     */
    public function repository(string $class): Repository
    {
        return new Repository($this, $class);
    }

    public function connection(): Connection
    {
        return $this->connection;
    }

    /**
     * @param class-string $class
     */
    public function metadata(string $class): EntityMetadata
    {
        return $this->metadata->for($class);
    }

    public function identityMap(): IdentityMap
    {
        return $this->identityMap;
    }

    /**
     * Turns one row into an entity.
     *
     * The same row read twice gives the same object back. Relations are handed the context
     * this row was read in, so touching one loads it for everything read beside it.
     *
     * @param array<string, mixed> $row
     */
    public function hydrate(EntityMetadata $metadata, array $row, LoadContext $context): object
    {
        $id = $row[$metadata->id->column] ?? null;

        if (is_int($id) || is_string($id)) {
            $known = $this->identityMap->get($metadata->class, $id);

            if ($known !== null) {
                // The same object, in a new set. Its relations belong to whichever set is
                // being read now, or an entity seen once on its own would go on loading them
                // one owner at a time.
                $this->bindRelations($metadata, $known, $row, $context);

                return $known;
            }
        }

        $values = [];

        foreach ($metadata->columns as $column) {
            $values[$column->property] = Caster::to($column->type, $row[$column->column] ?? null);
        }

        foreach ($metadata->associations as $association) {
            $ownerValue = $this->ownerValue($metadata, $association, $row);
            $context->expect($association, $ownerValue);

            $values[$association->property] = $association->isToOne()
                ? new Reference($context, $association, $ownerValue)
                : new Related($context, $association, $ownerValue);
        }

        $entity = $this->build($metadata, $values);

        if (is_int($id) || is_string($id)) {
            $this->identityMap->put($metadata->class, $id, $entity);
        }

        return $entity;
    }

    /**
     * Points an entity's relations at a result set, and says it is waiting on them.
     *
     * @param array<string, mixed> $row
     */
    public function bindRelations(
        EntityMetadata $metadata,
        object $entity,
        array $row,
        LoadContext $context
    ): void {
        /** @var array<string, mixed> $values */
        $values = get_object_vars($entity);

        foreach ($metadata->associations as $association) {
            $ownerValue = $this->ownerValue($metadata, $association, $row);
            $context->expect($association, $ownerValue);

            $handle = $values[$association->property] ?? null;

            if ($handle instanceof Related || $handle instanceof Reference) {
                $handle->rebind($context);
            }
        }
    }

    /**
     * What this entity matches on for one relation: the column pointing away, or its own id.
     *
     * @param array<string, mixed> $row
     */
    private function ownerValue(
        EntityMetadata $metadata,
        AssociationMetadata $association,
        array $row
    ): mixed {
        return $association->kind === AssociationMetadata::BELONGS_TO
            ? ($row[$association->ownerColumn] ?? null)
            : ($row[$metadata->id->column] ?? null);
    }

    /**
     * Builds the entity, through its constructor where it has one so that promoted readonly
     * properties work, and by assignment where it does not.
     *
     * @param array<string, mixed> $values
     */
    private function build(EntityMetadata $metadata, array $values): object
    {
        $reflection = new ReflectionClass($metadata->class);
        $constructor = $reflection->getConstructor();

        if ($constructor === null || $constructor->getNumberOfParameters() === 0) {
            $entity = $reflection->newInstance();

            foreach ($values as $property => $value) {
                if ($reflection->hasProperty($property)) {
                    $entity->{$property} = $value;
                }
            }

            return $entity;
        }

        $arguments = [];

        foreach ($constructor->getParameters() as $parameter) {
            $name = $parameter->getName();

            if (array_key_exists($name, $values)) {
                $arguments[] = $values[$name];

                continue;
            }

            $arguments[] = $parameter->isDefaultValueAvailable()
                ? $parameter->getDefaultValue()
                : null;
        }

        return $reflection->newInstanceArgs($arguments);
    }
}
