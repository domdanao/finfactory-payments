<?php

declare(strict_types=1);

namespace FinFactory\Payments\Resource;

use FinFactory\Payments\ApiObject;

/**
 * A webhook event. Deduplicate on `id`; go by the object's status, not the order events arrive in.
 *
 * @property-read string $id
 * @property-read string $object "event"
 * @property-read string $type Such as "payment_intent.succeeded"; see the constants.
 * @property-read int $created
 * @property-read string $api_version "v1"
 * @property-read bool $livemode
 * @property-read string|null $tenant_id
 * @property-read string|null $merchant_id
 * @property-read ApiObject $data `data->object` is the PaymentIntent, Refund or Dispute as of the event.
 */
class Event extends ApiObject
{
    public const PAYMENT_INTENT_CREATED = 'payment_intent.created';
    public const PAYMENT_INTENT_REQUIRES_ACTION = 'payment_intent.requires_action';
    public const PAYMENT_INTENT_PROCESSING = 'payment_intent.processing';
    public const PAYMENT_INTENT_SUCCEEDED = 'payment_intent.succeeded';
    public const PAYMENT_INTENT_PAYMENT_FAILED = 'payment_intent.payment_failed';
    public const PAYMENT_INTENT_CANCELED = 'payment_intent.canceled';
    public const REFUND_CREATED = 'refund.created';
    public const REFUND_UPDATED = 'refund.updated';
    public const REFUND_SUCCEEDED = 'refund.succeeded';
    public const REFUND_FAILED = 'refund.failed';
    public const DISPUTE_CREATED = 'dispute.created';
    public const DISPUTE_UPDATED = 'dispute.updated';
    public const DISPUTE_CLOSED = 'dispute.closed';

    /** The PaymentIntent, Refund or Dispute the event is about. */
    public function dataObject(): ?ApiObject
    {
        $object = $this->data?->object;
        return $object instanceof ApiObject ? $object : null;
    }
}
