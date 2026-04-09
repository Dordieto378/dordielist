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
        $token = $this->resolveToken();

        if (!$token) {
            $this->error('No AniList access token found. Save one in API settings or set ANILIST_ACCESS_TOKEN.');

            return self::FAILURE;
        }

        try {
            $result = $this->anilistSyncService->sync($token);
        } catch (\Throwable $e) {
            $this->error('AniList sync failed: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf(
            'AniList sync complete. Created: %d, updated: %d, deleted: %d.',
            $result['created'],
            $result['updated'],
            $result['deleted']
        ));

        return self::SUCCESS;
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
