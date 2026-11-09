<?php

declare(strict_types=1);

namespace FinFactory\Payments\Exception;

/** No answer was received: a network failure or a timeout. For a write, whether it took effect is not known. */
class ConnectionException extends FinFactoryException
{
}
