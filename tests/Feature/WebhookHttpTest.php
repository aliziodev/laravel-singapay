<?php

declare(strict_types=1);

use Aliziodev\Singapay\Enums\WebhookType;
use Aliziodev\Singapay\Events;
use Aliziodev\Singapay\Events\WebhookReceived;
use Aliziodev\Singapay\Testing\Concerns\InteractsWithSingaPay;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

uses(InteractsWithSingaPay::class);

afterEach(fn () => CarbonImmutable::setTestNow());

function vaPayload(string $reff = 'INV-2026-001'): array
{
    return [
        'status' => 200,
        'success' => true,
        'event' => 'va-transaction',
        'timestamp' => '26 Dec 2025 13:35:45',
        'data' => [
            'transaction' => [
                'reff_no' => $reff,
                'transaction_id' => '3211120250926133543246',
                'type' => 'va',
                'status' => 'paid',
                'amount' => ['value' => 100000, 'currency' => 'IDR'],
            ],
            'payment' => ['method' => 'va', 'additional_info' => ['va_number' => '7872955146576837']],
        ],
    ];
}

it('accepts a correctly signed delivery and dispatches both events', function (): void {
    Event::fake([WebhookReceived::class, Events\VirtualAccountPaid::class]);

    $this->postSingaPayWebhook(vaPayload())
        ->assertOk()
        ->assertJson(['received' => true]);

    Event::assertDispatched(WebhookReceived::class, fn (WebhookReceived $e): bool => $e->type === WebhookType::VirtualAccount);
    Event::assertDispatched(Events\VirtualAccountPaid::class, fn (Events\VirtualAccountPaid $e): bool => $e->reffNo() === 'INV-2026-001');

    $this->assertDatabaseHas('singapay_webhook_events', ['event_type' => 'va-transaction']);
});

it('acknowledges duplicate deliveries without re-dispatching', function (): void {
    Event::fake([Events\VirtualAccountPaid::class]);

    $this->postSingaPayWebhook(vaPayload())->assertOk()->assertJson(['received' => true]);
    $this->postSingaPayWebhook(vaPayload())->assertOk()->assertJson(['received' => true, 'duplicate' => true]);

    Event::assertDispatchedTimes(Events\VirtualAccountPaid::class, 1);

    expect(DB::table('singapay_webhook_events')->count())->toBe(1);
});

it('treats a different payload as a new delivery', function (): void {
    Event::fake([Events\VirtualAccountPaid::class]);

    $this->postSingaPayWebhook(vaPayload('INV-1'))->assertOk();
    $this->postSingaPayWebhook(vaPayload('INV-2'))->assertOk()->assertJsonMissing(['duplicate' => true]);

    Event::assertDispatchedTimes(Events\VirtualAccountPaid::class, 2);
});

it('rejects a delivery with an invalid signature', function (): void {
    Event::fake();

    $body = (string) json_encode(vaPayload());
    $headers = $this->singaPayWebhookHeaders($body);
    $headers['X-Signature'] = str_repeat('ab', 64);

    $this->call('POST', '/webhooks/singapay', server: $this->transformHeadersToServerVars($headers), content: $body)
        ->assertStatus(401);

    Event::assertNotDispatched(WebhookReceived::class);
    $this->assertDatabaseCount('singapay_webhook_events', 0);
});

it('rejects a tampered body', function (): void {
    Event::fake();

    $headers = $this->singaPayWebhookHeaders((string) json_encode(vaPayload('INV-1')));
    $tampered = (string) json_encode(vaPayload('INV-EVIL'));

    $this->call('POST', '/webhooks/singapay', server: $this->transformHeadersToServerVars($headers), content: $tampered)
        ->assertStatus(401);

    Event::assertNotDispatched(WebhookReceived::class);
});

it('rejects a replayed delivery with a stale timestamp', function (): void {
    Event::fake();

    $body = (string) json_encode(vaPayload());
    $headers = $this->singaPayWebhookHeaders($body);

    $this->travel(10)->minutes();

    $this->call('POST', '/webhooks/singapay', server: $this->transformHeadersToServerVars($headers), content: $body)
        ->assertStatus(401);

    Event::assertNotDispatched(WebhookReceived::class);
});

it('rejects deliveries with missing signature headers', function (): void {
    $this->postJson('/webhooks/singapay', vaPayload())->assertStatus(401);
});

it('still dispatches the generic event for unrecognized webhook types', function (): void {
    Event::fake([WebhookReceived::class]);

    $this->postSingaPayWebhook(['event' => 'future-webhook-type', 'data' => ['x' => 1]])
        ->assertOk();

    Event::assertDispatched(WebhookReceived::class, fn (WebhookReceived $e): bool => $e->type === null && $e->event() === 'future-webhook-type');

    $this->assertDatabaseHas('singapay_webhook_events', ['event_type' => null]);
});

it('dispatches the dedicated event class for every webhook type', function (array $payload, string $eventClass): void {
    Event::fake([$eventClass]);

    $this->postSingaPayWebhook($payload)->assertOk();

    Event::assertDispatched($eventClass);
})->with([
    'qris acquirer' => [['event' => 'qris-acquirer-transaction', 'data' => []], Events\QrisAcquirerPaid::class],
    'payment link (no event field)' => [['status' => 200, 'success' => true, 'data' => ['transaction' => ['type' => 'pl'], 'payment' => ['method' => 'payment_link']]], Events\PaymentLinkPaid::class],
    'ewallet money in' => [['event' => 'ewallet-native-transaction', 'data' => []], Events\EwalletPaid::class],
    'subscription cycle' => [['event' => 'subscription.cycle.payment_success', 'data' => []], Events\SubscriptionCycleProcessed::class],
    'disbursement' => [['response_code' => 'SP000', 'event' => 'disbursement', 'data' => []], Events\DisbursementProcessed::class],
    'ewallet topup' => [['response_code' => 'SP000', 'event' => 'ewallet-topup', 'data' => []], Events\EwalletTopupProcessed::class],
    'qris issuer' => [['response_code' => 'SP000', 'event' => 'qris-issuer', 'data' => []], Events\QrisIssuerProcessed::class],
    'settlement' => [['event' => 'settlement.completed', 'data' => []], Events\SettlementProcessed::class],
    'direct debit' => [['event' => 'direct-debit-binding', 'data' => ['binding_id' => 'b1', 'status' => 'ACTIVE']], Events\DirectDebitBindingUpdated::class],
    'payment link inquiry' => [['event' => 'payment_link.inquiry', 'data' => []], Events\PaymentLinkInquiryReceived::class],
    'product expiration' => [['event' => 'product_expiration', 'data' => []], Events\ProductsExpired::class],
    'transaction expiration' => [['event' => 'transaction_expiration', 'data' => []], Events\MoneyInTransactionsExpired::class],
]);

it('skips verification when disabled, and idempotency when disabled', function (): void {
    config()->set('singapay.webhooks.verify_signature', false);
    config()->set('singapay.webhooks.idempotency', false);
    reloadSingaPay();

    Event::fake([Events\VirtualAccountPaid::class]);

    $this->postJson('/webhooks/singapay', vaPayload())->assertOk();
    $this->postJson('/webhooks/singapay', vaPayload())->assertOk()->assertJsonMissing(['duplicate' => true]);

    Event::assertDispatchedTimes(Events\VirtualAccountPaid::class, 2);
    $this->assertDatabaseCount('singapay_webhook_events', 0);
});

it('rejects non-JSON bodies', function (): void {
    config()->set('singapay.webhooks.verify_signature', false);
    reloadSingaPay();

    $this->call('POST', '/webhooks/singapay', content: 'not-json')->assertStatus(400);
});
