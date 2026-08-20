<?php

declare(strict_types=1);

namespace Aliziodev\Singapay\Endpoints;

use Aliziodev\Singapay\Http\ApiRequest;
use Aliziodev\Singapay\Http\Response;

/**
 * Direct debit: bind a customer's card once, then charge repeatedly.
 *
 * Flow: {@see bindCard()} returns a single-use `redirect_url` (~15 minutes)
 * for the hosted binding webview; poll {@see bindingStatus()} until
 * `PENDING_AUTH` becomes `ACTIVE`; then {@see charge()} by `binding_id`.
 * Charges (and unbinds) may answer HTTP 202 with `requires_otp` /
 * `otp_required` — complete them via {@see verifyOtp()}.
 */
class DirectDebit extends Endpoint
{
    /**
     * Start a card binding.
     *
     * `POST /api/v2.0/direct-debit/binding`
     *
     * @param  array<string, mixed>  $data  Required: `customer_ref` (4–15 chars),
     *                                      `phone_no` — **digits only, no leading `+`**: `081234567890` or
     *                                      `6281234567890` both work, while true E.164 (`+6281234567890`) is rejected
     *                                      with the contentless `SP002 General Failure`. Optional: `bank_code`
     *                                      (3 chars), `payment_otp_mode` (WITH_OTP|WITHOUT_OTP),
     *                                      `success_redirect_url`, `failure_redirect_url`.
     */
    public function bindCard(array $data): Response
    {
        return $this->send(new ApiRequest('POST', '/api/v2.0/direct-debit/binding', body: $data));
    }

    /**
     * Retrieve a binding's status (poll `PENDING_AUTH` → `ACTIVE`).
     *
     * `GET /api/v2.0/direct-debit/binding/{binding_id}`
     *
     * @param  string  $bindingId  Binding UUID (malformed IDs 404 at the route layer).
     */
    public function bindingStatus(string $bindingId): Response
    {
        return $this->send(new ApiRequest('GET', "/api/v2.0/direct-debit/binding/{$this->segment($bindingId)}"));
    }

    /**
     * Unbind a card. May answer HTTP 202 with `otp_required` and an
     * `otp_handoff` object — pass its values to {@see verifyOtp()}.
     *
     * `POST /api/v2.0/direct-debit/binding/{binding_id}/unbind`
     *
     * @param  string  $bindingId  Binding UUID.
     */
    public function unbindCard(string $bindingId): Response
    {
        return $this->send(new ApiRequest('POST', "/api/v2.0/direct-debit/binding/{$this->segment($bindingId)}/unbind", body: []));
    }

    /**
     * Charge an active binding.
     *
     * `POST /api/v2.0/direct-debit/charge` — signed, but *not* money-out: this
     * collects funds from the customer, so it stays available with
     * `singapay.money_out.enabled` off. Accepting direct-debit payments must
     * never require unlocking real disbursement.
     *
     * May answer with `requires_otp: true`; complete via {@see verifyOtp()}.
     *
     * @param  array<string, mixed>  $data  Required: `binding_id` (UUID, must be
     *                                      ACTIVE), `merchant_reference` (idempotency key, max 100), `amount`
     *                                      (min 10,000). `account_id` defaults to the configured account.
     *                                      Optional: `currency` (default IDR), `description` (max 512).
     */
    public function charge(array $data): Response
    {
        return $this->send(new ApiRequest('POST', '/api/v2.0/direct-debit/charge', body: $this->withAccountId($data), signed: true));
    }

    /**
     * Verify an OTP for a pending charge OR a pending unbind.
     *
     * `POST /api/v2.0/direct-debit/verify-otp`
     *
     * Send `otp` plus exactly one flow: `transaction_id` (payment) or
     * `binding_id` + `unbind_context` (unbinding). Sending both — or
     * neither — is an error.
     *
     * @param  array<string, mixed>  $data
     */
    public function verifyOtp(array $data): Response
    {
        return $this->send(new ApiRequest('POST', '/api/v2.0/direct-debit/verify-otp', body: $data));
    }

    /**
     * Retrieve a charge transaction (auto-reconciles with the bank when a
     * non-terminal transaction has passed its processing window).
     *
     * `GET /api/v2.0/direct-debit/transaction/{transaction_id}`
     *
     * @param  string  $transactionId  Transaction UUID.
     */
    public function findTransaction(string $transactionId): Response
    {
        return $this->send(new ApiRequest('GET', "/api/v2.0/direct-debit/transaction/{$this->segment($transactionId)}"));
    }
}
