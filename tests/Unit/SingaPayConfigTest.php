<?php

declare(strict_types=1);

use Aliziodev\Singapay\Enums\Environment;
use Aliziodev\Singapay\Enums\Host;
use Aliziodev\Singapay\Exceptions\ConfigurationException;
use Aliziodev\Singapay\Support\SingaPayConfig;

covers(SingaPayConfig::class);

function baseConfig(array $overrides = []): array
{
    return array_replace_recursive([
        'environment' => 'sandbox',
        'client_id' => 'cid',
        'client_secret' => 'sec',
        'partner_id' => 'pid',
        'account_id' => 'acc',
        'base_urls' => [
            'payment' => ['sandbox' => 'https://sandbox.example/', 'production' => 'https://prod.example'],
            'biller' => ['sandbox' => 'https://biller-sandbox.example', 'production' => 'https://biller.example'],
            'identity' => ['sandbox' => 'https://id-sandbox.example', 'production' => 'https://id.example'],
        ],
    ], $overrides);
}

it('builds a typed snapshot with sensible defaults', function (): void {
    $config = SingaPayConfig::fromArray(baseConfig());

    expect($config->environment)->toBe(Environment::Sandbox)
        ->and($config->authVersion)->toBe('1.1')
        ->and($config->timeout)->toBe(30)
        ->and($config->retryTimes)->toBe(2)
        ->and($config->moneyOutEnabled)->toBeFalse()
        ->and($config->webhooksEnabled)->toBeTrue()
        ->and($config->webhookPath)->toBe('webhooks/singapay')
        ->and($config->webhookTolerance)->toBe(300)
        ->and($config->loggingEnabled)->toBeTrue();
});

it('resolves base URLs per host and environment, trimming trailing slashes', function (): void {
    $config = SingaPayConfig::fromArray(baseConfig());

    expect($config->baseUrl(Host::Payment))->toBe('https://sandbox.example')
        ->and($config->baseUrl(Host::Biller))->toBe('https://biller-sandbox.example');

    $production = SingaPayConfig::fromArray(baseConfig(['environment' => 'production']));

    expect($production->baseUrl(Host::Payment))->toBe('https://prod.example')
        ->and($production->baseUrl(Host::Identity))->toBe('https://id.example');
});

it('rejects unknown environments', function (): void {
    SingaPayConfig::fromArray(baseConfig(['environment' => 'staging']));
})->throws(ConfigurationException::class, 'environment');

it('rejects unknown auth versions', function (): void {
    SingaPayConfig::fromArray(baseConfig(['auth_version' => '2.0']));
})->throws(ConfigurationException::class, 'auth_version');

it('throws a descriptive error when a base URL is missing', function (): void {
    $config = SingaPayConfig::fromArray(baseConfig(['base_urls' => ['identity' => ['sandbox' => '']]]));

    $config->baseUrl(Host::Identity);
})->throws(ConfigurationException::class, 'base_urls.identity.sandbox');

it('throws lazily when a required credential is missing', function (): void {
    $config = SingaPayConfig::fromArray(baseConfig(['client_id' => null]));

    expect($config->clientId)->toBeNull();

    $config->requireClientId();
})->throws(ConfigurationException::class, 'client_id');

it('treats empty strings as missing credentials', function (): void {
    $config = SingaPayConfig::fromArray(baseConfig(['account_id' => '']));

    $config->requireAccountId();
})->throws(ConfigurationException::class, 'account_id');

it('requires identity credentials separately from payment credentials', function (): void {
    $config = SingaPayConfig::fromArray(baseConfig());

    $config->requireIdentityClientId();
})->throws(ConfigurationException::class, 'identity.client_id');
