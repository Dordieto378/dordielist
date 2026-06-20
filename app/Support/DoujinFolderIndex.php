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

        foreach ($disk->directories($root) as $path) {
            if ($this->looksLikeDoujinFolder($disk, $path)) {
                $entries[] = $this->makeEntry($path, null);
                continue;
            }

            $author = basename($path);

            foreach ($disk->directories($path) as $doujinPath) {
                $entries[] = $this->makeEntry($doujinPath, $author);
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
            ->get(['id', 'title_english', 'slug', 'cover_url', 'chapters_cnt'])
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
        $candidates = [];

        foreach ($entries as $entry) {
            $entryMediaId = (int) ($entry['media_id'] ?? 0);
            if ($entryMediaId === (int) $media->id) {
                $candidates[] = $entry;
                continue;
            }

            $entryTitleKeys = $this->titleKeysForValue((string) ($entry['legacy_title'] ?? ''));
            if ($entryTitleKeys && array_intersect($entryTitleKeys, $this->titleKeysForMedia($media))) {
                $candidates[] = $entry;
            }
        }

        if (!$candidates) {
            return null;
        }

        $best = array_shift($candidates);
        foreach ($candidates as $candidate) {
            if ($this->isPreferredEntry($candidate, $best, $media)) {
                $best = $candidate;
            }
        }

        return $best;
    }

    public function displayTitle(Media $media): string
    {
        return $media->title_english
            ?: ($media->slug ?: 'Untitled');
    }

    public function deduplicateEntries(array $entries, array $lookup): array
    {
        $unique = [];

        foreach ($entries as $entry) {
            $mediaId = $this->resolveMediaId($entry, $lookup);
            $key = $mediaId
                ? 'media:'.$mediaId
                : 'path:'.$this->normKey((string) ($entry['path'] ?? ''));

            $media = $mediaId ? ($lookup['by_id'][$mediaId] ?? null) : null;
            if (!isset($unique[$key]) || $this->isPreferredEntry($entry, $unique[$key], $media)) {
                $unique[$key] = $entry;
            }
        }

        return array_values($unique);
    }

    private function titleKeysForMedia(Media $media): array
    {
        return collect([
            $media->title_english,
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

    private function makeEntry(string $path, ?string $author): array
    {
        $folder = basename($path);
        $mediaId = ctype_digit($folder) ? (int) $folder : null;

        return [
            'author' => $author,
            'folder' => $folder,
            'path' => $path,
            'media_id' => $mediaId && $mediaId > 0 ? $mediaId : null,
            'legacy_title' => $mediaId ? null : $folder,
        ];
    }

    private function isPreferredEntry(array $candidate, array $current, ?Media $media = null): bool
    {
        if ($media) {
            $candidateScore = $this->entryScoreForMedia($candidate, $media);
            $currentScore = $this->entryScoreForMedia($current, $media);

            if ($candidateScore !== $currentScore) {
                return $candidateScore > $currentScore;
            }
        }

        $candidateFlat = empty($candidate['author']);
        $currentFlat = empty($current['author']);

        if ($candidateFlat !== $currentFlat) {
            return $candidateFlat;
        }

        return substr_count((string) ($candidate['path'] ?? ''), '/') < substr_count((string) ($current['path'] ?? ''), '/');
    }

    private function entryScoreForMedia(array $entry, Media $media): int
    {
        $score = 0;
        $entryAuthorKey = $this->normKey((string) ($entry['author'] ?? ''));
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

        if ($entryAuthorKey !== '' && in_array($entryAuthorKey, $authorKeys, true)) {
            $score += 100;
        }

        $entryTitleKeys = $this->titleKeysForValue((string) ($entry['legacy_title'] ?? ''));
        if ($entryTitleKeys && array_intersect($entryTitleKeys, $this->titleKeysForMedia($media))) {
            $score += 80;
        }

        if ((int) ($entry['media_id'] ?? 0) === (int) $media->id) {
            $score += 20;
        }

        if (!empty($entry['author']) && !empty($entry['legacy_title'])) {
            $score += 10;
        }

        return $score;
    }

    private function looksLikeDoujinFolder(FilesystemAdapter $disk, string $path): bool
    {
        if (ctype_digit(basename($path))) {
            return true;
        }

        if ($this->directoryHasImages($disk, $path)) {
            return true;
        }

        $childDirs = $disk->directories($path);

        foreach ($childDirs as $childPath) {
            if (ctype_digit(basename($childPath))) {
                return false;
            }
        }

        foreach ($childDirs as $childPath) {
            if ($this->directoryHasImages($disk, $childPath)) {
                return true;
            }
        }

        return false;
    }

    private function directoryHasImages(FilesystemAdapter $disk, string $path): bool
    {
        foreach ($disk->files($path) as $file) {
            if ($this->isImage($file)) {
                return true;
            }
        }

        return false;
    }

    private function isImage(string $path): bool
    {
        return in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)), ['jpg', 'jpeg', 'png', 'webp', 'gif'], true);
    }

    private function normKey(string $value): string
    {
        return trim(mb_strtolower($value));
    }
}
