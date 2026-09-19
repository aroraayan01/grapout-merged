<?php

namespace App\Models;

use App\Models\Business\Page;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Something said to the network.
 *
 * By a person, or by a person as their business page. A page's followers
 * see what the page says; a person's connections see everything the person
 * says, page or not. A repost is a post that points at another one — the
 * pointed-at post keeps its author, the repost keeps yours.
 */
class Post extends Model
{
    use HasUuids, SoftDeletes;

    public const MAX_IMAGES = 4;

    protected $fillable = [
        'user_id', 'page_id', 'body', 'images', 'repost_of_id', 'likes_count', 'comments_count', 'reposts_count',
    ];

    protected function casts(): array
    {
        return ['images' => 'array'];
    }

    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function page(): BelongsTo
    {
        return $this->belongsTo(Page::class, 'page_id');
    }

    public function repostOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'repost_of_id');
    }

    public function likers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'post_likes', 'post_id', 'user_id')->withTimestamps();
    }

    public function comments(): HasMany
    {
        return $this->hasMany(PostComment::class, 'post_id')->orderBy('id');
    }
}
