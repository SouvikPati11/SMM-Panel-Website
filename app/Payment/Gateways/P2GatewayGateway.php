<?php

declare(strict_types=1);

namespace App\Payment\Gateways;

use App\Core\Request;
use App\Payment\AbstractGateway;
use App\Payment\CallbackResult;
use App\Payment\PaymentException;
use App\Payment\PaymentInit;

/**
 * P2Gateway.in — ISOLATED PLACEHOLDER. NOT A WORKING INTEGRATION.
 *
 * At development time no official, public API documentation for P2Gateway.in
 * could be obtained, so no endpoint, parameter, authentication scheme or
 * signature algorithm has been implemented (inventing them would be unsafe).
 *
 * This adapter:
 *   - stores credentials securely (encrypted) so they are ready when implemented;
 *   - reports isImplemented() = false, so the gateway can never be offered to users;
 *   - refuses every call with a clear exception.
 *
 * To complete it, obtain from P2Gateway.in (see docs/payment-gateways.md):
 *   1. Base API URL and API version            5. Webhook/callback payload format and all status values
 *   2. Authentication method (headers/keys)    6. Webhook signature algorithm + which secret signs it
 *   3. "Create payment/order" endpoint + params 7. Status-query endpoint (server-side verification)
 *   4. Response fields (payment URL, reference) 8. Sandbox credentials, IP allow-list, currency (INR?) rules
 * Then implement createPayment(), handleCallback() and verifyPayment() following
 * OxaPayGateway as a template, and set isImplemented() to true.
 */
final class P2GatewayGateway extends AbstractGateway
{
    public function key(): string
    {
        return 'p2gateway';
    }

    public function label(): string
    {
        return 'P2Gateway.in (not yet implemented)';
    }

    public function isImplemented(): bool
    {
        return false;
    }

    public function credentialFields(): array
    {
        return [
            ['name' => 'api_key', 'label' => 'API Key', 'secret' => true, 'required' => false, 'help' => 'Stored encrypted. Unused until the integration is implemented from official docs.'],
            ['name' => 'api_secret', 'label' => 'API Secret / Salt', 'secret' => true, 'required' => false, 'help' => 'Stored encrypted.'],
            ['name' => 'merchant_id', 'label' => 'Merchant ID', 'secret' => false, 'required' => false],
        ];
    }

    public function createPayment(array $payment, array $user, string $returnUrl, string $callbackUrl): PaymentInit
    {
        throw new PaymentException('P2Gateway.in integration is not implemented (official API documentation required).', 'This payment method is not available yet.');
    }

    public function handleCallback(Request $request): CallbackResult
    {
        return CallbackResult::invalid('P2Gateway.in callbacks are not implemented; payload ignored.');
    }

    public function verifyPayment(array $payment): CallbackResult
    {
        return CallbackResult::invalid('P2Gateway.in verification is not implemented.');
    }
}
