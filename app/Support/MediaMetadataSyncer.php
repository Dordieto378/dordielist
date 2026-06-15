<?php

namespace App\Support;

use App\Models\AnilistAuthor;
use App\Models\AnilistGenre;
use App\Models\AnilistStudio;
use App\Models\AnilistTag;
use App\Models\DoujinAuthor;
use App\Models\Media;
use App\Models\VnDeveloper;
use App\Models\VnLanguage;
use App\Models\VnTag;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class MediaMetadataSyncer
{
    public function syncAnilist(
        Media $media,
        ?array $genres = null,
        ?array $tags = null,
        ?array $studios = null,
        ?array $authors = null
    ): void {
        $this->syncRelation($media, 'anilistGenres', AnilistGenre::class, $genres);
        $this->syncRelation($media, 'anilistTags', AnilistTag::class, $tags);
        $this->syncRelation($media, 'anilistStudios', AnilistStudio::class, $studios);
        $this->syncRelation($media, 'anilistAuthors', AnilistAuthor::class, $authors);
    }

    public function syncDoujin(Media $media, ?array $authors = null): void
    {
        $this->syncRelation($media, 'doujinAuthors', DoujinAuthor::class, $authors);
    }

    public function syncVn(
        Media $media,
        ?array $tags = null,
        ?array $languages = null,
        ?array $developers = null
    ): void {
        $this->syncRelation($media, 'vnTags', VnTag::class, $tags);
        $this->syncRelation($media, 'vnLanguages', VnLanguage::class, $languages);
        $this->syncRelation($media, 'vnDevelopers', VnDeveloper::class, $developers);
    }

    public function normalizedNames(?array $values): ?array
    {
        if ($values === null) {
            return null;
        }

        return $this->normalizePayload($values)
            ->pluck('name')
            ->values()
            ->all();
    }

    private function syncRelation(Media $media, string $relation, string $modelClass, ?array $values): void
    {
        if ($values === null) {
            return;
        }

        $records = $this->normalizePayload($values);
        $ids = [];

        foreach ($records as $record) {
            $ids[] = $this->firstOrCreateMetadata($modelClass, $record)->getKey();
        }

        $media->{$relation}()->sync($ids);
    }

    private function normalizePayload(array $values): Collection
    {
        return collect($values)
            ->map(function ($value) {
                if (is_array($value)) {
                    $name = trim((string) ($value['name'] ?? ''));
                    if ($name === '') {
                        return null;
                    }

                    $sourceId = $value['source_id'] ?? $value['id'] ?? null;
                    $sourceId = is_numeric($sourceId) ? (int) $sourceId : null;

                    return [
                        'name' => $name,
                        'source_id' => $sourceId,
                    ];
                }

                $name = trim((string) $value);

                return $name === ''
                    ? null
                    : ['name' => $name, 'source_id' => null];
            })
            ->filter()
            ->unique(fn (array $record) => mb_strtolower($record['name']))
            ->values();
    }

    private function firstOrCreateMetadata(string $modelClass, array $record): Model
    {
        $model = new $modelClass();
        $sourceColumn = $this->sourceIdColumn($modelClass);
        $entity = null;

        if ($sourceColumn !== null && !empty($record['source_id'])) {
            $entity = $modelClass::query()
                ->where($sourceColumn, $record['source_id'])
                ->orWhere('name', $record['name'])
                ->first();
        }

        if ($entity === null) {
            $create = ['name' => $record['name']];

            if ($sourceColumn !== null && !empty($record['source_id'])) {
                $create[$sourceColumn] = $record['source_id'];
            }

            if ($this->supportsSlug($modelClass)) {
                $slug = $this->makeUniqueSlug($model, $record['name']);
                if ($slug !== null) {
                    $create['slug'] = $slug;
                }
            }

            $entity = $modelClass::firstOrCreate(['name' => $record['name']], $create);
        }

        $dirty = false;

        if ($entity->name !== $record['name']) {
            $entity->name = $record['name'];
            $dirty = true;
        }

        if ($sourceColumn !== null && empty($entity->{$sourceColumn}) && !empty($record['source_id'])) {
            $entity->{$sourceColumn} = $record['source_id'];
            $dirty = true;
        }

        if ($this->supportsSlug($modelClass) && empty($entity->slug)) {
            $slug = $this->makeUniqueSlug($entity, $record['name']);
            if ($slug !== null) {
                $entity->slug = $slug;
                $dirty = true;
            }
        }

        if ($dirty) {
            $entity->save();
        }

        return $entity;
    }

    private function makeUniqueSlug(Model $model, string $name): ?string
    {
        $base = Str::slug($name);
        if ($base === '') {
            return null;
        }

        $slug = $base;
        $index = 2;

        while (
            $model->newQuery()
                ->where('slug', $slug)
                ->when($model->exists, fn ($query) => $query->whereKeyNot($model->getKey()))
                ->exists()
        ) {
            $slug = $base.'-'.$index++;
        }

        return $slug;
    }

    private function supportsSlug(string $modelClass): bool
    {
        return $modelClass !== VnLanguage::class;
    }

    private function sourceIdColumn(string $modelClass): ?string
    {
        return in_array($modelClass, [AnilistTag::class, AnilistStudio::class, AnilistAuthor::class], true)
            ? 'source_id'
            : null;
    }
}
