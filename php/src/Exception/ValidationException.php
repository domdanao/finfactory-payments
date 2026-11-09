<?php

declare(strict_types=1);

namespace FinFactory\Payments\Exception;

/** 400 `validation_failure`: a parameter is missing or not valid. getParam() names it. */
class ValidationException extends ApiErrorException
{
}
