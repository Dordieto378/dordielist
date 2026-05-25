<?php

namespace App\Console\Commands;

use App\Models\Episode;
use App\Models\Media;
use App\Support\MediaStoragePath;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class StabilizeEpisodeStorage extends Command
{
    protected $signature = 'episodes:stabilize-storage
        {--dry-run : Show the folder and database changes without applying them}
        {--force : Overwrite files if a stable target path already exists}
        {--map-hentai-sequence : Map unresolved numeric hentai folders to current hentai rows by sorted order}';

    protected $description = 'Move anime/hentai episode files to source-based folders and repair episode database paths.';

    private const VIDEO_EXTENSIONS = ['mp4', 'webm', 'mkv', 'avi', 'mov', 'm4v'];
    private const SUBTITLE_EXTENSIONS = ['vtt'];

    public function handle(): int
    {
        $disk = Storage::disk('public');
        $dryRun = (bool) $this->option('dry-run');
        $force = (bool) $this->option('force');
        $mapHentaiSequence = (bool) $this->option('map-hentai-sequence');

        $movedFiles = 0;
        $updatedRows = 0;
        $createdRows = 0;
        $conflicts = 0;

        Media::query()
            ->whereIn('type', ['anime', 'hentai'])
            ->whereNotNull('source_id')
            ->orderBy('id')
            ->chunkById(100, function ($mediaItems) use ($disk, $dryRun, $force, &$movedFiles, &$updatedRows, &$createdRows, &$conflicts) {
                foreach ($mediaItems as $media) {
                    $episodeDirs = $this->episodeCandidateDirs($media);
                    $subtitleDirs = $this->subtitleCandidateDirs($media);
                    $thumbDirs = $this->thumbnailCandidateDirs($media);

                    try {
                        foreach ($episodeDirs as $dir) {
                            if ($dir !== MediaStoragePath::episodeDirectory($media)) {
                                $movedFiles += $this->moveDirectoryContents(
                                    $disk,
                                    $dir,
                                    MediaStoragePath::episodeDirectory($media),
                                    $dryRun,
                                    $force,
                                    self::VIDEO_EXTENSIONS,
                                    $dir !== MediaStoragePath::mediaDirectory($media)
                                );
                            }
                        }

                        foreach ($subtitleDirs as $dir) {
                            if ($dir !== MediaStoragePath::subtitleDirectory($media)) {
                                $movedFiles += $this->moveDirectoryContents(
                                    $disk,
                                    $dir,
                                    MediaStoragePath::subtitleDirectory($media),
                                    $dryRun,
                                    $force,
                                    self::SUBTITLE_EXTENSIONS,
                                    $dir !== MediaStoragePath::mediaDirectory($media)
                                );
                            }
                        }

                        foreach ($thumbDirs as $dir) {
                            if ($dir !== MediaStoragePath::thumbnailDirectory($media)) {
                                $movedFiles += $this->moveDirectoryContents($disk, $dir, MediaStoragePath::thumbnailDirectory($media), $dryRun, $force);
                            }
                        }
                    } catch (\RuntimeException $e) {
                        $conflicts++;
                        $this->warn($e->getMessage());
                        continue;
                    }

                    $updatedRows += $this->rewriteEpisodeRows($media, $episodeDirs, $thumbDirs, $dryRun, $force);
                    $createdRows += $this->attachMissingEpisodes($media, $dryRun);
                }
            });

        if ($mapHentaiSequence) {
            [$sequenceMoved, $sequenceCreated, $sequenceConflicts] = $this->mapHentaiBySequence($disk, $dryRun, $force);
            $movedFiles += $sequenceMoved;
            $createdRows += $sequenceCreated;
            $conflicts += $sequenceConflicts;
        }

        $unresolved = $this->reportUnresolvedNumericFolders($disk);

        $prefix = $dryRun ? 'Dry run complete.' : 'Episode storage stabilized.';
        $this->info("{$prefix} moved_files={$movedFiles}, updated_rows={$updatedRows}, created_rows={$createdRows}, conflicts={$conflicts}, unresolved_numeric_folders={$unresolved}");

        return $conflicts > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function episodeCandidateDirs(Media $media): array
    {
        return array_values(array_unique(array_filter([
            MediaStoragePath::legacyEpisodeDirectory($media),
            MediaStoragePath::mediaTypeDirectory($media).'/'.$media->source_id,
            MediaStoragePath::mediaDirectory($media),
            MediaStoragePath::episodeDirectory($media),
        ])));
    }

    private function subtitleCandidateDirs(Media $media): array
    {
        return array_values(array_unique(array_filter([
            MediaStoragePath::legacyEpisodeDirectory($media),
            MediaStoragePath::mediaTypeDirectory($media).'/'.$media->source_id,
            MediaStoragePath::mediaDirectory($media),
            MediaStoragePath::subtitleDirectory($media),
        ])));
    }

    private function thumbnailCandidateDirs(Media $media): array
    {
        return array_values(array_unique(array_filter([
            MediaStoragePath::legacyThumbnailDirectory($media),
            MediaStoragePath::thumbnailDirectory($media),
        ])));
    }

    private function mapHentaiBySequence($disk, bool $dryRun, bool $force): array
    {
        $legacyDirs = collect($disk->directories('hentai'))
            ->filter(function (string $directory) {
                $name = basename($directory);
                if (!ctype_digit($name)) {
                    return false;
                }

                return !Media::where('type', 'hentai')
                    ->where(function ($query) use ($name) {
                        $query->where('id', (int) $name)
                            ->orWhere('source_id', (int) $name);
                    })
                    ->exists();
            })
            ->sortBy(fn (string $directory) => (int) basename($directory))
            ->values();

        if ($legacyDirs->isEmpty()) {
            return [0, 0, 0];
        }

        $mediaItems = Media::where('type', 'hentai')
            ->whereNotNull('source_id')
            ->orderBy('id')
            ->get();

        $movedFiles = 0;
        $createdRows = 0;
        $conflicts = 0;

        foreach ($legacyDirs as $index => $legacyDir) {
            $media = $mediaItems->get($index);
            if (!$media) {
                $this->warn("No current hentai row for sequence folder {$legacyDir}");
                $conflicts++;
                continue;
            }

            $targetDir = MediaStoragePath::episodeDirectory($media);
            $legacyThumbDir = 'episode-thumbs/'.basename($legacyDir);
            $targetThumbDir = MediaStoragePath::thumbnailDirectory($media);

            $this->line("sequence map {$legacyDir} -> {$targetDir} ({$media->title_romaji})");

            try {
                $movedFiles += $this->moveDirectoryContents($disk, $legacyDir, $targetDir, $dryRun, $force, self::VIDEO_EXTENSIONS);
                $movedFiles += $this->moveDirectoryContents($disk, $legacyDir, MediaStoragePath::subtitleDirectory($media), $dryRun, $force, self::SUBTITLE_EXTENSIONS);
                $movedFiles += $this->moveDirectoryContents($disk, $legacyThumbDir, $targetThumbDir, $dryRun, $force);
            } catch (\RuntimeException $e) {
                $conflicts++;
                $this->warn($e->getMessage());
                continue;
            }

            $createdRows += $this->attachMissingEpisodes($media, $dryRun, $dryRun ? $legacyDir : null);
        }

        return [$movedFiles, $createdRows, $conflicts];
    }

    private function moveDirectoryContents($disk, string $from, string $to, bool $dryRun, bool $force, ?array $extensions = null, bool $recursive = true): int
    {
        if (!$disk->directoryExists($from)) {
            return 0;
        }

        $files = $recursive ? $disk->allFiles($from) : $disk->files($from);
        if ($extensions !== null) {
            $extensionMap = array_fill_keys(array_map('strtolower', $extensions), true);
            $files = array_values(array_filter($files, function (string $file) use ($extensionMap) {
                return isset($extensionMap[strtolower(pathinfo($file, PATHINFO_EXTENSION))]);
            }));
        }

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

        if (!$dryRun && ($extensions === null || $disk->allFiles($from) === [])) {
            $disk->deleteDirectory($from);
        }

        return count($files);
    }

    private function rewriteEpisodeRows(Media $media, array $episodeDirs, array $thumbDirs, bool $dryRun, bool $force): int
    {
        $disk = Storage::disk('public');
        $updated = 0;
        $targetEpisodeDir = MediaStoragePath::episodeDirectory($media);
        $targetThumbDir = MediaStoragePath::thumbnailDirectory($media);
        $targetSubtitleDir = MediaStoragePath::subtitleDirectory($media);

        Episode::where('media_fk', $media->id)
            ->orderBy('id')
            ->chunkById(100, function ($episodes) use ($disk, $episodeDirs, $thumbDirs, $targetEpisodeDir, $targetThumbDir, $targetSubtitleDir, $dryRun, $force, &$updated) {
                foreach ($episodes as $episode) {
                    $filePath = $this->rewriteByPrefixes((string) $episode->file_path, $episodeDirs, $targetEpisodeDir);
                    $thumbPath = $this->rewriteByPrefixes((string) ($episode->thumbnail_path ?? ''), $thumbDirs, $targetThumbDir);
                    $filePath = $this->normalizeEpisodeVideoName($disk, $filePath, (int) $episode->episode_number, $targetEpisodeDir, $dryRun, $force);
                    $subtitlePaths = $this->subtitlePathsForVideo($disk, $filePath, $targetSubtitleDir, [
                        'default' => $episode->subtitle_path ?? null,
                        'top' => $episode->top_subtitle_path ?? null,
                        'center' => $episode->center_subtitle_path ?? null,
                    ]);
                    $subtitlePaths = $this->normalizeEpisodeSubtitleNames($disk, $subtitlePaths, (int) $episode->episode_number, $targetSubtitleDir, $dryRun, $force);

                    if (
                        $filePath === $episode->file_path
                        && $thumbPath === (string) ($episode->thumbnail_path ?? '')
                        && $subtitlePaths['default'] === ($episode->subtitle_path ?? null)
                        && $subtitlePaths['top'] === ($episode->top_subtitle_path ?? null)
                        && $subtitlePaths['center'] === ($episode->center_subtitle_path ?? null)
                    ) {
                        continue;
                    }

                    $updated++;
                    if ($dryRun) {
                        $this->line("update episode {$episode->id}: {$episode->file_path} -> {$filePath}");
                        continue;
                    }

                    $episode->file_path = $filePath;
                    $episode->thumbnail_path = $thumbPath !== '' ? $thumbPath : null;
                    $episode->subtitle_path = $subtitlePaths['default'];
                    $episode->top_subtitle_path = $subtitlePaths['top'];
                    $episode->center_subtitle_path = $subtitlePaths['center'];
                    $episode->save();
                }
            });

        return $updated;
    }

    private function subtitlePathsForVideo($disk, string $videoPath, string $targetSubtitleDir, array $storedPaths = []): array
    {
        $videoPath = ltrim(str_replace('\\', '/', $videoPath), '/');
        $videoBase = preg_replace('/\.[^.\/\\\\]+\z/', '', $videoPath);
        $targetBase = trim($targetSubtitleDir, '/').'/'.pathinfo($videoPath, PATHINFO_FILENAME);
        $episodeBase = trim($targetSubtitleDir, '/').'/'.preg_replace('/\.[^.\/\\\\]+\z/', '', basename($videoPath));

        return [
            'default' => $this->firstExistingPath($disk, [$storedPaths['default'] ?? null, $targetBase.'.vtt', $episodeBase.'.vtt', $videoBase.'.vtt']),
            'top' => $this->firstExistingPath($disk, [$storedPaths['top'] ?? null, $targetBase.'.top.vtt', $episodeBase.'.top.vtt', $videoBase.'.top.vtt']),
            'center' => $this->firstExistingPath($disk, [$storedPaths['center'] ?? null, $targetBase.'.center.vtt', $episodeBase.'.center.vtt', $videoBase.'.center.vtt']),
        ];
    }

    private function normalizeEpisodeVideoName($disk, string $path, int $episodeNumber, string $targetDir, bool $dryRun, bool $force): string
    {
        $path = ltrim(str_replace('\\', '/', $path), '/');
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if ($extension === '') {
            return $path;
        }

        $target = trim($targetDir, '/')."/eps{$episodeNumber}.{$extension}";

        return $this->movePathIfNeeded($disk, $path, $target, $dryRun, $force);
    }

    private function normalizeEpisodeSubtitleNames($disk, array $paths, int $episodeNumber, string $targetDir, bool $dryRun, bool $force): array
    {
        $targetBase = trim($targetDir, '/')."/eps{$episodeNumber}";

        return [
            'default' => $paths['default']
                ? $this->movePathIfNeeded($disk, $paths['default'], $targetBase.'.vtt', $dryRun, $force)
                : null,
            'top' => $paths['top']
                ? $this->movePathIfNeeded($disk, $paths['top'], $targetBase.'.top.vtt', $dryRun, $force)
                : null,
            'center' => $paths['center']
                ? $this->movePathIfNeeded($disk, $paths['center'], $targetBase.'.center.vtt', $dryRun, $force)
                : null,
        ];
    }

    private function movePathIfNeeded($disk, string $from, string $to, bool $dryRun, bool $force): string
    {
        $from = ltrim(str_replace('\\', '/', $from), '/');
        $to = ltrim(str_replace('\\', '/', $to), '/');

        if ($from === $to) {
            return $to;
        }

        if ($disk->exists($to) && !$force) {
            throw new \RuntimeException("Target already exists: {$to}. Re-run with --force to overwrite.");
        }

        if ($dryRun) {
            $this->line("rename {$from} -> {$to}");

            return $to;
        }

        $disk->makeDirectory(dirname($to));
        if ($disk->exists($to)) {
            $disk->delete($to);
        }
        $disk->move($from, $to);

        return $to;
    }

    private function firstExistingPath($disk, array $paths): ?string
    {
        foreach (array_unique(array_filter($paths)) as $path) {
            if ($disk->exists($path)) {
                return $path;
            }
        }

        return null;
    }

    private function attachMissingEpisodes(Media $media, bool $dryRun, ?string $sourceDir = null): int
    {
        $disk = Storage::disk('public');
        $episodeDir = MediaStoragePath::episodeDirectory($media);
        $scanDir = $sourceDir ?: $episodeDir;

        if (!$disk->directoryExists($scanDir)) {
            return 0;
        }

        $existing = Episode::where('media_fk', $media->id)
            ->pluck('episode_number')
            ->map(fn ($number) => (int) $number)
            ->all();
        $existingMap = array_fill_keys($existing, true);
        $next = $existing !== [] ? max($existing) + 1 : 1;
        $created = 0;

        foreach ($this->videoFiles($disk->allFiles($scanDir)) as $file) {
            $targetFile = $scanDir === $episodeDir
                ? $file
                : $this->replacePathPrefix($file, $scanDir, $episodeDir);
            $episodeNumber = $this->parseEpisodeNumber(basename($file));

            if ($episodeNumber === null) {
                while (isset($existingMap[$next])) {
                    $next++;
                }
                $episodeNumber = $next;
            }

            if (isset($existingMap[$episodeNumber])) {
                continue;
            }

            $thumbPath = MediaStoragePath::thumbnailDirectory($media)."/ep-{$episodeNumber}.jpg";
            $thumbPath = $disk->exists($thumbPath) ? $thumbPath : null;

            $created++;
            $existingMap[$episodeNumber] = true;
            $next = max($next, $episodeNumber + 1);

            if ($dryRun) {
                $this->line("attach {$targetFile} as media={$media->id} episode={$episodeNumber}");
                continue;
            }

            Episode::create([
                'media_fk' => $media->id,
                'media_type' => strtoupper((string) $media->type),
                'episode_number' => $episodeNumber,
                'file_path' => $targetFile,
                'thumbnail_path' => $thumbPath,
            ]);
        }

        if (!$dryRun && $created > 0 && ($media->source ?? null) !== 'anilist') {
            $media->episodes_cnt = Episode::where('media_fk', $media->id)->count();
            $media->save();
        }

        return $created;
    }

    private function rewriteByPrefixes(string $path, array $fromPrefixes, string $toPrefix): string
    {
        $normalizedPath = ltrim(str_replace('\\', '/', $path), '/');
        $normalizedTarget = trim(str_replace('\\', '/', $toPrefix), '/');
        if ($normalizedPath === $normalizedTarget || Str::startsWith($normalizedPath, $normalizedTarget.'/')) {
            return $path;
        }

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

    private function videoFiles(array $files): array
    {
        $videos = array_values(array_filter($files, function (string $file) {
            return in_array(strtolower(pathinfo($file, PATHINFO_EXTENSION)), self::VIDEO_EXTENSIONS, true);
        }));

        sort($videos, SORT_NATURAL | SORT_FLAG_CASE);

        return $videos;
    }

    private function parseEpisodeNumber(string $filename): ?int
    {
        $name = pathinfo($filename, PATHINFO_FILENAME);

        if (preg_match('/(?:episode|eps|ep|e)[\s\-_]*([0-9]{1,3})/i', $name, $matches)) {
            return (int) $matches[1];
        }

        if (preg_match('/\b([0-9]{1,3})\b/', $name, $matches)) {
            return (int) $matches[1];
        }

        return null;
    }

    private function reportUnresolvedNumericFolders($disk): int
    {
        $unresolved = 0;

        foreach (['anime', 'hentai'] as $type) {
            foreach ($disk->directories($type) as $directory) {
                $name = basename($directory);
                if (!ctype_digit($name)) {
                    continue;
                }

                $exists = Media::where('type', $type)
                    ->where(function ($query) use ($name) {
                        $query->where('id', (int) $name)
                            ->orWhere('source_id', (int) $name);
                    })
                    ->exists();

                if (!$exists) {
                    $unresolved++;
                    $this->warn("Unresolved legacy folder: {$directory}");
                }
            }
        }

        return $unresolved;
    }
}
