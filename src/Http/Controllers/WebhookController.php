<?php

declare(strict_types=1);

namespace Aliziodev\Singapay\Http\Controllers;

use Aliziodev\Singapay\Enums\WebhookType;
use Aliziodev\Singapay\Events\WebhookReceived;
use Aliziodev\Singapay\Models\WebhookEvent;
use Aliziodev\Singapay\Support\SingaPayConfig;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

/**
 * Receives verified SingaPay webhook deliveries and turns them into events.
 *
 * Flow: identify the webhook type from the payload (never from the URL —
 * several types share one callback URL), then run the idempotency protocol
 * against the {@see WebhookEvent} ledger:
 *
 * 1. **Claim** the delivery by inserting its ledger row (unique body hash)
 *    BEFORE dispatching. Concurrent duplicates — including deliberately
 *    replayed captures of a validly signed delivery — lose the insert race
 *    and are acknowledged without dispatching, so listeners fire once.
 * 2. **Dispatch** the generic {@see WebhookReceived} event plus the
 *    type-specific event. If a listener throws, the claim is released and
 *    the error propagates as a 5xx, so SingaPay's retry can reprocess —
 *    at-least-once semantics are preserved.
 * 3. **Mark processed.** Claims left unprocessed by a hard crash are
 *    treated as stale after a grace period and reclaimed by a later retry.
 */
final class WebhookController
{
    /**
     * The idempotency table name.
     */
    public const TABLE = 'singapay_webhook_events';

    /**
     * How long an unprocessed claim blocks duplicates before a retry may
     * reclaim it. Covers a hard crash between claiming and dispatching.
     */
    private const STALE_CLAIM_SECONDS = 300;

    public function __construct(
        private readonly SingaPayConfig $config,
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

        $claim = null;

        if ($this->config->webhookIdempotency) {
            // Retries deliver the same body bytes, so the body hash is a
            // stable, type-agnostic idempotency key.
            $claim = $this->claim(hash('sha256', $rawBody), $type, $payload);

            if ($claim === null) {
                return new JsonResponse(['received' => true, 'duplicate' => true]);
            }
        }

        try {
            $this->events->dispatch(new WebhookReceived($payload, $type));

            if ($type instanceof WebhookType) {
                $this->events->dispatch($type->makeEvent($payload));
            }
        } catch (Throwable $exception) {
            // Release the claim so the gateway's retry can reprocess.
            $claim?->delete();

            throw $exception;
        }

        $claim?->forceFill(['processed_at' => now()])->save();

        return new JsonResponse(['received' => true]);
    }

    /**
     * Atomically claim the delivery, or return null when it is a duplicate
     * of a processed or currently in-flight delivery.
     *
     * The unique index on event_id is the arbiter: exactly one concurrent
     * copy wins the insert. A claim whose holder crashed (unprocessed and
     * older than the grace period) is deleted and re-claimed.
     *
     * @param  array<array-key, mixed>  $payload
     */
    private function claim(string $eventId, ?WebhookType $type, array $payload, bool $retrying = false): ?WebhookEvent
    {
        try {
            return WebhookEvent::query()->create([
                'event_id' => $eventId,
                'event_type' => $type?->value,
                'payload' => $payload,
                'processed_at' => null,
            ]);
        } catch (UniqueConstraintViolationException) {
            $existing = WebhookEvent::query()->where('event_id', $eventId)->first();

            // Processed, in-flight, or vanished mid-race: acknowledge as duplicate.
            if ($retrying
                || $existing === null
                || $existing->processed_at !== null
                || ! $existing->created_at?->lt(now()->subSeconds(self::STALE_CLAIM_SECONDS))) {
                return null;
            }

            // Stale claim from a crashed worker — reclaim it once.
            $existing->delete();

            return $this->claim($eventId, $type, $payload, retrying: true);
        }
    }
}
