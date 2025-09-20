<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Episode;
use App\Models\Media;
use Illuminate\Support\Facades\Storage;


class EpisodeController extends Controller
{
    public function syncFromDisk(Request $request, int $mediaId)
    {
        $media = \App\Models\Media::findOrFail($mediaId);

        $type   = strtoupper($media->type ?? 'ANIME');
        // If your DB stores genres as JSON/text:
        $genres = is_array($media->genres) ? $media->genres : (json_decode($media->genres ?? '[]', true) ?: []);
        $hasH   = in_array('Hentai', $genres, true);

        $isHentai   = ($type === 'HENTAI') || ($type === 'ANIME' && $hasH);
        $baseDir    = $isHentai ? 'hentai' : 'anime';
        $mediaType  = $isHentai ? 'HENTAI' : 'ANIME';

        $disk = Storage::disk('public');
        $dir  = "{$baseDir}/{$mediaId}";

        if (!$disk->exists($dir)) {
            return back()->with('status', "Folder not found: {$dir}");
        }

        $files = collect($disk->files($dir))
            ->filter(fn($path) => in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)), ['mp4','webm']))
            ->sortBy(fn($path) => strtolower(basename($path)))
            ->values();

        if ($files->isEmpty()) {
            return back()->with('status', "No .mp4/.webm files found in {$dir}");
        }

        $existing = Episode::where('media_fk', $mediaId)
            ->pluck('file_path')
            ->map(fn($p) => ltrim($p, '/'))
            ->toArray();

        $nextEp  = (Episode::where('media_fk', $mediaId)->max('episode_number') ?? 0) + 1;
        $created = 0;

        foreach ($files as $relPath) {
            if (in_array($relPath, $existing, true)) {
                continue;
            }

            $basename  = basename($relPath);
            $parsedNum = $this->parseEpisodeNumber($basename);

            $epNumber = $parsedNum ?? $nextEp;
            if ($parsedNum === null) {
                $nextEp++;
            } else {
                $nextEp = max($nextEp, $epNumber + 1);
            }

            Episode::updateOrCreate(
                ['media_fk' => $mediaId, 'episode_number' => $epNumber],
                [
                    'media_type' => $mediaType,   // <-- ANIME or HENTAI correctly
                    'file_path'  => $relPath,
                ]
            );

            $created++;
        }

        return back()->with('status', "Synced {$created} new episode(s) from {$dir}");
    }


    private function parseEpisodeNumber(string $filename): ?int
    {
        $name = strtolower($filename);

        if (preg_match('/(?:episode|ep|e)[\s\-_]*([0-9]{1,3})/i', $name, $m)) {
            return (int) $m[1];
        }

        if (preg_match('/\b([0-9]{1,3})\b/', $name, $m)) {
            return (int) $m[1];
        }

        return null;
    }

    public function show($mediaId, $episodeNumber)
    {
        $media   = Media::findOrFail($mediaId);
        $episode = Episode::where('media_fk', $mediaId)
            ->where('episode_number', $episodeNumber)
            ->firstOrFail();

        $item = [
            'id'          => $media->id,
            'type'        => strtoupper($media->type),
            'title'       => [
                'english' => $media->title_english,
                'romaji'  => $media->title_romaji,
            ],
            'coverImage'  => ['extraLarge' => $media->cover_url ?: asset('images/no-image.jpg')],
            'description' => $media->description,
            'genres'      => is_array($media->genres) ? $media->genres : (json_decode($media->genres ?? '[]', true) ?: []),
            'tags'        => is_array($media->tags)   ? $media->tags   : (json_decode($media->tags   ?? '[]', true) ?: []),
            'averageScore'=> $media->avg_score,
            'episodes'    => null,
            'chapters'    => null,
            'volumes'     => null,
            'status'      => $media->media_status,
            'isAdult'     => (bool) $media->is_adult,
            'startDate'   => ['year' => $media->year, 'month' => null, 'day' => null],
            'countryOfOrigin' => $media->origin,
            'mediaListEntry' => [
                'score'    => $media->user_score,
                'progress' => $media->progress,
                'status'   => $media->list_status,
            ],
        ];

        $genres  = $item['genres'] ?? [];
        $type    = $item['type'] ?? '';
        $origin  = strtoupper($item['countryOfOrigin'] ?? '');
        if ($type === 'ANIME' && in_array('Hentai', $genres, true)) {
            $category = 'hentais';
        } elseif ($type === 'ANIME') {
            $category = 'animes';
        } elseif ($type === 'MANGA') {
            $category = ($origin === 'KR') ? 'manwhas' : 'mangas';
        } else {
            $category = 'animes';
        }

        return view('episodes.show', [
            'item'     => $item,
            'episode'  => $episode,
            'category' => $category,
        ]);
    }
}
