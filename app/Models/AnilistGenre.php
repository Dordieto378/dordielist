<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class AnilistGenre extends Model
{
    protected $table = 'anilist_genres';

    protected $guarded = [];

    public function media(): BelongsToMany
    {
        return $this->belongsToMany(Media::class, 'anilist_item_genre', 'genre_id', 'media_id');
    }
}
