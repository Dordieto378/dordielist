<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CollectionItem extends Model
{
    protected $table = 'collection_items';

    protected $fillable = [
      'collection_id',
      'item_type',
      'item_id',
      'thumbnail_url',
      'title',
    ];

    public function collection()
    {
      return $this->belongsTo(Collection::class);
    }
}
