<?php

declare(strict_types=1);

namespace Aliziodev\Singapay\Endpoints;

use Aliziodev\Singapay\Http\ApiRequest;
use Aliziodev\Singapay\Http\Response;

/**
 * Cardless ATM withdrawals.
 *
 * {@see create()} locks the balance and issues an OTP the customer uses at
 * the ATM. Amounts must be multiples of 50,000 (range 50,000–1,000,000).
 * The show endpoint is keyed by `reference_number`, not `transaction_id`.
 *
 * The lifecycle is `open` → `success` | `failed` | `expired`, and a locked
 * balance is reversed automatically on the latter two — but only the
 * *merchant* balance. Any customer-side balance your platform keeps is yours
 * to refund.
 *
 * **Create and show return different shapes for the same transaction.**
 * Create answers the v2 envelope (`transaction_status`, `otp_number`,
 * `gross_amount`/`fee`/`net_amount`); show and list answer a flat resource
 * (`status`, `amount`, `fee`, `total_paid`, `otp_expired_at`). Do not write
 * one reader for both. On show, `fee` is the platform margin *only* and
 * excludes the vendor/bank fee, which `total_paid` does include.
 *
 * `create()` is unverifiable in sandbox: it answers a bare HTTP 500 for every
 * input, including SingaPay's own documented example. Their spec says an
 * unprovisioned vendor should answer `SP011 Beneficiary Vendor Not Active`,
 * and the show endpoint even documents a 503 for "feature not available yet
 * in the current environment" — neither of which the gateway actually sends.
 * The read side (list, show, cancel) behaves correctly.
 */
class CardlessWithdrawal extends Endpoint
{
    /**
     * Create a withdrawal (locks balance, issues OTP). Rate-limited.
     *
     * `POST /api/v1.0/cardless-withdrawals/create` — signed (money-out guard applies).
     *
     * On success the response carries `data.otp_number` and `data.expired_at`
     * for you to forward to the customer, with `transaction_status.code`
     * `01` (Initiated). **`data.balance_after` is always `"0"` here** — it is
     * not the post-debit balance, so read {@see find()} or {@see list()} for
     * the real figure.
     *
     * Documented failures worth handling: `SP004` (duplicate
     * `reference_number`), `SP003` (insufficient balance), `SP011`
     * (no active cardless vendor) and `SP020` (account not found).
     *
     * @param  array<string, mixed>  $data  Required: `reference_number` (unique per
     *                                      account, max 64), `customer_name`, `customer_id`, `amount` — a **plain
     *                                      number**, multiple of 50,000, not the `{value, currency}` object used by the
     *                                      e-wallet money-out endpoints — and `vendor_code` (e.g. CLWD_BRI).
     *                                      `account_id` defaults to the configured account.
     */
    public function create(array $data): Response
    {
        return $this->send(new ApiRequest('POST', '/api/v1.0/cardless-withdrawals/create', body: $this->withAccountId($data), signed: true, moneyOut: true));
    }

    /**
     * List withdrawals (last 12 months, 25 per page).
     *
     * `GET /api/v1.0/cardless-withdrawals/transaction/{account_id}`
     *
     * @param  string|null  $accountId  Account ULID; defaults to `singapay.account_id`.
     * @param  array<string, mixed>  $filters  Optional filters (`page`, ...).
     */
    public function list(?string $accountId = null, array $filters = []): Response
    {
        return $this->send(new ApiRequest('GET', "/api/v1.0/cardless-withdrawals/transaction/{$this->accountId($accountId)}", query: $filters));
    }

    /**
     * Retrieve one withdrawal by reference number.
     *
     * `GET /api/v1.0/cardless-withdrawals/transaction/{account_id}/{reference_number}`
     *
     * `status` is the raw lifecycle column: `open` (the initial state despite
     * the platform enum naming it `pending`), `success`, `expired`, `failed`,
     * `refunded`, `canceled`. `location` and `processed_at` stay null while
     * the withdrawal is still `open`.
     *
     * @param  string  $referenceNumber  The merchant reference from creation
     *                                   (not the transaction_id).
     * @param  string|null  $accountId  Account ULID.
     */
    public function find(string $referenceNumber, ?string $accountId = null): Response
    {
        return $this->send(new ApiRequest('GET', "/api/v1.0/cardless-withdrawals/transaction/{$this->accountId($accountId)}/{$this->segment($referenceNumber)}"));
    }

    /**
     * Cancel an open withdrawal and release the locked balance. Only
     * withdrawals in status `open` can be cancelled.
     *
     * `POST /api/v1.0/cardless-withdrawals/cancel`
     *
     * @param  string  $referenceNumber  The merchant reference from creation.
     * @param  string  $reason  Cancellation reason (max 100).
     * @param  string|null  $accountId  Account ULID.
     */
    public function cancel(string $referenceNumber, string $reason, ?string $accountId = null): Response
    {
        return $this->send(new ApiRequest('POST', '/api/v1.0/cardless-withdrawals/cancel', body: [
            // Body value — resolved raw, unlike accountId() which URL-encodes for paths.
            'account_id' => $accountId ?? $this->config->requireAccountId(),
            'reference_number' => $referenceNumber,
            'reason' => $reason,
        ]));
    }
}
