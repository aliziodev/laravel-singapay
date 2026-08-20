<?php

declare(strict_types=1);

namespace Aliziodev\Singapay\Events;

use Aliziodev\Singapay\Enums\WebhookType;

/**
 * A settlement notification was received (webhook events
 * `settlement.completed`, `settlement.refunded`, `settlement.refund_cancelled`).
 */
class SettlementProcessed extends WebhookReceived
{
    /**
     * @param  array<array-key, mixed>  $payload
     */
    public function __construct(array $payload)
    {
        parent::__construct($payload, WebhookType::Settlement);
    }

    public function isCompleted(): bool
    {
        return $this->event() === 'settlement.completed';
    }

    public function isRefunded(): bool
    {
        return $this->event() === 'settlement.refunded';
    }

    public function isRefundCancelled(): bool
    {
        return $this->event() === 'settlement.refund_cancelled';
    }

    /**
     * The settlement reference number.
     */
    public function referenceNo(): ?string
    {
        $reference = $this->data('settlement.reference_no');

        return is_scalar($reference) ? (string) $reference : null;
    }
}
