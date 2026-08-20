<?php

declare(strict_types=1);

use Aliziodev\Singapay\Auth\AccessTokenManager;
use Aliziodev\Singapay\Enums\Host;
use Aliziodev\Singapay\Exceptions\AuthenticationException;
use Aliziodev\Singapay\Exceptions\IpNotWhitelistedException;
use Aliziodev\Singapay\Tests\TestCase;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

afterEach(fn () => CarbonImmutable::setTestNow());

it('requests a v1.1 token with the scheme-A signature and caches it', function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-20 10:00:00', 'Asia/Jakarta'));
    Http::fake(tokenEndpointFixtures());

    $manager = app(AccessTokenManager::class);

    expect($manager->token())->toBe('test-access-token')
        ->and($manager->token())->toBe('test-access-token');

    $expectedSignature = hash_hmac(
        'sha512',
        TestCase::CLIENT_ID.'_'.TestCase::CLIENT_SECRET.'_20260820',
        TestCase::CLIENT_SECRET,
    );

    Http::assertSentCount(1);
    Http::assertSent(function (Request $request) use ($expectedSignature): bool {
        return Str::endsWith($request->url(), '/api/v1.1/access-token/b2b')
            && $request->header('X-PARTNER-ID') === [TestCase::PARTNER_ID]
            && $request->header('X-CLIENT-ID') === [TestCase::CLIENT_ID]
            && $request->header('X-Signature') === [$expectedSignature]
            && $request->data() === ['grant_type' => 'client_credentials'];
    });
});

it('fetches a fresh token after forget()', function (): void {
    Http::fake(tokenEndpointFixtures());

    $manager = app(AccessTokenManager::class);

    $manager->token();
    $manager->forget();
    $manager->token();

    Http::assertSentCount(2);
});

it('refreshes the token after the cache TTL expires', function (): void {
    Http::fake([
        '*access-token*' => Http::response([
            'status' => 200,
            'success' => true,
            // Short lifetime: TTL becomes max(120 - 60, 60) = 60 seconds.
            'data' => ['access_token' => 'short-lived', 'expires_in' => '120'],
        ]),
    ]);

    $manager = app(AccessTokenManager::class);
    $manager->token();

    $this->travel(61)->seconds();

    $manager->token();

    Http::assertSentCount(2);
});

it('never caches a token beyond its actual lifetime', function (): void {
    Http::fake([
        '*access-token*' => Http::response([
            'status' => 200,
            'success' => true,
            // Lifetime below the refresh buffer: the TTL must clamp to ~1s,
            // never up to the 60s buffer floor (which would serve dead tokens).
            'data' => ['access_token' => 'ephemeral', 'expires_in' => '30'],
        ]),
    ]);

    $manager = app(AccessTokenManager::class);
    $manager->token();

    $this->travel(2)->seconds();

    $manager->token();

    Http::assertSentCount(2);
});

it('uses basic authentication for the legacy v1.0 scheme', function (): void {
    config()->set('singapay.auth_version', '1.0');
    reloadSingaPay();

    Http::fake(tokenEndpointFixtures());

    expect(app(AccessTokenManager::class)->token())->toBe('legacy-access-token');

    Http::assertSent(function (Request $request): bool {
        $expected = 'Basic '.base64_encode(TestCase::CLIENT_ID.':'.TestCase::CLIENT_SECRET);

        return Str::endsWith($request->url(), '/api/v1.0/access-token/b2b')
            && $request->header('Authorization') === [$expected]
            && $request->hasHeader('X-PARTNER-ID');
    });
});

it('requests biller tokens from the biller host with basic auth', function (): void {
    Http::fake(tokenEndpointFixtures());

    expect(app(AccessTokenManager::class)->token(Host::Biller))->toBe('biller-access-token');

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://sandbox-biller-b2b.singapay.id/api/v1.0/access-token/b2b');
});

it('caches payment and biller tokens independently', function (): void {
    Http::fake(tokenEndpointFixtures());

    $manager = app(AccessTokenManager::class);

    expect($manager->token(Host::Payment))->toBe('test-access-token')
        ->and($manager->token(Host::Biller))->toBe('biller-access-token')
        ->and($manager->token(Host::Payment))->toBe('test-access-token');

    Http::assertSentCount(2);
});

it('throws AuthenticationException when the gateway rejects the credentials', function (): void {
    Http::fake([
        '*access-token*' => Http::response([
            'status' => 401,
            'success' => false,
            'error' => ['code' => 401, 'message' => 'Invalid credentials'],
        ], 401),
    ]);

    app(AccessTokenManager::class)->token();
})->throws(AuthenticationException::class, 'Invalid credentials');

it('throws AuthenticationException when the response has no token', function (): void {
    Http::fake(['*access-token*' => Http::response(['status' => 200, 'success' => true, 'data' => []])]);

    app(AccessTokenManager::class)->token();
})->throws(AuthenticationException::class);

it('raises IpNotWhitelistedException when the token endpoint rejects the server IP', function (): void {
    // The real sandbox answers a bare HTTP 403 here — no SP017 — so a
    // non-whitelisted server would otherwise only ever see a generic
    // authentication failure and chase the wrong cause.
    Http::fake([
        '*access-token*' => Http::response([
            'status' => 403,
            'success' => false,
            'error' => ['code' => 403, 'message' => 'Your IP address (182.10.100.149) is not registered'],
        ], 403),
    ]);

    app(AccessTokenManager::class)->token();
})->throws(IpNotWhitelistedException::class, 'egress IP');
