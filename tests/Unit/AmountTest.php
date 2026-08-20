<?php

declare(strict_types=1);

use Aliziodev\Singapay\Exceptions\InvalidAmountException;
use Aliziodev\Singapay\Support\Amount;

covers(Amount::class, InvalidAmountException::class);

it('creates whole-rupiah amounts', function (): void {
    expect(Amount::rupiah(150_000)->value)->toBe(150000)
        ->and(Amount::rupiah(0)->value)->toBe(0);
});

it('rejects negative amounts', function (): void {
    Amount::rupiah(-1);
})->throws(InvalidAmountException::class, 'negative');

it('accepts numeric strings that are whole numbers', function (): void {
    expect(Amount::from('150000')->value)->toBe(150000)
        ->and(Amount::from('150000.00')->value)->toBe(150000);
});

it('rejects fractional numeric strings', function (): void {
    Amount::from('150000.50');
})->throws(InvalidAmountException::class, 'not a whole number');

it('rejects non-numeric strings', function (): void {
    Amount::from('abc');
})->throws(InvalidAmountException::class);

it('passes through existing instances', function (): void {
    $amount = Amount::rupiah(5000);

    expect(Amount::from($amount))->toBe($amount);
});

it('serializes to a bare integer for signed payloads', function (): void {
    expect(json_encode(['amount' => Amount::rupiah(100000)]))->toBe('{"amount":100000}');
});

it('formats as Indonesian rupiah', function (): void {
    expect(Amount::rupiah(1_500_000)->format())->toBe('Rp1.500.000');
});

it('casts to a numeric string', function (): void {
    expect((string) Amount::rupiah(2500))->toBe('2500');
});
