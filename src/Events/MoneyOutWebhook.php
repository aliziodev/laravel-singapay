<?php

declare(strict_types=1);

namespace Aliziodev\Singapay\Events;

use Aliziodev\Singapay\Enums\TransactionStatus;

/**
 * Shared accessors for the money-out webhook family (disbursement,
 * e-wallet top-up, QRIS issuer), which use the v2 envelope with a
 * `transaction_status` object and Unix-millisecond timestamps.
 */
abstract class MoneyOutWebhook extends WebhookReceived
{
    /**
     * The SingaPay-assigned transaction ID.
     */
    public function transactionId(): ?string
    {
        $id = $this->data('transaction_id');

        return is_scalar($id) ? (string) $id : null;
    }

    /**
     * The merchant-supplied reference number.
     */
    public function referenceNumber(): ?string
    {
        $reference = $this->data('reference_number');

        return is_scalar($reference) ? (string) $reference : null;
    }

    /**
     * The transaction status, when the payload carries a recognized code.
     */
    public function transactionStatus(): ?TransactionStatus
    {
        $code = $this->data('transaction_status.code');

        return is_scalar($code) ? TransactionStatus::tryFrom((string) $code) : null;
    }

    /**
     * Whether the funds actually moved successfully (status code 00).
     */
    public function isSuccessful(): bool
    {
        return $this->transactionStatus() === TransactionStatus::Success;
    }
}
