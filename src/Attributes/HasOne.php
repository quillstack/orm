<?php

declare(strict_types=1);

namespace Quillstack\Orm\Attributes;

use Attribute;

/**
 * One row of another table pointing back at this one, e.g. the profile of a user.
 */
#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER)]
final class HasOne
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
