<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Media extends Model
{

    public $incrementing = false;
    protected $keyType = 'int';

    // whitelist the columns you’ll mass-assign (including id)
    protected $fillable = [
        'id',
        'cover_url',
        'title',
        // …any other fields you set in updateOrCreate
    ];

    public function favorites()
    {
        return $this->morphMany(\App\Models\Favorite::class, 'favoritable');
    }

    /**
     * Helper: is this media currently favorited?
     */
    public function isFavorited(): bool
    {
        // directly check favoritable_type & favoritable_id
        return $this->favorites()
                    ->where('favoritable_type', self::class)
                    ->where('favoritable_id', $this->id)
                    ->exists();
    }
}
