<?php

declare(strict_types=1);

namespace Aliziodev\Singapay\Endpoints;

use Aliziodev\Singapay\Http\ApiRequest;
use Aliziodev\Singapay\Http\Response;

/**
 * Payment link histories — one record per customer payment attempt on a
 * payment link. Retained for one year; newest first by default.
 */
class PaymentLinkHistories extends Endpoint
{
    /**
     * List payment link histories.
     *
     * `GET /api/v1.0/payment-link-histories/{account_id}`
     *
     * @param  string|null  $accountId  Account ULID; defaults to `singapay.account_id`.
     * @param  array<string, mixed>  $filters  Supports `per_page`, `sort_by`, `sort_order`,
     *                                         `reff_no`, `status` (pending|paid|failed|expired), `payment_method_name`,
     *                                         `payment_method_value`, `amount`/`amount_min`/`amount_max`, `has_settle`,
     *                                         and ISO 8601 date ranges (`created_at_from/to`, `payment_date_from/to`,
     *                                         `processed_timestamp_from/to`, `settle_at_from/to`).
     */
    public function list(?string $accountId = null, array $filters = []): Response
    {
        return $this->send(new ApiRequest('GET', "/api/v1.0/payment-link-histories/{$this->accountId($accountId)}", query: $filters));
    }

    /**
     * Retrieve one history record.
     *
     * `GET /api/v1.0/payment-link-histories/{account_id}/{history_id}`
     *
     * @param  int  $historyId  Numeric history ID from the list endpoint.
     * @param  string|null  $accountId  Account ULID.
     */
    public function find(int $historyId, ?string $accountId = null): Response
    {
        return $this->send(new ApiRequest('GET', "/api/v1.0/payment-link-histories/{$this->accountId($accountId)}/{$historyId}"));
    }
}
