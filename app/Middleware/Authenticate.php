<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Services\Auth;

final class Authenticate implements Middleware
{
    public function handle(Request $request, \Closure $next, ?string $arg = null): Response
    {
        if (Auth::user() === null) {
            if ($request->wantsJson()) {
                return Response::json(['error' => 'Unauthenticated'], 401);
            }
            if ($request->method() === 'GET') {
                Session::set('intended', $request->path());
            }
            Session::flash('info', 'Please sign in to continue.');
            return Response::redirect('/login');
        }
        return $next($request);
    }
}
