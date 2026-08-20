<?php

declare(strict_types=1);

use Aliziodev\Singapay\Charges\ChargeResult;
use Aliziodev\Singapay\Enums\PaymentMethod;
use Aliziodev\Singapay\Exceptions\ChargeException;
use Aliziodev\Singapay\Facades\SingaPay;
use Aliziodev\Singapay\Support\Amount;
use Aliziodev\Singapay\Tests\TestCase;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * Fake the gateway: token endpoints, any test-specific fixtures, then a
 * catch-all success. Order matters — specific patterns must precede '*'.
 *
 * @param  array<string, mixed>  $fixtures
 */
function fakeGateway(array $fixtures = []): void
{
    Http::fake([
        ...tokenEndpointFixtures(),
        ...$fixtures,
        '*' => Http::response(['response_code' => 'SP000', 'response_message' => 'Successfully', 'data' => []]),
    ]);
}

// 2026-08-20 12:00 WIB = 05:00 UTC = 1787202000 s = 1787202000000 ms.
const CHARGE_EXPIRES = '2026-08-20 12:00:00';
const CHARGE_EXPIRES_MS = '1787202000000';

it('maps a payment link charge onto the documented payload', function (): void {
    fakeGateway();

    SingaPay::pay('payment_link', [
        'amount' => Amount::rupiah(150_000),
        'reference' => 'INV-2026-0001',
        'expires_at' => CarbonImmutable::parse(CHARGE_EXPIRES, 'Asia/Jakarta'),
        'redirect_url' => 'https://gonsu.id/thanks',
    ]);

    Http::assertSent(function (Request $request): bool {
        $body = $request->data();

        return str_ends_with($request->url(), '/api/v2.0/payment-link/'.TestCase::ACCOUNT_ID)
            && $body['reff_no'] === 'INV-2026-0001'
            // v2 computes the total itself when given items, so a charge with
            // only an amount asks for the `total` shape and sends no items.
            && $body['payment_link_type'] === 'total'
            && $body['total_amount'] === 150000
            && ! array_key_exists('items', $body)
            && $body['max_usage'] === 1
            // v2 takes a date string, not the 13-digit millis v1 demanded.
            && $body['expired_at'] === '2026-08-20T12:00:00+07:00'
            && $body['success_redirect_url'] === 'https://gonsu.id/thanks';
    });
});

it('keeps caller-provided items and applies the options escape hatch', function (): void {
    fakeGateway();

    SingaPay::charges()->create(PaymentMethod::PaymentLink, [
        'amount' => 100_000,
        'reference' => 'INV-2',
        'title' => 'Invoice #2',
        'items' => [
            ['name' => 'A', 'quantity' => 2, 'unit_price' => 25_000],
            ['name' => 'B', 'quantity' => 1, 'unit_price' => 50_000],
        ],
        'options' => ['customer_pays_fee' => true, 'max_usage' => 5],
    ]);

    Http::assertSent(function (Request $request): bool {
        $body = $request->data();

        return count($body['items'] ?? []) === 2
            && ($body['customer_pays_fee'] ?? null) === true
            && ($body['max_usage'] ?? null) === 5; // options override the built value
    });
});

it('maps an expiring virtual account charge onto a temporary closed VA', function (): void {
    fakeGateway();

    $result = SingaPay::pay('va', [
        'amount' => 150_000,
        'reference' => 'INV-3',
        'bank_code' => 'BRI',
        'expires_at' => CHARGE_EXPIRES_MS, // 13-digit strings pass through
        'customer' => ['name' => 'Budi Santoso'],
    ]);

    expect($result->method)->toBe(PaymentMethod::VirtualAccount);

    Http::assertSent(function (Request $request): bool {
        $body = $request->data();

        return str_ends_with($request->url(), '/api/v1.0/virtual-accounts/'.TestCase::ACCOUNT_ID)
            && $body['bank_code'] === 'BRI'
            && $body['kind'] === 'temporary'
            && $body['amount_type'] === 'closed'
            && $body['amount'] === 150000
            && $body['expired_at'] === CHARGE_EXPIRES_MS
            && $body['max_usage'] === 1
            && $body['merchant_reff_no'] === 'INV-3'
            && $body['name'] === 'Budi Santoso';
    });
});

it('creates a permanent VA when no expiry is given', function (): void {
    fakeGateway();

    SingaPay::pay('virtual-account', ['amount' => 50_000, 'bank_code' => 'BCA']);

    Http::assertSent(function (Request $request): bool {
        $body = $request->data();

        return ($body['kind'] ?? null) === 'permanent'
            && ! array_key_exists('expired_at', $body)
            && ! array_key_exists('max_usage', $body);
    });
});

it('maps a qris charge with an ISO 8601 expiry', function (): void {
    fakeGateway();

    SingaPay::pay('qris', [
        'amount' => '50000',
        'reference' => 'INV-4',
        'expires_at' => CarbonImmutable::parse(CHARGE_EXPIRES, 'Asia/Jakarta'),
    ]);

    Http::assertSent(function (Request $request): bool {
        $body = $request->data();

        return str_ends_with($request->url(), '/api/v1.0/qris-dynamic/'.TestCase::ACCOUNT_ID.'/generate-qr')
            && $body['amount'] === 50000
            && $body['merchant_reff_no'] === 'INV-4'
            && $body['expired_at'] === '2026-08-20T12:00:00+07:00';
    });
});

it('maps an e-wallet charge, normalizing the vendor prefix and customer fields', function (): void {
    fakeGateway();

    SingaPay::pay('ewallet', [
        'amount' => 75_000,
        'reference' => 'INV-5',
        'vendor' => 'dana',
        'customer' => ['name' => 'Budi', 'email' => 'budi@example.com', 'phone' => '0812345678'],
        'redirect_url' => 'https://gonsu.id/done',
    ], accountId: '01JOTHERACCOUNT');

    Http::assertSent(function (Request $request): bool {
        $body = $request->data();

        return str_ends_with($request->url(), '/api/v2.0/ewallet-native/create-order')
            && $body['ewallet_vendor'] === 'EWALLET_DANA'
            && $body['customer_name'] === 'Budi'
            && $body['customer_email'] === 'budi@example.com'
            && $body['customer_phone'] === '0812345678'
            && $body['merchant_redirect_url'] === 'https://gonsu.id/done'
            && $body['account_id'] === '01JOTHERACCOUNT';
    });
});

it('accepts an already prefixed e-wallet vendor', function (): void {
    fakeGateway();

    SingaPay::pay('wallet', ['amount' => 10_000, 'vendor' => 'EWALLET_OVO']);

    Http::assertSent(fn (Request $request): bool => ($request->data()['ewallet_vendor'] ?? null) === 'EWALLET_OVO');
});

it('exposes typed accessors for the payment artifact per method', function (): void {
    fakeGateway([
        '*payment-link*' => Http::response(['status' => 200, 'success' => true, 'data' => ['payment_url' => 'https://pay.test/pl']]),
        '*qris-dynamic*' => Http::response(['status' => 200, 'success' => true, 'data' => ['qr_data' => '000201QR']]),
        '*virtual-accounts*' => Http::response(['status' => 200, 'success' => true, 'data' => ['number' => '7872955146576837']]),
        '*create-order' => Http::response(['response_code' => 'SP000', 'response_message' => 'OK', 'data' => ['checkout_url' => 'https://pay.test/ew']]),
    ]);

    $paymentLink = SingaPay::pay('pl', ['amount' => 1000, 'reference' => 'R1']);
    $qris = SingaPay::pay('qris', ['amount' => 1000]);
    $va = SingaPay::pay('va', ['amount' => 1000, 'bank_code' => 'BRI']);
    $ewallet = SingaPay::pay('ewallet', ['amount' => 1000, 'vendor' => 'DANA']);

    expect($paymentLink->checkoutUrl())->toBe('https://pay.test/pl')
        ->and($paymentLink->qrString())->toBeNull()
        ->and($paymentLink->vaNumber())->toBeNull()
        ->and($qris->qrString())->toBe('000201QR')
        ->and($qris->checkoutUrl())->toBeNull()
        ->and($va->vaNumber())->toBe('7872955146576837')
        ->and($ewallet->checkoutUrl())->toBe('https://pay.test/ew')
        ->and($ewallet->successful())->toBeTrue()
        ->and($ewallet->data('checkout_url'))->toBe('https://pay.test/ew')
        ->and($ewallet)->toBeInstanceOf(ChargeResult::class);
});

it('rejects charges with missing required fields per method', function (): void {
    Http::fake();

    expect(fn () => SingaPay::pay('pl', ['amount' => 1000]))
        ->toThrow(ChargeException::class, 'reference')
        ->and(fn () => SingaPay::pay('va', ['amount' => 1000]))
        ->toThrow(ChargeException::class, 'bank_code')
        ->and(fn () => SingaPay::pay('ewallet', ['amount' => 1000]))
        ->toThrow(ChargeException::class, 'vendor')
        ->and(fn () => SingaPay::pay('qris', []))
        ->toThrow(ChargeException::class, 'amount');

    Http::assertNothingSent();
});

it('maps an e-wallet expiry to ISO 8601', function (): void {
    fakeGateway();

    SingaPay::pay('ewallet', [
        'amount' => 10_000,
        'vendor' => 'DANA',
        'expires_at' => CarbonImmutable::parse(CHARGE_EXPIRES, 'Asia/Jakarta'),
    ]);

    Http::assertSent(fn (Request $request): bool => ($request->data()['expired_at'] ?? null) === '2026-08-20T12:00:00+07:00');
});

it('accepts a 10-digit unix-second expiry string', function (): void {
    fakeGateway();

    // 1787202000 = 2026-08-20 12:00 WIB.
    SingaPay::pay('qris', ['amount' => 10_000, 'expires_at' => '1787202000']);

    Http::assertSent(fn (Request $request): bool => ($request->data()['expired_at'] ?? null) === '2026-08-20T12:00:00+07:00');
});

it('maps expiry mistakes to ChargeException instead of leaking parser errors', function (): void {
    Http::fake();

    expect(fn () => SingaPay::pay('qris', ['amount' => 1000, 'expires_at' => '178720200012']))
        ->toThrow(ChargeException::class, '10-digit Unix seconds or 13-digit milliseconds')
        ->and(fn () => SingaPay::pay('qris', ['amount' => 1000, 'expires_at' => 'not-a-real-date-!!']))
        ->toThrow(ChargeException::class, 'could not parse');

    Http::assertNothingSent();
});

it('rejects amounts of unsupported types', function (): void {
    Http::fake();

    expect(fn () => SingaPay::pay('qris', ['amount' => ['nested' => 1000]]))
        ->toThrow(ChargeException::class, 'integer')
        ->and(fn () => SingaPay::pay('qris', ['amount' => 1000, 'expires_at' => true]))
        ->toThrow(ChargeException::class, 'expires_at');

    Http::assertNothingSent();
});

it('rejects float amounts before anything is sent', function (): void {
    Http::fake();

    expect(fn () => SingaPay::pay('qris', ['amount' => 150000.5]))
        ->toThrow(ChargeException::class, 'floats are rejected');

    Http::assertNothingSent();
});

it('rejects unknown payment methods and invalid expiries', function (): void {
    Http::fake();

    expect(fn () => SingaPay::pay('crypto', ['amount' => 1000]))
        ->toThrow(ChargeException::class, 'Unknown payment method')
        ->and(fn () => SingaPay::pay('qris', ['amount' => 1000, 'expires_at' => 12345]))
        ->toThrow(ChargeException::class, 'expires_at');
});

it('works through the recording fake', function (): void {
    $fake = SingaPay::fake([
        '*payment-link*' => ['payment_url' => 'https://pay.test/faked'],
    ]);

    $result = SingaPay::pay('pl', ['amount' => 25_000, 'reference' => 'INV-9']);

    expect($result->checkoutUrl())->toBe('https://pay.test/faked');

    $fake->assertPaymentLinkCreated(fn (array $body): bool => $body['reff_no'] === 'INV-9');
});
