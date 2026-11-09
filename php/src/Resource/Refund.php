<?php

declare(strict_types=1);

namespace FinFactory\Payments\Resource;

use FinFactory\Payments\ApiObject;

/**
 * Money returned from a payment that succeeded.
 *
 * @property-read string $id
 * @property-read string $object "refund"
 * @property-read string $payment_intent
 * @property-read int $amount
 * @property-read string $currency
 * @property-read string $status One of the STATUS_* constants.
 * @property-read string|null $reason "requested_by_customer", "duplicate" or "fraudulent".
 * @property-read string|null $failure_reason "declined", "refund_not_possible", "refund_not_yet_available", "refunds_not_enabled", "restricted", "risk_declined" or "unknown".
 * @property-read ApiObject|array<string, string> $metadata
 * @property-read bool $livemode
 * @property-read int $created
 */
class Refund extends ApiObject
{
    public const STATUS_CREATED = 'created';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_AWAITING_APPROVAL = 'awaiting_approval';
    public const STATUS_SUCCEEDED = 'succeeded';
    public const STATUS_FAILED = 'failed';
}
