<?php

declare(strict_types=1);

namespace Quillstack\Orm\Metadata;

/**
 * One column, and the property it lands on.
 */
final class ColumnMetadata
{
    public function __construct(
        public readonly string $property,
        public readonly string $column,
        public readonly bool $isId = false,
        public readonly ?string $type = null,
        public readonly bool $nullable = false
    ) {
        //
    }
}
