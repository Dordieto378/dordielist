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

        $entries = $this->folderIndex->scanDisk($disk, $root);
        if ($this->option('all')) {
            if (empty($entries)) {
                $this->warn('No doujin folders found on disk.');
                return self::SUCCESS;
            }

            $lookup = $this->folderIndex->buildMediaLookup();

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
                $this->metadataSyncer->syncDoujin($m, [$entry['author']]);

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

                        $this->metadataSyncer->syncDoujin($media, [$entry['author']]);
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

        $entry = $this->folderIndex->findEntryForMedia($media->loadMissing('doujinAuthors:id,name'), $entries);
        if (!$entry) {
            $this->error("Folder not found for '{$this->folderIndex->displayTitle($media)}' in {$root}/<author>/.");
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

        foreach ($chapterDirs as $idx => $chPath) {
            $folderName = basename($chPath);
            $chapterNum = $this->parseChapterNumber($folderName) ?? (float)($idx + 1);
            $chapterNumber = $this->ensureUniqueChapterNumber($mediaId, $chapterNum, $folderName);

            $chapter = Chapter::updateOrCreate(
                ['media_fk' => $mediaId, 'chapter_number' => $chapterNumber],
                ['item_type' => 'doujin', 'item_id' => $mediaId, 'chapter_title' => $folderName]
            );

            $files = $disk->files($chPath);
            $images = array_values(array_filter($files, function ($f) {
                $ext = strtolower(pathinfo($f, PATHINFO_EXTENSION));
                return in_array($ext, ['jpg','jpeg','png','webp','gif'], true);
            }));
            if (empty($images)) continue;
            usort($images, 'strnatcasecmp');

            foreach ($images as $i => $rel) {
                ChapterPage::updateOrCreate(
                    ['chapter_id' => $chapter->id, 'page_number' => $i + 1],
                    ['file_path'  => ltrim($rel, '/')]
                );
            }

            if ($media && !$media->cover_url) {
                $media->cover_url = ltrim($images[0], '/');
                $media->save();
            }

            $chapterCount++;
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
}
