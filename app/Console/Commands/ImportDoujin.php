<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use App\Models\Media;
use App\Models\Chapter;
use App\Models\ChapterPage;
use App\Support\DoujinFolderIndex;
use App\Support\MediaMetadataSyncer;

class ImportDoujin extends Command
{
    // supports single or all; --force is optional destructive
    protected $signature = 'doujin:import {mediaId?}
                            {--all : Import every doujin found on disk}
                            {--path= : Import from an exact doujin folder path on the public disk}
                            {--force : Delete existing chapters/pages first}';

    protected $description = 'Import doujin chapters/pages from storage. Prefers storage/app/public/doujin/<author>/<media_id>.';

    public function __construct(
        private readonly MediaMetadataSyncer $metadataSyncer,
        private readonly DoujinFolderIndex $folderIndex,
    )
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $disk = Storage::disk('public');
        $root = 'doujin';
        if (!$disk->exists($root)) {
            $this->error("Folder not found: storage/app/public/{$root}");
            return self::FAILURE;
        }

        $force = (bool) $this->option('force');
        $forcedPath = $this->normalizeForcedPath($this->option('path'));

        $entries = $this->folderIndex->scanDisk($disk, $root);
        if ($this->option('all')) {
            if ($forcedPath !== null) {
                $this->error('--path cannot be combined with --all.');
                return self::FAILURE;
            }

            if (empty($entries)) {
                $this->warn('No doujin folders found on disk.');
                return self::SUCCESS;
            }

            $lookup = $this->folderIndex->buildMediaLookup();
            $entries = $this->folderIndex->deduplicateEntries($entries, $lookup);

            $created = 0;
            foreach ($entries as $entry) {
                $mediaId = $this->folderIndex->resolveMediaId($entry, $lookup);
                $folderTitle = $entry['legacy_title'] ?? null;

                if ($mediaId || $folderTitle === null) {
                    continue;
                }

                $m = new Media();
                $m->type          = 'doujin';
                $m->title_romaji  = $folderTitle;
                $m->title_english = null;
                $m->title_native  = $this->containsNonLatin($folderTitle) ? $folderTitle : null;
                $m->slug          = Str::slug($folderTitle);
                $m->cover_url     = null;
                $m->chapters_cnt  = 0;
                $m->save();
                $this->metadataSyncer->syncDoujin($m, $this->authorsForEntry($entry));

                $lookup = $this->folderIndex->buildMediaLookup();
                $created++;
            }

            $total = 0; $ok = 0; $fail = 0;
            foreach ($entries as $entry) {
                $total++;
                $mediaId = $this->folderIndex->resolveMediaId($entry, $lookup);
                $label = $entry['legacy_title'] ?? $entry['folder'];

                if (!$mediaId) {
                    $this->warn("Skip (no media id) {$label}");
                    continue;
                }

                $media = $lookup['by_id'][$mediaId] ?? Media::find($mediaId);
                try {
                    if ($media) {
                        $folderTitle = $entry['legacy_title'] ?? null;
                        if ($folderTitle && !$media->title_native && $this->containsNonLatin($folderTitle)) {
                            $media->title_native = $folderTitle;
                            $media->save();
                        }

                        $this->metadataSyncer->syncDoujin($media, $this->authorsForEntry($entry));
                    }
                    $count = $this->importOne($disk, $entry['path'], $mediaId, $force, $media);
                    $ok++;
                    $this->info($this->folderIndex->displayTitle($media ?? Media::findOrFail($mediaId)).": {$count} chapter(s)");
                } catch (\Throwable $ex) {
                    $fail++;
                    $this->error("Failed {$label} (id {$mediaId}): ".$ex->getMessage());
                }
            }

            $this->line("All done. created={$created} total={$total} ok={$ok} failed={$fail}");
            return $fail ? self::FAILURE : self::SUCCESS;
        }

        // Single media id path
        $mediaId = (int) $this->argument('mediaId');
        if (!$mediaId) {
            $this->error('Provide {mediaId} or use --all.');
            return self::FAILURE;
        }
        $media = Media::where('type','doujin')->find($mediaId);
        if (!$media) {
            $this->error("No doujin Media found with id {$mediaId}");
            return self::FAILURE;
        }

        $entry = $forcedPath !== null
            ? $this->entryFromForcedPath($disk, $forcedPath)
            : $this->folderIndex->findEntryForMedia($media->loadMissing('doujinAuthors:id,name'), $entries);

        if (!$entry) {
            $message = $forcedPath !== null
                ? "Folder not found for forced path '{$forcedPath}'."
                : "Folder not found for '{$this->folderIndex->displayTitle($media)}' in {$root}/<author>/.";
            $this->error($message);
            return self::FAILURE;
        }

        try {
            $folderTitle = $entry['legacy_title'] ?? null;
            if ($folderTitle && !$media->title_native && $this->containsNonLatin($folderTitle)) {
                $media->title_native = $folderTitle;
                $media->save();
            }
            $count = $this->importOne($disk, $entry['path'], $media->id, $force, $media);
            $this->info("Imported {$count} chapter(s) for id {$mediaId}");
            return self::SUCCESS;
        } catch (\Throwable $ex) {
            $this->error('Import failed: '.$ex->getMessage());
            return self::FAILURE;
        }
    }

    private function normalizeForcedPath(mixed $value): ?string
    {
        $path = trim((string) $value);

        if ($path === '') {
            return null;
        }

        return trim(str_replace('\\', '/', $path), '/');
    }

    private function entryFromForcedPath($disk, string $path): ?array
    {
        if (!$disk->exists($path)) {
            return null;
        }

        $segments = array_values(array_filter(explode('/', trim($path, '/'))));
        if (count($segments) < 2 || $segments[0] !== 'doujin') {
            return null;
        }

        $folder = (string) end($segments);
        $author = count($segments) > 2 ? $segments[count($segments) - 2] : null;
        $mediaId = ctype_digit($folder) ? (int) $folder : null;

        return [
            'author' => $author,
            'folder' => $folder,
            'path' => $path,
            'media_id' => $mediaId && $mediaId > 0 ? $mediaId : null,
            'legacy_title' => $mediaId ? null : $folder,
        ];
    }

    private function importOne($disk, string $targetPath, int $mediaId, bool $force, ?Media $media = null): int
    {
        if ($force) {
            $chapterIds = Chapter::where('media_fk', $mediaId)->pluck('id');
            ChapterPage::whereIn('chapter_id', $chapterIds)->delete();
            Chapter::where('media_fk', $mediaId)->delete();
            $this->warn("Deleted existing chapters/pages for media {$mediaId}");
        }

        $chapterDirs = $disk->directories($targetPath);
        if (empty($chapterDirs)) $chapterDirs = [$targetPath];
        usort($chapterDirs, 'strnatcasecmp');

        $chapterCount = 0;
        $seenChapterIds = [];

        foreach ($chapterDirs as $idx => $chPath) {
            $folderName = basename($chPath);
            $files = $disk->files($chPath);
            $images = array_values(array_filter($files, function ($f) {
                $ext = strtolower(pathinfo($f, PATHINFO_EXTENSION));
                return in_array($ext, ['jpg','jpeg','png','webp','gif'], true);
            }));
            if (empty($images)) {
                continue;
            }
            usort($images, 'strnatcasecmp');

            $chapterNum = $this->parseChapterNumber($folderName) ?? (float)($idx + 1);
            $chapterNumber = $this->ensureUniqueChapterNumber($mediaId, $chapterNum, $folderName);

            $chapter = Chapter::updateOrCreate(
                ['media_fk' => $mediaId, 'chapter_number' => $chapterNumber],
                ['item_type' => 'doujin', 'item_id' => $mediaId, 'chapter_title' => $folderName]
            );
            $seenChapterIds[] = $chapter->id;

            foreach ($images as $i => $rel) {
                ChapterPage::updateOrCreate(
                    ['chapter_id' => $chapter->id, 'page_number' => $i + 1],
                    ['file_path'  => ltrim($rel, '/')]
                );
            }

            $pageNumbers = range(1, count($images));
            ChapterPage::where('chapter_id', $chapter->id)
                ->whereNotIn('page_number', $pageNumbers)
                ->delete();

            if ($media) {
                $coverMissing = !$media->cover_url || !$disk->exists((string) $media->cover_url);
                if ($coverMissing) {
                    $media->cover_url = ltrim($images[0], '/');
                    $media->save();
                }
            }

            $chapterCount++;
        }

        $staleChapters = Chapter::where('media_fk', $mediaId)
            ->when(
                !empty($seenChapterIds),
                fn ($query) => $query->whereNotIn('id', $seenChapterIds)
            )
            ->when(
                empty($seenChapterIds),
                fn ($query) => $query
            )
            ->pluck('id');

        if ($staleChapters->isNotEmpty()) {
            ChapterPage::whereIn('chapter_id', $staleChapters)->delete();
            Chapter::whereIn('id', $staleChapters)->delete();
        }

        Media::where('id',$mediaId)->update(['chapters_cnt' => $chapterCount]);
        return $chapterCount;
    }

    private function parseChapterNumber(string $name): ?float
    {
        if (preg_match('/(\d+(?:[\._]\d+)?)/', strtolower($name), $m)) {
            $n = strtr($m[1], ['_' => '.', ',' => '.']);
            return (float)$n;
        }
        return null;
    }

    private function ensureUniqueChapterNumber(int $mediaId, float $base, string $title): float
    {
        $norm = fn ($n) => (float) number_format($n, 2, '.', '');
        $n = $norm($base);

        $existing = Chapter::where('media_fk',$mediaId)->where('chapter_number',$n)->first();
        if (!$existing) return $n;
        if (trim($existing->chapter_title) === trim($title)) return $n;

        for ($i=1; $i<=500; $i++) {
            $cand = $norm($base + $i*0.01);
            $row = Chapter::where('media_fk',$mediaId)->where('chapter_number',$cand)->first();
            if (!$row || trim($row->chapter_title) === trim($title)) return $cand;
        }
        return $norm($base + mt_rand(1,999)/100.0);
    }

    private function containsNonLatin(string $value): bool
    {
        return preg_match('/[^\p{Latin}\p{Common}\p{Inherited}\p{Nd}\p{Zs}\p{P}\p{S}]/u', $value) === 1;
    }

    private function authorsForEntry(array $entry): ?array
    {
        $author = trim((string) ($entry['author'] ?? ''));

        return $author === '' ? null : [$author];
    }
}
