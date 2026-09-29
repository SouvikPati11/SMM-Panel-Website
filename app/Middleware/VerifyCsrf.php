<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Csrf;
use App\Core\Exceptions\HttpException;
use App\Core\Request;
use App\Core\Response;

final class VerifyCsrf implements Middleware
{
    public function handle(Request $request, \Closure $next, ?string $arg = null): Response
    {
        if ($request->method() === 'POST' && !Csrf::verify($request)) {
            throw new HttpException(419, 'Invalid or expired form token.');
        }
        return $next($request);
    }
}
