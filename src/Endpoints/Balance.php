<?php

declare(strict_types=1);

namespace Aliziodev\Singapay\Endpoints;

use Aliziodev\Singapay\Http\ApiRequest;
use Aliziodev\Singapay\Http\Response;

/**
 * Real-time balance inquiry.
 *
 * Responses carry four money objects — `held_balance`, `available_balance`,
 * `pending_balance`, `balance` — each shaped `{value: string, currency: "IDR"}`.
 */
class Balance extends Endpoint
{
    /**
     * Aggregate balance across the whole merchant.
     *
     * `GET /api/v1.0/balance-inquiry`
     */
    public function merchant(): Response
    {
        return $this->send(new ApiRequest('GET', '/api/v1.0/balance-inquiry'));
    }

    /**
     * Balance of a single sub-account.
     *
     * `GET /api/v1.0/balance-inquiry/{account_id}`
     *
     * @param  string|null  $accountId  Account ULID; defaults to `singapay.account_id`.
     */
    public function account(?string $accountId = null): Response
    {
        return $this->send(new ApiRequest('GET', "/api/v1.0/balance-inquiry/{$this->accountId($accountId)}"));
    }
}
