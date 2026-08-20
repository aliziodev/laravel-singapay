<?php

declare(strict_types=1);

namespace Aliziodev\Singapay\Exceptions;

/**
 * Raised when a monetary amount cannot be represented safely.
 */
class InvalidAmountException extends SingaPayException
{
    /**
     * Amounts must be whole rupiah — floats corrupt signatures and lose precision.
     */
    public static function notAnInteger(string $value): self
    {
        return new self(
            "Amount [{$value}] is not a whole number. SingaPay amounts are integer rupiah — "
            .'never send floats; convert to the smallest unit first.'
        );
    }

    /**
     * Negative amounts are never valid for SingaPay operations.
     */
    public static function negative(int $value): self
    {
        return new self("Amount [{$value}] is negative. SingaPay amounts must be zero or positive.");
    }

    /**
     * The value cannot be represented as a native integer without loss.
     */
    public static function exceedsIntegerRange(string $value): self
    {
        return new self("Amount [{$value}] exceeds the platform integer range and cannot be represented safely.");
    }
}
