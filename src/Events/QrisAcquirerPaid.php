<?php

declare(strict_types=1);

namespace Aliziodev\Singapay\Events;

use Aliziodev\Singapay\Enums\WebhookType;

/**
 * A QRIS (acquirer / money-in) payment was received
 * (webhook event `qris-acquirer-transaction`).
 */
class QrisAcquirerPaid extends WebhookReceived
{
    /**
     * @param  array<array-key, mixed>  $payload
     */
    public function __construct(array $payload)
    {
        parent::__construct($payload, WebhookType::QrisAcquirer);
    }

    /**
     * The SingaPay transaction reference (`reff_no`).
     */
    public function reffNo(): ?string
    {
        $reff = $this->data('transaction.reff_no');

        return is_scalar($reff) ? (string) $reff : null;
    }

    /**
     * The merchant's own reference number for the QR.
     */
    public function merchantReffNo(): ?string
    {
        $reff = $this->data('transaction.merchant_reff_no');

        return is_scalar($reff) ? (string) $reff : null;
    }
}
