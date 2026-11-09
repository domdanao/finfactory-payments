<?php

declare(strict_types=1);

namespace FinFactory\Payments\TokenCache;

final class InMemoryTokenCache implements TokenCache
{
    /** @var array<string, array{access_token: string, expires_at: int}> */
    private array $tokens = [];

    public function get(string $key): ?array
    {
        return $this->tokens[$key] ?? null;
    }

    public function set(string $key, array $token): void
    {
        $this->tokens[$key] = $token;
    }

    public function delete(string $key): void
    {
        unset($this->tokens[$key]);
    }
}
