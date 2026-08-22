<?php

declare(strict_types=1);

namespace Quillstack\Orm\Migration;

/**
 * What the database is missing, and what would be run to give it.
 *
 * Nothing here is applied by building it. A migration is worth looking at before it happens,
 * so working it out and doing it are two steps rather than one.
 */
final class Plan
{
    /**
     * @param array<int, string> $statements what would be run, in order
     * @param array<int, string> $warnings things the entities do not account for, which are
     *                                     never acted on: dropping a column is not something
     *                                     to do because a property was renamed
     */
    public function __construct(
        public readonly array $statements = [],
        public readonly array $warnings = []
    ) {
        //
    }

    public function isEmpty(): bool
    {
        return $this->statements === [];
    }

    public function with(string $statement): self
    {
        return new self([...$this->statements, $statement], $this->warnings);
    }

    public function warning(string $warning): self
    {
        return new self($this->statements, [...$this->warnings, $warning]);
    }
}
