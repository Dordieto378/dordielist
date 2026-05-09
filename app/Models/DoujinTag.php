<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class DoujinTag extends Model
{
    protected $table = 'doujin_tags';

    protected $guarded = [];

    public function media(): BelongsToMany
    {
        return $this->belongsToMany(Media::class, 'doujin_item_tag', 'tag_id', 'media_id');
    }
}
