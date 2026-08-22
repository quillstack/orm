<?php

declare(strict_types=1);

namespace Quillstack\Orm\Tests\Mocks\Entities;

enum Status: string
{
    case Draft = 'draft';
    case Published = 'published';
}
