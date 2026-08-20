<?php

declare(strict_types=1);

namespace Aliziodev\Singapay\Auth;

use Aliziodev\Singapay\Support\JakartaClock;
use SensitiveParameter;

/**
 * Scheme D — credential signature for the identity-verification (KYC)
 * service, which lives on its own host with its own credentials.
 *
 * ```
 * timestamp = RFC 3339 UTC, second precision (e.g. 2026-05-26T07:30:00Z)
 * signature = HMAC-SHA256("{client_id}:{timestamp}", client_secret)   // hex
 * ```
 *
 * Deliberately isolated from the payment-API signers: this scheme uses
 * SHA-256 (not SHA-512), a colon separator (not underscore), a UTC
 * timestamp (not an Asia/Jakarta date), and separate credentials.
 * Never mix the two credential sets.
 */
final readonly class IdentitySigner
{
    public function __construct(private JakartaClock $clock) {}

    /**
     * Compute the signature for a given RFC 3339 timestamp.
     *
     * @return string 64-character lowercase hex HMAC-SHA256 digest.
     */
    public function sign(string $clientId, string $timestamp, #[SensitiveParameter] string $clientSecret): string
    {
        return hash_hmac('sha256', "{$clientId}:{$timestamp}", $clientSecret);
    }

    /**
     * The current timestamp in the exact format the KYC service expects.
     * The service rejects timestamps more than ±5 minutes from its clock.
     */
    public function timestamp(): string
    {
        return $this->clock->rfc3339Utc();
    }
}
