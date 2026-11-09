<?php

declare(strict_types=1);

use FinFactory\Payments\TokenCache\TokenCache;

/**
 * Keeps the Access Token in a file, so every request (and every poll of the QR Ph page) doesn't
 * fetch a new one. Good enough for one server; use APCu, Redis or your framework's cache in
 * production.
 */
final class FileTokenCache implements TokenCache
{
    public function __construct(private readonly string $dir = '')
    {
    }

    public function get(string $key): ?array
    {
        $raw = @file_get_contents($this->path($key));
        $token = $raw === false ? null : json_decode($raw, true);
        return \is_array($token) && isset($token['access_token'], $token['expires_at']) && $token['expires_at'] > time()
            ? $token
            : null;
    }

    public function set(string $key, array $token): void
    {
        $path = $this->path($key);
        $old = umask(0077); // readable by this user only: it's a bearer token
        try {
            file_put_contents($path, json_encode($token), LOCK_EX);
        } finally {
            umask($old);
        }
    }

    public function delete(string $key): void
    {
        @unlink($this->path($key));
    }

    private function path(string $key): string
    {
        return ($this->dir ?: sys_get_temp_dir()) . '/finfactory-token-' . hash('sha256', $key) . '.json';
    }
}
