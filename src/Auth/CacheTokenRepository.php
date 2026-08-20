<?php

declare(strict_types=1);

namespace Aliziodev\Singapay\Auth;

use Aliziodev\Singapay\Contracts\TokenRepositoryInterface;
use Closure;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\Repository as CacheRepository;

/**
 * Default token storage backed by the Laravel cache.
 *
 * When the configured cache store supports atomic locks (Redis, Memcached,
 * database, file, ...), token refreshes run under a lock so that a burst of
 * concurrent requests results in a single call to the token endpoint. Stores
 * without lock support degrade gracefully to unlocked refreshes.
 */
final readonly class CacheTokenRepository implements TokenRepositoryInterface
{
    /**
     * How long a refresh may hold the lock, and how long competing
     * processes wait for it, in seconds.
     */
    private const LOCK_SECONDS = 10;

    public function __construct(private CacheRepository $cache) {}

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

        return $store->lock("{$key}:lock", self::LOCK_SECONDS)
            ->block(self::LOCK_SECONDS, $callback);
    }
}
