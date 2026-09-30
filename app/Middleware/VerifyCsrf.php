<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Csrf;
use App\Core\Exceptions\HttpException;
use App\Core\Logger;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Services\Auth;

/**
 * CSRF protection for every session-based POST.
 *
 * A form left open until the session or its token expired used to end on a
 * bare "419" error page (a non-standard status some hosts replace with their
 * own error page, and the typed data was lost). Protection is unchanged —
 * nothing is ever processed without a valid token — but a same-site browser
 * form now gets a normal redirect back to the page it came from, a clear
 * message, and its fields restored (never passwords or secrets). If the login
 * also expired, the sign-in page comes first and then the same page, restored.
 * Cross-site POSTs are refused with 403 and nothing is restored, so this can't
 * be used to pre-fill a form for an admin.
 */
final class VerifyCsrf implements Middleware
{
    /** Keys that are never kept for restoring. */
    private const SENSITIVE = '/(pass|secret|token|otp|cvv|card|^key$|_key$|^code$)/i';

    public function handle(Request $request, \Closure $next, ?string $arg = null): Response
    {
        if ($request->method() !== 'POST' || Csrf::verify($request)) {
            return $next($request);
        }
        $sameOrigin = self::sameOrigin($request);
        Logger::info('Form token rejected (expired session or token)', ['path' => $request->path(), 'same_origin' => $sameOrigin, 'logged_in' => Auth::admin() !== null || Auth::user() !== null], 'security');
        if ($request->wantsJson()) {
            return Response::json(['error' => 'Your session or security token expired, so nothing was saved. Reload the page and try again.', 'code' => 'session_expired'], 419);
        }
        if (!$sameOrigin) {
            throw new HttpException(403, 'This form could not be verified, so nothing was saved. Open the page on this site and submit it again.');
        }
        $back = self::backPath($request);
        $area = str_starts_with($back, '/' . admin_path() . '/') || $back === '/' . admin_path() ? 'admin' : 'user';
        $signedIn = $area === 'admin' ? Auth::admin() !== null : Auth::user() !== null;
        $input = [];
        foreach ($request->post() as $k => $v) {
            if ($k !== '_token' && is_string($k) && !preg_match(self::SENSITIVE, $k) && (is_scalar($v) || is_array($v))) {
                $input[$k] = $v;
            }
        }
        // Restored when this page is next shown to a signed-in visitor (see App::handle).
        Session::set('_restore', ['path' => $back, 'input' => $input, 'at' => time()]);
        $isLoginForm = preg_match('#/(login|register)(/2fa)?$#', $request->path()) === 1;
        Session::flash('warning', $isLoginForm
            ? 'The page was open too long and its security token expired. Please try again.'
            : ($signedIn
                ? 'Your security token expired while the page was open, so nothing was saved. Your changes are restored below: check them and submit again.'
                : 'Your session expired after a period of inactivity, so nothing was saved. Sign in again and you will return to the page with your changes restored.'));
        return Response::redirect(url($back), 303);
    }

    /** Same site (Origin, then Referer, then the Fetch Metadata header). */
    private static function sameOrigin(Request $request): bool
    {
        $host = (string) parse_url(url('/'), PHP_URL_HOST);
        foreach (['Origin', 'Referer'] as $h) {
            $v = (string) $request->header($h);
            if ($v !== '' && $v !== 'null') {
                return strcasecmp((string) parse_url($v, PHP_URL_HOST), $host) === 0 || strcasecmp((string) parse_url($v, PHP_URL_HOST), (string) $request->server('HTTP_HOST')) === 0;
            }
        }
        return in_array((string) $request->header('Sec-Fetch-Site'), ['same-origin', 'same-site'], true);
    }

    /** The page the form was on (path + query on this site), or the posted path. */
    private static function backPath(Request $request): string
    {
        $ref = (string) $request->header('Referer');
        $base = rtrim((string) parse_url(url('/'), PHP_URL_PATH), '/');
        if ($ref !== '' && self::sameOrigin($request)) {
            $path = (string) parse_url($ref, PHP_URL_PATH);
            if ($base !== '' && str_starts_with($path, $base)) {
                $path = substr($path, strlen($base)) ?: '/';
            }
            $q = (string) parse_url($ref, PHP_URL_QUERY);
            if ($path !== '' && $path[0] === '/' && !str_starts_with($path, '//')) {
                return $path . ($q !== '' ? '?' . $q : '');
            }
        }
        return $request->path();
    }
}
