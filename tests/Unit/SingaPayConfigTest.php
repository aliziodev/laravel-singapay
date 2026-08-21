<?php

declare(strict_types=1);

use Aliziodev\Singapay\Enums\Environment;
use Aliziodev\Singapay\Enums\Host;
use Aliziodev\Singapay\Exceptions\ConfigurationException;
use Aliziodev\Singapay\Support\SingaPayConfig;

covers(SingaPayConfig::class, ConfigurationException::class);

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

it('offers both the hmac validation key and the client secret for webhook verification', function (): void {
    $config = SingaPayConfig::fromArray(baseConfig(['hmac_key' => 'hmac-validation-key']));

    expect($config->hmacKey)->toBe('hmac-validation-key')
        ->and($config->webhookSecrets())->toBe(['hmac-validation-key', 'sec']);
});

it('falls back to the client secret alone when no hmac key is set', function (): void {
    expect(SingaPayConfig::fromArray(baseConfig())->webhookSecrets())->toBe(['sec']);
});

it('deduplicates identical webhook secrets', function (): void {
    $config = SingaPayConfig::fromArray(baseConfig(['hmac_key' => 'sec']));

    expect($config->webhookSecrets())->toBe(['sec']);
});

it('accepts extra webhook secrets from a comma-separated env string', function (): void {
    $config = SingaPayConfig::fromArray(baseConfig([
        'webhooks' => ['secrets' => ' default-credential-secret , other-secret ,, '],
    ]));

    expect($config->webhookSecrets())
        ->toBe(['sec', 'default-credential-secret', 'other-secret']);
});

it('accepts extra webhook secrets given as an array', function (): void {
    $config = SingaPayConfig::fromArray(baseConfig([
        'webhooks' => ['secrets' => ['default-credential-secret', '', 'default-credential-secret']],
    ]));

    expect($config->webhookSecrets())->toBe(['sec', 'default-credential-secret']);
});

it('ignores extra webhook secrets that are neither a string nor an array', function (): void {
    $config = SingaPayConfig::fromArray(baseConfig(['webhooks' => ['secrets' => 42]]));

    expect($config->webhookExtraSecrets)->toBe([])
        ->and($config->webhookSecrets())->toBe(['sec']);
});

it('verifies a delivery signed by another credential once its secret is listed', function (): void {
    $config = SingaPayConfig::fromArray(baseConfig([
        'hmac_key' => 'hmac-validation-key',
        'webhooks' => ['secrets' => 'default-credential-secret'],
    ]));

    expect($config->webhookSecrets())
        ->toBe(['hmac-validation-key', 'sec', 'default-credential-secret']);
});

it('treats the top-level credentials as the connection named by default', function (): void {
    $config = SingaPayConfig::forConnection(baseConfig());

    expect($config->connection)->toBe('main')
        ->and($config->clientId)->toBe('cid');
});

it('merges a named connection over the shared top-level keys', function (): void {
    $config = SingaPayConfig::forConnection(baseConfig([
        'connections' => ['payouts' => ['client_id' => 'payouts-id', 'account_id' => 'payouts-acc']],
    ]), 'payouts');

    expect($config->connection)->toBe('payouts')
        ->and($config->clientId)->toBe('payouts-id')
        ->and($config->accountId)->toBe('payouts-acc')
        // Not overridden, so inherited from the top level.
        ->and($config->clientSecret)->toBe('sec')
        ->and($config->environment)->toBe(Environment::Sandbox);
});

it('follows a renamed default connection', function (): void {
    $config = SingaPayConfig::forConnection(baseConfig([
        'default' => 'payouts',
        'connections' => ['payouts' => ['client_id' => 'payouts-id']],
    ]));

    expect($config->connection)->toBe('payouts')
        ->and($config->clientId)->toBe('payouts-id');
});

it('refuses a connection that is not configured', function (): void {
    SingaPayConfig::forConnection(baseConfig([
        'connections' => ['payouts' => ['client_id' => 'x']],
    ]), 'ghost');
})->throws(ConfigurationException::class, 'ghost');

it('refuses a connection that is not an array', function (): void {
    SingaPayConfig::forConnection(baseConfig(['connections' => ['payouts' => 'nope']]), 'payouts');
})->throws(ConfigurationException::class, 'connections.payouts');

it('refuses application policy nested inside a connection', function (): void {
    SingaPayConfig::forConnection(baseConfig([
        'connections' => ['payouts' => ['client_id' => 'x', 'money_out' => ['enabled' => true]]],
    ]), 'payouts');
})->throws(ConfigurationException::class, 'money_out');

it('lists every connection name with the default first', function (): void {
    $config = baseConfig([
        'default' => 'main',
        'connections' => ['payouts' => ['client_id' => 'x'], 'main' => ['client_id' => 'y']],
    ]);

    expect(SingaPayConfig::connectionNames($config))->toBe(['main', 'payouts'])
        ->and(SingaPayConfig::connectionNames(baseConfig()))->toBe(['main']);
});

it('throws when no webhook secret is configured at all', function (): void {
    $config = SingaPayConfig::fromArray(baseConfig(['client_secret' => null]));

    $config->webhookSecrets();
})->throws(ConfigurationException::class, 'client_secret');
