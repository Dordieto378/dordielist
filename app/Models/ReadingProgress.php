<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReadingProgress extends Model
{
    protected $table = 'reading_progress';

    protected $guarded = [];

    protected $casts = [
        'scroll_offset' => 'float',
        'recorded_at' => 'integer',
    ];

    public function chapter(): BelongsTo
    {
        return $this->belongsTo(Chapter::class);
    }

    public function page(): BelongsTo
    {
        return $this->belongsTo(ChapterPage::class, 'page_id');
    }
}
