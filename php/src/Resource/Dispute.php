<?php

declare(strict_types=1);

namespace FinFactory\Payments\Resource;

use FinFactory\Payments\ApiObject;

/**
 * A chargeback on a card payment. Respond to it from the Merchant Dashboard.
 *
 * @property-read string $id
 * @property-read string $object "dispute"
 * @property-read int $amount
 * @property-read string $currency
 * @property-read string $payment_intent
 * @property-read string $status One of the STATUS_* constants.
 * @property-read string $reason "fraud", "authorization", "processing_error", "consumer_dispute" or "other".
 * @property-read string $network_reason_code
 * @property-read string $case_reference
 * @property-read int $chargeback_cost
 * @property-read int $admin_fee
 * @property-read int $cost_returned
 * @property-read int $debited What came off your balance in all.
 * @property-read string $charged_to "you" or "platform".
 * @property-read string|null $evidence_due_date YYYY-MM-DD, in your platform's time zone.
 * @property-read string|null $evidence_request
 * @property-read bool $livemode
 * @property-read int $created
 * @property-read int|null $closed
 */
class Dispute extends ApiObject
{
    public const STATUS_OPEN = 'open';
    public const STATUS_UNDER_REVIEW = 'under_review';
    public const STATUS_WON = 'won';
    public const STATUS_LOST = 'lost';
    public const STATUS_ACCEPTED = 'accepted';
}
