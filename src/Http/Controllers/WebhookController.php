<?php

declare(strict_types=1);

namespace Aliziodev\Singapay\Http\Controllers;

use Aliziodev\Singapay\Enums\WebhookType;
use Aliziodev\Singapay\Events\WebhookReceived;
use Aliziodev\Singapay\Support\SingaPayConfig;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Receives verified SingaPay webhook deliveries and turns them into events.
 *
 * Flow: identify the webhook type from the payload (never from the URL —
 * several types share one callback URL), skip duplicates via the
 * `singapay_webhook_events` table, then dispatch both the generic
 * {@see WebhookReceived} event and the type-specific event. Listeners run
 * synchronously; a listener that throws produces a 5xx, which makes
 * SingaPay retry the delivery — the record is only stored after listeners
 * succeed, preserving at-least-once semantics.
 */
final class WebhookController
{
    /**
     * The idempotency table name.
     */
    public const TABLE = 'singapay_webhook_events';

    public function __construct(
        private readonly SingaPayConfig $config,
        private readonly ConnectionResolverInterface $db,
        private readonly Dispatcher $events,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $rawBody = (string) $request->getContent();
        $payload = json_decode($rawBody, true);

        if (! is_array($payload)) {
            return new JsonResponse(['message' => 'Invalid payload.'], 400);
        }

        $type = WebhookType::fromPayload($payload);

        // Retries deliver the same body bytes, so the body hash is a stable,
        // type-agnostic idempotency key.
        $eventId = hash('sha256', $rawBody);

        if ($this->config->webhookIdempotency && $this->alreadyProcessed($eventId)) {
            return new JsonResponse(['received' => true, 'duplicate' => true]);
        }

        $this->events->dispatch(new WebhookReceived($payload, $type));

        if ($type instanceof WebhookType) {
            $this->events->dispatch($type->makeEvent($payload));
        }

        if ($this->config->webhookIdempotency) {
            $this->markProcessed($eventId, $type, $payload);
        }

        return new JsonResponse(['received' => true]);
    }

    private function alreadyProcessed(string $eventId): bool
    {
        return $this->db->connection()
            ->table(self::TABLE)
            ->where('event_id', $eventId)
            ->exists();
    }

    /**
     * @param  array<array-key, mixed>  $payload
     */
    private function markProcessed(string $eventId, ?WebhookType $type, array $payload): void
    {
        try {
            $this->db->connection()->table(self::TABLE)->insert([
                'event_id' => $eventId,
                'event_type' => $type?->value,
                'payload' => json_encode($payload, JSON_UNESCAPED_UNICODE),
                'processed_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (UniqueConstraintViolationException) {
            // A concurrent delivery of the same event won the race; both
            // already dispatched, which is acceptable under at-least-once.
        }
    }
}
