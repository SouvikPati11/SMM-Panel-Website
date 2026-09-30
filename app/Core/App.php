<?php

declare(strict_types=1);

namespace App\Core;

use App\Core\Exceptions\HttpException;
use App\Core\Exceptions\ValidationException;
use App\Middleware\Pipeline;
use App\Services\SettingsService;

/**
 * HTTP kernel: routes the request through middleware to a controller and
 * applies security headers to every response.
 */
final class App
{
    private static ?Router $router = null;
    private static ?Request $request = null;
    /** Tests can force the installed state without touching storage/installed.lock. */
    public static ?bool $forceInstalled = null;

    public static function router(): Router
    {
        if (self::$router === null) {
            self::$router = new Router();
            $router = self::$router;
            require BASE_PATH . '/routes/web.php';
            require BASE_PATH . '/routes/admin.php';
            require BASE_PATH . '/routes/api.php';
        }
        return self::$router;
    }

    public static function request(): ?Request
    {
        return self::$request;
    }

    public static function isInstalled(): bool
    {
        if (self::$forceInstalled !== null) {
            return self::$forceInstalled;
        }
        return is_file(STORAGE_PATH . '/installed.lock');
    }

    public static function run(): void
    {
        $request = Request::capture();
        $response = self::handle($request);
        $response->send();
    }

    public static function handle(Request $request): Response
    {
        self::$request = $request;
        \App\Services\CurrencyService::reset(); // display currency is resolved per request
        Cookie::reset();

        // Before installation, everything goes to the installer.
        if (!self::isInstalled()) {
            if (!str_starts_with($request->path(), '/install')) {
                return Response::redirect('/install');
            }
            return self::secure(\App\Install\Installer::handle($request), $request);
        }
        if (str_starts_with($request->path(), '/install')) {
            throw new HttpException(404);
        }

        if (Config::get('force_https') && !$request->isSecure() && PHP_SAPI !== 'cli') {
            return Response::redirect(url($request->path()), 301);
        }

        SettingsService::boot();

        [$route, $params] = self::router()->match($request);
        $request->setAttribute('route_params', $params);

        $stateless = in_array('stateless', $route['middleware'], true);
        $request->setAttribute('session', !$stateless);
        if (!$stateless) {
            Session::start($request);
            Session::ageFlashInput();
        }

        $core = static function (Request $req) use ($route, $params): Response {
            return self::dispatch($route['handler'], $req, $params);
        };

        try {
            $response = Pipeline::run($request, $route['middleware'], $core);
        } catch (ValidationException $e) {
            if ($request->wantsJson() || $stateless) {
                $response = Response::json(['error' => $e->getMessage(), 'errors' => $e->errors()], 422);
            } else {
                Session::flash('error', $e->getMessage());
                Session::flashInput($request->post());
                $back = $request->server('HTTP_REFERER');
                $response = Response::redirect(is_string($back) && self::sameOrigin($back) ? $back : url($request->path()));
            }
        }
        return self::secure($response, $request);
    }

    private static function dispatch(array|\Closure $handler, Request $request, array $params): Response
    {
        if ($handler instanceof \Closure) {
            $result = $handler($request, ...array_values($params));
        } else {
            [$class, $method] = $handler;
            $controller = new $class();
            $args = [];
            foreach ($params as $k => $v) {
                $args[] = in_array($k, ['id', 'page'], true) ? (int) $v : $v;
            }
            $result = $controller->$method($request, ...$args);
        }
        if ($result instanceof Response) {
            return $result;
        }
        if (is_array($result)) {
            return Response::json($result);
        }
        return Response::html((string) $result);
    }

    public static function sameOrigin(string $url): bool
    {
        $host = parse_url($url, PHP_URL_HOST);
        $appHost = parse_url((string) Config::get('url'), PHP_URL_HOST);
        return $host !== null && $host === $appHost;
    }

    private static function secure(Response $response, Request $request): Response
    {
        Cookie::attach($response);
        $response->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader('X-Frame-Options', 'SAMEORIGIN')
            ->withHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
            ->withHeader('Permissions-Policy', 'camera=(), microphone=(), geolocation=()')
            ->withHeader('Cross-Origin-Opener-Policy', 'same-origin');
        if ($request->isSecure()) {
            $response->withHeader('Strict-Transport-Security', 'max-age=31536000');
        }
        $type = $response->header('Content-Type') ?? 'text/html';
        if (str_starts_with($type, 'text/html') && $response->header('Content-Security-Policy') === null) {
            $response->withHeader(
                'Content-Security-Policy',
                "default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data: https:; font-src 'self' data:; connect-src 'self'; frame-ancestors 'self'; form-action 'self' https:; base-uri 'self'; object-src 'none'"
            );
        }
        if (!$response->header('Cache-Control')) {
            $response->withHeader('Cache-Control', 'no-store, private');
        }
        // Account, admin, auth and installer pages must never be indexed — also for
        // JSON, redirects and error responses, which carry no <meta name="robots">.
        if (\App\Services\SeoService::isPrivatePath($request->path())) {
            $response->withHeader('X-Robots-Tag', 'noindex, nofollow');
        }
        return $response;
    }
}
