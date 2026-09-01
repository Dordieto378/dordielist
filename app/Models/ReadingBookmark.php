<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReadingBookmark extends Model
{
    protected $guarded = [];

    public function media(): BelongsTo
    {
        return $this->belongsTo(Media::class);
    }

    public function chapter(): BelongsTo
    {
        return $this->belongsTo(Chapter::class);
    }

    public function page(): BelongsTo
    {
        return $this->belongsTo(ChapterPage::class, 'page_id');
    }
}
