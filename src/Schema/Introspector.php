<?php

declare(strict_types=1);

namespace Quillstack\Orm\Schema;

/**
 * Reads what the database actually has, so the difference against what the entities say can
 * be worked out rather than guessed.
 */
interface Introspector
{
    /**
     * @return string[]
     */
    public function tables(): array;

    /**
     * @return string[] the columns of a table, by name
     */
    public function columns(string $table): array;

    /**
     * @return string[] the indexes of a table, by name
     */
    public function indexes(string $table): array;

    /**
     * @return string[] the columns of a table already carrying a foreign key
     */
    public function foreignKeyColumns(string $table): array;
}
