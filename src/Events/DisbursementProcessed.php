<?php

declare(strict_types=1);

namespace Aliziodev\Singapay\Events;

use Aliziodev\Singapay\Enums\WebhookType;

/**
 * A disbursement (bank transfer money-out) finished processing
 * (webhook event `disbursement`).
 *
 * Check {@see isSuccessful()} — this event fires for failures too
 * (transaction status code 06).
 */
class DisbursementProcessed extends MoneyOutWebhook
{
    /**
     * @param  array<array-key, mixed>  $payload
     */
    public function __construct(array $payload)
    {
        parent::__construct($payload, WebhookType::Disbursement);
    }
}
