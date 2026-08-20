<?php

declare(strict_types=1);

namespace Aliziodev\Singapay\Endpoints;

use Aliziodev\Singapay\Http\ApiRequest;
use Aliziodev\Singapay\Http\Response;

/**
 * Virtual account provisioning.
 *
 * - `virtual_account_id` is the VA's **ULID**, not the VA number customers
 *   pay to.
 * - `kind`: `temporary` (requires `expired_at` + `max_usage`) or `permanent`.
 * - `amount_type`: `closed` (exact `amount`) or `open` (`min_amount`/`max_amount`).
 * - `expired_at` in requests is a 13-digit Unix millisecond string.
 */
class VirtualAccounts extends Endpoint
{
    /**
     * List virtual accounts.
     *
     * `GET /api/v1.0/virtual-accounts/{account_id}`
     *
     * @param  string|null  $accountId  Account ULID; defaults to `singapay.account_id`.
     * @param  array<string, mixed>  $filters  `merchant_reff_no` (partial match).
     */
    public function list(?string $accountId = null, array $filters = []): Response
    {
        return $this->send(new ApiRequest('GET', "/api/v1.0/virtual-accounts/{$this->accountId($accountId)}", query: $filters));
    }

    /**
     * Create a virtual account.
     *
     * `POST /api/v1.0/virtual-accounts/{account_id}`
     *
     * @param  array<string, mixed>  $data  Required: `bank_code` (e.g. BRI, BCA, BNI, ...),
     *                                      `kind` (temporary|permanent). Conditional: `expired_at` + `max_usage` (1–255)
     *                                      when temporary; `amount` when `amount_type` closed; `min_amount` + `max_amount`
     *                                      when open. Optional: `amount_type` (default closed), `name`, `merchant_reff_no`.
     * @param  string|null  $accountId  Account ULID.
     */
    public function create(array $data, ?string $accountId = null): Response
    {
        return $this->send(new ApiRequest('POST', "/api/v1.0/virtual-accounts/{$this->accountId($accountId)}", body: $data));
    }

    /**
     * Retrieve one virtual account.
     *
     * `GET /api/v1.0/virtual-accounts/{account_id}/{virtual_account_id}`
     *
     * @param  string  $virtualAccountId  The VA **ULID** (not the VA number).
     * @param  string|null  $accountId  Account ULID.
     */
    public function find(string $virtualAccountId, ?string $accountId = null): Response
    {
        return $this->send(new ApiRequest('GET', "/api/v1.0/virtual-accounts/{$this->accountId($accountId)}/{$this->segment($virtualAccountId)}"));
    }

    /**
     * Update a virtual account. May call the issuing bank's API — vendor
     * failures surface as HTTP 500.
     *
     * `PUT /api/v1.0/virtual-accounts/{account_id}/{virtual_account_id}`
     *
     * @param  string  $virtualAccountId  The VA ULID.
     * @param  array<string, mixed>  $data  Required: `status` (active|inactive|expired).
     *                                      Conditional: `amount` (closed), `min_amount`/`max_amount` (open),
     *                                      `expired_at` + `max_usage` (temporary). Optional: `name`.
     * @param  string|null  $accountId  Account ULID.
     */
    public function update(string $virtualAccountId, array $data, ?string $accountId = null): Response
    {
        return $this->send(new ApiRequest('PUT', "/api/v1.0/virtual-accounts/{$this->accountId($accountId)}/{$this->segment($virtualAccountId)}", body: $data));
    }

    /**
     * Delete a virtual account. Blocked (403) once the VA has transactions.
     *
     * `DELETE /api/v1.0/virtual-accounts/{account_id}/{virtual_account_id}`
     *
     * @param  string  $virtualAccountId  The VA ULID.
     * @param  string|null  $accountId  Account ULID.
     */
    public function delete(string $virtualAccountId, ?string $accountId = null): Response
    {
        return $this->send(new ApiRequest('DELETE', "/api/v1.0/virtual-accounts/{$this->accountId($accountId)}/{$this->segment($virtualAccountId)}"));
    }
}
