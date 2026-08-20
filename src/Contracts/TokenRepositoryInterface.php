<?php

declare(strict_types=1);

namespace Aliziodev\Singapay\Contracts;

use Closure;

/**
 * Storage for SingaPay access tokens.
 *
 * The default implementation stores tokens in the Laravel cache with a TTL
 * slightly shorter than the token lifetime, and uses an atomic lock so that
 * concurrent workers do not stampede the token endpoint when a token expires.
 */
interface TokenRepositoryInterface
{
    /**
     * Retrieve a cached token, or null when absent or expired.
     */
    public function get(string $key): ?string;

    /**
     * Store a token for the given number of seconds.
     */
    public function put(string $key, string $token, int $ttlSeconds): void;

    /**
     * Discard a cached token (e.g. after the gateway reported SP013).
     */
    public function forget(string $key): void;

    /**
     * Run the callback under an atomic lock scoped to the key, when the
     * underlying store supports locking; otherwise run it directly.
     *
     * @template TValue
     *
     * @param  Closure(): TValue  $callback
     * @return TValue
     */
    public function withLock(string $key, Closure $callback): mixed;
}
