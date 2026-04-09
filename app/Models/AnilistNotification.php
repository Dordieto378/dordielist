<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AnilistNotification extends Model
{
    protected $guarded = [];

    protected $casts = [
        'is_read' => 'boolean',
        'payload' => 'array',
        'notified_at' => 'datetime',
    ];
}
