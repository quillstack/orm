<?php

declare(strict_types=1);

namespace Quillstack\Orm\Attributes;

use Attribute;

/**
 * A column of the table. Without a name of its own it takes the property's, written the way
 * a column usually is: `createdAt` reads `created_at`.
 */
#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER)]
final class Column
{
    public function __construct(public readonly ?string $name = null)
    {
        //
    }
}
