<?php

declare(strict_types=1);

namespace Aliziodev\Singapay\Endpoints;

use Aliziodev\Singapay\Http\ApiRequest;
use Aliziodev\Singapay\Http\Response;

/**
 * E-wallet money-in (native checkout).
 *
 * Vendors: DANA and ShopeePay redirect to a checkout URL; OVO is
 * push-to-pay and requires `customer_phone`. Default expiration is 30
 * minutes; `expired_at` is ISO 8601.
 */
class EwalletMoneyIn extends Endpoint
{
    /**
     * Create a checkout (v1 legacy — v2 {@see createOrder()} is preferred).
     *
     * `POST /api/v1.0/ewallet-native/{account_id}/create-checkout`
     *
     * @param  array<string, mixed>  $data  Required: `amount`, `ewallet_vendor`
     *                                      (e.g. EWALLET_DANA). Optional: `expired_at` (ISO 8601), `customer_name`,
     *                                      `customer_email`, `customer_phone` (required for OVO),
     *                                      `merchant_redirect_url`, `merchant_reff_no`.
     * @param  string|null  $accountId  Account ULID; defaults to `singapay.account_id`.
     */
    public function createCheckout(array $data, ?string $accountId = null): Response
    {
        return $this->send(new ApiRequest('POST', "/api/v1.0/ewallet-native/{$this->accountId($accountId)}/create-checkout", body: $data));
    }

    /**
     * Create an order (v2). `account_id` travels in the body; when omitted
     * the configured default account is used. Responds with the v2 envelope.
     *
     * `POST /api/v2.0/ewallet-native/create-order`
     *
     * @param  array<string, mixed>  $data  Same fields as {@see createCheckout()}
     *                                      plus optional `account_id`.
     */
    public function createOrder(array $data): Response
    {
        return $this->send(new ApiRequest('POST', '/api/v2.0/ewallet-native/create-order', body: $this->withAccountId($data)));
    }

    /**
     * List e-wallet transactions.
     *
     * `GET /api/v1.0/ewallet-native-transactions/{account_id}`
     *
     * @param  string|null  $accountId  Account ULID.
     * @param  array<string, mixed>  $filters  Optional filters.
     */
    public function listTransactions(?string $accountId = null, array $filters = []): Response
    {
        return $this->send(new ApiRequest('GET', "/api/v1.0/ewallet-native-transactions/{$this->accountId($accountId)}", query: $filters));
    }

    /**
     * Retrieve one e-wallet transaction.
     *
     * `GET /api/v1.0/ewallet-native-transactions/{account_id}/{transaction_id}`
     *
     * @param  int  $transactionId  Numeric `ewallet_transactions.id`.
     * @param  string|null  $accountId  Account ULID.
     */
    public function findTransaction(int $transactionId, ?string $accountId = null): Response
    {
        return $this->send(new ApiRequest('GET', "/api/v1.0/ewallet-native-transactions/{$this->accountId($accountId)}/{$transactionId}"));
    }

    /**
     * Refresh and retrieve a transaction's status. For open transactions
     * the gateway may contact the vendor to refresh the status.
     *
     * `GET /api/v1.0/ewallet-native/{account_id}/inquiry-status/{id}`
     *
     * @param  int  $id  Numeric `ewallet_transactions.id`.
     * @param  string|null  $accountId  Account ULID.
     */
    public function inquireStatus(int $id, ?string $accountId = null): Response
    {
        return $this->send(new ApiRequest('GET', "/api/v1.0/ewallet-native/{$this->accountId($accountId)}/inquiry-status/{$id}"));
    }
}
