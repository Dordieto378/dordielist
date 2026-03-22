<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class DoujinAuthor extends Model
{
    protected $table = 'doujin_authors';

    protected $guarded = [];

    public function media(): BelongsToMany
    {
        return $this->belongsToMany(Media::class, 'doujin_item_author', 'author_id', 'media_id');
    }
}
