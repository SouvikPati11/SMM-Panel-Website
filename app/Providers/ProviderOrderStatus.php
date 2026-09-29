<?php

declare(strict_types=1);

namespace App\Providers;

final class ProviderOrderStatus
{
    public function __construct(
        /** one of: pending, processing, in_progress, completed, partial, cancelled */
        public readonly string $status,
        public readonly ?int $startCount = null,
        public readonly ?int $remains = null,
        public readonly ?string $charge = null,
        public readonly ?string $currency = null,
        public readonly string $raw = '',
    ) {
    }

    /** Normalise the many spellings providers use for order status. */
    public static function normalize(string $status): ?string
    {
        $s = strtolower(preg_replace('/[\s_\-]+/', '', $status));
        return match ($s) {
            'pending', 'awaiting', 'queued', 'new' => 'pending',
            'processing' => 'processing',
            'inprogress', 'active', 'running' => 'in_progress',
            'completed', 'complete', 'success', 'done', 'finished' => 'completed',
            'partial', 'partiallycompleted', 'partialcompleted' => 'partial',
            'canceled', 'cancelled', 'cancel', 'refunded', 'fail', 'failed', 'error' => 'cancelled',
            default => null,
        };
    }
}
