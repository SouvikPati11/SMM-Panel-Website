<?php

declare(strict_types=1);

namespace App\Payment;

use App\Core\Crypto;

abstract class AbstractGateway implements PaymentGatewayInterface
{
    protected array $credentials;
    protected array $options;

    /** @param array $method row from payment_methods */
    public function __construct(protected array $method)
    {
        $this->credentials = Crypto::decryptArray($method['credentials_enc'] ?? null);
        $this->options = json_decode((string) ($method['config'] ?? ''), true) ?: [];
    }

    public function isImplemented(): bool
    {
        return true;
    }

    public function isConfigured(): bool
    {
        foreach ($this->credentialFields() as $f) {
            if (($f['required'] ?? true) && trim((string) ($this->credentials[$f['name']] ?? '')) === '') {
                return false;
            }
        }
        return true;
    }

    public function optionFields(): array
    {
        return [];
    }

    public function getPaymentStatus(array $payment): string
    {
        return $this->verifyPayment($payment)->status;
    }

    protected function cred(string $name): string
    {
        return trim((string) ($this->credentials[$name] ?? ''));
    }

    protected function opt(string $name, mixed $default = null): mixed
    {
        return $this->options[$name] ?? $default;
    }

    /**
     * true when the gateway authenticates its callbacks (signature/HMAC).
     * false = callback content is untrusted: PaymentService ignores the claimed
     * status and always settles from a server-side verifyPayment() query.
     */
    public function callbacksAreSigned(): bool
    {
        return true;
    }

    /** true = the verified paid amount must equal the expected amount exactly. */
    public function requiresExactAmount(): bool
    {
        return false;
    }

    /** Merchant-side order reference sent to the gateway (stored in payments.merchant_order_id). */
    public function merchantOrderId(array $payment): string
    {
        return 'PAY-' . $payment['id'];
    }

    /** Minutes after which the gateway itself fails the order (null = use site setting). */
    public function orderTimeoutMinutes(): ?int
    {
        return null;
    }

    /** Whether this gateway can be offered for the site currency. */
    public function supportsCurrency(string $currency): bool
    {
        return true;
    }

    /** Extra fields the customer must fill on the Add Funds form: list<array{name,label,type,pattern,hint}> */
    public function paymentFields(): array
    {
        return [];
    }

    /**
     * Validate customer-entered paymentFields(); returns cleaned values stored
     * in payments.meta. Throw ValidationException with a user-safe message.
     */
    public function validatePaymentFields(array $input): array
    {
        return [];
    }

    /** Amount the customer must pay (credit amount + fee) as a plain decimal string. */
    protected function chargeAmount(array $payment): string
    {
        return \App\Core\Money::of(\App\Core\Money::add((string) $payment['amount'], (string) ($payment['fee'] ?? '0')), 2);
    }
}
