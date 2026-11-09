<?php

declare(strict_types=1);

namespace FinFactory\Payments\Exception;

/** 422 `invariant_violation`: the request would break a rule that always holds. */
class InvariantViolationException extends ApiErrorException
{
}
