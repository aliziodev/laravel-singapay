<?php

declare(strict_types=1);

use Aliziodev\Singapay\Auth\AccessTokenManager;
use Aliziodev\Singapay\Auth\IdentityTokenManager;
use Aliziodev\Singapay\Contracts\SingaPayClientInterface;
use Aliziodev\Singapay\SingaPay;
use Aliziodev\Singapay\Support\SingaPayConfig;
use Aliziodev\Singapay\Tests\TestCase;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Http;

uses(TestCase::class)->in('Feature');

/**
 * Rebuild the SDK singletons after mutating the `singapay` config at
 * runtime. The config snapshot is captured at boot; tests that change
 * behaviour (e.g. enable money-out) call this to re-capture it.
 */
function reloadSingaPay(): void
{
    foreach ([
        SingaPayConfig::class,
        SingaPayClientInterface::class,
        AccessTokenManager::class,
        IdentityTokenManager::class,
        SingaPay::class,
    ] as $abstract) {
        app()->forgetInstance($abstract);
    }

    Facade::clearResolvedInstances();
}

/**
 * Http fixtures for the three token endpoints, keyed by full URL.
 *
 * @return array<string, PromiseInterface>
 */
function tokenEndpointFixtures(): array
{
    return [
        'https://sandbox-payment-b2b.singapay.id/api/v1.1/access-token/b2b' => Http::response([
            'status' => 200,
            'success' => true,
            'data' => ['access_token' => 'test-access-token', 'token_type' => 'Bearer', 'expires_in' => '216000'],
        ]),
        'https://sandbox-payment-b2b.singapay.id/api/v1.0/access-token/b2b' => Http::response([
            'status' => 200,
            'success' => true,
            'data' => ['access_token' => 'legacy-access-token', 'token_type' => 'Bearer', 'expires_in' => '3600'],
        ]),
        'https://sandbox-biller-b2b.singapay.id/api/v1.0/access-token/b2b' => Http::response([
            'status' => 200,
            'success' => true,
            'data' => ['access_token' => 'biller-access-token', 'token_type' => 'Bearer', 'expires_in' => '3600'],
        ]),
        'https://sandbox-apigw.singapay.id/api/v1/kyc/auth/get-auth-token' => Http::response([
            'access_token' => 'kyc-access-token',
            'token_type' => 'Bearer',
            'expires_in' => 3600,
        ]),
    ];
}

/**
 * Load the shared signature-vector fixture.
 *
 * Decoded with objects preserved (assoc=false) so an empty JSON object in a
 * vector payload stays a stdClass and does not silently degrade to [].
 */
function signatureVectors(): stdClass
{
    static $fixture = null;

    if ($fixture === null) {
        $fixture = json_decode(
            (string) file_get_contents(__DIR__.'/Fixtures/signature-vectors.json'),
            associative: false,
            flags: JSON_THROW_ON_ERROR,
        );
    }

    return $fixture;
}
