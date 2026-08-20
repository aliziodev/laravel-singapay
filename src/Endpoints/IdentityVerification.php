<?php

declare(strict_types=1);

namespace Aliziodev\Singapay\Endpoints;

use Aliziodev\Singapay\Enums\Host;
use Aliziodev\Singapay\Http\ApiRequest;
use Aliziodev\Singapay\Http\Response;

/**
 * Identity verification (KYC) — a separate service on its own host with its
 * own credential pair (`singapay.identity.*`) and signature scheme (D).
 *
 * Responses use a flat envelope: `{code, data, message, pricing, request_id}`.
 * Only `code: "SUCCESS"` responses are billed (`pricing: "PAID"`).
 */
class IdentityVerification extends Endpoint
{
    /**
     * Verify the holder name on a bank account.
     *
     * `POST {identity}/api/v1/kyc/bank/verify`
     *
     * @param  array<string, mixed>  $data  Required: `request_id` (idempotency key,
     *                                      UUID v4 recommended), `account_number` (6–20 digits), `bank_code`
     *                                      (3-digit clearing code), `name` (compared against the registered holder).
     */
    public function verifyBankAccount(array $data): Response
    {
        return $this->send(new ApiRequest('POST', '/api/v1/kyc/bank/verify', body: $data, host: Host::Identity));
    }

    /**
     * Verify the holder name on an e-wallet account.
     *
     * `POST {identity}/api/v1/kyc/ewallet/verify`
     *
     * @param  array<string, mixed>  $data  Required: `request_id`, `phone_number`
     *                                      (pattern ^(0|62)...), `name`, `ewallet_code` (DANA|SHOPEEPAY|GOPAY|OVO).
     */
    public function verifyEwalletAccount(array $data): Response
    {
        return $this->send(new ApiRequest('POST', '/api/v1/kyc/ewallet/verify', body: $data, host: Host::Identity));
    }
}
