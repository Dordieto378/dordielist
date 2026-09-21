<?php
namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    /**
     * The Artisan commands provided by your application.
     *
     * @var array
     */
    protected $commands = [
        \App\Console\Commands\ImportVndb::class,
        \App\Console\Commands\ImportAniList::class,
        \App\Console\Commands\ImportTmdb::class,
        \App\Console\Commands\ImportDoujin::class,
        \App\Console\Commands\ImportDoujinArchives::class,
        \App\Console\Commands\StabilizeChapterStorage::class,
    ];

    /**
     * Define the application's command schedule.
     */
    protected function schedule(Schedule $schedule)
    {
        $schedule->command('anilist:import')
            ->everyFiveMinutes()
            ->withoutOverlapping(30)
            ->runInBackground()
            ->appendOutputTo(storage_path('logs/anilist-import.log'));

        $schedule->command('tmdb:import')
            ->everyFiveMinutes()
            ->withoutOverlapping(60)
            ->runInBackground()
            ->appendOutputTo(storage_path('logs/tmdb-import.log'));

        $schedule->command('vndb:import')
            ->hourly()
            ->withoutOverlapping(120)
            ->runInBackground()
            ->appendOutputTo(storage_path('logs/vndb-import.log'));
    }

    // …
}
