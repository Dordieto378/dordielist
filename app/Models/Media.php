<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Media extends Model
{
    protected $guarded = [];
    public $timestamps = false;

    protected $casts = [
        'genres'  => 'array',
        'tags'    => 'array',
        'publisher'     => 'array',
        'languages' => 'array',
    ];
}
