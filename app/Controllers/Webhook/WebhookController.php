<?php

declare(strict_types=1);

namespace App\Controllers\Webhook;

use App\Controllers\Controller;
use App\Core\RateLimiter;
use App\Core\Request;
use App\Core\Response;
use App\Services\PaymentService;

/**
 * Payment gateway callbacks: POST /webhooks/{gateway}. Stateless, no CSRF
 * (gateways can't send our token) — authenticity comes from signature
 * verification plus a server-to-server status query inside PaymentService.
 */
final class WebhookController extends Controller
{
    public function handle(Request $request, string $gateway): Response
    {
        if (!preg_match('/^[a-z0-9_]{2,40}$/', $gateway)) {
            return Response::text('not found', 404);
        }
        if (strlen($request->rawBody()) > 256 * 1024) {
            return Response::text('payload too large', 413);
        }
        if (!RateLimiter::hit('webhook:' . $request->ip(), 300, 60)) {
            return Response::text('slow down', 429);
        }
        return PaymentService::handleWebhook($gateway, $request);
    }
}
