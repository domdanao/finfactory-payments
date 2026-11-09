<?php

declare(strict_types=1);

namespace FinFactory\Payments\Http;

final class HttpResponse
{
    /** @var array<string, string> Lower-cased header names. */
    public readonly array $headers;

    /** @param array<string, string> $headers */
    public function __construct(
        public readonly int $statusCode,
        array $headers,
        public readonly string $body,
    ) {
        $this->headers = array_change_key_case($headers, CASE_LOWER);
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }
}
