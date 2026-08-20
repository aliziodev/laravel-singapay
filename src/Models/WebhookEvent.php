<?php

declare(strict_types=1);

namespace Aliziodev\Singapay\Models;

use Aliziodev\Singapay\Enums\WebhookType;
use Aliziodev\Singapay\Events\WebhookReceived;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A processed SingaPay webhook delivery.
 *
 * Rows are written by the webhook controller (idempotency ledger) — one
 * per unique delivery, keyed by a hash of the raw body. The model exists
 * for the consumer side: inspecting webhook history, filtering by type,
 * replaying events, and pruning.
 *
 * ```php
 * WebhookEvent::ofType(WebhookType::Disbursement)->latest()->limit(20)->get();
 *
 * event($webhookEvent->toEvent()); // replay through your listeners
 * ```
 *
 * Pruning: rows are only needed for the duration of SingaPay's retry
 * window (minutes), so schedule the standard pruner:
 *
 * ```php
 * Schedule::command('model:prune', [
 *     '--model' => [\Aliziodev\Singapay\Models\WebhookEvent::class],
 * ])->daily();
 * ```
 *
 * Retention defaults to 7 days (config `singapay.webhooks.prune_after_days`).
 *
 * @property int $id
 * @property string $event_id SHA-256 hash of the raw delivery body (idempotency key).
 * @property string|null $event_type Canonical {@see WebhookType} value, null for unrecognized types.
 * @property array<array-key, mixed>|null $payload The full decoded webhook payload.
 * @property Carbon|null $processed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class WebhookEvent extends Model
{
    use MassPrunable;

    protected $table = 'singapay_webhook_events';

    protected $fillable = [
        'event_id',
        'event_type',
        'payload',
        'processed_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'processed_at' => 'datetime',
        ];
    }

    /**
     * The identified webhook type, when the payload matched a known one.
     */
    public function webhookType(): ?WebhookType
    {
        return $this->event_type === null ? null : WebhookType::tryFrom($this->event_type);
    }

    /**
     * Rebuild the (typed) event object for this delivery.
     *
     * Note: dispatching only this object skips listeners bound to the
     * generic {@see WebhookReceived} class — Laravel resolves listeners by
     * exact class, not parent classes. Use {@see replay()} to mirror a
     * live delivery exactly.
     */
    public function toEvent(): WebhookReceived
    {
        $payload = $this->payload ?? [];
        $type = $this->webhookType();

        return $type instanceof WebhookType
            ? $type->makeEvent($payload)
            : new WebhookReceived($payload);
    }

    /**
     * Re-dispatch this delivery exactly like the webhook controller did on
     * arrival: the generic {@see WebhookReceived} event first, then the
     * type-specific event — so every listener that saw the live delivery
     * sees the replay too.
     *
     * ```php
     * WebhookEvent::find($id)->replay();
     * ```
     */
    public function replay(?Dispatcher $events = null): void
    {
        $events ??= app(Dispatcher::class);

        $payload = $this->payload ?? [];
        $type = $this->webhookType();

        $events->dispatch(new WebhookReceived($payload, $type));

        if ($type instanceof WebhookType) {
            $events->dispatch($type->makeEvent($payload));
        }
    }

    /**
     * Filter deliveries by webhook type.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeOfType(Builder $query, WebhookType|string $type): Builder
    {
        return $query->where('event_type', $type instanceof WebhookType ? $type->value : $type);
    }

    /**
     * Rows eligible for `php artisan model:prune`.
     *
     * @return Builder<static>
     */
    public function prunable(): Builder
    {
        $days = (int) config('singapay.webhooks.prune_after_days', 7);

        return static::query()->where('created_at', '<', now()->subDays(max($days, 1)));
    }
}
