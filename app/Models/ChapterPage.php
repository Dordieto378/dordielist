<?php
// app/Models/ChapterPage.php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ChapterPage extends Model
{
    protected $fillable = ['chapter_id', 'page_number', 'file_path'];

    public function chapter()
    {
        return $this->belongsTo(Chapter::class);
    }
}
