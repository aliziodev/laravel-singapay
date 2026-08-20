<?php

declare(strict_types=1);

use Aliziodev\Singapay\Contracts\JsonNormalizerInterface;
use Aliziodev\Singapay\Contracts\SingaPayClientInterface;
use Aliziodev\Singapay\Contracts\TokenRepositoryInterface;
use Aliziodev\Singapay\Endpoints\PaymentLinks;
use Aliziodev\Singapay\Http\Client;
use Aliziodev\Singapay\Http\Middleware\VerifyWebhookSignature;
use Aliziodev\Singapay\SingaPay;
use Aliziodev\Singapay\SingaPayServiceProvider;
use Aliziodev\Singapay\Support\JsonNormalizer;
use Aliziodev\Singapay\Support\SingaPayConfig;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Psr\Log\NullLogger;

it('binds the SDK services as singletons', function (): void {
    expect(app(SingaPayClientInterface::class))->toBeInstanceOf(Client::class)
        ->and(app(SingaPayClientInterface::class))->toBe(app(SingaPayClientInterface::class))
        ->and(app(JsonNormalizerInterface::class))->toBeInstanceOf(JsonNormalizer::class)
        ->and(app(TokenRepositoryInterface::class))->toBe(app(TokenRepositoryInterface::class))
        ->and(app(SingaPay::class))->toBe(app(SingaPay::class))
        ->and(app('singapay'))->toBe(app(SingaPay::class));
});

it('builds the typed config snapshot from the merged config file', function (): void {
    $config = app(SingaPayConfig::class);

    expect($config->clientId)->toBe('test-client-id')
        ->and($config->baseUrls)->toHaveKeys(['payment', 'biller', 'identity'])
        ->and($config->moneyOutEnabled)->toBeFalse();
});

it('exposes every endpoint group through the facade', function (): void {
    expect(Aliziodev\Singapay\Facades\SingaPay::paymentLinks())->toBeInstanceOf(PaymentLinks::class)
        ->and(app(SingaPay::class)->paymentLinks())->toBe(app(SingaPay::class)->paymentLinks());
});

it('registers the webhook route without the web middleware group', function (): void {
    expect(Route::has('singapay.webhook'))->toBeTrue();

    $route = Route::getRoutes()->getByName('singapay.webhook');

    expect($route->uri())->toBe('webhooks/singapay')
        ->and($route->methods())->toContain('POST')
        ->and($route->gatherMiddleware())->toContain(VerifyWebhookSignature::class)
        ->and($route->gatherMiddleware())->not->toContain('web');
});

it('registers the artisan commands', function (): void {
    $commands = array_keys(Artisan::all());

    expect($commands)->toContain('singapay:install', 'singapay:token', 'singapay:ping', 'singapay:verify-signature');
});

it('injects a null logger into the client when logging is disabled', function (): void {
    config()->set('singapay.logging.enabled', false);
    reloadSingaPay();

    $client = app(SingaPayClientInterface::class);

    $logger = (new ReflectionProperty($client, 'logger'))->getValue($client);

    expect($logger)->toBeInstanceOf(NullLogger::class);

    // And a real request through the client skips logging entirely.
    Http::fake([
        ...tokenEndpointFixtures(),
        '*balance-inquiry*' => Http::response(['status' => 200, 'success' => true, 'data' => []]),
    ]);

    expect(Aliziodev\Singapay\Facades\SingaPay::balance()->merchant()->successful())->toBeTrue();
});

it('registers no publishables or commands outside the console', function (): void {
    $app = Mockery::mock($this->app)->makePartial();
    $app->shouldReceive('runningInConsole')->twice()->andReturnFalse();

    $provider = new SingaPayServiceProvider($app);

    foreach (['registerPublishables', 'registerCommands'] as $method) {
        (new ReflectionMethod($provider, $method))->invoke($provider);
    }

    // Mockery verifies runningInConsole was consulted exactly twice and
    // the early returns prevented anything below from executing.
    expect(true)->toBeTrue();
});

it('exposes the client, config, and charge accessors on the manager', function (): void {
    $manager = app(SingaPay::class);

    expect($manager->client())->toBe(app(SingaPayClientInterface::class))
        ->and($manager->config())->toBe(app(SingaPayConfig::class))
        ->and($manager->charges())->toBe($manager->charges());
});

it('refuses to fake without a facade application', function (): void {
    $app = Aliziodev\Singapay\Facades\SingaPay::getFacadeApplication();

    try {
        Aliziodev\Singapay\Facades\SingaPay::setFacadeApplication(null);

        expect(fn () => Aliziodev\Singapay\Facades\SingaPay::fake())
            ->toThrow(RuntimeException::class, 'no application');
    } finally {
        Aliziodev\Singapay\Facades\SingaPay::setFacadeApplication($app);
    }
});
