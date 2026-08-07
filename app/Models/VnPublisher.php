<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class VnPublisher extends Model
{
    protected $table = 'vn_publishers';

    protected $guarded = [];

    public function media(): BelongsToMany
    {
        return $this->belongsToMany(Media::class, 'vn_item_publisher', 'publisher_id', 'media_id');
    }
}
