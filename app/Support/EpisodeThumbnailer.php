<?php

namespace App\Support;

use App\Models\Media;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;

class EpisodeThumbnailer
{
    /**
     * Generate (or reuse) a thumbnail for an episode video stored on the public disk.
     */
    public static function generate(Media|int $media, int $episodeNumber, string $videoRelPath, bool $force = false): ?string
    {
        $disk = Storage::disk('public');
        $videoRelPath = ltrim($videoRelPath, '/');

        if (!$disk->exists($videoRelPath)) {
            return null;
        }

        $thumbRelDir = $media instanceof Media
            ? MediaStoragePath::thumbnailDirectory($media)
            : "episode-thumbs/{$media}";
        $thumbRelPath = "{$thumbRelDir}/ep-{$episodeNumber}.jpg";
        $outputPath = $disk->path($thumbRelPath);

        if (!$force && $disk->exists($thumbRelPath)) {
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
}
