<?php

declare(strict_types=1);

namespace Aliziodev\Singapay\Endpoints;

use Aliziodev\Singapay\Http\ApiRequest;
use Aliziodev\Singapay\Http\Response;

/**
 * QRIS money-out (issuer side): decode a merchant-presented QR, then pay it
 * from the merchant balance.
 */
class QrisMoneyOut extends Endpoint
{
    /**
     * Decode an MPM QR payload and return the merchant details.
     *
     * `POST /api/v2.0/qris/issuer/mpm/inquiry-merchant`
     *
     * @param  string  $qrData  Raw QRIS payload (max 500 chars).
     */
    public function inquireMerchant(string $qrData): Response
    {
        return $this->send(new ApiRequest('POST', '/api/v2.0/qris/issuer/mpm/inquiry-merchant', body: ['qr_data' => $qrData]));
    }

    /**
     * Pay a scanned QR from the merchant balance.
     *
     * `POST /api/v2.0/qris/issuer/mpm/payment-credit` — signed (money-out guard applies).
     *
     * Never retry this call automatically — use {@see inquireStatus()} after
     * ambiguous outcomes.
     *
     * @param  array<string, mixed>  $data  Required: `reference_number` (max 64,
     *                                      idempotency key), `amount` (1,000–10,000,000), `qr_data`, `customer_name`.
     *                                      `account_id` defaults to the configured account. Optional:
     *                                      `customer_email`, `customer_phone`, `customer_location`.
     */
    public function triggerPaymentCredit(array $data): Response
    {
        return $this->send(new ApiRequest('POST', '/api/v2.0/qris/issuer/mpm/payment-credit', body: $this->withAccountId($data), signed: true, moneyOut: true));
    }

    /**
     * Query a QRIS transaction's status.
     *
     * `POST /api/v2.0/qris/status/{account_id}`
     *
     * @param  string  $referenceNumber  Merchant reference (max 64).
     * @param  string  $scope  `issuer` or `acquirer`.
     * @param  string|null  $accountId  Account ULID; defaults to `singapay.account_id`.
     */
    public function inquireStatus(string $referenceNumber, string $scope = 'issuer', ?string $accountId = null): Response
    {
        return $this->send(new ApiRequest(
            'POST',
            "/api/v2.0/qris/status/{$this->accountId($accountId)}",
            body: ['reference_number' => $referenceNumber, 'scope' => $scope],
        ));
    }
}
