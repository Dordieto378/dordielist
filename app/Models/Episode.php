<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Episode extends Model
{
    protected $fillable = [
        'media_id', 'media_type',
        'episode_number', 'file_path',
    ];
}
