<?php

declare(strict_types=1);

namespace FinFactory\Payments\Exception;

/** 422 `policy_rejection`: your account may not do this now, for the reason in getErrorCode(). */
class PolicyRejectionException extends ApiErrorException
{
}
