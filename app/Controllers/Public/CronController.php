<?php

declare(strict_types=1);

namespace App\Controllers\Public;

use App\Controllers\Controller;
use App\Core\Config;
use App\Core\RateLimiter;
use App\Core\Request;
use App\Core\Response;
use App\Services\CronService;

/**
 * Optional HTTP trigger for hosts whose cron can only fetch URLs:
 *   curl -s https://example.com/cron/run/CRON_KEY
 * Disabled unless CRON_KEY (32+ chars) is set in .env. CLI cron is preferred.
 */
final class CronController extends Controller
{
    public function run(Request $request, string $token): Response
    {
        $key = (string) Config::get('cron_key', '');
        if (strlen($key) < 32 || !hash_equals($key, $token)) {
            return Response::text('not found', 404);
        }
        if (!RateLimiter::hit('cronhttp', 3, 50)) {
            return Response::text('too frequent', 429);
        }
        @ignore_user_abort(true);
        $results = CronService::runDue();
        return Response::json(array_map(static fn ($r) => $r['status'], $results));
    }
}
