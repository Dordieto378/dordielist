<?php

namespace App\Console\Commands;

use App\Models\Chapter;
use App\Models\ChapterPage;
use App\Models\Media;
use App\Support\MediaStoragePath;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class StabilizeChapterStorage extends Command
{
    protected $signature = 'chapters:stabilize-storage
        {--dry-run : Show the folder and database changes without applying them}
        {--force : Overwrite files if a stable target path already exists}';

    protected $description = 'Move manga/manwha chapter files to source-based folders and repair chapter page paths.';

    public function handle(): int
    {
        $disk = Storage::disk('public');
        $dryRun = (bool) $this->option('dry-run');
        $force = (bool) $this->option('force');

        $movedFiles = 0;
        $updatedPages = 0;
        $updatedCovers = 0;
        $conflicts = 0;

        Media::query()
            ->whereIn('type', ['manga', 'manwha'])
            ->whereNotNull('source_id')
            ->orderBy('id')
            ->chunkById(100, function ($mediaItems) use ($disk, $dryRun, $force, &$movedFiles, &$updatedPages, &$updatedCovers, &$conflicts) {
                foreach ($mediaItems as $media) {
                    $sourceDirs = $this->candidateDirs($media);
                    $targetDir = MediaStoragePath::chapterDirectory($media);

                    try {
                        foreach ($sourceDirs as $sourceDir) {
                            if ($sourceDir === $targetDir) {
                                continue;
                            }

                            $movedFiles += $this->moveDirectoryContents($disk, $sourceDir, $targetDir, $dryRun, $force);
                        }
                    } catch (\RuntimeException $e) {
                        $conflicts++;
                        $this->warn($e->getMessage());
                        continue;
                    }

                    [$pageCount, $coverCount] = $this->rewriteDatabasePaths($media, $sourceDirs, $targetDir, $dryRun);
                    $updatedPages += $pageCount;
                    $updatedCovers += $coverCount;
                }
            });

        $prefix = $dryRun ? 'Dry run complete.' : 'Chapter storage stabilized.';
        $this->info("{$prefix} moved_files={$movedFiles}, updated_pages={$updatedPages}, updated_covers={$updatedCovers}, conflicts={$conflicts}");

        return $conflicts > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function candidateDirs(Media $media): array
    {
        return array_values(array_unique(array_filter([
            MediaStoragePath::legacyChapterDirectory($media),
            MediaStoragePath::mediaTypeDirectory($media).'/'.$media->source_id,
            MediaStoragePath::chapterDirectory($media),
        ])));
    }

    private function moveDirectoryContents($disk, string $from, string $to, bool $dryRun, bool $force): int
    {
        if (!$disk->directoryExists($from)) {
            return 0;
        }

        $files = $disk->allFiles($from);
        if ($files === []) {
            return 0;
        }

        foreach ($files as $file) {
            $target = $this->replacePathPrefix($file, $from, $to);
            if ($target !== $file && $disk->exists($target) && !$force) {
                throw new \RuntimeException("Target already exists: {$target}. Re-run with --force to overwrite.");
            }
        }

        foreach ($files as $file) {
            $target = $this->replacePathPrefix($file, $from, $to);

            if ($dryRun) {
                $this->line("move {$file} -> {$target}");
                continue;
            }

            $disk->makeDirectory(dirname($target));
            if ($disk->exists($target)) {
                $disk->delete($target);
            }
            $disk->move($file, $target);
        }

        if (!$dryRun) {
            $disk->deleteDirectory($from);
        }

        return count($files);
    }

    private function rewriteDatabasePaths(Media $media, array $sourceDirs, string $targetDir, bool $dryRun): array
    {
        $chapterIds = Chapter::where('media_fk', $media->id)->pluck('id');
        if ($chapterIds->isEmpty()) {
            return [0, 0];
        }

        $updatedPages = 0;
        ChapterPage::whereIn('chapter_id', $chapterIds)
            ->orderBy('id')
            ->chunkById(500, function ($pages) use ($sourceDirs, $targetDir, $dryRun, &$updatedPages) {
                foreach ($pages as $page) {
                    $newPath = $this->rewriteByPrefixes((string) $page->file_path, $sourceDirs, $targetDir);
                    if ($newPath === $page->file_path) {
                        continue;
                    }

                    $updatedPages++;
                    if ($dryRun) {
                        $this->line("update page {$page->id}: {$page->file_path} -> {$newPath}");
                        continue;
                    }

                    $page->file_path = $newPath;
                    $page->save();
                }
            });

        $coverPath = is_string($media->cover_url) ? $media->cover_url : '';
        $newCoverPath = $this->rewriteByPrefixes($coverPath, $sourceDirs, $targetDir);
        $updatedCovers = 0;

        if ($newCoverPath !== $coverPath) {
            $updatedCovers = 1;
            if ($dryRun) {
                $this->line("update media {$media->id} cover: {$coverPath} -> {$newCoverPath}");
            } else {
                $media->cover_url = $newCoverPath;
                $media->save();
            }
        }

        return [$updatedPages, $updatedCovers];
    }

    private function rewriteByPrefixes(string $path, array $fromPrefixes, string $toPrefix): string
    {
        foreach ($fromPrefixes as $fromPrefix) {
            $rewritten = $this->replacePathPrefix($path, $fromPrefix, $toPrefix);
            if ($rewritten !== $path) {
                return $rewritten;
            }
        }

        return $path;
    }

    private function replacePathPrefix(string $path, string $fromPrefix, string $toPrefix): string
    {
        $path = ltrim(str_replace('\\', '/', $path), '/');
        $fromPrefix = trim(str_replace('\\', '/', $fromPrefix), '/');
        $toPrefix = trim(str_replace('\\', '/', $toPrefix), '/');

        if ($path === $fromPrefix) {
            return $toPrefix;
        }

        if (!Str::startsWith($path, $fromPrefix.'/')) {
            return $path;
        }

        return $toPrefix.'/'.Str::after($path, $fromPrefix.'/');
    }
}
