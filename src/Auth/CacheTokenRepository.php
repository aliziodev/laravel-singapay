<?php

declare(strict_types=1);

namespace Aliziodev\Singapay\Auth;

use Aliziodev\Singapay\Contracts\TokenRepositoryInterface;
use Closure;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Cache\Repository as CacheRepository;

/**
 * Default token storage backed by the Laravel cache.
 *
 * When the configured cache store supports atomic locks (Redis, Memcached,
 * database, file, ...), token refreshes run under a lock so that a burst of
 * concurrent requests results in a single call to the token endpoint. Stores
 * without lock support degrade gracefully to unlocked refreshes.
 *
 * The lock lifetime must exceed the slowest possible token request — the
 * service provider passes the HTTP timeout plus a buffer — otherwise the
 * lock could expire mid-refresh and waiting processes would stampede after
 * all. A process that exhausts the wait window proceeds without the lock
 * instead of failing: the double-check inside the refresh callback keeps
 * duplicate fetches rare, and a duplicate token request is harmless.
 */
final readonly class CacheTokenRepository implements TokenRepositoryInterface
{
    /**
     * @param  int  $lockSeconds  Lock lifetime and maximum wait, in seconds.
     *                            Must comfortably exceed the token endpoint's HTTP timeout.
     */
    public function __construct(
        private CacheRepository $cache,
        private int $lockSeconds = 45,
    ) {}

    public function get(string $key): ?string
    {
        $token = $this->cache->get($key);

        return is_string($token) && $token !== '' ? $token : null;
    }

    public function put(string $key, string $token, int $ttlSeconds): void
    {
        $this->cache->put($key, $token, max($ttlSeconds, 1));
    }

    public function forget(string $key): void
    {
        $this->cache->forget($key);
    }

    public function withLock(string $key, Closure $callback): mixed
    {
        $store = $this->cache->getStore();

        if (! $store instanceof LockProvider) {
            return $callback();
        }

        $seconds = max($this->lockSeconds, 1);

        try {
            return $store->lock("{$key}:lock", $seconds)->block($seconds, $callback);
        } catch (LockTimeoutException) {
            // The lock holder is stuck or slower than the wait window.
            // Proceed unlocked rather than surfacing a non-SDK exception;
            // the caller's double-check minimizes duplicate refreshes.
            return $callback();
        }
    }
}
