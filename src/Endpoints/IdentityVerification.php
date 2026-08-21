<?php

declare(strict_types=1);

namespace Aliziodev\Singapay\Endpoints;

use Aliziodev\Singapay\Enums\Host;
use Aliziodev\Singapay\Http\ApiRequest;
use Aliziodev\Singapay\Http\Response;

/**
 * Identity verification (KYC): confirm the registered holder name on a bank
 * or e-wallet account before you send money to it.
 *
 * Runs on its own host with its own credentials, issued from the **merchant
 * KYC dashboard** rather than the payment gateway one — client ids there look
 * like `kc_live_a3f2c4`, not the payment host's UUIDs, and the client secret
 * is shown once at creation. Verified 2026-08-21: the payment credentials are
 * rejected here with `401 invalid credential or signature`.
 *
 * Worth knowing before you build on it:
 *
 * - **Idempotent on `request_id`.** The same `(merchant, request_id)` pair
 *   returns the same answer and is billed once. For bank verification that
 *   holds only when the previous attempt completed; an upstream `FAILED` is
 *   re-run on retry.
 * - **Every response carries `pricing`** (`PAID`/`FREE`) alongside `code`,
 *   `message`, `data` and `request_id`, so you can tell which calls you were
 *   charged for. Read it from `$response->raw['pricing']`.
 * - **Rate limit** is 60 requests per second per merchant; bursts get
 *   `429` with `Retry-After` and `X-RateLimit-*` headers.
 * - **A credential may carry its own IP allowlist**, separate from the
 *   payment host's whitelist. A non-empty list rejects other IPs with
 *   `403 IP_NOT_ALLOWED`.
 * - Auth failures are all `401` regardless of cause — `INVALID_SIGNATURE`,
 *   `UNKNOWN_CLIENT_ID` and `REVOKED_CREDENTIAL` are deliberately
 *   indistinguishable so the API does not leak which client ids exist.
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
