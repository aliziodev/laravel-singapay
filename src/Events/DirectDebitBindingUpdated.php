<?php

declare(strict_types=1);

namespace Aliziodev\Singapay\Events;

use Aliziodev\Singapay\Enums\WebhookType;

/**
 * A direct-debit binding notification was received
 * (configured via `direct_debit_notif_url`).
 */
class DirectDebitBindingUpdated extends WebhookReceived
{
    /**
     * @param  array<array-key, mixed>  $payload
     */
    public function __construct(array $payload)
    {
        parent::__construct($payload, WebhookType::DirectDebit);
    }

    /**
     * The binding UUID.
     */
    public function bindingId(): ?string
    {
        $id = $this->data('binding_id');

        return is_scalar($id) ? (string) $id : null;
    }

    /**
     * The binding status (e.g. PENDING_AUTH, ACTIVE).
     */
    public function status(): ?string
    {
        $status = $this->data('status');

        return is_scalar($status) ? (string) $status : null;
    }
}
