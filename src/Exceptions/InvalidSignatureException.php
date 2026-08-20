<?php

declare(strict_types=1);

namespace Aliziodev\Singapay\Exceptions;

use Aliziodev\Singapay\Http\Response;

/**
 * Raised for SP016: the gateway rejected the request signature.
 *
 * This always indicates a bug or misconfiguration on the caller's side
 * (wrong secret, clock drift past midnight WIB, or a body that was mutated
 * after signing) — never retry, investigate instead.
 */
class InvalidSignatureException extends RequestException
{
    protected static function buildMessage(Response $response): string
    {
        return parent::buildMessage($response)
            .' This indicates an SDK-side signing problem: verify the client secret, that the server clock '
            .'is correct, and run `php artisan singapay:verify-signature` to debug the payload.';
    }
}
