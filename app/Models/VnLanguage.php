<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class VnLanguage extends Model
{
    protected $table = 'vn_languages';

    protected $guarded = [];

    public function media(): BelongsToMany
    {
        return $this->belongsToMany(Media::class, 'vn_item_language', 'language_id', 'media_id');
    }
}
