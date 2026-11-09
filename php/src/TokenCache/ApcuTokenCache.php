<?php

declare(strict_types=1);

namespace FinFactory\Payments\TokenCache;

/** Shares tokens between the requests one PHP-FPM pool serves, with ext-apcu. */
final class ApcuTokenCache implements TokenCache
{
    public function __construct()
    {
        if (!\function_exists('apcu_fetch') || !apcu_enabled()) {
            throw new \LogicException('FinFactory: ApcuTokenCache needs ext-apcu, enabled.');
        }
    }

    public function get(string $key): ?array
    {
        $value = apcu_fetch($key, $success);
        return $success && \is_array($value) ? $value : null;
    }

    public function set(string $key, array $token): void
    {
        apcu_store($key, $token, max(1, $token['expires_at'] - time()));
    }

    public function delete(string $key): void
    {
        apcu_delete($key);
    }
}
