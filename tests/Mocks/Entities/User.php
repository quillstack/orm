<?php

declare(strict_types=1);

namespace Quillstack\Orm\Tests\Mocks\Entities;

use Quillstack\Orm\Attributes\Column;
use Quillstack\Orm\Attributes\HasMany;
use Quillstack\Orm\Attributes\HasOne;
use Quillstack\Orm\Attributes\Id;
use Quillstack\Orm\Attributes\Table;
use Quillstack\Orm\Reference;
use Quillstack\Orm\Related;

#[Table('users')]
final class User
{
    /**
     * @param Related<Post> $posts
     * @param Reference<Profile> $profile
     */
    public function __construct(
        #[Id] public ?int $id = null,
        #[Column] public string $email = '',
        #[Column] public bool $active = true,
        #[HasMany(Post::class, 'user_id')] public readonly Related $posts = new Related(),
        #[HasOne(Profile::class, 'user_id')] public readonly Reference $profile = new Reference()
    ) {
        //
    }
}
