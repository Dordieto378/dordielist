<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Favorite extends Model
{
    protected $fillable = [
        'favoritable_type',
        'favoritable_id',
        'thumbnail_url',
        'title',
    ];

    public function favoritable()
    {
        return $this->morphTo();
    }
}
