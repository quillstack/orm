<?php

declare(strict_types=1);

namespace Quillstack\Orm\Attributes;

use Attribute;

/**
 * Rows of another table reached through a table in between, e.g. the tags on a post.
 *
 * Declaring it is also what creates that table in between, with an index and a foreign key on
 * each of its two columns and a pair which cannot repeat.
 */
#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER)]
final class BelongsToMany
{
    /**
     * @param class-string $target
     * @param string $table the table in between
     * @param string $foreignKey the column there holding this entity's id
     * @param string $relatedKey the column there holding the other entity's id
     */
    public function __construct(
        public readonly string $target,
        public readonly string $table,
        public readonly string $foreignKey,
        public readonly string $relatedKey
    ) {
        //
    }
}
