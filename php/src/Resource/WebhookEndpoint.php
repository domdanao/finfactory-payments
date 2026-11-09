<?php

declare(strict_types=1);

namespace FinFactory\Payments\Resource;

use FinFactory\Payments\ApiObject;

/**
 * An HTTPS address events are sent to.
 *
 * @property-read string $id
 * @property-read string $object "webhook_endpoint"
 * @property-read string $url
 * @property-read list<string> $enabled_events
 * @property-read string $status "enabled" or "disabled".
 * @property-read string|null $secret whsec_...: on the create and rotate_secret answers only. Store it.
 * @property-read bool $livemode
 * @property-read int $created
 */
class WebhookEndpoint extends ApiObject
{
}
