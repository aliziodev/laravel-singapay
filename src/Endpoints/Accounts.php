<?php

declare(strict_types=1);

namespace Aliziodev\Singapay\Endpoints;

use Aliziodev\Singapay\Http\ApiRequest;
use Aliziodev\Singapay\Http\Response;

/**
 * Sub-account management.
 *
 * Account IDs are ULID strings. `account_type` may be `owned` or
 * `personal_managed` (active immediately) or `business_managed` (requires
 * KYB approval; save the returned `kyb_onboarding_url` — it becomes null
 * once KYB is verified).
 */
class Accounts extends Endpoint
{
    /**
     * List all sub-accounts.
     *
     * `GET /api/v1.0/accounts`
     */
    public function list(): Response
    {
        return $this->send(new ApiRequest('GET', '/api/v1.0/accounts'));
    }

    /**
     * Create a sub-account.
     *
     * `POST /api/v1.0/accounts`
     *
     * @param  array<string, mixed>  $data  `name` (required, 3–100 chars),
     *                                      `account_type` (owned|personal_managed|business_managed),
     *                                      `invite_members` (array of emails, max 50).
     */
    public function create(array $data): Response
    {
        return $this->send(new ApiRequest('POST', '/api/v1.0/accounts', body: $data));
    }

    /**
     * Retrieve a single sub-account.
     *
     * `GET /api/v1.0/accounts/{id}`
     *
     * @param  string  $accountId  Account ULID.
     */
    public function find(string $accountId): Response
    {
        return $this->send(new ApiRequest('GET', "/api/v1.0/accounts/{$this->segment($accountId)}"));
    }

    /**
     * Update a sub-account's name, status, and/or member invitations.
     *
     * `PATCH /api/v1.0/accounts/update/{id}`
     *
     * @param  string  $accountId  Account ULID.
     * @param  array<string, mixed>  $data  At least one of `name` (3–100 chars),
     *                                      `status` (active|inactive), `invite_members` (replaces the entire list).
     */
    public function update(string $accountId, array $data): Response
    {
        return $this->send(new ApiRequest('PATCH', "/api/v1.0/accounts/update/{$this->segment($accountId)}", body: $data));
    }

    /**
     * Update only a sub-account's status.
     *
     * `PATCH /api/v1.0/accounts/update/{id}` — a convenience wrapper over
     * {@see update()}. The OpenAPI spec advertises a separate
     * `accounts/update-status/{id}` route, but it does not exist: sandbox
     * answers `404 The route ... could not be found`, while `update/{id}`
     * accepts a status change. Verified 2026-08-21.
     *
     * @param  string  $accountId  Account ULID.
     * @param  string  $status  `active` or `inactive`.
     */
    public function updateStatus(string $accountId, string $status): Response
    {
        return $this->update($accountId, ['status' => $status]);
    }

    /**
     * Delete a sub-account. Fails with HTTP 400 while the account still
     * holds a balance.
     *
     * `DELETE /api/v1.0/accounts/{id}` — absent from `merchant-api.json` but
     * verified working in sandbox (2026-08-21), so do not "clean it up" on
     * the strength of the spec alone.
     *
     * @param  string  $accountId  Account ULID.
     */
    public function delete(string $accountId): Response
    {
        return $this->send(new ApiRequest('DELETE', "/api/v1.0/accounts/{$this->segment($accountId)}"));
    }
}
