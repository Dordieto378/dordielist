<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DoujinPage extends Model
{
    use HasFactory;

    protected $table = 'doujin_pages';

    protected $fillable = [
        'doujin_id',
        'page_number',
        'file_path',
    ];

    public function doujin()
    {
        return $this->belongsTo(Doujin::class);
    }
}
