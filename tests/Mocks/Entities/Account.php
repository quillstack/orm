<?php

declare(strict_types=1);

namespace Quillstack\Orm\Tests\Mocks\Entities;

use Quillstack\Orm\Attributes\Column;
use Quillstack\Orm\Attributes\Id;
use Quillstack\Orm\Attributes\Table;

#[Table('accounts')]
final class Account
{
    public function __construct(
        #[Id] public ?int $id = null,
        #[Column(length: 40, unique: true)] public string $handle = '',
        #[Column(index: true)] public string $email = '',
        #[Column(length: 0)] public ?string $notes = null
    ) {
        //
    }
}
