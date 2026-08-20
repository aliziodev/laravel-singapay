<?php

declare(strict_types=1);

namespace Aliziodev\Singapay\Exceptions;

use Throwable;

/**
 * Raised when the SingaPay API could not be reached at all
 * (DNS failure, TLS problem, connect/read timeout).
 *
 * For money-out operations, never assume a timed-out request failed —
 * call the relevant inquiry-status endpoint before retrying.
 */
class ConnectionException extends SingaPayException
{
    public static function to(string $url, Throwable $previous): self
    {
        return new self("Unable to reach SingaPay at [{$url}]: {$previous->getMessage()}", previous: $previous);
    }
}
