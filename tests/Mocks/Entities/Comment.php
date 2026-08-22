<?php

declare(strict_types=1);

namespace Quillstack\Orm\Tests\Mocks\Entities;

use Quillstack\Orm\Attributes\BelongsTo;
use Quillstack\Orm\Attributes\Column;
use Quillstack\Orm\Attributes\Id;
use Quillstack\Orm\Attributes\Table;
use Quillstack\Orm\Reference;

#[Table('comments')]
final class Comment
{
    /**
     * @param Reference<Post> $post
     */
    public function __construct(
        #[Id] public ?int $id = null,
        #[Column('post_id')] public ?int $postId = null,
        #[Column] public string $body = '',
        #[BelongsTo(Post::class, 'post_id')] public readonly Reference $post = new Reference()
    ) {
        //
    }
}
