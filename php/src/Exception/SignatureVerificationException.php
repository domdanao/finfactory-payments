<?php

declare(strict_types=1);

namespace FinFactory\Payments\Exception;

/** A webhook Webhook-Signature did not verify, or its timestamp is outside the tolerance. */
class SignatureVerificationException extends FinFactoryException
{
}
