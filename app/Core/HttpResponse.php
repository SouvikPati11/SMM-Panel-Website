<?php

declare(strict_types=1);

namespace App\Core;

final class HttpResponse
{
    public function __construct(
        public readonly int $status,
        public readonly string $body,
        public readonly int $durationMs = 0,
        public readonly ?string $error = null,
        /** true when the request never reached the remote host (safe to retry) */
        public readonly bool $notSent = false,
    ) {
    }

    public function ok(): bool
    {
        return $this->error === null && $this->status >= 200 && $this->status < 300;
    }

    public function isNetworkError(): bool
    {
        return $this->error !== null;
    }

    public function json(): ?array
    {
        $data = json_decode($this->body, true);
        return is_array($data) ? $data : null;
    }
}
