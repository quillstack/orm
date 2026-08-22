<?php

declare(strict_types=1);

namespace Quillstack\Orm\Attributes;

use Attribute;

/**
 * The column telling one row from another. An entity has exactly one.
 */
#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER)]
final class Id
{
    public function __construct(public readonly ?string $column = null)
    {
        //
    }
}
