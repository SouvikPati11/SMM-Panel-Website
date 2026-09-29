<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Request;
use App\Core\Response;
use App\Services\Auth;

final class RedirectIfAdmin implements Middleware
{
    public function handle(Request $request, \Closure $next, ?string $arg = null): Response
    {
        if (Auth::admin() !== null) {
            return Response::redirect(admin_url());
        }
        return $next($request);
    }
}
