<?php

namespace App\Http\Middleware;

use Illuminate\Auth\Middleware\Authenticate as Middleware;

class Authenticate extends Middleware
{
    /**
     * Get the path the user should be redirected to when they are not authenticated.
     */
    protected function redirectTo($request)
    {
        // if it’s not an AJAX/JSON request, send them to your login page
        if (! $request->expectsJson()) {
            return route('login');
        }
    }
}
