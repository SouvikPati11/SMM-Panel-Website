<?php

declare(strict_types=1);

namespace App\Core\Exceptions;

/** Thrown by services for user-correctable input problems. Message is safe to display. */
class ValidationException extends \RuntimeException
{
    /** @param array<string,string> $errors */
    public function __construct(string $message, private array $errors = [])
    {
        parent::__construct($message);
    }

    public function errors(): array
    {
        return $this->errors;
    }
}
