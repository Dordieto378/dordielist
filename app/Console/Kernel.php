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
        \App\Console\Commands\ImportDoujin::class,
        \App\Console\Commands\GenerateEpisodeThumbnails::class,
        \App\Console\Commands\StabilizeEpisodeStorage::class,
        \App\Console\Commands\StabilizeChapterStorage::class,
    ];

    /**
     * Define the application's command schedule.
     */
    protected function schedule(Schedule $schedule)
    {
        $schedule->command('anilist:import')
            ->everyThirtySeconds();
        $schedule->command('vndb:import')
            ->everyThirtySeconds();
    }

    // …
}
