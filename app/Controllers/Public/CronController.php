<?php

declare(strict_types=1);

namespace App\Controllers\Public;

use App\Controllers\Controller;
use App\Core\RateLimiter;
use App\Core\Request;
use App\Core\Response;
use App\Services\CronService;
use App\Services\CronStatus;
use App\Services\CronUrl;

/**
 * URL cron trigger: GET https://example.com/tasks/run/{key}
 * (Admin → Cron shows the exact URL and wget/curl commands.)
 *
 * Runs every task that is due, exactly like `php cron/run.php`, with the same
 * per-task database locks, and answers with a JSON summary. Disabled (404)
 * until a key exists (CRON_KEY in .env, or generated in Admin → Cron).
 */
final class CronController extends Controller
{
    public function run(Request $request, string $token): Response
    {
        if (!CronUrl::matches($token)) {
            // Remember the last rejected call (at most once a minute) so the admin can
            // see that the cron job reaches the site but uses an old/wrong URL.
            if (RateLimiter::hit('cronhttp:bad', 1, 60)) {
                CronStatus::write(['url_rejected_at' => now(), 'url_rejected_ip' => $request->ip()]);
            }
            return self::noCache(Response::text('not found', 404));
        }
        if (!RateLimiter::hit('cronhttp', 3, 50)) {
            return self::noCache(Response::json(['ok' => false, 'error' => 'Called too often; the next call runs normally.'], 429));
        }
        @ignore_user_abort(true); // keep running if the cron client disconnects
        @set_time_limit(600);
        $started = now();
        CronStatus::write([
            'invoked_at' => $started, 'finished_at' => null, 'stage' => 'running', 'exit_code' => null, 'error' => null,
            'php_version' => PHP_VERSION, 'sapi' => PHP_SAPI, 'trigger' => 'url', 'php_binary' => PHP_BINARY, 'script' => CronUrl::PATH,
            'url_last_at' => $started, 'url_last_ip' => $request->ip(), 'url_last_agent' => mb_substr((string) $request->userAgent(), 0, 120),
        ]);
        $results = CronService::runDue();
        $summary = array_map(static fn ($r) => $r['status'], $results);
        $errors = [];
        foreach ($results as $task => $r) {
            if ($r['status'] === 'failed') {
                $errors[] = "{$task}: " . ($r['output']['error'] ?? 'unknown error');
            }
        }
        CronStatus::write(['finished_at' => now(), 'stage' => $errors ? 'failed' : 'done', 'exit_code' => $errors ? 1 : 0, 'error' => $errors ? implode(' | ', $errors) : null, 'summary' => $summary, 'url_last_status' => $errors ? 500 : 200]
            + ($errors ? ['last_error' => implode(' | ', $errors), 'last_error_at' => now()] : ['last_success_at' => now()]));
        return self::noCache(Response::json([
            'ok' => !$errors,
            'ran' => $summary,
            'message' => $errors ? implode(' | ', $errors) : ($summary ? 'Ran ' . count($summary) . ' due task(s).' : 'No task was due; nothing to do.'),
            'time' => $started,
        ], $errors ? 500 : 200));
    }

    /** Never let LiteSpeed/proxies serve a cached answer instead of running the tasks. */
    private static function noCache(Response $r): Response
    {
        return $r->withHeader('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0')
            ->withHeader('X-LiteSpeed-Cache-Control', 'no-cache')
            ->withHeader('Pragma', 'no-cache');
    }
}
