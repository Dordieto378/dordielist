<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Auth\Middleware\Authenticate as Middleware;
use Symfony\Component\HttpFoundation\Response;

class Authenticate extends Middleware
{
    public function handle($request, Closure $next, ...$guards): Response
    {
        $response = parent::handle($request, $next, ...$guards);

        $user = $request->user();

        if (! $user) {
            return $response;
        }

        if ($user->status === 'active') {
            return $response;
        }

        Auth::guard($guards[0] ?? null)->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        $message = $user->status === 'banned'
            ? 'This account has been banned.'
            : 'Your account is not active yet. Please wait for confirmation.';

        return redirect()
            ->route('login')
            ->with('status', $message)
            ->with('status_color', 'red');
    }

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
