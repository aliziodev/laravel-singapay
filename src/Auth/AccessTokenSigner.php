<?php

declare(strict_types=1);

namespace Aliziodev\Singapay\Auth;

use Aliziodev\Singapay\Support\JakartaClock;
use SensitiveParameter;

/**
 * Scheme A — signature for the access-token v1.1 request.
 *
 * ```
 * payload     = "{client_id}_{client_secret}_{YYYYMMDD}"
 * X-Signature = HMAC-SHA512(payload, client_secret)   // lowercase hex
 * ```
 *
 * The date component uses the Asia/Jakarta calendar day — never UTC. The
 * official Node.js documentation example uses a UTC date, which produces a
 * rejected signature between 00:00 and 07:00 WIB; this SDK deliberately
 * pins the timezone via {@see JakartaClock}.
 */
final readonly class AccessTokenSigner
{
    public function __construct(private JakartaClock $clock) {}

    /**
     * Compute the X-Signature header value for today's token request.
     *
     * @return string 128-character lowercase hex HMAC-SHA512 digest.
     */
    public function sign(string $clientId, #[SensitiveParameter] string $clientSecret): string
    {
        return hash_hmac('sha512', $this->payload($clientId, $clientSecret), $clientSecret);
    }

    /**
     * The exact string that gets signed, exposed for debugging tools.
     * Handle with care — it contains the client secret.
     */
    public function payload(string $clientId, #[SensitiveParameter] string $clientSecret): string
    {
        return "{$clientId}_{$clientSecret}_{$this->clock->signatureDate()}";
    }
}
