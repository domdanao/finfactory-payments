<?php

declare(strict_types=1);

namespace FinFactory\Payments\Exception;

/** 429 `rate_limited`: too many requests. */
class RateLimitException extends ApiErrorException
{
    /** Seconds to wait, from the Retry-After header, when the API sent one. */
    public function getRetryAfter(): ?float
    {
        $value = $this->getHeaders()['retry-after'] ?? null;
        return $value !== null && is_numeric($value) ? max(0.0, (float) $value) : null;
    }
}
