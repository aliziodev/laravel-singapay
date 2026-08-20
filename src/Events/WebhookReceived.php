<?php

declare(strict_types=1);

namespace Aliziodev\Singapay\Events;

use Aliziodev\Singapay\Enums\WebhookType;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Support\Arr;

/**
 * Generic event dispatched for every verified SingaPay webhook delivery,
 * including types the SDK does not (yet) recognize.
 *
 * A dedicated subclass (e.g. {@see VirtualAccountPaid}) is dispatched
 * *in addition* to this event whenever the payload matches a known type,
 * so listeners can subscribe as narrowly or broadly as they like.
 */
class WebhookReceived
{
    use Dispatchable;

    /**
     * @param  array<array-key, mixed>  $payload  The full decoded webhook payload.
     * @param  WebhookType|null  $type  The identified webhook type, or null when unrecognized.
     */
    public function __construct(
        public readonly array $payload,
        public readonly ?WebhookType $type = null,
    ) {}

    /**
     * The raw `event` field value from the payload, when present.
     */
    public function event(): ?string
    {
        $event = $this->payload['event'] ?? null;

        return is_string($event) ? $event : null;
    }

    /**
     * Retrieve a value from the payload's `data` section using dot notation.
     */
    public function data(?string $key = null, mixed $default = null): mixed
    {
        $data = $this->payload['data'] ?? [];

        if (! is_array($data)) {
            return $default;
        }

        return $key === null ? $data : Arr::get($data, $key, $default);
    }
}
