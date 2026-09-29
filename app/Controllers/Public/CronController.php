<?php

declare(strict_types=1);

namespace App\Controllers\Public;

use App\Controllers\Controller;
use App\Core\Config;
use App\Core\RateLimiter;
use App\Core\Request;
use App\Core\Response;
use App\Services\CronService;
use App\Services\CronStatus;

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
        CronStatus::write(['invoked_at' => now(), 'finished_at' => null, 'stage' => 'running', 'exit_code' => null, 'error' => null, 'php_version' => PHP_VERSION, 'sapi' => PHP_SAPI . ' (URL trigger)', 'php_binary' => PHP_BINARY, 'script' => '/tasks/run']);
        $results = CronService::runDue();
        $summary = array_map(static fn ($r) => $r['status'], $results);
        $errors = [];
        foreach ($results as $task => $r) {
            if ($r['status'] === 'failed') {
                $errors[] = "{$task}: " . ($r['output']['error'] ?? 'unknown error');
            }
        }
        CronStatus::write(['finished_at' => now(), 'stage' => $errors ? 'failed' : 'done', 'exit_code' => $errors ? 1 : 0, 'error' => $errors ? implode(' | ', $errors) : null, 'summary' => $summary]
            + ($errors ? ['last_error' => implode(' | ', $errors), 'last_error_at' => now()] : ['last_success_at' => now()]));
        return Response::json($summary, $errors ? 500 : 200);
    }
}
