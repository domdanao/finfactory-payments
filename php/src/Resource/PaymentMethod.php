<?php

declare(strict_types=1);

namespace FinFactory\Payments\Resource;

use FinFactory\Payments\ApiObject;

/**
 * A method your account can take, and whether it can take a payment now.
 *
 * @property-read string $id
 * @property-read string $object "payment_method"
 * @property-read string $method "card", "qrph", "cash", ...
 * @property-read string $channel
 * @property-read bool $available
 * @property-read string|null $unavailable_reason
 * @property-read bool $livemode
 */
class PaymentMethod extends ApiObject
{
}
