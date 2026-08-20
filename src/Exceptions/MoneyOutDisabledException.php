<?php

declare(strict_types=1);

namespace Aliziodev\Singapay\Exceptions;

/**
 * Raised when a money-out operation is attempted while the explicit
 * money-out guard is disabled.
 *
 * The guard (config `singapay.money_out.enabled`, default false) exists to
 * prevent real transfers from an environment that was never meant to move
 * money — a staging box with production credentials, for example.
 */
class MoneyOutDisabledException extends SingaPayException
{
    public static function create(string $operation): self
    {
        return new self(
            "Money-out operation [{$operation}] is blocked because money-out is disabled. "
            .'Set SINGAPAY_MONEY_OUT=true (config singapay.money_out.enabled) only in environments '
            .'that are allowed to move real funds.'
        );
    }
}
