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

    /** Amount the customer must pay (credit amount + fee) as a plain decimal string. */
    protected function chargeAmount(array $payment): string
    {
        return \App\Core\Money::of(\App\Core\Money::add((string) $payment['amount'], (string) ($payment['fee'] ?? '0')), 2);
    }
}
