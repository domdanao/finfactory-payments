<?php

declare(strict_types=1);

namespace FinFactory\Payments\TokenCache;

/**
 * Where Access Tokens are kept between calls. The default, InMemoryTokenCache, lasts as long as
 * the PHP process; under PHP-FPM that is one request, so share tokens across requests with
 * ApcuTokenCache or Psr16TokenCache (Redis, Memcached, Laravel and Symfony caches).
 *
 * A token is ['access_token' => string, 'expires_at' => int unix seconds].
 */
interface TokenCache
{
    /** @return array{access_token: string, expires_at: int}|null */
    public function get(string $key): ?array;

    /** @param array{access_token: string, expires_at: int} $token */
    public function set(string $key, array $token): void;

    public function delete(string $key): void;
}
