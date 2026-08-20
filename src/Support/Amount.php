<?php

declare(strict_types=1);

namespace Aliziodev\Singapay\Support;

use Aliziodev\Singapay\Exceptions\InvalidAmountException;
use JsonSerializable;
use Stringable;

/**
 * Integer-only rupiah amount.
 *
 * SingaPay signatures hash the serialized request body, and whole floats
 * serialize differently across runtimes (100000.0 vs 100000), which breaks
 * signatures silently. This value object makes the invalid state
 * unrepresentable: amounts are always whole rupiah integers.
 *
 * ```php
 * Amount::rupiah(150_000)->value;      // 150000
 * Amount::from('150000')->value;       // 150000
 * Amount::from('150000.50');           // throws InvalidAmountException
 * ```
 */
final readonly class Amount implements JsonSerializable, Stringable
{
    /**
     * @param  int  $value  Whole rupiah, zero or positive.
     */
    private function __construct(public int $value) {}

    /**
     * Create an amount from whole rupiah.
     *
     * Passing a float raises a TypeError by design (strict types).
     *
     * @throws InvalidAmountException When the value is negative.
     */
    public static function rupiah(int $value): self
    {
        if ($value < 0) {
            throw InvalidAmountException::negative($value);
        }

        return new self($value);
    }

    /**
     * Create an amount from an integer, numeric string, or another Amount.
     *
     * Only plain decimal strings are accepted: digits with an optional
     * all-zero fraction ("100000", "100000.00"). Fractional values,
     * exponent notation ("1e3"), signs ("+100"), and values beyond the
     * platform integer range are rejected — anything ambiguous has no
     * place in a signed payment payload.
     *
     * @throws InvalidAmountException When the value is fractional, malformed, negative, or too large.
     */
    public static function from(int|string|self $value): self
    {
        if ($value instanceof self) {
            return $value;
        }

        if (is_int($value)) {
            return self::rupiah($value);
        }

        if (preg_match('/^(?<whole>\d+)(\.(?<fraction>\d+))?$/', $value, $matches) !== 1) {
            throw InvalidAmountException::notAnInteger($value);
        }

        if (isset($matches['fraction']) && ltrim($matches['fraction'], '0') !== '') {
            throw InvalidAmountException::notAnInteger($value);
        }

        $whole = ltrim($matches['whole'], '0');

        if ($whole === '') {
            return self::rupiah(0);
        }

        $max = (string) PHP_INT_MAX;

        // strcmp, not string comparison operators: PHP compares two numeric
        // strings numerically, and PHP_INT_MAX+1 rounds to the same double
        // as PHP_INT_MAX — the exact boundary this guard exists for.
        if (strlen($whole) > strlen($max) || (strlen($whole) === strlen($max) && strcmp($whole, $max) > 0)) {
            throw InvalidAmountException::exceedsIntegerRange($value);
        }

        return self::rupiah((int) $whole);
    }

    /**
     * Serialize as a bare integer, the only representation SingaPay accepts.
     */
    public function jsonSerialize(): int
    {
        return $this->value;
    }

    /**
     * Human-readable Indonesian format, e.g. "Rp150.000".
     */
    public function format(): string
    {
        return 'Rp'.number_format($this->value, 0, ',', '.');
    }

    public function __toString(): string
    {
        return (string) $this->value;
    }
}
