<?php

declare(strict_types=1);

namespace Aliziodev\Singapay\Events;

use Aliziodev\Singapay\Enums\WebhookType;

/**
 * A virtual-account payment was received (webhook event `va-transaction`).
 *
 * VA webhooks are only sent after the payment is confirmed, so the
 * transaction status is normally `paid`.
 */
class VirtualAccountPaid extends WebhookReceived
{
    /**
     * @param  array<array-key, mixed>  $payload
     */
    public function __construct(array $payload)
    {
        parent::__construct($payload, WebhookType::VirtualAccount);
    }

    /**
     * The merchant reference (`reff_no`) attached to the transaction.
     */
    public function reffNo(): ?string
    {
        $reff = $this->data('transaction.reff_no');

        return is_scalar($reff) ? (string) $reff : null;
    }

    /**
     * The SingaPay transaction ID — a stable idempotency key.
     */
    public function transactionId(): ?string
    {
        $id = $this->data('transaction.transaction_id');

        return is_scalar($id) ? (string) $id : null;
    }

    /**
     * The virtual account number that was paid.
     */
    public function vaNumber(): ?string
    {
        $number = $this->data('payment.additional_info.va_number');

        return is_scalar($number) ? (string) $number : null;
    }
}
