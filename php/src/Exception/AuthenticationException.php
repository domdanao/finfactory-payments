<?php

declare(strict_types=1);

namespace FinFactory\Payments\Exception;

/** 401 `authentication_failure`: no Access Token, or one that is not valid or has expired. */
class AuthenticationException extends ApiErrorException
{
}
