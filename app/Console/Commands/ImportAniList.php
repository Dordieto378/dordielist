<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\AnilistSyncService;
use Illuminate\Console\Command;

class ImportAniList extends Command
{
    protected $signature = 'anilist:import';

    protected $description = 'Sync AniList entries into the local media table';

    public function __construct(private readonly AnilistSyncService $anilistSyncService)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        try {
            $token = $this->resolveToken();
        } catch (\Throwable $e) {
            $this->error('AniList sync failed: '.$this->formatExceptionMessage($e));

            return self::FAILURE;
        }

        if (!$token) {
            $this->error('No AniList access token found. Save one in API settings or set ANILIST_ACCESS_TOKEN.');

            return self::FAILURE;
        }

        try {
            $result = $this->anilistSyncService->sync($token);
        } catch (\Throwable $e) {
            $this->error('AniList sync failed: '.$this->formatExceptionMessage($e));

            return self::FAILURE;
        }

        $this->info(sprintf(
            'AniList sync %s. Created: %d, updated: %d, deleted: %d.',
            !empty($result['partial']) ? 'partial' : 'complete',
            $result['created'],
            $result['updated'],
            $result['deleted']
        ));

        foreach ($result['list_failures'] ?? [] as $failure) {
            $this->warn(sprintf(
                'Skipped %s %s: %s',
                $failure['type'] ?? 'UNKNOWN',
                $failure['status'] ?? 'UNKNOWN',
                $failure['message'] ?? 'Unknown error.'
            ));
        }

        if (!empty($result['list_failures'])) {
            $this->warn('Stale local AniList deletion was skipped because the remote list snapshot was incomplete.');
        }

        if (!empty($result['notification_error'])) {
            $this->warn('AniList notification sync failed: '.$result['notification_error']);
        }

        return self::SUCCESS;
    }

    private function formatExceptionMessage(\Throwable $e): string
    {
        $message = trim($e->getMessage());

        return $message !== '' ? $message : 'Unknown error.';
    }

    private function resolveToken(): ?string
    {
        $adminUser = User::with('role')
            ->get()
            ->first(function (User $user) {
                return optional($user->role)->role === 'Admin' && filled($user->anilist_access_token);
            });

        if ($adminUser && filled($adminUser->anilist_access_token)) {
            return $adminUser->anilist_access_token;
        }

        $userWithToken = User::query()
            ->get()
            ->first(fn (User $user) => filled($user->anilist_access_token));

        if ($userWithToken && filled($userWithToken->anilist_access_token)) {
            return $userWithToken->anilist_access_token;
        }

        $envToken = env('ANILIST_ACCESS_TOKEN');

        return filled($envToken) ? $envToken : null;
    }
}
