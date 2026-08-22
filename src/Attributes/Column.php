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
    /**
     * @param ?int $length how much room a text column needs; without one it takes the size a
     *                      short piece of text usually gets, and zero means no limit at all
     * @param bool $unique whether no two rows may share this value, which also puts an index
     *                     on the column
     */
    public function __construct(
        public readonly ?string $name = null,
        public readonly ?int $length = null,
        public readonly bool $unique = false,
        public readonly bool $index = false
    ) {
        //
    }
}
