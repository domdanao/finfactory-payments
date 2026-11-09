<?php

declare(strict_types=1);

namespace FinFactory\Payments\Exception;

/**
 * The API refused the request with its error envelope. Branch on getType() or getErrorCode(),
 * never on the message, which may be reworded.
 */
class ApiErrorException extends FinFactoryException
{
    /**
     * @param array<string, string> $headers Lower-cased header names.
     */
    public function __construct(
        string $message,
        private readonly int $statusCode,
        private readonly string $type,
        private readonly string $errorCode,
        private readonly ?string $param,
        private readonly ?string $requestId,
        private readonly array $headers,
        private readonly mixed $body,
    ) {
        parent::__construct($message, $statusCode);
    }

    /** The HTTP status. */
    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    /** One of ten categories, such as `validation_failure`, each always sent on the same status. */
    public function getType(): string
    {
        return $this->type;
    }

    /** A stable name for this refusal, such as `amount_exceeds_refundable`. */
    public function getErrorCode(): string
    {
        return $this->errorCode;
    }

    /** The field at fault, when there is one. */
    public function getParam(): ?string
    {
        return $this->param;
    }

    /** Quote it when you contact support. */
    public function getRequestId(): ?string
    {
        return $this->requestId;
    }

    /** @return array<string, string> */
    public function getHeaders(): array
    {
        return $this->headers;
    }

    /** The decoded response body. */
    public function getBody(): mixed
    {
        return $this->body;
    }
}
