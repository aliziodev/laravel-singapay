<?php

declare(strict_types=1);

namespace Aliziodev\Singapay\Endpoints;

use Aliziodev\Singapay\Http\ApiRequest;
use Aliziodev\Singapay\Http\Response;

/**
 * Payment link management.
 *
 * Rules worth knowing before calling {@see create()}:
 * - `total_amount` must equal the sum of the item subtotals exactly.
 * - `reff_no` is at most 40 characters, no spaces or slashes.
 * - `expired_at` is a 13-digit Unix **millisecond** string (unlike the
 *   X-Timestamp header, which is seconds) — use JakartaClock::toMilliseconds().
 * - `payment_link_id` is the numeric `payment_links.id`, not a ULID.
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
     * `POST /api/v1.0/payment-link-manage/{account_id}`
     *
     * @param  array<string, mixed>  $data  Required: `reff_no`, `title`, `max_usage`,
     *                                      `total_amount`, `items` (each: name, quantity, unit_price). Optional:
     *                                      `required_customer_detail`, `customer_pays_fee`, `expired_at` (13-digit ms string),
     *                                      `whitelisted_payment_method`, `redirect_url`, `success_redirect_url`,
     *                                      `expired_redirect_url`, `optional_metadata`.
     * @param  string|null  $accountId  Account ULID.
     */
    public function create(array $data, ?string $accountId = null): Response
    {
        return $this->send(new ApiRequest('POST', "/api/v1.0/payment-link-manage/{$this->accountId($accountId)}", body: $data));
    }

    /**
     * Retrieve one payment link.
     *
     * `GET /api/v1.0/payment-link-manage/{account_id}/{payment_link_id}`
     *
     * @param  int  $paymentLinkId  Numeric `payment_links.id`.
     * @param  string|null  $accountId  Account ULID.
     */
    public function find(int $paymentLinkId, ?string $accountId = null): Response
    {
        return $this->send(new ApiRequest('GET', "/api/v1.0/payment-link-manage/{$this->accountId($accountId)}/{$paymentLinkId}"));
    }

    /**
     * Update a payment link. `reff_no`, `title`, `total_amount`, and `items`
     * are immutable — create a new link instead.
     *
     * `PUT /api/v1.0/payment-link-manage/{account_id}/{payment_link_id}`
     *
     * @param  int  $paymentLinkId  Numeric `payment_links.id`.
     * @param  array<string, mixed>  $data  Required: `max_usage` (>= current usage),
     *                                      `status` (open|closed|expired). Optional fields as in {@see create()}.
     * @param  string|null  $accountId  Account ULID.
     */
    public function update(int $paymentLinkId, array $data, ?string $accountId = null): Response
    {
        return $this->send(new ApiRequest('PUT', "/api/v1.0/payment-link-manage/{$this->accountId($accountId)}/{$paymentLinkId}", body: $data));
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
