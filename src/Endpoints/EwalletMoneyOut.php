<?php

declare(strict_types=1);

namespace Aliziodev\Singapay\Endpoints;

use Aliziodev\Singapay\Http\ApiRequest;
use Aliziodev\Singapay\Http\Response;

/**
 * E-wallet money-out (top-up to a customer's wallet).
 *
 * Flow: {@see inquireAccount()} (validates the wallet and returns limits and
 * fees) → {@see triggerTopup()} → {@see inquireStatus()}. Wallets: DANA,
 * OVO, GOPAY, SHOPEEPAY. Amount objects here use decimal strings
 * (`{"value": "10000.00", "currency": "IDR"}`).
 */
class EwalletMoneyOut extends Endpoint
{
    /**
     * Validate a destination wallet and preview limits and fees.
     *
     * `POST /api/v2.0/ewallet/account-inquiry`
     *
     * @param  array<string, mixed>  $data  Required: `ewallet_code` (DANA|OVO|GOPAY|SHOPEEPAY),
     *                                      `customer_number` (10–15 chars), `amount` ({value: "10000.00", currency: "IDR"}).
     *                                      `account_id` defaults to the configured account.
     */
    public function inquireAccount(array $data): Response
    {
        return $this->send(new ApiRequest('POST', '/api/v2.0/ewallet/account-inquiry', body: $this->withAccountId($data)));
    }

    /**
     * Send funds to a customer's e-wallet.
     *
     * `POST /api/v2.0/ewallet/trigger-topup` — signed (money-out guard applies).
     *
     * Never retry this call automatically — use {@see inquireStatus()} after
     * ambiguous outcomes. On failure SingaPay refunds the gross amount
     * automatically.
     *
     * @param  array<string, mixed>  $data  Required: `reference_number` (unique per
     *                                      account within 24h, max 64), `ewallet_code`, `customer_number`, `amount`
     *                                      (decimal-string object). `account_id` defaults to the configured account.
     *                                      Optional: `notes` (max 50).
     */
    public function triggerTopup(array $data): Response
    {
        return $this->send(new ApiRequest('POST', '/api/v2.0/ewallet/trigger-topup', body: $this->withAccountId($data), signed: true, moneyOut: true));
    }

    /**
     * Query a top-up's status by reference number.
     *
     * `POST /api/v2.0/ewallet/{account_id}/inquiry-status`
     *
     * @param  string  $referenceNumber  Merchant reference (max 64).
     * @param  string|null  $accountId  Account ULID.
     */
    public function inquireStatus(string $referenceNumber, ?string $accountId = null): Response
    {
        return $this->send(new ApiRequest(
            'POST',
            "/api/v2.0/ewallet/{$this->accountId($accountId)}/inquiry-status",
            body: ['reference_number' => $referenceNumber],
        ));
    }
}
