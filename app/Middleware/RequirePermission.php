<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Exceptions\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Services\Auth;

/** perm:orders.manage — the admin must hold the named permission (super admins hold all). */
final class RequirePermission implements Middleware
{
    public function handle(Request $request, \Closure $next, ?string $arg = null): Response
    {
        if ($arg === null || !Auth::adminCan($arg)) {
            throw new HttpException(403, 'Your role does not include permission for this section.');
        }
        return $next($request);
    }
}
