<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class VnTag extends Model
{
    protected $table = 'vn_tags';

    protected $guarded = [];

    public function media(): BelongsToMany
    {
        return $this->belongsToMany(Media::class, 'vn_item_tag', 'tag_id', 'media_id');
    }
}
