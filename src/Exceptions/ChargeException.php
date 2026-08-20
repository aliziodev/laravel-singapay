<?php

declare(strict_types=1);

namespace Aliziodev\Singapay\Exceptions;

use Aliziodev\Singapay\Enums\PaymentMethod;

/**
 * Raised when the unified charge API receives input it cannot map onto
 * the selected payment method — before anything is sent to the gateway.
 */
class ChargeException extends SingaPayException
{
    /**
     * The given method string matches no supported payment method.
     */
    public static function unknownMethod(string $method): self
    {
        return new self(
            "Unknown payment method [{$method}]. "
            .'Supported: payment_link (pl), virtual_account (va), qris, ewallet.'
        );
    }

    /**
     * A field the selected method requires is missing from the input.
     */
    public static function missingField(PaymentMethod $method, string $field): self
    {
        return new self(
            "A {$method->label()} charge requires the [{$field}] field. "
            .'Add it to the data array passed to charges()->create() / pay().'
        );
    }

    /**
     * A field holds a value the charge builder cannot interpret.
     */
    public static function invalidField(string $field, string $reason): self
    {
        return new self("Invalid charge field [{$field}]: {$reason}");
    }
}
