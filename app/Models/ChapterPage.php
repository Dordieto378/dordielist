<?php
// app/Models/ChapterPage.php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ChapterPage extends Model
{
    protected $fillable = [
        'chapter_id',
        'chapter_number',  // here this is actually the *page* number
        'file_path',
    ];

    public function chapter()
    {
        return $this->belongsTo(Chapter::class);
    }
}
