<?php

declare(strict_types=1);

namespace App\Providers;

use App\Core\Crypto;

/**
 * Builds the adapter for a `providers` row. To support a provider whose API is
 * not compatible with API v2 (even via the config map), implement
 * ProviderAdapterInterface and register it in ADAPTERS.
 */
final class ProviderFactory
{
    public const ADAPTERS = [
        'standard_v2' => [
            'label' => 'Standard SMM API v2 (key + action)',
            'class' => StandardApiV2Adapter::class,
        ],
    ];

    /** @var array<int, ProviderAdapterInterface> test overrides */
    private static array $overrides = [];

    public static function override(int $providerId, ?ProviderAdapterInterface $adapter): void
    {
        if ($adapter === null) {
            unset(self::$overrides[$providerId]);
        } else {
            self::$overrides[$providerId] = $adapter;
        }
    }

    public static function make(array $provider): ProviderAdapterInterface
    {
        $id = (int) $provider['id'];
        if (isset(self::$overrides[$id])) {
            return self::$overrides[$id];
        }
        $def = self::ADAPTERS[$provider['adapter']] ?? null;
        if (!$def) {
            throw new ProviderException('Unknown provider adapter: ' . $provider['adapter'], ProviderException::KIND_UNSUPPORTED);
        }
        $config = json_decode((string) ($provider['config'] ?? ''), true) ?: [];
        $class = $def['class'];
        return new $class($id, (string) $provider['api_url'], Crypto::decrypt($provider['api_key_enc'] ?? ''), $config, (int) ($provider['timeout'] ?: 30));
    }
}
