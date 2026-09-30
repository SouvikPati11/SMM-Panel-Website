<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Services\Auth;

final class AdminAuthenticate implements Middleware
{
    public function handle(Request $request, \Closure $next, ?string $arg = null): Response
    {
        if (Auth::admin() === null) {
            if ($request->wantsJson()) {
                return Response::json(['error' => 'Unauthenticated'], 401);
            }
            if ($request->method() === 'GET') {
                Session::set('admin_intended', $request->pathWithQuery());
            }
            return Response::redirect(admin_url('login'));
        }
        return $next($request);
    }
}
