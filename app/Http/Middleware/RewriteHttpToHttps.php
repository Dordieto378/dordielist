<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RewriteHttpToHttps
{
    public function handle(Request $request, Closure $next)
    {
        /** @var Response $response */
        $response = $next($request);

        // Only modify HTML responses
        if (
            $response->headers->has('Content-Type')
            && str_contains($response->headers->get('Content-Type'), 'text/html')
        ) {
            $content = $response->getContent();

            // Replace any http://your-domain with https://your-domain
            $httpHost = parse_url(config('app.url'), PHP_URL_HOST);
            $content = str_replace(
                "http://{$httpHost}",
                "https://{$httpHost}",
                $content
            );

            $response->setContent($content);
        }

        return $response;
    }
}
