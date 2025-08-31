<?php
namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class UpgradeInsecureRequests
{
    public function handle(Request $request, Closure $next)
    {
        $response = $next($request);

        // Instruct browsers to turn any http:// resource into https://
        $response->headers->set(
            'Content-Security-Policy',
            "upgrade-insecure-requests"
        );

        return $response;
    }
}
