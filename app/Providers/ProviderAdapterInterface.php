<?php

declare(strict_types=1);

namespace App\Providers;

/**
 * Contract every SMM provider adapter implements. The order engine only talks
 * to this interface, so adding a provider with a different API means adding
 * an adapter class and registering it in ProviderFactory — nothing else changes.
 *
 * Failure semantics (critical for money safety) — adapters MUST throw
 * ProviderException with the right kind:
 *   KIND_REJECTED    provider answered and definitively refused (safe to refund)
 *   KIND_UNREACHABLE request never reached the provider (safe to retry)
 *   KIND_UNKNOWN     request may have been received; outcome unknown (never auto-retry/refund)
 */
interface ProviderAdapterInterface
{
    /** @param array{service:string,link:string,quantity:int,runs?:int,interval?:int,comments?:string,usernames?:string,username?:string,answer_number?:string,keywords?:string} $params */
    public function addOrder(array $params): string;

    public function status(string $providerOrderId): ProviderOrderStatus;

    /**
     * @param list<string> $providerOrderIds
     * @return array<string, ProviderOrderStatus|ProviderException>
     */
    public function multiStatus(array $providerOrderIds): array;

    /** @return array<string, true|string> provider order id => true or error message */
    public function cancel(array $providerOrderIds): array;

    /** @return string provider refill id */
    public function refill(string $providerOrderId): string;

    /** @return string normalized refill status: pending|processing|completed|rejected */
    public function refillStatus(string $refillId): string;

    /** @return list<array{service:string,name:string,category:string,type:string,rate:string,min:int,max:int,refill:bool,cancel:bool,dripfeed:bool,description:string}> */
    public function services(): array;

    /** @return array{balance:string,currency:string} */
    public function balance(): array;
}
