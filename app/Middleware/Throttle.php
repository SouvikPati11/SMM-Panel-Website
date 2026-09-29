<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Exceptions\HttpException;
use App\Core\RateLimiter;
use App\Core\Request;
use App\Core\Response;
use App\Services\Auth;

/** throttle:name,maxHits,windowSeconds — per IP (and per user when logged in). POST only by default. */
final class Throttle implements Middleware
{
    public function handle(Request $request, \Closure $next, ?string $arg = null): Response
    {
        [$name, $max, $window] = array_pad(explode(',', (string) $arg), 3, null);
        $max = (int) ($max ?: 30);
        $window = (int) ($window ?: 60);
        if ($request->method() === 'POST') {
            $who = Auth::user()['id'] ?? $request->ip();
            if (!RateLimiter::hit('web:' . $name . ':' . $who, $max, $window)) {
                if ($request->wantsJson()) {
                    return Response::json(['error' => 'Too many requests. Please wait and try again.'], 429);
                }
                throw new HttpException(429, 'Too many attempts. Please wait a minute and try again.');
            }
        }
        return $next($request);
    }
}
