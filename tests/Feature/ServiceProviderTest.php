<?php

declare(strict_types=1);

use Aliziodev\Singapay\Contracts\JsonNormalizerInterface;
use Aliziodev\Singapay\Contracts\SingaPayClientInterface;
use Aliziodev\Singapay\Contracts\TokenRepositoryInterface;
use Aliziodev\Singapay\Endpoints\PaymentLinks;
use Aliziodev\Singapay\Http\Client;
use Aliziodev\Singapay\Http\Middleware\VerifyWebhookSignature;
use Aliziodev\Singapay\SingaPay;
use Aliziodev\Singapay\Support\JsonNormalizer;
use Aliziodev\Singapay\Support\SingaPayConfig;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Route;

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
