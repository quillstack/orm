<?php

declare(strict_types=1);

namespace Quillstack\Orm\Tests\Mocks\Entities;

use Quillstack\Orm\Attributes\BelongsTo;
use Quillstack\Orm\Attributes\Column;
use Quillstack\Orm\Attributes\HasMany;
use Quillstack\Orm\Attributes\Id;
use Quillstack\Orm\Attributes\Table;
use Quillstack\Orm\Related;
use Quillstack\Orm\Reference;

#[Table('posts')]
final class Post
{
    /**
     * @param Reference<User> $user
     * @param Related<Comment> $comments
     */
    public function __construct(
        #[Id] public ?int $id = null,
        #[Column('user_id')] public ?int $userId = null,
        #[Column] public string $title = '',
        #[BelongsTo(User::class, 'user_id')] public readonly Reference $user = new Reference(),
        #[HasMany(Comment::class, 'post_id')] public readonly Related $comments = new Related()
    ) {
        //
    }
}
