<?php

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
        // For example, you could bind a custom exception handler:
        // $exceptions->singleton(
        //     Illuminate\Contracts\Debug\ExceptionHandler::class,
        //     App\Exceptions\Handler::class
        // );
    })

    // 4) Scheduled tasks
    ->withSchedule(function (Schedule $schedule) {
        // $schedule->command('inspire')->hourly();
    })

    // Finally, build the application instance
    ->create();
