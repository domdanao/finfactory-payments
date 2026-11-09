<?php

declare(strict_types=1);

namespace FinFactory\Payments\Exception;

/** The token endpoint, POST /oauth/token, refused the client ID and secret. */
class OAuthException extends FinFactoryException
{
    /** @param array<string, string> $headers */
    public function __construct(
        private readonly string $error,
        string $description,
        private readonly int $statusCode,
        private readonly array $headers = [],
    ) {
        parent::__construct($description !== '' ? $description : $error, $statusCode);
    }

    /** `invalid_client`, `invalid_request`, `unsupported_grant_type` or `rate_limited`. */
    public function getError(): string
    {
        return $this->error;
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    public function getRequestId(): ?string
    {
        return $this->headers['x-request-id'] ?? null;
    }
}
