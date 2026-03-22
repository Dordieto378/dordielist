<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class AnilistTag extends Model
{
    protected $table = 'anilist_tags';

    protected $guarded = [];

    public function media(): BelongsToMany
    {
        return $this->belongsToMany(Media::class, 'anilist_item_tag', 'tag_id', 'media_id');
    }
}
