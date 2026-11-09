<?php

declare(strict_types=1);

namespace FinFactory\Payments\Exception;

/** The request took longer than the timeout. */
class TimeoutException extends ConnectionException
{
}
