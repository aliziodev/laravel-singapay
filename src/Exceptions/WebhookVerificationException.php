<?php

declare(strict_types=1);

namespace Aliziodev\Singapay\Exceptions;

/**
 * Raised when an inbound webhook fails signature or timestamp verification.
 *
 * The webhook middleware converts this into a 401 response; the exception
 * message is safe to log but is never echoed back to the caller.
 */
class WebhookVerificationException extends SingaPayException
{
    public static function missingHeaders(): self
    {
        return new self('Webhook rejected: X-Signature, X-Timestamp, or Authorization header is missing.');
    }

    public static function invalidSignature(): self
    {
        return new self('Webhook rejected: X-Signature does not match the computed signature.');
    }

    public static function staleTimestamp(int $ageSeconds, int $tolerance): self
    {
        return new self(
            "Webhook rejected: X-Timestamp is {$ageSeconds}s away from server time "
            ."(tolerance {$tolerance}s). Possible replay, or severe server clock drift."
        );
    }

    public static function invalidBody(): self
    {
        return new self('Webhook rejected: request body is not valid JSON.');
    }
}
