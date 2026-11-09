<?php

declare(strict_types=1);

namespace FinFactory\Payments\Exception;

/** 403 `authorization_failure`: the key may not act on what the request names. */
class AuthorizationException extends ApiErrorException
{
}
