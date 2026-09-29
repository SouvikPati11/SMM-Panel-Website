<?php

declare(strict_types=1);

namespace App\Payment;

use App\Payment\Gateways\CryptomusGateway;
use App\Payment\Gateways\OxaPayGateway;
use App\Payment\Gateways\P2GatewayGateway;

/** Maps gateway keys to adapter classes. `manual` is handled by ManualPaymentService. */
final class GatewayRegistry
{
    public const GATEWAYS = [
        'oxapay' => OxaPayGateway::class,
        'cryptomus' => CryptomusGateway::class,
        'p2gateway' => P2GatewayGateway::class,
    ];

    public static function make(array $method): PaymentGatewayInterface
    {
        $class = self::GATEWAYS[$method['gateway']] ?? null;
        if ($class === null) {
            throw new PaymentException('Unknown gateway ' . $method['gateway']);
        }
        return new $class($method);
    }

    public static function isAutomatic(string $gateway): bool
    {
        return isset(self::GATEWAYS[$gateway]);
    }
}
