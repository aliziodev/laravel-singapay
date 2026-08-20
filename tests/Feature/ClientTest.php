<?php

declare(strict_types=1);

use Aliziodev\Singapay\Exceptions\ConnectionException;
use Aliziodev\Singapay\Exceptions\DuplicateReferenceException;
use Aliziodev\Singapay\Exceptions\InsufficientBalanceException;
use Aliziodev\Singapay\Exceptions\InvalidSignatureException;
use Aliziodev\Singapay\Exceptions\IpNotWhitelistedException;
use Aliziodev\Singapay\Exceptions\MoneyOutDisabledException;
use Aliziodev\Singapay\Exceptions\RequestException;
use Aliziodev\Singapay\Exceptions\ValidationException;
use Aliziodev\Singapay\Facades\SingaPay;
use Aliziodev\Singapay\Tests\TestCase;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException as HttpConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

afterEach(fn () => CarbonImmutable::setTestNow());

function v2Success(array $data = []): array
{
    return ['response_code' => 'SP000', 'response_message' => 'Successfully', 'data' => $data];
}

it('sends bearer, partner, and accept headers', function (): void {
    Http::fake([...tokenEndpointFixtures(), '*balance-inquiry*' => Http::response(['status' => 200, 'success' => true, 'data' => []])]);

    SingaPay::balance()->merchant();

    Http::assertSent(function (Request $request): bool {
        return Str::endsWith($request->url(), '/api/v1.0/balance-inquiry')
            && $request->header('Authorization') === ['Bearer test-access-token']
            && $request->header('X-PARTNER-ID') === [TestCase::PARTNER_ID]
            && $request->hasHeader('Accept', 'application/json');
    });
});

it('retries idempotent GET requests on server errors', function (): void {
    Http::fake([
        ...tokenEndpointFixtures(),
        '*balance-inquiry*' => Http::sequence()
            ->pushStatus(500)
            ->push(['status' => 200, 'success' => true, 'data' => ['balance' => ['value' => '10.00']]]),
    ]);

    $response = SingaPay::balance()->merchant();

    expect($response->data('balance.value'))->toBe('10.00');

    Http::assertSentCount(3); // token + failed GET + retried GET
});

it('never retries write requests', function (): void {
    Http::fake([
        ...tokenEndpointFixtures(),
        '*payment-link-manage*' => Http::response('server error', 500),
    ]);

    expect(fn () => SingaPay::paymentLinks()->create(['reff_no' => 'INV-1'], 'ACC'))
        ->toThrow(RequestException::class);

    Http::assertSentCount(2); // token + a single POST, no retry
});

it('refreshes the token and retries exactly once on HTTP 401', function (): void {
    Http::fake([
        'https://sandbox-payment-b2b.singapay.id/api/v1.1/access-token/b2b' => Http::sequence()
            ->push(['status' => 200, 'success' => true, 'data' => ['access_token' => 'stale-token', 'expires_in' => '216000']])
            ->push(['status' => 200, 'success' => true, 'data' => ['access_token' => 'fresh-token', 'expires_in' => '216000']]),
        '*balance-inquiry*' => Http::sequence()
            ->push(['status' => 401, 'success' => false, 'error' => ['code' => 401, 'message' => 'Unauthorized']], 401)
            ->push(['status' => 200, 'success' => true, 'data' => []]),
    ]);

    expect(SingaPay::balance()->merchant()->successful())->toBeTrue();

    Http::assertSentCount(4); // token, 401 response, fresh token, retried request

    Http::assertSent(fn (Request $request): bool => $request->header('Authorization') === ['Bearer fresh-token']);
});

it('gives up after a single token refresh', function (): void {
    Http::fake([
        '*access-token*' => Http::response(['status' => 200, 'success' => true, 'data' => ['access_token' => 't', 'expires_in' => '300']]),
        '*balance-inquiry*' => Http::response(['status' => 401, 'success' => false, 'error' => ['code' => 401, 'message' => 'Unauthorized']], 401),
    ]);

    expect(fn () => SingaPay::balance()->merchant())->toThrow(RequestException::class);

    Http::assertSentCount(4); // no infinite refresh loop
});

it('blocks signed requests while money-out is disabled, before any traffic', function (): void {
    Http::fake();

    expect(fn () => SingaPay::disbursement()->transfer([
        'reference_number' => 'REF-1',
        'bank_code' => '014',
        'bank_account_number' => '1234567890',
        'amount' => 50000,
    ]))->toThrow(MoneyOutDisabledException::class, 'money_out.enabled');

    Http::assertNothingSent();
});

it('signs money-out requests and sends the exact hashed bytes', function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-20 10:00:00', 'Asia/Jakarta'));

    config()->set('singapay.money_out.enabled', true);
    reloadSingaPay();

    Http::fake([...tokenEndpointFixtures(), '*disbursement/transfer' => Http::response(v2Success())]);

    SingaPay::disbursement()->transfer([
        'reference_number' => 'REF-1',
        'bank_code' => '014',
        'bank_account_number' => '1234567890',
        'amount' => 50000,
        'notes' => 'gaji/agustus',
    ]);

    // Independent expectation: sort keys, encode unescaped, hash, sign.
    $expectedBody = [
        'account_id' => TestCase::ACCOUNT_ID,
        'amount' => 50000,
        'bank_account_number' => '1234567890',
        'bank_code' => '014',
        'notes' => 'gaji/agustus',
        'reference_number' => 'REF-1',
    ];
    $normalized = json_encode($expectedBody, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $timestamp = CarbonImmutable::now()->getTimestamp();
    $stringToSign = 'POST:/api/v2.0/disbursement/transfer:test-access-token:'.hash('sha256', (string) $normalized).':'.$timestamp;
    $expectedSignature = hash_hmac('sha512', $stringToSign, TestCase::CLIENT_SECRET);

    Http::assertSent(function (Request $request) use ($normalized, $timestamp, $expectedSignature): bool {
        return Str::endsWith($request->url(), '/api/v2.0/disbursement/transfer')
            && $request->body() === $normalized
            && $request->header('X-Timestamp') === [(string) $timestamp]
            && $request->header('X-Signature') === [$expectedSignature];
    });
});

it('maps SP error codes to dedicated exceptions', function (string $code, string $message, string $exception): void {
    Http::fake([
        ...tokenEndpointFixtures(),
        '*qris-dynamic*' => Http::response(['response_code' => $code, 'response_message' => $message, 'data' => null], 400),
    ]);

    expect(fn () => SingaPay::qris()->generate(['amount' => 10000]))
        ->toThrow($exception, $message);
})->with([
    'SP003 insufficient balance' => ['SP003', 'Insufficient Balance', InsufficientBalanceException::class],
    'SP004 duplicate reference' => ['SP004', 'Duplicate Reference Number', DuplicateReferenceException::class],
    'SP016 invalid signature' => ['SP016', 'Signature Invalid', InvalidSignatureException::class],
    'SP017 unauthorized ip' => ['SP017', 'Unauthorized IP', IpNotWhitelistedException::class],
    'SP018 validation error' => ['SP018', 'Validation Error', ValidationException::class],
]);

it('surfaces v1 envelope failures with the gateway message', function (): void {
    Http::fake([
        ...tokenEndpointFixtures(),
        '*accounts*' => Http::response(['status' => 404, 'success' => false, 'error' => ['code' => 404, 'message' => 'Account not found.']], 404),
    ]);

    expect(fn () => SingaPay::accounts()->find('01J000000000000000000000AA'))
        ->toThrow(RequestException::class, 'Account not found.');
});

it('wraps transport failures in the SDK connection exception', function (): void {
    Http::fake([
        ...tokenEndpointFixtures(),
        '*payment-link-manage*' => fn () => throw new HttpConnectionException('DNS failure'),
    ]);

    expect(fn () => SingaPay::paymentLinks()->create(['reff_no' => 'X'], 'ACC'))
        ->toThrow(ConnectionException::class, 'Unable to reach SingaPay');
});
