<?php

namespace App\Support;

use App\Models\Media;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Str;

class DoujinFolderIndex
{
    public function scanDisk(FilesystemAdapter $disk, string $root = 'doujin'): array
    {
        $entries = [];

        foreach ($disk->directories($root) as $authorPath) {
            $author = basename($authorPath);

            foreach ($disk->directories($authorPath) as $doujinPath) {
                $folder = basename($doujinPath);
                $mediaId = ctype_digit($folder) ? (int) $folder : null;

                $entries[] = [
                    'author' => $author,
                    'folder' => $folder,
                    'path' => $doujinPath,
                    'media_id' => $mediaId && $mediaId > 0 ? $mediaId : null,
                    'legacy_title' => $mediaId ? null : $folder,
                ];
            }
        }

        return $entries;
    }

    public function buildMediaLookup(): array
    {
        $byId = [];
        $byAuthorAndTitle = [];
        $byTitle = [];

        Media::with('doujinAuthors:id,name')
            ->where('type', 'doujin')
            ->get(['id', 'title_romaji', 'title_english', 'title_native', 'slug', 'cover_url', 'chapters_cnt'])
            ->each(function (Media $media) use (&$byId, &$byAuthorAndTitle, &$byTitle) {
                $byId[$media->id] = $media;

                $titleKeys = $this->titleKeysForMedia($media);
                $authorKeys = $media->doujinAuthors
                    ->pluck('name')
                    ->map(fn ($name) => $this->normKey((string) $name))
                    ->filter()
                    ->unique()
                    ->values()
                    ->all();

                foreach ($titleKeys as $titleKey) {
                    $byTitle[$titleKey] ??= $media->id;

                    foreach ($authorKeys as $authorKey) {
                        $compound = $authorKey.'|'.$titleKey;
                        $byAuthorAndTitle[$compound] ??= $media->id;
                    }
                }
            });

        return [
            'by_id' => $byId,
            'by_author_and_title' => $byAuthorAndTitle,
            'by_title' => $byTitle,
        ];
    }

    public function resolveMediaId(array $entry, array $lookup): ?int
    {
        $mediaId = isset($entry['media_id']) ? (int) $entry['media_id'] : null;
        if ($mediaId && isset($lookup['by_id'][$mediaId])) {
            return $mediaId;
        }

        $titleKeys = $this->titleKeysForValue((string) ($entry['legacy_title'] ?? ''));
        if (!$titleKeys) {
            return null;
        }

        $authorKey = $this->normKey((string) ($entry['author'] ?? ''));
        foreach ($titleKeys as $titleKey) {
            $compound = $authorKey === '' ? null : $authorKey.'|'.$titleKey;

            if ($compound && isset($lookup['by_author_and_title'][$compound])) {
                return (int) $lookup['by_author_and_title'][$compound];
            }
        }

        foreach ($titleKeys as $titleKey) {
            if (isset($lookup['by_title'][$titleKey])) {
                return (int) $lookup['by_title'][$titleKey];
            }
        }

        return null;
    }

    public function findEntryForMedia(Media $media, array $entries): ?array
    {
        foreach ($entries as $entry) {
            if ((int) ($entry['media_id'] ?? 0) === (int) $media->id) {
                return $entry;
            }
        }

        $titleKeys = $this->titleKeysForMedia($media);
        $authorKeys = $media->relationLoaded('doujinAuthors')
            ? $media->doujinAuthors
            : $media->doujinAuthors()->get(['name']);

        $authorKeys = $authorKeys
            ->pluck('name')
            ->map(fn ($name) => $this->normKey((string) $name))
            ->filter()
            ->unique()
            ->values()
            ->all();

        foreach ($entries as $entry) {
            $entryTitleKeys = $this->titleKeysForValue((string) ($entry['legacy_title'] ?? ''));
            if (!$entryTitleKeys || !array_intersect($entryTitleKeys, $titleKeys)) {
                continue;
            }

            if (!$authorKeys) {
                return $entry;
            }

            $entryAuthorKey = $this->normKey((string) ($entry['author'] ?? ''));
            if ($entryAuthorKey !== '' && in_array($entryAuthorKey, $authorKeys, true)) {
                return $entry;
            }
        }

        return null;
    }

    public function displayTitle(Media $media): string
    {
        return $media->title_english
            ?: ($media->title_romaji
                ?: ($media->title_native
                    ?: ($media->slug ?: 'Untitled')));
    }

    private function titleKeysForMedia(Media $media): array
    {
        return collect([
            $media->title_english,
            $media->title_romaji,
            $media->title_native,
            $media->slug,
        ])
            ->flatMap(fn ($value) => $this->titleKeysForValue((string) $value))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    private function titleKeysForValue(string $value): array
    {
        $normalized = $this->normKey($value);
        if ($normalized === '') {
            return [];
        }

        $slug = Str::slug($value);

        return collect([$normalized, $slug !== '' ? $slug : null])
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    private function normKey(string $value): string
    {
        return trim(mb_strtolower($value));
    }
}
