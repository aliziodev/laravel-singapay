<?php

declare(strict_types=1);

use Aliziodev\Singapay\Auth\IdentitySigner;
use Aliziodev\Singapay\Auth\IdentityTokenManager;
use Aliziodev\Singapay\Contracts\TokenRepositoryInterface;
use Aliziodev\Singapay\Exceptions\AuthenticationException;
use Aliziodev\Singapay\Exceptions\ConnectionException;
use Aliziodev\Singapay\Support\SingaPayConfig;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException as HttpConnectionException;
use Illuminate\Http\Client\Factory;
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

it('honours the double-check inside the lock without a second exchange', function (): void {
    Http::fake();

    // Simulates losing the pre-lock race: get() misses outside the lock,
    // then hits inside it — no credential exchange may happen.
    $repository = new class implements TokenRepositoryInterface
    {
        private int $reads = 0;

        public function get(string $key): ?string
        {
            return ++$this->reads > 1 ? 'kyc-token-from-competitor' : null;
        }

        public function put(string $key, string $token, int $ttlSeconds): void {}

        public function forget(string $key): void {}

        public function withLock(string $key, Closure $callback): mixed
        {
            return $callback();
        }
    };

    $manager = new IdentityTokenManager(
        $repository,
        app(Factory::class),
        app(IdentitySigner::class),
        app(SingaPayConfig::class),
    );

    expect($manager->token())->toBe('kyc-token-from-competitor');

    Http::assertNothingSent();
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
