<?php

declare(strict_types=1);

namespace Aliziodev\Singapay\Endpoints;

use Aliziodev\Singapay\Http\ApiRequest;
use Aliziodev\Singapay\Http\Response;

/**
 * Disbursement (bank transfer money-out).
 *
 * {@see transfer()} moves real money: it is signed, guarded by
 * `singapay.money_out.enabled`, and must NEVER be retried blindly — after
 * SP001/SP002/SP005 or a timeout, call {@see inquireStatus()} with the same
 * reference number first, or you risk paying twice.
 */
class Disbursement extends Endpoint
{
    /**
     * List disbursements (last 12 months, paginated).
     *
     * `GET /api/v1.0/disbursement/{account_id}`
     *
     * @param  string|null  $accountId  Account ULID; defaults to `singapay.account_id`.
     * @param  array<string, mixed>  $filters  Optional filters.
     */
    public function list(?string $accountId = null, array $filters = []): Response
    {
        return $this->send(new ApiRequest('GET', "/api/v1.0/disbursement/{$this->accountId($accountId)}", query: $filters));
    }

    /**
     * Retrieve one disbursement by its business transaction ID.
     *
     * `GET /api/v1.0/disbursement/{account_id}/{transaction_id}`
     *
     * @param  string  $transactionId  SingaPay-assigned business transaction ID (not a numeric PK).
     * @param  string|null  $accountId  Account ULID.
     */
    public function find(string $transactionId, ?string $accountId = null): Response
    {
        return $this->send(new ApiRequest('GET', "/api/v1.0/disbursement/{$this->accountId($accountId)}/{$this->segment($transactionId)}"));
    }

    /**
     * Preview the gross / fee / net breakdown for a destination bank.
     *
     * `POST /api/v1.0/disbursement/{account_id}/check-fee`
     *
     * @param  array<string, mixed>  $data  `bank_swift_code` (e.g. BRINIDJA), `amount`.
     * @param  string|null  $accountId  Account ULID.
     */
    public function checkFee(array $data, ?string $accountId = null): Response
    {
        return $this->send(new ApiRequest('POST', "/api/v1.0/disbursement/{$this->accountId($accountId)}/check-fee", body: $data));
    }

    /**
     * Validate a destination account number and retrieve the registered
     * holder name — confirm it with your user before transferring.
     *
     * `POST /api/v1.0/disbursement/{account_id}/check-beneficiary`
     *
     * Note: this endpoint is referenced by the official disbursement
     * overview but its request schema is not publicly documented; mirror
     * the transfer fields (`bank_code`, `bank_account_number`) and verify
     * against the sandbox. The KYC service's bank verification
     * ({@see IdentityVerification::verifyBankAccount()}) is the fully
     * documented equivalent.
     *
     * @param  array<string, mixed>  $data
     * @param  string|null  $accountId  Account ULID.
     */
    public function checkBeneficiary(array $data, ?string $accountId = null): Response
    {
        return $this->send(new ApiRequest('POST', "/api/v1.0/disbursement/{$this->accountId($accountId)}/check-beneficiary", body: $data));
    }

    /**
     * Transfer funds to a bank account.
     *
     * `POST /api/v2.0/disbursement/transfer` — signed (money-out guard applies).
     *
     * Never retry this call automatically. On SP001/SP005/timeout the real
     * outcome is unknown — call {@see inquireStatus()} first.
     *
     * @param  array<string, mixed>  $data  Required: `reference_number` (unique per
     *                                      account, max 64), `bank_code` (3-digit numeric like "014" or SWIFT like
     *                                      "CENAIDJA"), `bank_account_number` (6–30 digits), `amount`. `account_id`
     *                                      defaults to the configured account. Optional: `notes` (max 100).
     */
    public function transfer(array $data): Response
    {
        return $this->send(new ApiRequest('POST', '/api/v2.0/disbursement/transfer', body: $this->withAccountId($data), signed: true, moneyOut: true));
    }

    /**
     * Query a disbursement's current status by reference number.
     *
     * `POST /api/v2.0/disbursement/{account_id}/inquiry-status`
     *
     * @param  string  $referenceNumber  The merchant reference used at transfer time.
     * @param  string|null  $accountId  Account ULID.
     */
    public function inquireStatus(string $referenceNumber, ?string $accountId = null): Response
    {
        return $this->send(new ApiRequest(
            'POST',
            "/api/v2.0/disbursement/{$this->accountId($accountId)}/inquiry-status",
            body: ['reference_number' => $referenceNumber],
        ));
    }
}
