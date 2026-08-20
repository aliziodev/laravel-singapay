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
 */
class CardlessWithdrawal extends Endpoint
{
    /**
     * Create a withdrawal (locks balance, issues OTP). Rate-limited.
     *
     * `POST /api/v1.0/cardless-withdrawals/create` — signed (money-out guard applies).
     *
     * @param  array<string, mixed>  $data  Required: `reference_number` (unique per
     *                                      account, max 64), `customer_name`, `customer_id`, `amount` (multiple of
     *                                      50,000), `vendor_code` (e.g. CLWD_BRI). `account_id` defaults to the
     *                                      configured account.
     */
    public function create(array $data): Response
    {
        return $this->send(new ApiRequest('POST', '/api/v1.0/cardless-withdrawals/create', body: $this->withAccountId($data), signed: true));
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
     * @param  string  $referenceNumber  The merchant reference from creation
     *                                   (not the transaction_id).
     * @param  string|null  $accountId  Account ULID.
     */
    public function find(string $referenceNumber, ?string $accountId = null): Response
    {
        return $this->send(new ApiRequest('GET', "/api/v1.0/cardless-withdrawals/transaction/{$this->accountId($accountId)}/{$referenceNumber}"));
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
            'account_id' => $this->accountId($accountId),
            'reference_number' => $referenceNumber,
            'reason' => $reason,
        ]));
    }
}
