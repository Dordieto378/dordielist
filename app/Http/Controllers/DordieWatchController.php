<?php

namespace App\Http\Controllers;

use App\Models\Media;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

class DordieWatchController extends Controller
{
    public function config(Request $request): JsonResponse
    {
        abort_unless($this->isLocalRequest($request), 403);

        return response()->json([
            'version' => 1,
            'library_url' => URL::signedRoute('dordiewatch.library'),
        ]);
    }

    public function show(Media $media): JsonResponse
    {
        abort_unless(in_array($this->dordieWatchMediaType($media), ['anime', 'hentai', 'movie'], true), 404);

        return response()->json([
            ...$this->mediaPayload($media),
            'library_url' => URL::signedRoute('dordiewatch.library'),
        ]);
    }

    public function library(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'ids' => ['required', 'array', 'max:500'],
            'ids.*' => ['required', 'integer', 'min:1'],
        ]);

        $requestedIds = collect($validated['ids'])
            ->map(fn (mixed $id): int => (int) $id)
            ->unique()
            ->values();

        $mediaById = Media::query()
            ->whereIn('id', $requestedIds)
            ->with([
                'anilistGenres:id,name',
                'anilistStudios:id,name',
                'tmdbGenres:id,name',
                'tmdbProductionCompanies:id,name',
            ])
            ->get()
            ->filter(fn (Media $media): bool => in_array(
                $this->dordieWatchMediaType($media),
                ['anime', 'hentai', 'movie'],
                true
            ))
            ->keyBy('id');

        $availableIds = $requestedIds
            ->filter(fn (int $id): bool => $mediaById->has($id))
            ->values();

        DB::transaction(function () use ($availableIds): void {
            if ($availableIds->isEmpty()) {
                DB::table('dordiewatch_media')->delete();

                return;
            }

            DB::table('dordiewatch_media')
                ->whereNotIn('media_id', $availableIds)
                ->delete();

            DB::table('dordiewatch_media')->upsert(
                $availableIds
                    ->map(fn (int $id): array => [
                        'media_id' => $id,
                        'last_seen_at' => now(),
                    ])
                    ->all(),
                ['media_id'],
                ['last_seen_at']
            );
        });

        return response()->json([
            'version' => 1,
            'available_ids' => $availableIds,
            'media' => $requestedIds
                ->map(fn (int $id): ?array => $mediaById->has($id)
                    ? $this->mediaPayload($mediaById->get($id))
                    : null)
                ->filter()
                ->values(),
        ]);
    }

    private function mediaPayload(Media $media): array
    {
        $media->loadMissing([
            'anilistGenres:id,name',
            'anilistStudios:id,name',
            'tmdbGenres:id,name',
            'tmdbProductionCompanies:id,name',
        ]);

        $titles = [
            'english' => $this->cleanString($media->title_english),
            'romaji' => $this->cleanString($media->title_romaji),
            'native' => $this->cleanString($media->title_native),
        ];
        $isMovie = strtolower((string) $media->type) === 'movie';
        $genres = $media->metadataNamesFrom($isMovie ? 'tmdbGenres' : 'anilistGenres');

        return [
            'version' => 1,
            'id' => $media->id,
            'source' => $media->source,
            'source_id' => $media->source_id,
            'type' => $this->dordieWatchMediaType($media, $genres),
            'title' => $titles,
            'display_title' => collect($titles)->first(fn (?string $title) => filled($title)) ?? 'Untitled',
            'cover_url' => $this->absoluteMediaUrl($media->cover_url),
            'banner_url' => $this->absoluteMediaUrl($media->banner_url),
            'description' => $this->plainDescription($media->description),
            'episodes' => $media->episodes_cnt ? (int) $media->episodes_cnt : null,
            'year' => $media->year ? (int) $media->year : null,
            'genres' => $genres,
            'studios' => $media->metadataNamesFrom($isMovie ? 'tmdbProductionCompanies' : 'anilistStudios'),
            'website_url' => $isMovie
                ? route('movies.show', ['media' => $media->id])
                : route('media.show', ['id' => $media->id]),
        ];
    }

    private function dordieWatchMediaType(Media $media, ?array $genres = null): string
    {
        $type = strtolower((string) $media->type);

        if ($type === 'movie') {
            return 'movie';
        }

        $genres ??= $media->metadataNamesFrom('anilistGenres');

        if ($type === 'hentai') {
            return 'hentai';
        }

        $normalizedGenres = array_map(
            fn (string $genre): string => strtolower($genre),
            $genres
        );

        if ($type === 'anime' && in_array('hentai', $normalizedGenres, true)) {
            return 'hentai';
        }

        return $type;
    }

    private function cleanString(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }

    private function absoluteMediaUrl(?string $path): ?string
    {
        $path = trim((string) $path);

        if ($path === '') {
            return null;
        }

        if (Str::startsWith($path, ['http://', 'https://'])) {
            return $path;
        }

        if (Str::startsWith($path, '/')) {
            return url($path);
        }

        return asset($path);
    }

    private function plainDescription(?string $description): ?string
    {
        $description = preg_replace('/<\s*br\s*\/?>/i', "\n", (string) $description);
        $description = preg_replace('/<\/p>\s*<p>/i', "\n\n", $description);
        $description = html_entity_decode(strip_tags($description), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $description = trim((string) preg_replace("/\n{3,}/", "\n\n", $description));

        return $description !== '' ? $description : null;
    }

    private function isLocalRequest(Request $request): bool
    {
        return in_array($request->ip(), ['127.0.0.1', '::1'], true);
    }
}
