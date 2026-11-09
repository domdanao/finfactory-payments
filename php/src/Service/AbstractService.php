<?php

declare(strict_types=1);

namespace FinFactory\Payments\Service;

use FinFactory\Payments\FinFactory;

/**
 * Every method takes `$opts` last:
 *  - idempotency_key: for a create, confirm, cancel, simulate or refund. Use a value unique to the
 *    operation, such as your order number and attempt. When left out, the SDK makes a random one
 *    and reuses it on its own retries.
 *  - timeout (seconds), max_retries, headers: override the client's for this call.
 */
abstract class AbstractService
{
    public function __construct(protected readonly FinFactory $client)
    {
    }

    protected static function id(string $id, string $what): string
    {
        if ($id === '') {
            throw new \InvalidArgumentException("FinFactory: a {$what} id is required.");
        }
        return rawurlencode($id);
    }
}
