<?php

declare(strict_types=1);

namespace Aliziodev\Singapay\Events;

use Aliziodev\Singapay\Enums\WebhookType;

/**
 * A payment-link payment was received.
 *
 * Identified by `event` = `payment-link-transaction`, or — because the
 * gateway may omit the `event` field for this type — by
 * `data.transaction.type` = "pl" / `data.payment.method` = "payment_link".
 */
class PaymentLinkPaid extends WebhookReceived
{
    /**
     * @param  array<array-key, mixed>  $payload
     */
    public function __construct(array $payload)
    {
        parent::__construct($payload, WebhookType::PaymentLink);
    }

    /**
     * The transaction reference (`reff_no`).
     */
    public function reffNo(): ?string
    {
        $reff = $this->data('transaction.reff_no');

        return is_scalar($reff) ? (string) $reff : null;
    }

    /**
     * The payment link's own reference (`payment_link.reff_no`).
     */
    public function paymentLinkReffNo(): ?string
    {
        $reff = $this->data('payment.additional_info.payment_link.reff_no');

        return is_scalar($reff) ? (string) $reff : null;
    }

    /**
     * The numeric payment link ID.
     */
    public function paymentLinkId(): ?int
    {
        $id = $this->data('payment.additional_info.payment_link.id');

        return is_numeric($id) ? (int) $id : null;
    }
}
