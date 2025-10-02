<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Collection extends Model
{
    protected $fillable = ['name','is_system'];

    public function items()
    {
        return $this->hasMany(CollectionItem::class);
    }

    public function latestItem()
    {
        return $this->hasOne(CollectionItem::class)
                    ->latestOfMany('created_at');
    }
}
