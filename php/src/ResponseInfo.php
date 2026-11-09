<?php

declare(strict_types=1);

namespace FinFactory\Payments;

/** What the SDK knows about the HTTP response an object came from. */
final class ResponseInfo
{
    /** @param array<string, string> $headers Lower-cased header names. */
    public function __construct(
        public readonly int $statusCode,
        public readonly array $headers,
        /** The X-Request-ID header. Quote it when you contact support. */
        public readonly ?string $requestId,
        /** True when a retry with the same Idempotency-Key was answered with the first result. */
        public readonly bool $idempotentReplayed,
        /** The Idempotency-Key that was sent, if any. */
        public readonly ?string $idempotencyKey,
    ) {
    }
}
