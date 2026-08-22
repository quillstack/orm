<?php

declare(strict_types=1);

namespace Quillstack\Orm\Schema;

/**
 * An index, which the entity says nothing about directly: every relation puts one on the
 * column it matches, because a relation without one is a table scan per lookup and nobody
 * remembers to add them by hand.
 */
final class IndexSchema
{
    /**
     * @param string[] $columns
     */
    public function __construct(
        public readonly string $name,
        public readonly array $columns,
        public readonly bool $unique = false
    ) {
        //
    }

    /**
     * @param string[] $columns
     */
    public static function nameFor(string $table, array $columns, bool $unique = false): string
    {
        return $table . '_' . implode('_', $columns) . ($unique ? '_unique' : '_index');
    }
}
