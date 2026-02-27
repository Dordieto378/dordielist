<?php

namespace App\Support;

use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;

class EpisodeThumbnailer
{
    /**
     * Generate (or reuse) a thumbnail for an episode video stored on the public disk.
     */
    public static function generate(int $mediaId, int $episodeNumber, string $videoRelPath, bool $force = false): ?string
    {
        $disk = Storage::disk('public');
        $videoRelPath = ltrim($videoRelPath, '/');

        if (!$disk->exists($videoRelPath)) {
            return null;
        }

        $thumbRelDir = "episode-thumbs/{$mediaId}";
        $thumbRelPath = "{$thumbRelDir}/ep-{$episodeNumber}.jpg";
        $outputPath = $disk->path($thumbRelPath);

        if (!$force && $disk->exists($thumbRelPath)) {
            self::ensurePublicStorageMirror($thumbRelPath, $outputPath);
            return $thumbRelPath;
        }

        $disk->makeDirectory($thumbRelDir);

        $inputPath = $disk->path($videoRelPath);
        $tmpPath = $disk->path("{$thumbRelDir}/.tmp-{$episodeNumber}-".uniqid('', true).'.jpg');

        $ffmpegBin = (string) env('FFMPEG_BIN', 'ffmpeg');
        $seekCandidates = [
            8 + (($episodeNumber * 13) % 37), // deterministic variation per episode
            12,
            5,
            1,
        ];

        try {
            foreach ($seekCandidates as $seekSecond) {
                $process = new Process([
                    $ffmpegBin,
                    '-y',
                    '-hide_banner',
                    '-loglevel',
                    'error',
                    '-ss',
                    (string) $seekSecond,
                    '-i',
                    $inputPath,
                    '-frames:v',
                    '1',
                    '-q:v',
                    '3',
                    $tmpPath,
                ]);
                $process->setTimeout(60);
                $process->run();

                if ($process->isSuccessful() && is_file($tmpPath) && filesize($tmpPath) > 0) {
                    if (is_file($outputPath)) {
                        @unlink($outputPath);
                    }
                    if (!@rename($tmpPath, $outputPath)) {
                        @copy($tmpPath, $outputPath);
                        @unlink($tmpPath);
                    }

                    if (is_file($outputPath) && filesize($outputPath) > 0) {
                        self::ensurePublicStorageMirror($thumbRelPath, $outputPath);
                        return $thumbRelPath;
                    }
                }
            }
        } catch (\Throwable $e) {
            // Keep sync resilient: thumbnail generation failure should not fail episode sync.
        } finally {
            if (is_file($tmpPath)) {
                @unlink($tmpPath);
            }
        }

        return null;
    }

    /**
     * Some environments use a real public/storage directory instead of a symlink.
     * Mirror thumbnails there so /storage/... URLs resolve immediately.
     */
    private static function ensurePublicStorageMirror(string $thumbRelPath, string $sourcePath): void
    {
        $publicStorageRoot = public_path('storage');
        if (!is_dir($publicStorageRoot)) {
            return;
        }

        $targetPath = $publicStorageRoot.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, ltrim($thumbRelPath, '/'));
        $targetDir = dirname($targetPath);
        if (!is_dir($targetDir)) {
            @mkdir($targetDir, 0775, true);
        }

        if (!is_file($targetPath) || filesize($targetPath) <= 0) {
            @copy($sourcePath, $targetPath);
        }
    }
}
