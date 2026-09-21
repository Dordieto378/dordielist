<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\TmdbSyncService;
use Illuminate\Console\Command;

class ImportTmdb extends Command
{
    protected $signature = 'tmdb:import';

    protected $description = 'Sync the configured TMDb movie lists into the local media table';

    public function __construct(private readonly TmdbSyncService $tmdbSync)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        try {
            $credentials = $this->resolveCredentials();
        } catch (\Throwable $exception) {
            $this->error('TMDb sync failed: '.$this->exceptionMessage($exception));

            return self::FAILURE;
        }

        if (! $credentials) {
            $this->error('No TMDb API Read Access Token found. Save one in API settings first.');

            return self::FAILURE;
        }

        [$apiToken, $sessionId] = $credentials;

        try {
            $result = $this->tmdbSync->sync($apiToken, $sessionId);
        } catch (\Throwable $exception) {
            $this->error('TMDb sync failed: '.$this->exceptionMessage($exception));

            return self::FAILURE;
        }

        $this->info(sprintf(
            'TMDb sync complete. Created: %d, updated: %d, failed: %d.',
            $result['created'],
            $result['updated'],
            $result['failed'],
        ));

        foreach ($result['failures'] as $failure) {
            $this->warn(sprintf(
                'Movie %d failed: %s',
                $failure['tmdb_id'],
                $failure['message'],
            ));
        }

        return $result['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function resolveCredentials(): ?array
    {
        $users = User::with('role')->get();
        $user = $users->first(fn (User $candidate) => optional($candidate->role)->role === 'Admin'
            && filled($candidate->tmdb_api_token)
            && filled($candidate->tmdb_session_id))
            ?? $users->first(fn (User $candidate) => filled($candidate->tmdb_api_token)
                && filled($candidate->tmdb_session_id))
            ?? $users->first(fn (User $candidate) => optional($candidate->role)->role === 'Admin'
                && filled($candidate->tmdb_api_token))
            ?? $users->first(fn (User $candidate) => filled($candidate->tmdb_api_token));

        if ($user) {
            return [$user->tmdb_api_token, $user->tmdb_session_id];
        }

        $configToken = config('services.tmdb.token');

        return filled($configToken) ? [$configToken, null] : null;
    }

    private function exceptionMessage(\Throwable $exception): string
    {
        return trim($exception->getMessage()) ?: 'Unknown error.';
    }
}
