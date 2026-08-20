<?php

declare(strict_types=1);

namespace Aliziodev\Singapay\Events;

use Aliziodev\Singapay\Enums\WebhookType;

/**
 * A customer opened (or abandoned) a payment link
 * (webhook events `payment_link.inquiry`, `payment_link.inquiry.expired`).
 */
class PaymentLinkInquiryReceived extends WebhookReceived
{
    /**
     * @param  array<array-key, mixed>  $payload
     */
    public function __construct(array $payload)
    {
        parent::__construct($payload, WebhookType::PaymentLinkInquiry);
    }

    /**
     * Whether this delivery reports an expired inquiry session.
     */
    public function isExpired(): bool
    {
        return $this->event() === 'payment_link.inquiry.expired';
    }

    /**
     * The inquiry history reference — the documented idempotency key.
     */
    public function historyReffNo(): ?string
    {
        $reff = $this->data('payment_link_history.reff_no');

        return is_scalar($reff) ? (string) $reff : null;
    }
}
