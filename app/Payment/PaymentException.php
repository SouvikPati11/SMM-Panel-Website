<?php

declare(strict_types=1);

namespace App\Payment;

/** Gateway failure. $userMessage is safe to show; details go to the payment log. */
final class PaymentException extends \RuntimeException
{
    public function __construct(string $message, public readonly string $userMessage = 'The payment gateway is temporarily unavailable. Please try again or choose another method.')
    {
        parent::__construct($message);
    }
}
