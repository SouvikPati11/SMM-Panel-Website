<?php

declare(strict_types=1);

namespace App\Core\Exceptions;

class HttpException extends \RuntimeException
{
    public function __construct(private int $status, string $message = '')
    {
        parent::__construct($message, $status);
    }

    public function getStatus(): int
    {
        return $this->status;
    }
}
