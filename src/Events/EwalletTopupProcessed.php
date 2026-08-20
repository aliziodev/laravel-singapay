<?php

declare(strict_types=1);

namespace Aliziodev\Singapay\Events;

use Aliziodev\Singapay\Enums\WebhookType;

/**
 * An e-wallet top-up (money-out) finished processing
 * (webhook event `ewallet-topup`).
 *
 * On failure SingaPay refunds the gross amount to the merchant balance
 * automatically; check {@see isSuccessful()} before fulfilling.
 */
class EwalletTopupProcessed extends MoneyOutWebhook
{
    /**
     * @param  array<array-key, mixed>  $payload
     */
    public function __construct(array $payload)
    {
        parent::__construct($payload, WebhookType::EwalletTopup);
    }
}
