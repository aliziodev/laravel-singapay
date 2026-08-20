<?php

declare(strict_types=1);

namespace Aliziodev\Singapay\Events;

use Aliziodev\Singapay\Enums\WebhookType;

/**
 * An e-wallet (native / money-in) payment was received
 * (webhook event `ewallet-native-transaction`).
 */
class EwalletPaid extends WebhookReceived
{
    /**
     * @param  array<array-key, mixed>  $payload
     */
    public function __construct(array $payload)
    {
        parent::__construct($payload, WebhookType::EwalletMoneyIn);
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
     * The e-wallet vendor (e.g. GOPAY, DANA, OVO, SHOPEEPAY).
     */
    public function vendor(): ?string
    {
        $vendor = $this->data('transaction.ewallet_vendor') ?? $this->data('payment.vendor');

        return is_scalar($vendor) ? (string) $vendor : null;
    }
}
