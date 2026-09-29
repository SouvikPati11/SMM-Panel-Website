<?php

declare(strict_types=1);

namespace App\Payment;

/** Gateway failure. $userMessage is safe to show; details go to the payment log. */
final class PaymentException extends \RuntimeException
{
    /**
     * @param bool $ambiguous true when the request may have reached the gateway
     *                        (e.g. timeout after sending) — the local payment is
     *                        kept pending and verified later instead of failed.
     */
    public function __construct(
        string $message,
        public readonly string $userMessage = 'The payment gateway is temporarily unavailable. Please try again or choose another method.',
        public readonly bool $ambiguous = false,
    ) {
        parent::__construct($message);
    }
}
