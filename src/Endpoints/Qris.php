<?php

declare(strict_types=1);

namespace Aliziodev\Singapay\Endpoints;

use Aliziodev\Singapay\Http\ApiRequest;
use Aliziodev\Singapay\Http\Response;

/**
 * QRIS money-in (dynamic MPM QR generation).
 *
 * Generated QRs are single-use with a fixed amount. Note: `expired_at` here
 * is an ISO 8601 datetime — unlike payment links and virtual accounts,
 * which use Unix milliseconds.
 */
class Qris extends Endpoint
{
    /**
     * Generate a dynamic QR.
     *
     * `POST /api/v1.0/qris-dynamic/{account_id}/generate-qr`
     *
     * @param  array<string, mixed>  $data  Required: `amount`. Optional:
     *                                      `expired_at` (ISO 8601, in the future), `merchant_reff_no` (max 255).
     * @param  string|null  $accountId  Account ULID; defaults to `singapay.account_id`.
     */
    public function generate(array $data, ?string $accountId = null): Response
    {
        return $this->send(new ApiRequest('POST', "/api/v1.0/qris-dynamic/{$this->accountId($accountId)}/generate-qr", body: $data));
    }

    /**
     * List QRIS transactions.
     *
     * `GET /api/v1.0/qris-dynamic/{account_id}`
     *
     * @param  string|null  $accountId  Account ULID.
     * @param  array<string, mixed>  $filters  Optional reference/status/amount/date filters.
     */
    public function list(?string $accountId = null, array $filters = []): Response
    {
        return $this->send(new ApiRequest('GET', "/api/v1.0/qris-dynamic/{$this->accountId($accountId)}", query: $filters));
    }

    /**
     * Retrieve one QRIS transaction.
     *
     * `GET /api/v1.0/qris-dynamic/{account_id}/show/{id}` (note the `/show/` segment)
     *
     * @param  int  $id  Numeric `qris_transactions.id`.
     * @param  string|null  $accountId  Account ULID.
     */
    public function find(int $id, ?string $accountId = null): Response
    {
        return $this->send(new ApiRequest('GET', "/api/v1.0/qris-dynamic/{$this->accountId($accountId)}/show/{$id}"));
    }
}
