<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Chapter extends Model
{
    protected $fillable = [
        'item_type',
        'item_id',
        'media_fk',
        'chapter_number',
        'chapter_title',
    ];

    public function pages()
    {
        return $this->hasMany(ChapterPage::class);
    }

    public function media()
    {
        return $this->belongsTo(\App\Models\Media::class, 'media_fk');
    }
}
