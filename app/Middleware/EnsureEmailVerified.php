<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Request;
use App\Core\Response;
use App\Services\Auth;

/** When email verification is enabled in settings, block panel access until verified. */
final class EnsureEmailVerified implements Middleware
{
    public function handle(Request $request, \Closure $next, ?string $arg = null): Response
    {
        $user = Auth::user();
        if ($user && setting('email_verification', '0') === '1' && empty($user['email_verified_at'])) {
            if ($request->wantsJson()) {
                return Response::json(['error' => 'Email address not verified'], 403);
            }
            return Response::redirect('/verify-email');
        }
        return $next($request);
    }
}
