<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Request;
use App\Core\Response;

/**
 * Resolves middleware aliases ("auth", "perm:orders.manage", "throttle:login,5,60")
 * and runs them as an onion around the controller.
 */
final class Pipeline
{
    private const ALIASES = [
        'csrf' => VerifyCsrf::class,
        'auth' => Authenticate::class,
        'guest' => RedirectIfAuthenticated::class,
        'verified' => EnsureEmailVerified::class,
        'admin' => AdminAuthenticate::class,
        'admin.guest' => RedirectIfAdmin::class,
        'perm' => RequirePermission::class,
        'throttle' => Throttle::class,
        'maintenance' => Maintenance::class,
    ];

    /** @param list<string> $middleware */
    public static function run(Request $request, array $middleware, \Closure $core): Response
    {
        $stack = [];
        foreach ($middleware as $m) {
            [$name, $arg] = array_pad(explode(':', $m, 2), 2, null);
            if ($name === 'stateless') {
                continue;
            }
            $class = self::ALIASES[$name] ?? null;
            if ($class === null) {
                throw new \InvalidArgumentException("Unknown middleware {$name}");
            }
            $stack[] = [$class, $arg];
        }

        $next = $core;
        foreach (array_reverse($stack) as [$class, $arg]) {
            $next = static function (Request $req) use ($class, $arg, $next): Response {
                return (new $class())->handle($req, $next, $arg);
            };
        }
        return $next($request);
    }
}
