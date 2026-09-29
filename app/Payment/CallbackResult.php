<?php

declare(strict_types=1);

namespace App\Payment;

final class CallbackResult
{
    public function __construct(
        public readonly bool $valid,
        public readonly ?string $gatewayRef = null,
        /** pending | completed | failed | expired | cancelled */
        public readonly string $status = 'pending',
        public readonly ?string $amount = null,
        public readonly ?string $currency = null,
        public readonly ?string $gatewayStatus = null,
        public readonly ?string $orderId = null,
        public readonly string $error = '',
        public readonly array $raw = [],
        /** bank/UPI transaction reference when the gateway provides one */
        public readonly ?string $utr = null,
    ) {
    }

    public static function invalid(string $error, array $raw = []): self
    {
        return new self(false, error: $error, raw: $raw);
    }
}
