<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class AnilistAuthor extends Model
{
    protected $table = 'anilist_authors';

    protected $guarded = [];

    public function media(): BelongsToMany
    {
        return $this->belongsToMany(Media::class, 'anilist_item_author', 'author_id', 'media_id');
    }
}
