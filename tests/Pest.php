<?php

declare(strict_types=1);

use Aliziodev\Singapay\Auth\AccessTokenManager;
use Aliziodev\Singapay\Auth\IdentityTokenManager;
use Aliziodev\Singapay\Auth\RequestSigner;
use Aliziodev\Singapay\Contracts\SingaPayClientInterface;
use Aliziodev\Singapay\SingaPay;
use Aliziodev\Singapay\SingaPayManager;
use Aliziodev\Singapay\Support\JakartaClock;
use Aliziodev\Singapay\Support\SingaPayConfig;
use Aliziodev\Singapay\Tests\TestCase;
use Aliziodev\Singapay\Webhooks\WebhookVerifier;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;

uses(TestCase::class)->in('Feature');

/**
 * Rebuild the SDK singletons after mutating the `singapay` config at
 * runtime. The manager itself reads config lazily, but the default
 * connection is cached as a container singleton; tests that change
 * behaviour (e.g. enable money-out) call this to re-capture it. Forgetting
 * the manager also clears any instance left by `SingaPay::fake()`.
 */
function reloadSingaPay(): void
{
    foreach ([
        SingaPayManager::class,
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
