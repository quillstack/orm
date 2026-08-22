<?php

declare(strict_types=1);

namespace Quillstack\Orm\Schema;

/**
 * One column, as the database should have it.
 */
final class ColumnSchema
{
    public const INTEGER = 'integer';
    public const BIG_INTEGER = 'big-integer';
    public const FLOAT = 'float';
    public const BOOLEAN = 'boolean';
    public const STRING = 'string';
    public const TEXT = 'text';
    public const DATETIME = 'datetime';

    public function __construct(
        public readonly string $name,
        public readonly string $type,
        public readonly bool $nullable = false,
        public readonly ?int $length = null,
        public readonly bool $autoIncrement = false
    ) {
        //
    }
}
