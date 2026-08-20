<?php

declare(strict_types=1);

use Aliziodev\Singapay\Enums\WebhookType;
use Aliziodev\Singapay\Events\DisbursementProcessed;
use Aliziodev\Singapay\Events\WebhookReceived;
use Aliziodev\Singapay\Models\WebhookEvent;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;

function makeWebhookEvent(array $attributes = []): WebhookEvent
{
    return WebhookEvent::query()->create(array_merge([
        'event_id' => hash('sha256', uniqid('', true)),
        'event_type' => 'va-transaction',
        'payload' => ['event' => 'va-transaction', 'data' => ['transaction' => ['reff_no' => 'INV-1']]],
        'processed_at' => now(),
    ], $attributes));
}

it('casts the payload to an array and processed_at to a datetime', function (): void {
    $event = makeWebhookEvent();

    $fresh = $event->fresh();

    expect($fresh->payload)->toBeArray()
        ->and($fresh->payload['data']['transaction']['reff_no'])->toBe('INV-1')
        ->and($fresh->processed_at)->toBeInstanceOf(Carbon::class);
});

it('resolves the webhook type, tolerating unknown and missing values', function (): void {
    expect(makeWebhookEvent()->webhookType())->toBe(WebhookType::VirtualAccount)
        ->and(makeWebhookEvent(['event_type' => 'future-type'])->webhookType())->toBeNull()
        ->and(makeWebhookEvent(['event_type' => null])->webhookType())->toBeNull();
});

it('rebuilds the typed event for replay', function (): void {
    $record = makeWebhookEvent([
        'event_type' => 'disbursement',
        'payload' => ['event' => 'disbursement', 'data' => ['reference_number' => 'REF-9', 'transaction_status' => ['code' => '00']]],
    ]);

    $event = $record->toEvent();

    expect($event)->toBeInstanceOf(DisbursementProcessed::class)
        ->and($event->referenceNumber())->toBe('REF-9')
        ->and($event->isSuccessful())->toBeTrue();

    // Replaying goes through the normal event system.
    Event::fake([DisbursementProcessed::class]);
    event($record->toEvent());
    Event::assertDispatched(DisbursementProcessed::class);
});

it('replays both the generic and the typed event, mirroring a live delivery', function (): void {
    $record = makeWebhookEvent([
        'event_type' => 'disbursement',
        'payload' => ['event' => 'disbursement', 'data' => ['reference_number' => 'REF-R']],
    ]);

    Event::fake([WebhookReceived::class, DisbursementProcessed::class]);

    $record->replay();

    // toEvent() alone would skip WebhookReceived listeners — replay must not.
    Event::assertDispatched(WebhookReceived::class);
    Event::assertDispatched(DisbursementProcessed::class, fn (DisbursementProcessed $e): bool => $e->referenceNumber() === 'REF-R');
});

it('falls back to the generic event for unrecognized types', function (): void {
    $record = makeWebhookEvent(['event_type' => null, 'payload' => ['event' => 'brand-new', 'data' => []]]);

    $event = $record->toEvent();

    expect($event)->toBeInstanceOf(WebhookReceived::class)
        ->and($event->type)->toBeNull()
        ->and($event->event())->toBe('brand-new');
});

it('filters by webhook type via the ofType scope', function (): void {
    makeWebhookEvent(['event_type' => 'va-transaction']);
    makeWebhookEvent(['event_type' => 'disbursement']);
    makeWebhookEvent(['event_type' => 'disbursement']);

    expect(WebhookEvent::query()->ofType(WebhookType::Disbursement)->count())->toBe(2)
        ->and(WebhookEvent::query()->ofType('va-transaction')->count())->toBe(1);
});

it('prunes rows older than the configured retention', function (): void {
    config()->set('singapay.webhooks.prune_after_days', 7);

    $old = makeWebhookEvent();
    $old->forceFill(['created_at' => now()->subDays(8)])->save();

    $recent = makeWebhookEvent();

    $this->artisan('model:prune', ['--model' => [WebhookEvent::class]])->assertSuccessful();

    expect(WebhookEvent::query()->pluck('id')->all())->toBe([$recent->id]);
});

it('is the same ledger the webhook controller writes to', function (): void {
    config()->set('singapay.webhooks.verify_signature', false);
    reloadSingaPay();

    $this->postJson('/webhooks/singapay', ['event' => 'va-transaction', 'data' => []])->assertOk();

    $record = WebhookEvent::query()->sole();

    expect($record->webhookType())->toBe(WebhookType::VirtualAccount)
        ->and($record->payload)->toBe(['event' => 'va-transaction', 'data' => []])
        ->and($record->processed_at)->not->toBeNull();
});
