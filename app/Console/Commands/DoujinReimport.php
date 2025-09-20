<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use App\Models\Media;
use App\Models\Chapter;
use App\Models\ChapterPage;

class DoujinReimport extends Command
{
    protected $signature = 'doujin:reimport {mediaId} {--force : Delete existing chapters/pages first}';
    protected $description = 'Re-import chapters/pages for one doujin by media id (media_fk). Searches storage/app/public/doujin/{*}/{<doujinName>}';

    public function handle(): int
    {
        $mediaId = (int) $this->argument('mediaId');

        /** @var Media|null $media */
        $media = Media::where('type', 'doujin')->find($mediaId);
        if (!$media) {
            $this->error("No doujin Media found with id {$mediaId}");
            return self::FAILURE;
        }

        $disk = Storage::disk('public');
        $root = 'doujin';

        if (!$disk->exists($root)) {
            $this->error("Folder not found: storage/app/public/{$root}");
            return self::FAILURE;
        }

        // Try to locate the folder for this doujin under ANY author folder:
        // storage/app/public/doujin/<authorFolder>/<doujinFolder>
        $doujinName = $media->title_romaji ?: ($media->title_english ?: ($media->slug ?: ''));
        if ($doujinName === '') {
            $this->error("Media {$mediaId} has no title_romaji/title_english/slug to match a folder.");
            return self::FAILURE;
        }

        $authorDirs = $disk->directories($root);
        $targetPath = null;
        foreach ($authorDirs as $authorPath) {
            $candidate = $authorPath . '/' . $doujinName;
            if ($disk->exists($candidate)) {
                $targetPath = $candidate;
                break;
            }
        }

        if (!$targetPath) {
            $this->error("Could not find a folder named '{$doujinName}' under any '{$root}/<author>' directory.");
            $this->line("Looked under: storage/app/public/{$root}/**/{$doujinName}");
            return self::FAILURE;
        }

        // Optional cleanup
        if ($this->option('force')) {
            $chapterIds = Chapter::where('media_fk', $mediaId)->pluck('id');
            ChapterPage::whereIn('chapter_id', $chapterIds)->delete();
            Chapter::where('media_fk', $mediaId)->delete();
            $this->warn("Deleted existing chapters/pages for media {$mediaId}");
        }

        // Build chapter list: if no subdirs, treat the doujin folder as one chapter
        $chapterDirs = $disk->directories($targetPath);
        if (empty($chapterDirs)) {
            $chapterDirs = [$targetPath];
        }
        usort($chapterDirs, 'strnatcasecmp');

        $chapterCount = 0;

        foreach ($chapterDirs as $idx => $chPath) {
            $folderName = basename($chPath);
            $chapterNum = $this->parseChapterNumber($folderName) ?? (float)($idx + 1);

            // Make sure (media_fk, chapter_number) is unique; if occupied by a different title, bump in +0.01 steps
            $chapterNumber = $this->ensureUniqueChapterNumber($mediaId, $chapterNum, $folderName);

            $chapter = Chapter::updateOrCreate(
                [
                    'media_fk'       => $mediaId,
                    'chapter_number' => $chapterNumber,
                ],
                [
                    'item_type'     => 'doujin',
                    'item_id'       => $mediaId,
                    'chapter_title' => $folderName,
                ]
            );

            // All image files inside this chapter
            $files = $disk->files($chPath);
            $images = array_values(array_filter($files, function ($f) {
                $ext = strtolower(pathinfo($f, PATHINFO_EXTENSION));
                return in_array($ext, ['jpg','jpeg','png','webp','gif'], true);
            }));
            if (empty($images)) {
                // nothing to import for this folder
                continue;
            }
            usort($images, 'strnatcasecmp');

            // Insert/update pages
            foreach ($images as $i => $rel) {
                ChapterPage::updateOrCreate(
                    [
                        'chapter_id'  => $chapter->id,
                        'page_number' => $i + 1,
                    ],
                    [
                        'file_path'   => ltrim($rel, '/'),
                    ]
                );
            }

            // If media had no cover_url, set it to the first image of the first chapter we saw
            if (!$media->cover_url) {
                $media->cover_url = ltrim($images[0], '/');
                $media->save();
            }

            $chapterCount++;
        }

        // Update count on media
        $media->update(['chapters_cnt' => $chapterCount]);

        $this->info("Re-imported {$chapterCount} chapter(s) for doujin media id {$mediaId}");
        return self::SUCCESS;
    }

    private function parseChapterNumber(string $name): ?float
    {
        // match e.g. "Chapter 1", "ch_02", "1.5", etc.
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
        $existing = Chapter::where('media_fk', $mediaId)
            ->where('chapter_number', $n)
            ->first();

        if (!$existing) return $n;
        if (trim($existing->chapter_title) === trim($title)) return $n;

        // bump by 0.01 until free
        for ($i = 1; $i <= 500; $i++) {
            $cand = $norm($base + $i * 0.01);
            $row = Chapter::where('media_fk', $mediaId)
                ->where('chapter_number', $cand)
                ->first();
            if (!$row || trim($row->chapter_title) === trim($title)) {
                return $cand;
            }
        }
        return $norm($base + mt_rand(1, 999) / 100.0);
    }
}
