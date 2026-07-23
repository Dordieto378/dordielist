<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Media extends Model
{
    public const METADATA_RELATIONS = [
        'anilistTags:id,name',
        'anilistGenres:id,name',
        'anilistStudios:id,name',
        'anilistAuthors:id,name',
        'doujinAuthors:id,name',
        'vnTags:id,name',
        'vnLanguages:id,name',
        'vnDevelopers:id,name',
    ];

    protected $guarded = [];

    public $timestamps = false;

    public function anilistTags(): BelongsToMany
    {
        return $this->belongsToMany(AnilistTag::class, 'anilist_item_tag', 'media_id', 'tag_id');
    }

    public function anilistGenres(): BelongsToMany
    {
        return $this->belongsToMany(AnilistGenre::class, 'anilist_item_genre', 'media_id', 'genre_id');
    }

    public function anilistStudios(): BelongsToMany
    {
        return $this->belongsToMany(AnilistStudio::class, 'anilist_item_studio', 'media_id', 'studio_id');
    }

    public function anilistAuthors(): BelongsToMany
    {
        return $this->belongsToMany(AnilistAuthor::class, 'anilist_item_author', 'media_id', 'author_id');
    }

    public function doujinAuthors(): BelongsToMany
    {
        return $this->belongsToMany(DoujinAuthor::class, 'doujin_item_author', 'media_id', 'author_id');
    }

    public function vnTags(): BelongsToMany
    {
        return $this->belongsToMany(VnTag::class, 'vn_item_tag', 'media_id', 'tag_id');
    }

    public function vnLanguages(): BelongsToMany
    {
        return $this->belongsToMany(VnLanguage::class, 'vn_item_language', 'media_id', 'language_id');
    }

    public function vnDevelopers(): BelongsToMany
    {
        return $this->belongsToMany(VnDeveloper::class, 'vn_item_developer', 'media_id', 'developer_id');
    }

    public function archive(): HasOne
    {
        return $this->hasOne(MediaArchive::class);
    }

    public function metadataNamesFrom(string $relation): array
    {
        if (! $this->relationLoaded($relation)) {
            $this->load($relation);
        }

        return $this->{$relation}
            ->pluck('name')
            ->map(fn ($name) => trim((string) $name))
            ->filter()
            ->unique(fn ($name) => mb_strtolower($name))
            ->values()
            ->all();
    }
}
