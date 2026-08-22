<?php

declare(strict_types=1);

namespace Quillstack\Orm\Tests\Mocks\Entities;

use Quillstack\Orm\Attributes\BelongsTo;
use Quillstack\Orm\Attributes\BelongsToMany;
use Quillstack\Orm\Attributes\Column;
use Quillstack\Orm\Attributes\HasMany;
use Quillstack\Orm\Attributes\Id;
use Quillstack\Orm\Attributes\Table;
use Quillstack\Orm\Reference;
use Quillstack\Orm\Related;

#[Table('posts')]
final class Post
{
    /**
     * @param Reference<User> $user
     * @param Related<Comment> $comments
     * @param Related<Tag> $tags
     */
    public function __construct(
        #[Id] public ?int $id = null,
        #[Column('user_id')] public ?int $userId = null,
        #[Column] public string $title = '',
        #[BelongsTo(User::class, 'user_id')] public readonly Reference $user = new Reference(),
        #[HasMany(Comment::class, 'post_id')] public readonly Related $comments = new Related(),
        #[BelongsToMany(Tag::class, table: 'post_tag', foreignKey: 'post_id', relatedKey: 'tag_id')]
        public readonly Related $tags = new Related()
    ) {
        //
    }
}
