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
        \App\Console\Commands\ImportAnilist::class,
        \App\Console\Commands\ImportDoujin::class,
        \App\Console\Commands\MigrateMediaMetadata::class,
        \App\Console\Commands\GenerateEpisodeThumbnails::class,
    ];

    /**
     * Define the application's command schedule.
     */
    protected function schedule(Schedule $schedule)
    {
        // (optional) if you want to rebuild nightly, you could do:
        // $schedule->command('doujins:build-index')->daily();
    }

    // …
}
