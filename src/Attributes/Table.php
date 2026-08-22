<?php

declare(strict_types=1);

namespace Quillstack\Orm\Attributes;

use Attribute;

/**
 * The table an entity is read from and written to.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class Table
{
    public function __construct(public readonly string $name)
    {
        //
    }
}
