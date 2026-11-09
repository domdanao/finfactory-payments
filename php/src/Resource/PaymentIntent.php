<?php

declare(strict_types=1);

namespace FinFactory\Payments\Resource;

use FinFactory\Payments\ApiObject;

/**
 * One payment to take, whatever the method.
 *
 * @property-read string $id
 * @property-read string $object "payment_intent"
 * @property-read int $amount In the currency's minor unit: 10000 in php is ₱100.00.
 * @property-read int $amount_received
 * @property-read string $currency Lower-case ISO 4217, such as "php".
 * @property-read list<string>|null $payment_method_types
 * @property-read string $channel
 * @property-read string $status One of the STATUS_* constants.
 * @property-read ApiObject|null $next_action `type` says what the customer is to do; see the NEXT_ACTION_* constants.
 * @property-read ApiObject|null $last_payment_error `code`, `message`, and in test mode `decline_code`.
 * @property-read string|null $cancellation_reason
 * @property-read int|null $canceled_at
 * @property-read string|null $description
 * @property-read ApiObject|array<string, string> $metadata
 * @property-read bool $livemode
 * @property-read int $created Unix timestamp.
 */
class PaymentIntent extends ApiObject
{
    public const STATUS_REQUIRES_PAYMENT_METHOD = 'requires_payment_method';
    public const STATUS_REQUIRES_CONFIRMATION = 'requires_confirmation';
    public const STATUS_REQUIRES_ACTION = 'requires_action';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_SUCCEEDED = 'succeeded';
    public const STATUS_CANCELED = 'canceled';

    public const NEXT_ACTION_REDIRECT_TO_URL = 'redirect_to_url';
    public const NEXT_ACTION_MOUNT_EMBEDDED_CHECKOUT = 'mount_embedded_checkout';
    public const NEXT_ACTION_QRPH_DISPLAY_QR_CODE = 'qrph_display_qr_code';
    public const NEXT_ACTION_CASH_DISPLAY_REFERENCE = 'cash_display_reference';

    /** The hosted checkout or card page URL to send the customer to, when next_action is redirect_to_url. */
    public function redirectUrl(): ?string
    {
        return $this->next_action?->type === self::NEXT_ACTION_REDIRECT_TO_URL
            ? $this->next_action->redirect_to_url?->url
            : null;
    }

    /** The embedded checkout client token (ct_...), when next_action is mount_embedded_checkout. */
    public function clientToken(): ?string
    {
        return $this->next_action?->type === self::NEXT_ACTION_MOUNT_EMBEDDED_CHECKOUT
            ? $this->next_action->mount_embedded_checkout?->client_token
            : null;
    }

    public function isSucceeded(): bool
    {
        return $this->status === self::STATUS_SUCCEEDED;
    }
}
