<?php
// app/Models/Chapter.php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Chapter extends Model
{
    protected $fillable = [
        'item_type',    // e.g. "manga" or "manwha"
        'item_id',      // the media ID
        'chapter_number',
    ];

    public function pages()
    {
        return $this->hasMany(ChapterPage::class);
    }
}
