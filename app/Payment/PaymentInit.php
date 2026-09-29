<?php

declare(strict_types=1);

namespace App\Payment;

final class PaymentInit
{
    public function __construct(
        public readonly string $redirectUrl,
        public readonly string $gatewayRef,
        public readonly ?string $expiresAt = null,
        public readonly array $meta = [],
    ) {
    }
}
