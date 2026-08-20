<?php

declare(strict_types=1);

use Aliziodev\Singapay\Auth\RequestSigner;
use Aliziodev\Singapay\Enums\WebhookType;
use Aliziodev\Singapay\Events;
use Aliziodev\Singapay\Events\WebhookReceived;
use Aliziodev\Singapay\Facades\SingaPay;
use Aliziodev\Singapay\Models\WebhookEvent;
use Aliziodev\Singapay\Testing\Concerns\InteractsWithSingaPay;
use Aliziodev\Singapay\Tests\TestCase;
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

it('accepts deliveries signed with the dashboard hmac validation key', function (): void {
    config()->set('singapay.hmac_key', 'dashboard-hmac-validation-key');
    reloadSingaPay();

    Event::fake([Events\VirtualAccountPaid::class]);

    // The helper signs with the primary webhook key — the HMAC key here.
    $this->postSingaPayWebhook(vaPayload())->assertOk();

    Event::assertDispatched(Events\VirtualAccountPaid::class);
});

it('still accepts client-secret-signed deliveries when an hmac key is configured', function (): void {
    config()->set('singapay.hmac_key', 'dashboard-hmac-validation-key');
    reloadSingaPay();

    $body = (string) json_encode(vaPayload());
    $timestamp = (string) now()->getTimestamp();
    $signature = app(RequestSigner::class)->signHashedBody(
        'POST',
        '/webhooks/singapay',
        'test-webhook-token',
        hash('sha256', $body),
        (int) $timestamp,
        TestCase::CLIENT_SECRET,
    );

    $this->call('POST', '/webhooks/singapay', server: $this->transformHeadersToServerVars([
        'Content-Type' => 'application/json',
        'Authorization' => 'Bearer test-webhook-token',
        'X-Timestamp' => $timestamp,
        'X-Signature' => $signature,
    ]), content: $body)->assertOk();
});

it('releases the claim and answers 5xx when a listener throws, so retries reprocess', function (): void {
    $attempts = 0;

    Event::listen(Events\VirtualAccountPaid::class, function () use (&$attempts): void {
        if (++$attempts === 1) {
            throw new RuntimeException('listener exploded');
        }
    });

    // First delivery: listener fails → 5xx (SingaPay will retry), claim released.
    $this->postSingaPayWebhook(vaPayload('INV-RETRY'))->assertServerError();

    $this->assertDatabaseCount('singapay_webhook_events', 0);

    // The gateway's retry must be reprocessed, not swallowed as a duplicate.
    $this->postSingaPayWebhook(vaPayload('INV-RETRY'))->assertOk()->assertJsonMissing(['duplicate' => true]);

    expect($attempts)->toBe(2);
    $this->assertDatabaseCount('singapay_webhook_events', 1);
});

it('acknowledges an in-flight duplicate without dispatching (claim-then-dispatch)', function (): void {
    $body = (string) json_encode(vaPayload('INV-INFLIGHT'));

    // Another worker holds a fresh, unprocessed claim for this delivery.
    WebhookEvent::query()->create([
        'event_id' => hash('sha256', $body),
        'event_type' => 'va-transaction',
        'payload' => [],
        'processed_at' => null,
    ]);

    Event::fake([Events\VirtualAccountPaid::class]);

    $this->call(
        'POST',
        '/webhooks/singapay',
        server: $this->transformHeadersToServerVars($this->singaPayWebhookHeaders($body)),
        content: $body,
    )->assertOk()->assertJson(['duplicate' => true]);

    Event::assertNotDispatched(Events\VirtualAccountPaid::class);
});

it('reclaims a stale claim left by a crashed worker', function (): void {
    $body = (string) json_encode(vaPayload('INV-STALE'));

    $stale = WebhookEvent::query()->create([
        'event_id' => hash('sha256', $body),
        'event_type' => 'va-transaction',
        'payload' => [],
        'processed_at' => null,
    ]);
    $stale->forceFill(['created_at' => now()->subMinutes(10)])->save();

    Event::fake([Events\VirtualAccountPaid::class]);

    $this->call(
        'POST',
        '/webhooks/singapay',
        server: $this->transformHeadersToServerVars($this->singaPayWebhookHeaders($body)),
        content: $body,
    )->assertOk()->assertJsonMissing(['duplicate' => true]);

    Event::assertDispatched(Events\VirtualAccountPaid::class);

    expect(WebhookEvent::query()->sole()->processed_at)->not->toBeNull();
});

it('builds signed headers even for raw non-JSON bodies', function (): void {
    $headers = $this->singaPayWebhookHeaders('not-json');

    expect($headers)->toHaveKeys(['X-Signature', 'X-Timestamp', 'Authorization'])
        ->and(strlen($headers['X-Signature']))->toBe(128);
});

it('provides the fake through the testing concern shorthand', function (): void {
    $fake = $this->fakeSingaPay(['*balance*' => ['balance' => ['value' => '7.00']]]);

    expect(SingaPay::balance()->merchant()->data('balance.value'))->toBe('7.00');

    $fake->assertSentCount(1);
});

it('rejects non-JSON bodies', function (): void {
    config()->set('singapay.webhooks.verify_signature', false);
    reloadSingaPay();

    $this->call('POST', '/webhooks/singapay', content: 'not-json')->assertStatus(400);
});
