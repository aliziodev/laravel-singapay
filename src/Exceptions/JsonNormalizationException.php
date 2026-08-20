<?php

declare(strict_types=1);

namespace Aliziodev\Singapay\Exceptions;

use JsonException;

/**
 * Raised when a request body cannot be canonicalized for signing.
 */
class JsonNormalizationException extends SingaPayException
{
    /**
     * A float value was found in a payload that is about to be signed.
     *
     * SingaPay signatures hash the serialized body; PHP renders whole floats
     * as "100000.0" while other runtimes render "100000", so any float would
     * produce an unverifiable signature. Amounts must be integers (rupiah).
     */
    public static function floatDetected(string $path): self
    {
        return new self(
            "Float value detected at [{$path}]. Signed payloads must not contain floats — "
            .'send amounts as integers (whole rupiah) or use the Amount value object.'
        );
    }

    /**
     * The payload could not be encoded to JSON at all.
     */
    public static function encodingFailed(JsonException $previous): self
    {
        return new self("Unable to encode payload as JSON: {$previous->getMessage()}", previous: $previous);
    }

    /**
     * A value type that has no canonical JSON representation was found.
     */
    public static function unsupportedType(string $type, string $path): self
    {
        return new self(
            "Unsupported value of type [{$type}] at [{$path}]. "
            .'Signed payloads may only contain arrays, objects, strings, integers, booleans, and null.'
        );
    }
}
