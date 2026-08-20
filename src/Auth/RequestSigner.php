<?php

declare(strict_types=1);

namespace Aliziodev\Singapay\Auth;

use Aliziodev\Singapay\Contracts\JsonNormalizerInterface;
use SensitiveParameter;
use stdClass;

/**
 * Scheme C — request signature for money-moving endpoints, and the shared
 * formula used to verify inbound webhooks.
 *
 * ```
 * hashed_body    = SHA-256(normalized_json)
 * string_to_sign = "{METHOD}:{ENDPOINT}:{ACCESS_TOKEN}:{hashed_body}:{TIMESTAMP}"
 * X-Signature    = HMAC-SHA512(string_to_sign, client_secret)   // lowercase hex
 * ```
 *
 * - METHOD is uppercase.
 * - ENDPOINT is the path including the /api prefix and any query string,
 *   without scheme or host.
 * - ACCESS_TOKEN is the bearer token without the "Bearer " prefix. For
 *   webhooks this is the (random) token SingaPay sent us, not our own.
 * - TIMESTAMP is Unix seconds (the X-Timestamp header value).
 */
final readonly class RequestSigner
{
    public function __construct(private JsonNormalizerInterface $normalizer) {}

    /**
     * Sign an outbound request body.
     *
     * @param  array<array-key, mixed>|stdClass  $body  The exact body that will be sent.
     * @return string 128-character lowercase hex HMAC-SHA512 digest.
     */
    public function sign(
        string $method,
        string $endpoint,
        string $accessToken,
        array|stdClass $body,
        int $timestamp,
        #[SensitiveParameter] string $clientSecret,
    ): string {
        return $this->signHashedBody(
            $method,
            $endpoint,
            $accessToken,
            $this->normalizer->hash($body),
            $timestamp,
            $clientSecret,
        );
    }

    /**
     * Sign with an already-computed body hash. Used by webhook verification,
     * where the hash may come from the raw request bytes.
     */
    public function signHashedBody(
        string $method,
        string $endpoint,
        string $accessToken,
        string $hashedBody,
        int $timestamp,
        #[SensitiveParameter] string $clientSecret,
    ): string {
        return hash_hmac(
            'sha512',
            $this->stringToSign($method, $endpoint, $accessToken, $hashedBody, $timestamp),
            $clientSecret,
        );
    }

    /**
     * Build the canonical string-to-sign, exposed for debugging tools.
     */
    public function stringToSign(
        string $method,
        string $endpoint,
        string $accessToken,
        string $hashedBody,
        int $timestamp,
    ): string {
        return strtoupper($method).':'.$endpoint.':'.$accessToken.':'.$hashedBody.':'.$timestamp;
    }
}
