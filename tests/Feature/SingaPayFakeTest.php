<?php

declare(strict_types=1);

use Aliziodev\Singapay\Contracts\SingaPayClientInterface;
use Aliziodev\Singapay\Enums\ResponseCode;
use Aliziodev\Singapay\Facades\SingaPay;
use Aliziodev\Singapay\Http\ApiRequest;
use Aliziodev\Singapay\Http\Response;
use Aliziodev\Singapay\Testing\FakeSingaPayClient;
use Aliziodev\Singapay\Testing\SingaPayFake;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\AssertionFailedError;

it('swaps the manager and the client contract', function (): void {
    $fake = SingaPay::fake();

    expect($fake)->toBeInstanceOf(SingaPayFake::class)
        ->and(app(SingaPayClientInterface::class))->toBeInstanceOf(FakeSingaPayClient::class)
        ->and(app(Aliziodev\Singapay\SingaPay::class))->toBe($fake);
});

it('records requests without touching HTTP', function (): void {
    Http::fake();
    $fake = SingaPay::fake();

    SingaPay::paymentLinks()->create(['reff_no' => 'INV-001', 'total_amount' => 10000]);

    Http::assertNothingSent();

    expect($fake->recorded())->toHaveCount(1)
        ->and($fake->recorded()[0]->method)->toBe('POST');
});

it('returns fixture data for matching paths', function (): void {
    SingaPay::fake([
        '*payment-link-manage*' => ['payment_url' => 'https://pay.test/abc'],
    ]);

    $response = SingaPay::paymentLinks()->create(['reff_no' => 'INV-1']);

    expect($response->data('payment_url'))->toBe('https://pay.test/abc')
        ->and($response->successful())->toBeTrue();
});

it('supports Response and Closure fixtures', function (): void {
    SingaPay::fake([
        '*balance-inquiry*' => new Response(200, ResponseCode::Success, 'OK', ['balance' => ['value' => '5.00']], []),
        '*qris-dynamic*' => fn (ApiRequest $request): array => ['echo' => $request->body['amount']],
    ]);

    expect(SingaPay::balance()->merchant()->data('balance.value'))->toBe('5.00')
        ->and(SingaPay::qris()->generate(['amount' => 12345])->data('echo'))->toBe(12345);
});

it('defaults unmatched requests to an empty success', function (): void {
    SingaPay::fake();

    $response = SingaPay::accounts()->list();

    expect($response->successful())->toBeTrue()
        ->and($response->data())->toBe([]);
});

it('supports adding stubs after faking', function (): void {
    $fake = SingaPay::fake();
    $fake->stub('*va-transactions*', ['rows' => 3]);

    expect(SingaPay::vaTransactions()->list()->data('rows'))->toBe(3);
});

it('asserts sent requests by pattern and callback', function (): void {
    $fake = SingaPay::fake();

    SingaPay::virtualAccounts()->create(['bank_code' => 'BRI', 'kind' => 'permanent']);

    $fake->assertSent('*virtual-accounts*');
    $fake->assertSent(fn (ApiRequest $r): bool => $r->body['bank_code'] === 'BRI');
    $fake->assertNotSent('*disbursement*');
    $fake->assertSentCount(1);
});

it('provides sugar assertions for common flows', function (): void {
    $fake = SingaPay::fake();

    SingaPay::paymentLinks()->create(['reff_no' => 'INV-001']);

    config()->set('singapay.money_out.enabled', true);
    SingaPay::disbursement()->transfer(['reference_number' => 'REF-1', 'amount' => 1000]);

    $fake->assertPaymentLinkCreated(fn (array $body): bool => $body['reff_no'] === 'INV-001');
    $fake->assertDisbursementRequested(fn (array $body): bool => $body['reference_number'] === 'REF-1');
});

it('fails assertions when nothing matched', function (): void {
    $fake = SingaPay::fake();

    expect(fn () => $fake->assertSent('*payment-link*'))->toThrow(AssertionFailedError::class);

    $fake->assertNothingSent();

    SingaPay::accounts()->list();

    expect(fn () => $fake->assertNothingSent())->toThrow(AssertionFailedError::class);
});
