<?php

declare(strict_types=1);

use Aliziodev\Singapay\Enums\PaymentMethod;
use Aliziodev\Singapay\Exceptions\ChargeException;

covers(PaymentMethod::class, ChargeException::class);

it('parses aliases case-insensitively', function (string $alias, PaymentMethod $expected): void {
    expect(PaymentMethod::parse($alias))->toBe($expected);
})->with([
    ['payment_link', PaymentMethod::PaymentLink],
    ['payment-link', PaymentMethod::PaymentLink],
    ['PaymentLink', PaymentMethod::PaymentLink],
    ['PL', PaymentMethod::PaymentLink],
    ['link', PaymentMethod::PaymentLink],
    ['virtual_account', PaymentMethod::VirtualAccount],
    ['Virtual Account', PaymentMethod::VirtualAccount],
    ['va', PaymentMethod::VirtualAccount],
    ['qris', PaymentMethod::Qris],
    ['QR', PaymentMethod::Qris],
    ['ewallet', PaymentMethod::Ewallet],
    ['e-wallet', PaymentMethod::Ewallet],
    ['wallet', PaymentMethod::Ewallet],
]);

it('passes enum instances through untouched', function (): void {
    expect(PaymentMethod::parse(PaymentMethod::Qris))->toBe(PaymentMethod::Qris);
});

it('rejects unknown methods with the supported list', function (): void {
    PaymentMethod::parse('crypto');
})->throws(ChargeException::class, 'Supported: payment_link');

it('labels every method', function (): void {
    foreach (PaymentMethod::cases() as $method) {
        expect($method->label())->not->toBe('');
    }
});
