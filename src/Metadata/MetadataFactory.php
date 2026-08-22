<?php

declare(strict_types=1);

namespace Quillstack\Orm\Metadata;

use ReflectionAttribute;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionParameter;
use ReflectionProperty;
use Quillstack\Orm\Attributes\BelongsTo;
use Quillstack\Orm\Attributes\Column;
use Quillstack\Orm\Attributes\HasMany;
use Quillstack\Orm\Attributes\HasOne;
use Quillstack\Orm\Attributes\Id;
use Quillstack\Orm\Attributes\Table;
use Quillstack\Orm\Exceptions\NotAnEntityException;

/**
 * Reads an entity once and remembers what it found. Reflection is not cheap enough to do on
 * every row, and an entity does not change while the process runs.
 */
class MetadataFactory
{
    /**
     * @var array<class-string, EntityMetadata>
     */
    private array $known = [];

    /**
     * @param class-string $class
     */
    public function for(string $class): EntityMetadata
    {
        return $this->known[$class] ??= $this->read($class);
    }

    /**
     * @param class-string $class
     */
    private function read(string $class): EntityMetadata
    {
        $reflection = new ReflectionClass($class);
        $table = $this->attribute($reflection->getAttributes(Table::class));

        if (!$table instanceof Table) {
            throw new NotAnEntityException("`{$class}` is missing the #[Table] attribute");
        }

        $columns = [];
        $associations = [];
        $id = null;

        foreach ($this->members($reflection) as $name => $member) {
            $type = $this->typeOf($member);

            foreach ($member->getAttributes() as $attribute) {
                $instance = $attribute->newInstance();

                if ($instance instanceof Id) {
                    $id = new ColumnMetadata($name, $instance->column ?? self::columnName($name), true, $type['name'], $type['nullable']);
                    $columns[$id->column] = $id;
                }

                if ($instance instanceof Column) {
                    $column = new ColumnMetadata($name, $instance->name ?? self::columnName($name), false, $type['name'], $type['nullable']);
                    $columns[$column->column] = $column;
                }

                if ($instance instanceof HasMany || $instance instanceof HasOne) {
                    $associations[$name] = new AssociationMetadata(
                        $name,
                        $instance->target,
                        $instance instanceof HasMany
                            ? AssociationMetadata::HAS_MANY
                            : AssociationMetadata::HAS_ONE,
                        // Filled in below, once the id column is known: a relation pointing
                        // back here is matched against whatever tells this row apart.
                        '',
                        $instance->foreignKey
                    );
                }

                if ($instance instanceof BelongsTo) {
                    $associations[$name] = new AssociationMetadata(
                        $name,
                        $instance->target,
                        AssociationMetadata::BELONGS_TO,
                        $instance->localKey,
                        ''
                    );
                }
            }
        }

        if ($id === null) {
            throw new NotAnEntityException("`{$class}` is missing a property marked #[Id]");
        }

        return new EntityMetadata(
            $class,
            $table->name,
            $id,
            $columns,
            $this->withOwnerColumns($associations, $id)
        );
    }

    /**
     * A relation pointing back at this entity is matched against its id, and one pointing
     * away is matched against the other entity's — which is only known once both have been
     * read, so it is filled in by the manager rather than here.
     *
     * @param array<string, AssociationMetadata> $associations
     *
     * @return array<string, AssociationMetadata>
     */
    private function withOwnerColumns(array $associations, ColumnMetadata $id): array
    {
        $filled = [];

        foreach ($associations as $name => $association) {
            $filled[$name] = $association->kind === AssociationMetadata::BELONGS_TO
                ? $association
                : new AssociationMetadata(
                    $association->property,
                    $association->target,
                    $association->kind,
                    $id->column,
                    $association->targetColumn
                );
        }

        return $filled;
    }

    /**
     * Constructor parameters and plain properties alike, so an entity can be written either
     * way round.
     *
     * @param ReflectionClass<object> $reflection
     *
     * @return array<string, ReflectionParameter|ReflectionProperty>
     */
    private function members(ReflectionClass $reflection): array
    {
        $members = [];

        foreach ($reflection->getConstructor()?->getParameters() ?? [] as $parameter) {
            $members[$parameter->getName()] = $parameter;
        }

        foreach ($reflection->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
            $members[$property->getName()] ??= $property;
        }

        return $members;
    }

    /**
     * @return array{name: ?string, nullable: bool}
     */
    private function typeOf(ReflectionParameter|ReflectionProperty $member): array
    {
        $type = $member->getType();

        // A union or an intersection names no single type, so there is nothing to say.
        if (!$type instanceof ReflectionNamedType) {
            return ['name' => null, 'nullable' => true];
        }

        return ['name' => $type->getName(), 'nullable' => $type->allowsNull()];
    }

    /**
     * @param ReflectionAttribute<object>[] $attributes
     */
    private function attribute(array $attributes): ?object
    {
        return isset($attributes[0]) ? $attributes[0]->newInstance() : null;
    }

    /**
     * `createdAt` reads `created_at`, which is what a column is usually called.
     */
    public static function columnName(string $property): string
    {
        return strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', $property));
    }
}
