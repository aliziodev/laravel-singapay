<?php

declare(strict_types=1);

namespace Aliziodev\Singapay\Contracts;

use Aliziodev\Singapay\Exceptions\JsonNormalizationException;
use stdClass;

/**
 * Produces the canonical JSON representation required by SingaPay signatures.
 *
 * SingaPay's request-signature scheme (HMAC over a SHA-256 body hash) requires
 * both parties to serialize the request body byte-for-byte identically. This
 * contract encapsulates those canonicalization rules so every signer in the
 * SDK shares a single, heavily tested implementation.
 */
interface JsonNormalizerInterface
{
    /**
     * Canonicalize the payload into its normalized JSON string.
     *
     * Rules (must match SingaPay's server-side implementation exactly):
     * - Object keys are sorted recursively using byte-order string comparison.
     * - Lists keep their element order; each element is normalized recursively.
     * - Encoding uses unescaped slashes and unescaped unicode, no whitespace.
     * - Float values are rejected because PHP and the gateway disagree on how
     *   whole floats serialize (100000.0 vs 100000), which corrupts signatures.
     *
     * @param  array<array-key, mixed>|stdClass  $payload  Request body to canonicalize.
     * @return string The normalized JSON string.
     *
     * @throws JsonNormalizationException When the payload contains a float or cannot be encoded.
     */
    public function normalize(array|stdClass $payload): string;

    /**
     * Return the lowercase hex SHA-256 digest of the normalized payload.
     *
     * @param  array<array-key, mixed>|stdClass  $payload  Request body to canonicalize and hash.
     * @return string 64-character lowercase hex digest.
     *
     * @throws JsonNormalizationException When the payload contains a float or cannot be encoded.
     */
    public function hash(array|stdClass $payload): string;
}
