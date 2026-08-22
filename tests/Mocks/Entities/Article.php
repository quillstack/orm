<?php

declare(strict_types=1);

namespace Quillstack\Orm\Tests\Mocks\Entities;

use DateTimeImmutable;
use Quillstack\Orm\Attributes\Column;
use Quillstack\Orm\Attributes\Id;
use Quillstack\Orm\Attributes\Table;

/**
 * Every kind of value an entity can hold, to check they survive the round trip.
 */
#[Table('articles')]
final class Article
{
    public function __construct(
        #[Id] public ?int $id = null,
        #[Column] public string $title = '',
        #[Column] public int $views = 0,
        #[Column] public float $rating = 0.0,
        #[Column] public bool $featured = false,
        #[Column] public ?Status $status = null,
        #[Column('published_at')] public ?DateTimeImmutable $publishedAt = null
    ) {
        //
    }
}
