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

it('rejects ambiguous numeric notations outright', function (string $value): void {
    expect(fn () => Amount::from($value))->toThrow(InvalidAmountException::class);
})->with([
    'exponent notation' => ['1e3'],
    'explicit plus sign' => ['+100'],
    'negative string' => ['-5'],
    'leading whitespace' => [' 100'],
    'hex-ish' => ['0x1A'],
    'bare fraction' => ['.5'],
    'empty string' => [''],
]);

it('rejects values beyond the platform integer range', function (): void {
    Amount::from('99999999999999999999');
})->throws(InvalidAmountException::class, 'integer range');

it('rejects PHP_INT_MAX + 1 exactly, where numeric string comparison would saturate', function (): void {
    // '9223372036854775808' promotes to the same double as PHP_INT_MAX, so
    // a numeric > comparison would pass and (int) would silently saturate.
    Amount::from('9223372036854775808');
})->throws(InvalidAmountException::class, 'integer range');

it('accepts exactly PHP_INT_MAX', function (): void {
    expect(Amount::from((string) PHP_INT_MAX)->value)->toBe(PHP_INT_MAX);
});

it('normalizes leading zeros and zero-only strings', function (): void {
    expect(Amount::from('000123')->value)->toBe(123)
        ->and(Amount::from('0')->value)->toBe(0)
        ->and(Amount::from('0.00')->value)->toBe(0)
        ->and(Amount::from('150000.000')->value)->toBe(150000);
});

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
