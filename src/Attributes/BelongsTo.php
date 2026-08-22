<?php

declare(strict_types=1);

namespace Quillstack\Orm\Attributes;

use Attribute;

/**
 * The row this one points at, e.g. the user a post belongs to.
 *
 * The column named here is the one on this table holding the other entity's id.
 */
#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER)]
final class BelongsTo
{
    /**
     * @param class-string $target
     */
    public function __construct(
        public readonly string $target,
        public readonly string $localKey
    ) {
        //
    }
}
