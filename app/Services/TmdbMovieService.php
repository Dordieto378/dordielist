<?php

namespace App\Services;

use App\Models\Media;
use App\Support\MediaMetadataSyncer;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

class TmdbMovieService
{
    private const API_URL = 'https://api.themoviedb.org/3';
    private const IMAGE_URL = 'https://image.tmdb.org/t/p/original';

    public function __construct(private readonly MediaMetadataSyncer $metadataSyncer)
    {
    }

    public function import(int $tmdbId, string $token): Media
    {
        $response = Http::withToken($token)
            ->acceptJson()
            ->timeout(30)
            ->get(self::API_URL."/movie/{$tmdbId}", [
                'append_to_response' => 'keywords',
                'language' => 'en-US',
            ]);

        $this->ensureSuccessful($response);
        $payload = $response->json();

        return DB::transaction(function () use ($payload, $tmdbId) {
            $releaseDate = $payload['release_date'] ?: null;
            $title = trim((string) ($payload['title'] ?? $payload['original_title'] ?? ''));
            if ($title === '') {
                throw new RuntimeException('TMDb returned a movie without a title.');
            }

            $existing = Media::where('source', 'tmdb')->where('source_id', $tmdbId)->first();
            $slug = $existing?->slug ?: $this->uniqueSlug($title, $tmdbId);

            $media = Media::updateOrCreate(
                ['source' => 'tmdb', 'source_id' => $tmdbId],
                [
                    'type' => 'movie',
                    'title_english' => $title,
                    'title_romaji' => null,
                    'title_native' => $payload['original_title'] ?? null,
                    'slug' => $slug,
                    'cover_url' => $this->imageUrl($payload['poster_path'] ?? null),
                    'banner_url' => $this->imageUrl($payload['backdrop_path'] ?? null),
                    'description' => $payload['overview'] ?: null,
                    'origin' => $payload['production_countries'][0]['iso_3166_1'] ?? null,
                    'media_status' => strtoupper(str_replace(' ', '_', (string) ($payload['status'] ?? ''))),
                    'year' => $releaseDate ? (int) substr($releaseDate, 0, 4) : null,
                    'start_date' => $releaseDate,
                    'runtime_minutes' => $payload['runtime'] ?? null,
                    'tmdb_vote_average' => $payload['vote_average'] ?? null,
                ]
            );

            $this->metadataSyncer->syncTmdb(
                $media,
                $this->records($payload['genres'] ?? []),
                $this->records($payload['keywords']['keywords'] ?? []),
                $this->records($payload['production_companies'] ?? [])
            );

            return $media->fresh(Media::METADATA_RELATIONS);
        });
    }

    private function records(array $records): array
    {
        return collect($records)
            ->filter(fn ($record) => is_array($record) && filled($record['name'] ?? null))
            ->map(fn (array $record) => [
                'name' => trim((string) $record['name']),
                'source_id' => isset($record['id']) ? (int) $record['id'] : null,
            ])
            ->values()
            ->all();
    }

    private function imageUrl(?string $path): ?string
    {
        return $path ? self::IMAGE_URL.$path : null;
    }

    private function uniqueSlug(string $title, int $tmdbId): string
    {
        $base = Str::slug($title) ?: 'tmdb-movie-'.$tmdbId;
        $slug = $base;
        $suffix = 2;

        while (Media::where('slug', $slug)->exists()) {
            $slug = $base.'-'.$suffix++;
        }

        return $slug;
    }

    private function ensureSuccessful(Response $response): void
    {
        if ($response->successful()) {
            return;
        }

        throw new RuntimeException(
            trim((string) $response->json('status_message'))
                ?: 'TMDb request failed with status '.$response->status().'.'
        );
    }
}
