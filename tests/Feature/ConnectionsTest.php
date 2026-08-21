<?php

declare(strict_types=1);

use Aliziodev\Singapay\Contracts\SingaPayClientInterface;
use Aliziodev\Singapay\Events;
use Aliziodev\Singapay\Exceptions\ConfigurationException;
use Aliziodev\Singapay\Facades\SingaPay;
use Aliziodev\Singapay\Http\ApiRequest;
use Aliziodev\Singapay\Http\Response;
use Aliziodev\Singapay\SingaPayManager;
use Aliziodev\Singapay\Tests\TestCase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;

covers(SingaPayManager::class);

/**
 * Declare a second credential set, the way a merchant with a Specific
 * dashboard credential for one sub-account would.
 */
function withPayoutsConnection(): void
{
    config()->set('singapay.connections', [
        'payouts' => [
            'client_id' => 'payouts-client-id',
            'client_secret' => 'payouts-client-secret',
            'partner_id' => 'payouts-partner-id',
            'account_id' => '01J5XW8LD0R6M9CJEXAMPLE99',
        ],
    ]);

    reloadSingaPay();
}

it('reaches a second credential set by name', function (): void {
    withPayoutsConnection();

    $payouts = SingaPay::connection('payouts')->config();

    expect($payouts->connection)->toBe('payouts')
        ->and($payouts->clientId)->toBe('payouts-client-id')
        ->and($payouts->clientSecret)->toBe('payouts-client-secret')
        ->and($payouts->accountId)->toBe('01J5XW8LD0R6M9CJEXAMPLE99')
        // Everything not listed on the connection is inherited.
        ->and($payouts->environment)->toBe(app(SingaPayManager::class)->config()->environment)
        ->and($payouts->moneyOutEnabled)->toBeFalse();
});

it('leaves the default connection untouched when another is added', function (): void {
    withPayoutsConnection();

    expect(SingaPay::config()->clientId)->toBe(TestCase::CLIENT_ID)
        ->and(SingaPay::config()->connection)->toBe('main')
        ->and(SingaPay::getDefaultConnection())->toBe('main')
        ->and(SingaPay::connectionNames())->toBe(['main', 'payouts']);
});

it('sends a request with the credentials of the named connection', function (): void {
    withPayoutsConnection();

    Http::fake(array_merge(tokenEndpointFixtures(), [
        'https://sandbox-payment-b2b.singapay.id/api/v1.0/balance-inquiry' => Http::response([
            'status' => 200,
            'success' => true,
            'data' => ['balance' => ['value' => '1000.00', 'currency' => 'IDR']],
        ]),
    ]));

    SingaPay::connection('payouts')->balance()->merchant();

    Http::assertSent(fn ($request): bool => str_contains($request->url(), 'balance-inquiry')
        && $request->header('X-PARTNER-ID') === ['payouts-partner-id']);
});

it('refuses a connection that is not configured', function (): void {
    withPayoutsConnection();

    SingaPay::connection('nope');
})->throws(ConfigurationException::class, 'nope');

it('refuses application policy nested inside a connection', function (): void {
    config()->set('singapay.connections', [
        'payouts' => ['client_id' => 'x', 'money_out' => ['enabled' => true]],
    ]);
    reloadSingaPay();

    SingaPay::connection('payouts');
})->throws(ConfigurationException::class, 'money_out');

it('honours a non-default connection name', function (): void {
    config()->set('singapay.default', 'payouts');
    config()->set('singapay.connections', [
        'payouts' => ['client_id' => 'payouts-client-id', 'client_secret' => 'payouts-client-secret'],
    ]);
    reloadSingaPay();

    expect(SingaPay::getDefaultConnection())->toBe('payouts')
        ->and(SingaPay::config()->clientId)->toBe('payouts-client-id')
        ->and(SingaPay::connectionNames())->toBe(['payouts']);
});

it('treats the top-level keys as the default connection when none is declared', function (): void {
    expect(SingaPay::connectionNames())->toBe(['main'])
        ->and(SingaPay::config()->clientId)->toBe(TestCase::CLIENT_ID);
});

it('verifies a webhook signed by any configured connection', function (): void {
    withPayoutsConnection();

    Event::fake([Events\DisbursementProcessed::class]);

    // Signed with the payouts credential while the default connection — the
    // one API calls use — holds a different secret entirely.
    postWebhookSignedWith('payouts-client-secret', disbursementPayload())
        ->assertOk()
        ->assertJson(['received' => true]);

    Event::assertDispatched(Events\DisbursementProcessed::class);
});

it('still rejects a webhook signed by a credential no connection holds', function (): void {
    withPayoutsConnection();

    Event::fake();

    postWebhookSignedWith('a-credential-we-do-not-hold', disbursementPayload())
        ->assertStatus(401);

    Event::assertNotDispatched(Events\WebhookReceived::class);
});

it('fakes every connection at once', function (): void {
    withPayoutsConnection();

    $fake = SingaPay::fake([
        'balance-inquiry' => ['balance' => ['value' => '500.00', 'currency' => 'IDR']],
    ]);

    SingaPay::connection('payouts')->balance()->merchant();

    expect(SingaPay::connection('payouts'))->toBe($fake);
    $fake->assertSentCount(1);
});

it('keeps the access tokens of different connections apart', function (): void {
    withPayoutsConnection();

    Http::fake(tokenEndpointFixtures());

    app(SingaPayManager::class)->tokens()->token();
    app(SingaPayManager::class)->tokens('payouts')->token();

    // Two exchanges, not one: the second connection did not reuse the
    // first's cached token, because the cache key includes the client id.
    Http::assertSentCount(2);
});

it('skips a connection with no usable secret instead of failing verification', function (): void {
    config()->set('singapay.connections', [
        // Half-configured: no secret of its own, so it contributes no
        // verification candidate — and must not break the ones that do.
        'halfway' => ['client_id' => 'halfway-client-id', 'client_secret' => null],
    ]);
    reloadSingaPay();

    expect(app(SingaPayManager::class)->webhookSecrets())->toBe([TestCase::CLIENT_SECRET]);

    Event::fake([Events\DisbursementProcessed::class]);

    postWebhookSignedWith(TestCase::CLIENT_SECRET, disbursementPayload())->assertOk();

    Event::assertDispatched(Events\DisbursementProcessed::class);
});

it('reports a missing secret when no connection has one', function (): void {
    config()->set('singapay.client_secret', null);
    config()->set('singapay.hmac_key', null);
    reloadSingaPay();

    app(SingaPayManager::class)->webhookSecrets();
})->throws(ConfigurationException::class, 'client_secret');

it('applies a container decorator to every connection, not just the default', function (): void {
    withPayoutsConnection();

    app()->extend(
        SingaPayClientInterface::class,
        fn (SingaPayClientInterface $client): SingaPayClientInterface => new class($client) implements SingaPayClientInterface
        {
            public function __construct(public readonly SingaPayClientInterface $inner) {}

            public function send(ApiRequest $request): Response
            {
                return $this->inner->send($request);
            }
        }
    );

    // Decorating the transport is the documented extension point, so it has
    // to reach a named connection too — otherwise metrics or tracing added
    // this way would silently miss every payout.
    expect(SingaPay::connection('payouts')->client())->toHaveProperty('inner')
        ->and(SingaPay::client())->toHaveProperty('inner');

    // ...and the wrapped client is still the payouts one, not the default
    // collapsed back in.
    Http::fake(array_merge(tokenEndpointFixtures(), [
        'https://sandbox-payment-b2b.singapay.id/api/v1.0/balance-inquiry' => Http::response([
            'status' => 200, 'success' => true, 'data' => [],
        ]),
    ]));

    SingaPay::connection('payouts')->balance()->merchant();

    Http::assertSent(fn ($request): bool => str_contains($request->url(), 'balance-inquiry')
        && $request->header('X-PARTNER-ID') === ['payouts-partner-id']);
});
