<?php

namespace App\Services;

use App\Models\Media;

class TmdbSyncService
{
    public function __construct(
        private readonly TmdbListService $tmdbLists,
        private readonly TmdbMovieService $tmdbMovies,
    ) {}

    public function sync(string $apiToken, ?string $sessionId = null): array
    {
        $entries = $this->tmdbLists->configuredListEntries($apiToken, $sessionId);
        $created = 0;
        $updated = 0;
        $failures = [];

        foreach ($entries as $tmdbId => $status) {
            $alreadyExists = Media::query()
                ->where('source', 'tmdb')
                ->where('source_id', $tmdbId)
                ->exists();

            try {
                $movie = $this->tmdbMovies->import($tmdbId, $apiToken);
                $movie->update(['list_status' => $status]);
                $alreadyExists ? $updated++ : $created++;
            } catch (\Throwable $exception) {
                $failures[] = [
                    'tmdb_id' => $tmdbId,
                    'message' => trim($exception->getMessage()) ?: 'Unknown error.',
                ];
            }
        }

        return [
            'created' => $created,
            'updated' => $updated,
            'failed' => count($failures),
            'failures' => $failures,
        ];
    }
}
