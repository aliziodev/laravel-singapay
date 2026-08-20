<?php

declare(strict_types=1);

namespace Aliziodev\Singapay\Webhooks;

use Aliziodev\Singapay\Auth\RequestSigner;
use Aliziodev\Singapay\Exceptions\WebhookVerificationException;
use Aliziodev\Singapay\Support\JakartaClock;
use SensitiveParameter;

/**
 * Verifies inbound SingaPay webhook signatures.
 *
 * SingaPay signs webhooks with the same colon-separated scheme as money-out
 * requests:
 *
 * ```
 * string_to_sign = "POST:{ENDPOINT}:{ACCESS_TOKEN}:{hashed_body}:{TIMESTAMP}"
 * X-Signature    = HMAC-SHA512(string_to_sign, client_secret)
 * ```
 *
 * - ENDPOINT is the merchant's own callback path including any query string.
 * - ACCESS_TOKEN comes from the inbound `Authorization` header (a random
 *   token SingaPay generated — not the merchant's token), stripped of the
 *   "Bearer " prefix.
 * - The official verification samples hash a *re-normalized* body: parse the
 *   raw JSON, recursively ksort() keys, re-encode with unescaped unicode and
 *   slashes, then SHA-256. This verifier follows that spec, and additionally
 *   accepts a hash of the raw bytes as a defensive fallback, so a gateway
 *   that signs the raw body verbatim still verifies. Both comparisons are
 *   constant-time.
 * - The X-Timestamp header must be within a configurable tolerance of the
 *   server clock, which blocks replayed deliveries.
 */
final readonly class WebhookVerifier
{
    public function __construct(
        private RequestSigner $signer,
        private JakartaClock $clock,
    ) {}

    /**
     * Verify a webhook delivery, throwing when anything does not check out.
     *
     * @param  string  $rawBody  The raw request bytes — never a re-encoded parse.
     * @param  string|null  $signature  The X-Signature header value.
     * @param  string|null  $timestamp  The X-Timestamp header value (Unix seconds).
     * @param  string|null  $authorization  The Authorization header value ("Bearer ...").
     * @param  string  $endpoint  The callback path including query string, e.g. "/webhooks/singapay".
     * @param  string|list<string>  $clientSecret  Candidate HMAC key(s): the merchant client secret
     *                                             and/or the dashboard's HMAC Validation Key. The signature must match one of them.
     * @param  int  $toleranceSeconds  Maximum allowed clock skew / replay window.
     *
     * @throws WebhookVerificationException When headers are missing, the timestamp is stale, or the signature does not match.
     */
    public function verify(
        string $rawBody,
        ?string $signature,
        ?string $timestamp,
        ?string $authorization,
        string $endpoint,
        #[SensitiveParameter] string|array $clientSecret,
        int $toleranceSeconds = 300,
    ): void {
        if ($signature === null || $signature === ''
            || $timestamp === null || $timestamp === ''
            || $authorization === null || $authorization === '') {
            throw WebhookVerificationException::missingHeaders();
        }

        if (! ctype_digit($timestamp)) {
            throw WebhookVerificationException::missingHeaders();
        }

        $age = abs($this->clock->unixSeconds() - (int) $timestamp);

        if ($age > $toleranceSeconds) {
            throw WebhookVerificationException::staleTimestamp($age, $toleranceSeconds);
        }

        $token = preg_replace('/^Bearer\s+/i', '', $authorization) ?? $authorization;
        $secrets = is_string($clientSecret) ? [$clientSecret] : $clientSecret;

        foreach ($this->candidateHashes($rawBody) as $hashedBody) {
            foreach ($secrets as $secret) {
                $expected = $this->signer->signHashedBody(
                    'POST',
                    $endpoint,
                    $token,
                    $hashedBody,
                    (int) $timestamp,
                    $secret,
                );

                if (hash_equals($expected, strtolower($signature))) {
                    return;
                }
            }
        }

        throw WebhookVerificationException::invalidSignature();
    }

    /**
     * Body-hash candidates, in spec order: the re-normalized hash from the
     * official verification samples first, then the raw-byte hash.
     *
     * @return list<string>
     *
     * @throws WebhookVerificationException When the body is not valid JSON.
     */
    private function candidateHashes(string $rawBody): array
    {
        $decoded = json_decode($rawBody, true);

        if (! is_array($decoded)) {
            throw WebhookVerificationException::invalidBody();
        }

        $this->sortRecursively($decoded);

        $normalized = json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $candidates = [];

        if (is_string($normalized)) {
            $candidates[] = hash('sha256', $normalized);
        }

        $candidates[] = hash('sha256', $rawBody);

        return $candidates;
    }

    /**
     * Recursive byte-order key sort, mirroring the official PHP sample
     * (`ksort($array, SORT_STRING)` at every level).
     *
     * @param  array<array-key, mixed>  $array
     */
    private function sortRecursively(array &$array): void
    {
        ksort($array, SORT_STRING);

        foreach ($array as &$value) {
            if (is_array($value)) {
                $this->sortRecursively($value);
            }
        }
    }
}
