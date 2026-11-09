<?php

declare(strict_types=1);

namespace FinFactory\Payments\TokenCache;

/**
 * Wraps any PSR-16 cache (Psr\SimpleCache\CacheInterface): Laravel's Cache::store(), Symfony's
 * Psr16Cache, and the rest. The SDK does not depend on psr/simple-cache; it calls get, set and
 * delete on the object you pass.
 */
final class Psr16TokenCache implements TokenCache
{
    public function __construct(private readonly object $cache)
    {
        foreach (['get', 'set', 'delete'] as $method) {
            if (!method_exists($cache, $method)) {
                throw new \InvalidArgumentException("FinFactory: the cache has no {$method}() method; pass a PSR-16 cache.");
            }
        }
    }

    public function get(string $key): ?array
    {
        $value = $this->cache->get(self::safeKey($key));
        return \is_array($value) ? $value : null;
    }

    public function set(string $key, array $token): void
    {
        $this->cache->set(self::safeKey($key), $token, max(1, $token['expires_at'] - time()));
    }

    public function delete(string $key): void
    {
        $this->cache->delete(self::safeKey($key));
    }

    /** PSR-16 keys may not hold {}()/\@: characters. */
    private static function safeKey(string $key): string
    {
        return 'finfactory_token_' . hash('sha256', $key);
    }
}
