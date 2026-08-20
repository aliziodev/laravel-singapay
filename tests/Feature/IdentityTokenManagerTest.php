<?php

declare(strict_types=1);

use Aliziodev\Singapay\Auth\IdentityTokenManager;
use Aliziodev\Singapay\Exceptions\AuthenticationException;
use Aliziodev\Singapay\Exceptions\ConnectionException;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException as HttpConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

afterEach(fn () => CarbonImmutable::setTestNow());

it('exchanges scheme-D signed credentials for a token and caches it', function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-05-26 14:30:00', 'Asia/Jakarta'));
    Http::fake(tokenEndpointFixtures());

    $manager = app(IdentityTokenManager::class);

    expect($manager->token())->toBe('kyc-access-token')
        ->and($manager->token())->toBe('kyc-access-token');

    Http::assertSentCount(1);

    $expectedSignature = hash_hmac('sha256', 'test-identity-client:2026-05-26T07:30:00Z', 'test-identity-secret');

    Http::assertSent(function (Request $request) use ($expectedSignature): bool {
        return str_ends_with($request->url(), '/api/v1/kyc/auth/get-auth-token')
            && $request->data() === [
                'client_id' => 'test-identity-client',
                'timestamp' => '2026-05-26T07:30:00Z',
                'signature' => $expectedSignature,
            ];
    });
});

it('exchanges fresh credentials after forget()', function (): void {
    Http::fake(tokenEndpointFixtures());

    $manager = app(IdentityTokenManager::class);
    $manager->token();
    $manager->forget();
    $manager->token();

    Http::assertSentCount(2);
});

it('throws AuthenticationException when the KYC service rejects the exchange', function (): void {
    Http::fake(['*get-auth-token*' => Http::response(['message' => 'unauthorized'], 401)]);

    app(IdentityTokenManager::class)->token();
})->throws(AuthenticationException::class, 'identity token exchange failed');

it('throws AuthenticationException when no token is returned', function (): void {
    Http::fake(['*get-auth-token*' => Http::response(['token_type' => 'Bearer'])]);

    app(IdentityTokenManager::class)->token();
})->throws(AuthenticationException::class);

it('wraps transport failures in the SDK connection exception', function (): void {
    Http::fake(['*get-auth-token*' => fn () => throw new HttpConnectionException('down')]);

    app(IdentityTokenManager::class)->token();
})->throws(ConnectionException::class, 'Unable to reach SingaPay');
