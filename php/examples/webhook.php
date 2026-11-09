<?php

// A webhook receiver in plain PHP. Serve it over https and register its URL as a webhook endpoint.
//
//   FINFACTORY_WEBHOOK_SECRET=whsec_... php -S localhost:3000 examples/webhook.php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use FinFactory\Payments\Exception\SignatureVerificationException;
use FinFactory\Payments\Resource\Event;
use FinFactory\Payments\Webhook;

try {
    $event = Webhook::constructEvent(
        (string) file_get_contents('php://input'),
        $_SERVER['HTTP_WEBHOOK_SIGNATURE'] ?? null,
        (string) getenv('FINFACTORY_WEBHOOK_SECRET'),
    );
} catch (SignatureVerificationException $e) {
    http_response_code(400);
    echo $e->getMessage();
    return;
}

// Deduplicate on $event->id in your database: deliveries can repeat.
switch ($event->type) {
    case Event::PAYMENT_INTENT_SUCCEEDED:
        error_log("paid: {$event->data->object->id} {$event->data->object->amount}");
        break;
    case Event::PAYMENT_INTENT_PAYMENT_FAILED:
        error_log("failed: {$event->data->object->id} {$event->data->object->last_payment_error?->code}");
        break;
    default:
        error_log("event: {$event->type}");
}

http_response_code(200);
