<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Media extends Model
{
    // simplest: allow mass assignment for all columns we use
    protected $guarded = [];
    public $timestamps = false;

    // make sure JSON comes back as arrays
    protected $casts = [
        'genres'  => 'array',
        'tags'    => 'array',
        'publisher'     => 'array',
        'languages' => 'array',
    ];
}
