<?php

declare(strict_types=1);

namespace Aliziodev\Singapay\Events;

use Aliziodev\Singapay\Enums\WebhookType;

/**
 * A subscription lifecycle notification was received
 * (webhook events `subscription.cycle.payment_success`,
 * `subscription.cycle.payment_failed`, `subscription.plan.status_changed`).
 *
 * Note: the envelope's `success` field is always true — it reports webhook
 * emission, not charge success. Use {@see isPaymentFailed()} instead.
 */
class SubscriptionCycleProcessed extends WebhookReceived
{
    /**
     * @param  array<array-key, mixed>  $payload
     */
    public function __construct(array $payload)
    {
        parent::__construct($payload, WebhookType::SubscriptionCycle);
    }

    public function isPaymentSuccess(): bool
    {
        return $this->event() === 'subscription.cycle.payment_success';
    }

    public function isPaymentFailed(): bool
    {
        return $this->event() === 'subscription.cycle.payment_failed';
    }

    public function isStatusChange(): bool
    {
        return $this->event() === 'subscription.plan.status_changed';
    }

    /**
     * The plan ULID.
     */
    public function planId(): ?string
    {
        $id = $this->data('plan.id');

        return is_scalar($id) ? (string) $id : null;
    }

    /**
     * The billing-cycle bill number — the documented idempotency key.
     */
    public function billNumber(): ?string
    {
        $number = $this->data('bill.bill_number');

        return is_scalar($number) ? (string) $number : null;
    }
}
