<?php

declare(strict_types=1);

namespace Quillstack\Orm\Tests\Mocks\Entities;

use Quillstack\Orm\Attributes\Column;
use Quillstack\Orm\Attributes\Table;

#[Table('nowhere')]
class WithoutAnId
{
    #[Column]
    public string $name = '';
}
