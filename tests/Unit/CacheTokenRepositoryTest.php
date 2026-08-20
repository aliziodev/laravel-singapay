<?php

declare(strict_types=1);

use Aliziodev\Singapay\Auth\CacheTokenRepository;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Cache\Store;

covers(CacheTokenRepository::class);

function lockCapableRepository(): CacheTokenRepository
{
    return new CacheTokenRepository(new CacheRepository(new ArrayStore));
}

it('stores, reads, and forgets tokens', function (): void {
    $repository = lockCapableRepository();

    expect($repository->get('k'))->toBeNull();

    $repository->put('k', 'token-value', 120);

    expect($repository->get('k'))->toBe('token-value');

    $repository->forget('k');

    expect($repository->get('k'))->toBeNull();
});

it('treats non-string cache hits as missing', function (): void {
    $cache = new CacheRepository(new ArrayStore);
    $cache->put('k', ['not' => 'a-string'], 120);

    expect((new CacheTokenRepository($cache))->get('k'))->toBeNull();
});

it('never stores with a non-positive ttl', function (): void {
    $repository = lockCapableRepository();

    $repository->put('k', 'token-value', -5);

    // Clamped to 1 second instead of "forever" or a store error.
    expect($repository->get('k'))->toBe('token-value');
});

it('runs the callback under a lock when the store supports it', function (): void {
    $result = lockCapableRepository()->withLock('k', fn (): string => 'locked-result');

    expect($result)->toBe('locked-result');
});

it('degrades to an unlocked callback on stores without lock support', function (): void {
    $store = new class implements Store
    {
        /** @var array<string, mixed> */
        private array $items = [];

        public function get($key): mixed
        {
            return $this->items[$key] ?? null;
        }

        public function many(array $keys): array
        {
            return array_map(fn ($key) => $this->get($key), array_combine($keys, $keys));
        }

        public function put($key, $value, $seconds): bool
        {
            $this->items[$key] = $value;

            return true;
        }

        public function putMany(array $values, $seconds): bool
        {
            foreach ($values as $key => $value) {
                $this->put($key, $value, $seconds);
            }

            return true;
        }

        public function increment($key, $value = 1): int|bool
        {
            return false;
        }

        public function decrement($key, $value = 1): int|bool
        {
            return false;
        }

        public function forever($key, $value): bool
        {
            return $this->put($key, $value, 0);
        }

        public function touch($key, $seconds): bool
        {
            return isset($this->items[$key]);
        }

        public function forget($key): bool
        {
            unset($this->items[$key]);

            return true;
        }

        public function flush(): bool
        {
            $this->items = [];

            return true;
        }

        public function getPrefix(): string
        {
            return '';
        }
    };

    $repository = new CacheTokenRepository(new CacheRepository($store));

    expect($repository->withLock('k', fn (): string => 'plain-result'))->toBe('plain-result');
});
