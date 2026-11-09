<?php

declare(strict_types=1);

namespace FinFactory\Payments\Exception;

/** 409 `conflict`: the object is not in a state that allows this. */
class ConflictException extends ApiErrorException
{
}
