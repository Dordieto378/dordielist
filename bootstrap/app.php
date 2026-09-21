<?php

use Illuminate\Http\Exceptions\PostTooLargeException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Routing;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Foundation\Configuration\Exceptions;
use Laravel\Fortify\Http\Middleware\EnsureTwoFactorAuthenticatable;
use Illuminate\Console\Scheduling\Schedule;

return Application::configure(basePath: dirname(__DIR__))
    // 1) Routing configuration
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )

    // 2) Middleware registration (aliases, global, groups, etc.)
    ->withMiddleware(function (Middleware $middleware) {
        // Alias middleware so you can refer to them by short keys:
        $middleware->alias([
            'auth'       => \App\Http\Middleware\Authenticate::class,                   // default auth
            'two_factor' => EnsureTwoFactorAuthenticatable::class,                     // Fortify 2FA
            // add more as needed...
        ]);

        // You could also append global middleware:
        // $middleware->append(\App\Http\Middleware\LogRequests::class);
    })

    // 3) Exception handling customization
    ->withExceptions(function (Exceptions $exceptions) {
        $exceptions->render(function (PostTooLargeException $exception, $request) {
            if ($request->is('vn/*/game/upload')) {
                return back()
                    ->withInput()
                    ->with('open_vn_game_upload_modal', true)
                    ->with('vn_game_upload_error', 'The ZIP is too large for this server.');
            }

            if ($request->is('media/*/episodes/upload') || $request->is('media/*/chapters/upload')) {
                return back()
                    ->withInput()
                    ->with('open_media_content_upload_modal', true)
                    ->with('media_content_upload_error', 'The ZIP is too large for this server.');
            }

            return back()
                ->withInput()
                ->with('error', 'The upload is too large for this server.');
        });
    })

    // 4) Scheduled tasks
    ->withSchedule(function (Schedule $schedule) {
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
    })

    // Finally, build the application instance
    ->create();
