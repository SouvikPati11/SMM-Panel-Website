<?php

declare(strict_types=1);

namespace App\Payment;

use App\Core\Request;

/**
 * Contract for automatic payment gateways.
 *
 * Normalised statuses used everywhere: pending | completed | failed | expired | cancelled.
 * A wallet is credited ONLY when handleCallback() returns a verified result AND
 * verifyPayment() (server-to-server query) confirms "completed", or when the
 * cron verifier's verifyPayment() confirms it. Browser redirects never credit.
 */
interface PaymentGatewayInterface
{
    public function key(): string;

    public function label(): string;

    /** false for placeholders whose API is not implemented */
    public function isImplemented(): bool;

    public function isConfigured(): bool;

    /** Credential fields for the admin form: list<array{name,label,secret:bool,help?:string}> */
    public function credentialFields(): array;

    /** Non-secret option fields: list<array{name,label,type,default,help?}> */
    public function optionFields(): array;

    /**
     * Create the payment at the gateway.
     * @param array $payment row from `payments` (amount + fee = amount to charge)
     */
    public function createPayment(array $payment, array $user, string $returnUrl, string $callbackUrl): PaymentInit;

    /** Parse + authenticate an incoming webhook. Must not trust anything before the signature check. */
    public function handleCallback(Request $request): CallbackResult;

    /** Server-to-server status query for a payment we created. */
    public function verifyPayment(array $payment): CallbackResult;

    /** Convenience: normalised status string from verifyPayment(). */
    public function getPaymentStatus(array $payment): string;

    /** false = callbacks are unauthenticated; settle only from verifyPayment(). */
    public function callbacksAreSigned(): bool;

    /** true = verified amount must equal the expected amount exactly. */
    public function requiresExactAmount(): bool;

    /** Merchant order reference to send; stored in payments.merchant_order_id (unique). */
    public function merchantOrderId(array $payment): string;

    /** Gateway-enforced order lifetime in minutes, or null. */
    public function orderTimeoutMinutes(): ?int;

    public function supportsCurrency(string $currency): bool;

    /** Extra customer inputs for the Add Funds form. */
    public function paymentFields(): array;

    /** Validate/clean paymentFields() input (throws ValidationException). */
    public function validatePaymentFields(array $input): array;
}
