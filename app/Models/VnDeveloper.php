<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class VnDeveloper extends Model
{
    protected $table = 'vn_developers';

    protected $guarded = [];

    public function media(): BelongsToMany
    {
        return $this->belongsToMany(Media::class, 'vn_item_developer', 'developer_id', 'media_id');
    }
}
