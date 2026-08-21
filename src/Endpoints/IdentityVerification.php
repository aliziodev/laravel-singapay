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
 *   returns the same answer and is billed once. Reusing a `request_id` with
 *   a *different* payload is rejected with `DUPLICATE_REFERENCE`, so use a
 *   fresh UUID v4 per distinct check. For bank verification the reuse rule
 *   holds only when the previous attempt completed; an upstream `FAILED` is
 *   re-run on retry.
 * - **HTTP status maps to `code`**: 400 CLIENT_ERROR, 401 UNAUTHORIZED,
 *   402 INSUFFICIENT_BALANCE, 409 DUPLICATE_REFERENCE, 500 INTERNAL_ERROR,
 *   502 SERVER_ERROR (the wallet or bank upstream failed, not you).
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
     * ⚠️ **A successful response does not mean the name matched.** `code` is
     * `SUCCESS` whenever the lookup ran at all — including when there is no
     * account for that number — so `$response->successful()` is not the
     * answer to "may I pay this person". Branch on `data.status` and
     * `data.suggestion`:
     *
     * | `data.status` | `data.suggestion` | Meaning |
     * |---|---|---|
     * | `found with kyc` | `pass`/`review`/`reject` by score | Real registered name; `similarity` is meaningful |
     * | `found without kyc` | `review` | Account exists but the holder never completed KYC; `similarity` is 0 and means nothing |
     * | `not found` | `reject` | No account on this wallet for this number |
     *
     * All three are billed (`pricing: PAID`); only failures are free. And
     * `message` is always the literal `OK`, so never branch on it.
     *
     * Exactly one wallet is checked per call, for one fee. Earlier versions
     * returned an `other_ewallet_similarity` array; it has been removed.
     *
     * @param  array<string, mixed>  $data  Required: `request_id` (UUID v4
     *                                      recommended), `phone_number` (`^(0|62)[1-9][0-9]{7,11}$` — both
     *                                      `08...` and `62...` accepted, never a leading `+`), `name` (1–200
     *                                      chars, compared case-insensitively after whitespace and honorific
     *                                      normalisation), `ewallet_code` (DANA|SHOPEEPAY|GOPAY|OVO).
     */
    public function verifyEwalletAccount(array $data): Response
    {
        return $this->send(new ApiRequest('POST', '/api/v1/kyc/ewallet/verify', body: $data, host: Host::Identity));
    }
}
