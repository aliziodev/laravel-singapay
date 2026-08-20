<?php

declare(strict_types=1);

namespace Aliziodev\Singapay\Endpoints;

use Aliziodev\Singapay\Http\ApiRequest;
use Aliziodev\Singapay\Http\Response;

/**
 * Virtual account transactions (read-only) — one record per bank transfer
 * received on a VA.
 *
 * The show endpoint takes the **business** `transaction_id` string
 * (e.g. "VA-20251024-0001H9X8ZK"), not a numeric ID and not a ULID.
 */
class VaTransactions extends Endpoint
{
    /**
     * List VA transactions.
     *
     * `GET /api/v1.0/va-transactions/{account_id}`
     *
     * @param  string|null  $accountId  Account ULID; defaults to `singapay.account_id`.
     * @param  array<string, mixed>  $filters  Supports `per_page`, `sort_by`, `sort_order`,
     *                                         `transaction_id`, `merchant_reff_no` (partial), `va_number` (exact),
     *                                         `status` (unpaid|paid|expired|failed), amount filters, `has_settle`, and
     *                                         Unix-millisecond ranges (`post_timestamp_from/to`, `processed_timestamp_from/to`,
     *                                         `settle_at_from/to`).
     */
    public function list(?string $accountId = null, array $filters = []): Response
    {
        return $this->send(new ApiRequest('GET', "/api/v1.0/va-transactions/{$this->accountId($accountId)}", query: $filters));
    }

    /**
     * Retrieve one VA transaction.
     *
     * `GET /api/v1.0/va-transactions/{account_id}/{transaction_id}`
     *
     * @param  string  $transactionId  Business transaction ID (e.g. "VA-20251024-0001H9X8ZK").
     * @param  string|null  $accountId  Account ULID.
     */
    public function find(string $transactionId, ?string $accountId = null): Response
    {
        return $this->send(new ApiRequest('GET', "/api/v1.0/va-transactions/{$this->accountId($accountId)}/{$transactionId}"));
    }

    /**
     * List transactions received on one VA number.
     *
     * `GET /api/v1.0/va-transactions/{account_id}/detail-by-va-number/{virtual_account_no}`
     *
     * @param  string  $vaNumber  The VA number customers pay to (e.g. "88810012345678").
     * @param  string|null  $accountId  Account ULID.
     * @param  array<string, mixed>  $filters  Same filters as {@see list()} minus `va_number`;
     *                                         `per_page` defaults to 50 here.
     */
    public function listByVaNumber(string $vaNumber, ?string $accountId = null, array $filters = []): Response
    {
        return $this->send(new ApiRequest(
            'GET',
            "/api/v1.0/va-transactions/{$this->accountId($accountId)}/detail-by-va-number/{$vaNumber}",
            query: $filters,
        ));
    }
}
