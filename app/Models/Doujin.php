<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Doujin extends Model
{
    use HasFactory;

    // Which columns are mass‐assignable (for inserting/updating)
    protected $fillable = [
        'author_name',
        'doujin_name',
        'folder',
        'cover_url',
    ];
    
    public function pages()
    {
        return $this->hasMany(DoujinPage::class, 'doujin_id')
                    ->orderBy('page_number');
    }
}
