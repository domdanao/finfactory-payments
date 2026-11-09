<?php

declare(strict_types=1);

namespace FinFactory\Payments\Exception;

/** 409 `timeout_or_in_progress`: a duplicate still running, or an outcome not known yet. Retry later. */
class InProgressException extends ApiErrorException
{
}
