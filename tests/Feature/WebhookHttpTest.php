<?php

declare(strict_types=1);

use Aliziodev\Singapay\Auth\RequestSigner;
use Aliziodev\Singapay\Enums\WebhookType;
use Aliziodev\Singapay\Events;
use Aliziodev\Singapay\Events\WebhookReceived;
use Aliziodev\Singapay\Facades\SingaPay;
use Aliziodev\Singapay\Models\WebhookEvent;
use Aliziodev\Singapay\Support\JakartaClock;
use Aliziodev\Singapay\Support\SingaPayConfig;
use Aliziodev\Singapay\Testing\Concerns\InteractsWithSingaPay;
use Aliziodev\Singapay\Tests\TestCase;
use Aliziodev\Singapay\Webhooks\WebhookVerifier;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;

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
    'product expiration' => [['event' => 'product-expiration', 'data' => []], Events\ProductsExpired::class],
    'transaction expiration' => [['event' => 'transaction-expiration', 'data' => []], Events\MoneyInTransactionsExpired::class],
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

/**
 * The genuine sandbox `disbursement` delivery captured on 2026-08-21, verbatim
 * apart from shortened identifiers. It is signed by the credential that owns
 * the notification, which is not necessarily the one that made the transfer.
 *
 * @return array<string, mixed>
 */
function disbursementPayload(): array
{
    return [
        'response_code' => 'SP000',
        'response_message' => 'Successfully',
        'event' => 'disbursement',
        'data' => [
            'transaction_id' => '1401541222026082121111766336934',
            'reference_number' => 'WH-OK-260821141116',
            'transaction_status' => ['code' => '00', 'desc' => 'Success'],
            'post_timestamp' => '1787321477000',
            'processed_timestamp' => '1787321478000',
            'bank' => [
                'code' => '002',
                'name' => 'BRI',
                'account_name' => 'PT SAMPLE COMPANY',
                'account_number' => '100000000000001',
            ],
            'gross_amount' => ['currency' => 'IDR', 'value' => '11000.00'],
            'fee' => ['currency' => 'IDR', 'value' => '1000'],
            'net_amount' => ['currency' => 'IDR', 'value' => '10000.00'],
            'balance_after' => ['currency' => 'IDR', 'value' => '1231011.00'],
            'notes' => '',
        ],
    ];
}

/**
 * Post a delivery signed with an arbitrary key, the way a credential the app
 * is not configured with would sign it.
 *
 * @param  array<string, mixed>  $payload
 * @return TestResponse<Response>
 */
function postWebhookSignedWith(string $secret, array $payload): TestResponse
{
    $body = (string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $timestamp = app(JakartaClock::class)->unixSeconds();

    $signature = app(RequestSigner::class)->signHashedBody(
        'POST',
        '/webhooks/singapay',
        'test-webhook-token',
        hash('sha256', WebhookVerifier::normalizeBody($body) ?? $body),
        $timestamp,
        $secret,
    );

    return test()->call(
        'POST',
        '/webhooks/singapay',
        server: test()->transformHeadersToServerVars([
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer test-webhook-token',
            'X-Timestamp' => (string) $timestamp,
            'X-Signature' => $signature,
        ]),
        content: $body,
    );
}

it('rejects a delivery signed by a credential the app is not configured with', function (): void {
    Event::fake();

    postWebhookSignedWith('another-credentials-secret', disbursementPayload())
        ->assertStatus(401);

    Event::assertNotDispatched(WebhookReceived::class);
    $this->assertDatabaseCount('singapay_webhook_events', 0);
});

it('accepts that delivery once the other credential secret is listed', function (): void {
    config()->set('singapay.webhooks.secrets', 'another-credentials-secret');
    app()->forgetInstance(SingaPayConfig::class);

    Event::fake([Events\DisbursementProcessed::class]);

    postWebhookSignedWith('another-credentials-secret', disbursementPayload())
        ->assertOk()
        ->assertJson(['received' => true]);

    Event::assertDispatched(
        Events\DisbursementProcessed::class,
        fn (Events\DisbursementProcessed $e): bool => $e->referenceNumber() === 'WH-OK-260821141116'
            && $e->isSuccessful()
            && $e->transactionId() === '1401541222026082121111766336934',
    );

    $this->assertDatabaseHas('singapay_webhook_events', ['event_type' => 'disbursement']);
});

it('still reports a failed disbursement through the same event', function (): void {
    config()->set('singapay.webhooks.secrets', ['another-credentials-secret']);
    app()->forgetInstance(SingaPayConfig::class);

    Event::fake([Events\DisbursementProcessed::class]);

    $failed = disbursementPayload();
    $failed['response_code'] = 'SP001';
    $failed['response_message'] = 'Transaction Failure';
    $failed['data']['reference_number'] = 'WH-NG-260821141116';
    $failed['data']['transaction_status'] = ['code' => '06', 'desc' => 'Failed'];
    $failed['data']['failed_reason'] = 'Account validation failed: ACCOUNT-INTERNAL_SERVER_ERROR';
    $failed['data']['failed_code'] = '500';

    postWebhookSignedWith('another-credentials-secret', $failed)->assertOk();

    Event::assertDispatched(
        Events\DisbursementProcessed::class,
        fn (Events\DisbursementProcessed $e): bool => $e->isSuccessful() === false
            && $e->data('failed_code') === '500',
    );
});
