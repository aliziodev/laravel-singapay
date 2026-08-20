<?php

declare(strict_types=1);

namespace Aliziodev\Singapay\Endpoints;

use Aliziodev\Singapay\Http\ApiRequest;
use Aliziodev\Singapay\Http\Response;

/**
 * Balance transfers between the merchant's own sub-accounts.
 *
 * The transfer itself moves money and therefore requires the request
 * signature and the money-out guard.
 */
class AccountTransfer extends Endpoint
{
    /**
     * List transfers involving an account.
     *
     * `GET /api/v1.0/account-transfer/{account_id}`
     *
     * @param  string|null  $accountId  Account ULID; defaults to `singapay.account_id`.
     * @param  array<string, mixed>  $filters  `transaction_id` (partial match), `status` (success|pending|failed).
     */
    public function list(?string $accountId = null, array $filters = []): Response
    {
        return $this->send(new ApiRequest('GET', "/api/v1.0/account-transfer/{$this->accountId($accountId)}", query: $filters));
    }

    /**
     * Retrieve one transfer by its transaction ID.
     *
     * `GET /api/v1.0/account-transfer/{account_id}/{transaction_id}`
     *
     * @param  string  $transactionId  SingaPay transaction ID.
     * @param  string|null  $accountId  Remitter or beneficiary account ULID.
     */
    public function find(string $transactionId, ?string $accountId = null): Response
    {
        return $this->send(new ApiRequest('GET', "/api/v1.0/account-transfer/{$this->accountId($accountId)}/{$this->segment($transactionId)}"));
    }

    /**
     * Move balance to another sub-account of the same merchant.
     *
     * `POST /api/v1.0/account-transfer/{account_id}/transfer` — signed (money-out guard applies).
     *
     * @param  array<string, mixed>  $data  `amount` (required), `beneficiary_account_number`
     *                                      (required, 12-digit account number), `merchant_ref_no` (idempotency key, max 191).
     * @param  string|null  $accountId  Source (remitter) account ULID.
     */
    public function transfer(array $data, ?string $accountId = null): Response
    {
        return $this->send(new ApiRequest(
            'POST',
            "/api/v1.0/account-transfer/{$this->accountId($accountId)}/transfer",
            body: $data,
            signed: true,
            moneyOut: true,
        ));
    }
}
