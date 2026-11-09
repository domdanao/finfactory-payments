<?php

declare(strict_types=1);

namespace FinFactory\Payments\Exception;

/** 404 `not_found`: no such object for this key, or no such route. */
class NotFoundException extends ApiErrorException
{
}
