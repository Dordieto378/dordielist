<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class AnilistStudio extends Model
{
    protected $table = 'anilist_studios';

    protected $guarded = [];

    public function media(): BelongsToMany
    {
        return $this->belongsToMany(Media::class, 'anilist_item_studio', 'studio_id', 'media_id');
    }
}
