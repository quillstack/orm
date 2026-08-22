<?php

declare(strict_types=1);

namespace Quillstack\Orm\Tests\Mocks\Entities;

use Quillstack\Orm\Attributes\Column;
use Quillstack\Orm\Attributes\Id;
use Quillstack\Orm\Attributes\Table;

/**
 * An entity written with plain properties rather than a constructor, because both ways round
 * have to work.
 */
#[Table('tags')]
final class Tag
{
    #[Id]
    public ?int $id = null;

    #[Column]
    public string $name = '';
}
