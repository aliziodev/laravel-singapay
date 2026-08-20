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
     * Numeric strings with a fractional part (e.g. "100000.50") are rejected;
     * "100000.00" is accepted because it is a whole number.
     *
     * @throws InvalidAmountException When the value is fractional or negative.
     */
    public static function from(int|string|self $value): self
    {
        if ($value instanceof self) {
            return $value;
        }

        if (is_int($value)) {
            return self::rupiah($value);
        }

        if (! is_numeric($value) || (float) $value !== floor((float) $value)) {
            throw InvalidAmountException::notAnInteger($value);
        }

        return self::rupiah((int) (float) $value);
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
