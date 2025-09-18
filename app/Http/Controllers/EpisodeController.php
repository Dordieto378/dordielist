<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Episode;
use App\Models\Media;
use Illuminate\Support\Facades\Storage;


class EpisodeController extends Controller
{
    /**
     * POST /media/{media}/episodes
     * Stores uploaded video files under: anime/{mediaId}/ep-{N}.{ext}
     */
    public function syncFromDisk(Request $request, int $mediaId)
    {
        // ensure the media exists
        $media = Media::findOrFail($mediaId);

        $disk = Storage::disk('public'); // storage/app/public
        $dir  = "anime/{$mediaId}";

        if (! $disk->exists($dir)) {
            return back()->with('status', "Folder not found: {$dir}");
        }

        // find all .mp4/.webm files
        $files = collect($disk->files($dir))
            ->filter(fn($path) => in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)), ['mp4','webm']))
            ->sortBy(fn($path) => strtolower(basename($path)))
            ->values();

        if ($files->isEmpty()) {
            return back()->with('status', "No .mp4/.webm files found in {$dir}");
        }

        // already registered episodes
        $existing = Episode::where('media_fk', $mediaId)
            ->pluck('file_path')
            ->map(fn($p) => ltrim($p, '/'))
            ->toArray();

        $nextEp  = (Episode::where('media_fk', $mediaId)->max('episode_number') ?? 0) + 1;
        $created = 0;

        foreach ($files as $relPath) {
            if (in_array($relPath, $existing, true)) {
                continue; // skip already added
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
                    'media_type' => 'ANIME',
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

    /**
     * GET /media/{media}/episodes/{episode}
     * Show the player page for a specific episode. (Uses local Media; no AniList.)
     */
    public function show($mediaId, $episodeNumber)
    {
        $media   = Media::findOrFail($mediaId);
        $episode = Episode::where('media_fk', $mediaId)
            ->where('episode_number', $episodeNumber)
            ->firstOrFail();

        // map a minimal “item” array your blade expects
        $item = [
            'id'          => $media->id,
            'type'        => strtoupper($media->type), // 'ANIME' | 'MANGA'
            'title'       => ['english' => $media->title, 'romaji' => $media->alt_title],
            'coverImage'  => ['extraLarge' => $media->cover_url ?: asset('images/no-image.jpg')],
            'description' => $media->description ?: 'No synopsis available.',
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

        // category string (matches your favorites/collections code)
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
