<?php

declare(strict_types=1);

namespace App\Providers;

final class ProviderException extends \RuntimeException
{
    public const KIND_REJECTED = 'rejected';
    public const KIND_UNREACHABLE = 'unreachable';
    public const KIND_UNKNOWN = 'unknown';
    public const KIND_UNSUPPORTED = 'unsupported';

    public function __construct(string $message, public readonly string $kind = self::KIND_UNKNOWN, public readonly ?int $httpStatus = null)
    {
        parent::__construct($message);
    }

    public function isSafeToRetry(): bool
    {
        return $this->kind === self::KIND_UNREACHABLE;
    }

    public function isDefinitiveRejection(): bool
    {
        return $this->kind === self::KIND_REJECTED;
    }
}
