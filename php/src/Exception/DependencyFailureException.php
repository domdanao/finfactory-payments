<?php

declare(strict_types=1);

namespace FinFactory\Payments\Exception;

/** 503 `dependency_failure`. On a write, whether it took effect is not known: retry with the same Idempotency-Key. */
class DependencyFailureException extends ApiErrorException
{
}
