<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Collection extends Model
{
    protected $fillable = ['name','is_system'];

    /**
     * All the “collection_items” rows for this collection.
     */
    public function items()
    {
        return $this->hasMany(CollectionItem::class);
    }

    public function latestItem()
    {
        // picks the single most-recent item by created_at
        return $this->hasOne(CollectionItem::class)
                    ->latestOfMany('created_at');
    }
}
