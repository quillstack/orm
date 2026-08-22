<?php

declare(strict_types=1);

namespace Quillstack\Orm\Attributes;

use Attribute;

/**
 * Rows of another table pointing back at this one, e.g. the posts of a user.
 *
 * The column named here is the one on the other table holding this entity's id. Declaring it
 * is also what tells the schema to index that column and put a foreign key on it, so a
 * relation is never the slow kind by accident.
 */
#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER)]
final class HasMany
{
    /**
     * @param class-string $target
     */
    public function __construct(
        public readonly string $target,
        public readonly string $foreignKey
    ) {
        //
    }
}
