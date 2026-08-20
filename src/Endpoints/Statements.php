<?php

declare(strict_types=1);

namespace Aliziodev\Singapay\Endpoints;

use Aliziodev\Singapay\Http\ApiRequest;
use Aliziodev\Singapay\Http\Response;

/**
 * Account statements (mutations ledger).
 *
 * Lists are filtered on `processed_timestamp`, default to the current
 * month, span at most one year, and page 25 rows at a time.
 */
class Statements extends Endpoint
{
    /**
     * List statements for an account.
     *
     * `GET /api/v1.0/statements/{account_id}`
     *
     * @param  string|null  $accountId  Account ULID; defaults to `singapay.account_id`.
     * @param  array<string, mixed>  $filters  `start_date`, `end_date` (defaults: current month).
     */
    public function list(?string $accountId = null, array $filters = []): Response
    {
        return $this->send(new ApiRequest('GET', "/api/v1.0/statements/{$this->accountId($accountId)}", query: $filters));
    }

    /**
     * Retrieve one statement row.
     *
     * `GET /api/v1.0/statements/{account_id}/{statement_id}`
     *
     * @param  string  $statementId  Matched against `statements.transaction_id`.
     * @param  string|null  $accountId  Account ULID.
     */
    public function find(string $statementId, ?string $accountId = null): Response
    {
        return $this->send(new ApiRequest('GET', "/api/v1.0/statements/{$this->accountId($accountId)}/{$statementId}"));
    }
}
