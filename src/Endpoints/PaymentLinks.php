<?php

declare(strict_types=1);

namespace Aliziodev\Singapay\Endpoints;

use Aliziodev\Singapay\Http\ApiRequest;
use Aliziodev\Singapay\Http\Response;

/**
 * Payment link management.
 *
 * {@see create()}, {@see find()} and {@see update()} use the **v2** API,
 * which the gateway spec marks as current and whose v1 counterparts it labels
 * "Legacy". {@see list()}, {@see delete()} and {@see paymentMethods()} stay on
 * v1 because v2 has no equivalent.
 *
 * Rules worth knowing:
 * - `payment_link_type` decides the shape: `total` takes `total_amount` and
 *   ignores `items`; `items` takes `items` and computes the total server-side.
 * - `expired_at` is any parseable date/time string (ISO 8601 recommended) —
 *   **not** the 13-digit millisecond string v1 required. Omit it to default to
 *   24 hours from creation.
 * - `reff_no` is at most 40 characters, no spaces or slashes.
 * - `payment_link_id` is the numeric `payment_links.id`, not a ULID.
 * - `max_usage` defaults to 1; `0` means unlimited. `is_multiple_payment`,
 *   `is_unlimited_usage` and `required_customer_detail` are derived from it and
 *   cannot be set directly.
 * - On update, `max_usage` must be >= the current usage.
 */
class PaymentLinks extends Endpoint
{
    /**
     * List payment links for an account.
     *
     * `GET /api/v1.0/payment-link-manage/{account_id}`
     *
     * @param  string|null  $accountId  Account ULID; defaults to `singapay.account_id`.
     */
    public function list(?string $accountId = null): Response
    {
        return $this->send(new ApiRequest('GET', "/api/v1.0/payment-link-manage/{$this->accountId($accountId)}"));
    }

    /**
     * List the payment method codes accepted by `whitelisted_payment_method`.
     *
     * `GET /api/v1.0/payment-link-manage/payment-methods`
     */
    public function paymentMethods(): Response
    {
        return $this->send(new ApiRequest('GET', '/api/v1.0/payment-link-manage/payment-methods'));
    }

    /**
     * Create a payment link.
     *
     * `POST /api/v2.0/payment-link/{account_id}`
     *
     * @param  array<string, mixed>  $data  Required: `reff_no` and
     *                                      `payment_link_type` (`total`|`items`) — plus `total_amount` for `total`,
     *                                      or `items` (each: `name`, `quantity`, `unit_price`; negative prices act as
     *                                      discounts) for `items`. Optional: `description`, `max_usage` (1 by default,
     *                                      `0` for unlimited), `expired_at` (any parseable date string; defaults to
     *                                      24h), `whitelisted_payment_method` (omit to auto-select eligible methods),
     *                                      `success_redirect_url`, `expired_redirect_url`, `optional_metadata`,
     *                                      `customer_name`/`customer_email`/`customer_phone` (single-use links only,
     *                                      and name+email travel together), `required_customer_number`,
     *                                      `required_customer_email` (multi-use links only).
     * @param  string|null  $accountId  Account ULID.
     */
    public function create(array $data, ?string $accountId = null): Response
    {
        return $this->send(new ApiRequest('POST', "/api/v2.0/payment-link/{$this->accountId($accountId)}", body: $data));
    }

    /**
     * Retrieve one payment link.
     *
     * `GET /api/v2.0/payment-link/{payment_link_id}` — v2 resolves and
     * access-checks the owning account from the link itself, so no account id
     * is needed.
     *
     * @param  int  $paymentLinkId  Numeric `payment_links.id`.
     */
    public function find(int $paymentLinkId): Response
    {
        return $this->send(new ApiRequest('GET', "/api/v2.0/payment-link/{$paymentLinkId}"));
    }

    /**
     * Update a payment link. `reff_no`, `total_amount` and `items` are
     * immutable — create a new link instead.
     *
     * `PUT /api/v2.0/payment-link/update/{payment_link_id}`
     *
     * Every field is optional and omitting one leaves it untouched, unlike v1
     * which demanded `status` on every call and silently ignored the rest.
     *
     * @param  int  $paymentLinkId  Numeric `payment_links.id`.
     * @param  array<string, mixed>  $data  Any of `status`
     *                                      (open|closed|expired), `description`, `max_usage` (>= current usage; `0`
     *                                      for unlimited), `expired_at` (must be in the future),
     *                                      `whitelisted_payment_method` (`null`/`[]` re-auto-selects; omit to leave
     *                                      the current whitelist alone), `success_redirect_url`,
     *                                      `expired_redirect_url`, `optional_metadata`, `required_customer_number`,
     *                                      `required_customer_email`.
     */
    public function update(int $paymentLinkId, array $data): Response
    {
        return $this->send(new ApiRequest('PUT', "/api/v2.0/payment-link/update/{$paymentLinkId}", body: $data));
    }

    /**
     * Delete a payment link. Blocked (403) once the link has any payment
     * history.
     *
     * `DELETE /api/v1.0/payment-link-manage/{account_id}/{payment_link_id}`
     *
     * @param  int  $paymentLinkId  Numeric `payment_links.id`.
     * @param  string|null  $accountId  Account ULID.
     */
    public function delete(int $paymentLinkId, ?string $accountId = null): Response
    {
        return $this->send(new ApiRequest('DELETE', "/api/v1.0/payment-link-manage/{$this->accountId($accountId)}/{$paymentLinkId}"));
    }
}
