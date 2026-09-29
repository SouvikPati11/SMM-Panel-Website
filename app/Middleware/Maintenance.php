<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Exceptions\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Services\Auth;

/** Blocks the public site and user panel in maintenance mode; admins bypass. */
final class Maintenance implements Middleware
{
    public function handle(Request $request, \Closure $next, ?string $arg = null): Response
    {
        if (setting('maintenance_mode', '0') === '1' && Auth::admin() === null) {
            throw new HttpException(503, (string) setting('maintenance_message', 'We are performing scheduled maintenance. Please check back soon.'));
        }
        return $next($request);
    }
}
