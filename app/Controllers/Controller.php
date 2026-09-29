<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\App;
use App\Core\Exceptions\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\View;
use App\Services\Auth;

abstract class Controller
{
    protected function view(string $template, array $data = [], int $status = 200): Response
    {
        return Response::html(View::render($template, $data), $status);
    }

    protected function redirect(string $to): Response
    {
        return Response::redirect($to);
    }

    /** Redirect to the previous page when it is on this site, else to $fallback. */
    protected function back(Request $request, string $fallback = '/'): Response
    {
        $ref = $request->server('HTTP_REFERER');
        if (is_string($ref) && App::sameOrigin($ref)) {
            return Response::redirect($ref);
        }
        return Response::redirect($fallback);
    }

    protected function success(string $message): void
    {
        Session::flash('success', $message);
    }

    protected function error(string $message): void
    {
        Session::flash('error', $message);
    }

    protected function json(mixed $data, int $status = 200): Response
    {
        return Response::json($data, $status);
    }

    protected function user(): array
    {
        $u = Auth::user();
        if (!$u) {
            throw new HttpException(401);
        }
        return $u;
    }

    protected function admin(): array
    {
        $a = Auth::admin();
        if (!$a) {
            throw new HttpException(401);
        }
        return $a;
    }

    protected function notFound(): never
    {
        throw new HttpException(404);
    }

    protected function pageNum(Request $request): int
    {
        return max(1, $request->int('page', 1));
    }
}
