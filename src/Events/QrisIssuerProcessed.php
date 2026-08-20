<?php

declare(strict_types=1);

namespace Aliziodev\Singapay\Events;

use Aliziodev\Singapay\Enums\WebhookType;

/**
 * A QRIS issuer payment credit (money-out) finished processing
 * (webhook event `qris-issuer`).
 */
class QrisIssuerProcessed extends MoneyOutWebhook
{
    /**
     * @param  array<array-key, mixed>  $payload
     */
    public function __construct(array $payload)
    {
        parent::__construct($payload, WebhookType::QrisIssuer);
    }
}
