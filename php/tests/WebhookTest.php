<?php

declare(strict_types=1);

namespace FinFactory\Payments\Tests;

use FinFactory\Payments\Exception\SignatureVerificationException;
use FinFactory\Payments\Resource\Event;
use FinFactory\Payments\Resource\PaymentIntent;
use FinFactory\Payments\Webhook;
use PHPUnit\Framework\TestCase;

/** The worked example from the Webhooks guide. */
final class WebhookTest extends TestCase
{
    private const SECRET = 'whsec_Kf3vQ9m2Xc7LpR1tYb8dNw5sUa0eHj4ZgTq6iVoMxCk';
    private const T = 1790434852;
    private const BODY = '{"id":"evt_01j9x4b2c3d4e5f6g7h8j9k0m1","object":"event","type":"payment_intent.succeeded","created":1790434850,"api_version":"v1","livemode":false,"tenant_id":"7d2e4f61-3a8b-4c95-b0e7-2f1a9c6d8e43","merchant_id":"c4a91e2b-6f37-4d08-9b5c-e0d2f8a17b36","data":{"object":{"id":"pi_01j9x2m8k4q7r5t3v6w8y0z2a4","object":"payment_intent","amount":10000,"amount_received":10000,"currency":"php","payment_method_types":["qrph"],"channel":"ecommerce","status":"succeeded","next_action":null,"last_payment_error":null,"cancellation_reason":null,"canceled_at":null,"description":"Order 1042","metadata":{"order_id":"1042"},"livemode":false,"created":1790434700}}}';
    private const HEADER = 't=1790434852,v1=ba1753e225757778aff55cb9a81cac1eb2a5f0724c32055eb8fb5e6285d54094';

    public function testVerifiesTheWorkedExample(): void
    {
        $event = Webhook::constructEvent(self::BODY, self::HEADER, self::SECRET, now: self::T + 10);
        self::assertInstanceOf(Event::class, $event);
        self::assertSame(Event::PAYMENT_INTENT_SUCCEEDED, $event->type);
        self::assertInstanceOf(PaymentIntent::class, $event->dataObject());
        self::assertSame('pi_01j9x2m8k4q7r5t3v6w8y0z2a4', $event->data->object->id);
        self::assertTrue($event->dataObject()->isSucceeded());
        self::assertSame(self::HEADER, Webhook::generateTestHeader(self::BODY, self::SECRET, self::T));
    }

    public function testRejectsBadDeliveries(): void
    {
        self::assertFalse(Webhook::isValid(str_replace('10000', '10001', self::BODY), self::HEADER, self::SECRET, now: self::T));
        self::assertFalse(Webhook::isValid(self::BODY, self::HEADER, 'whsec_other', now: self::T));
        self::assertFalse(Webhook::isValid(self::BODY, self::HEADER, self::SECRET, now: self::T + 301));
        self::assertFalse(Webhook::isValid(self::BODY, 'garbage', self::SECRET, now: self::T));
        self::assertFalse(Webhook::isValid(self::BODY, null, self::SECRET));
        self::assertTrue(Webhook::isValid(self::BODY, self::HEADER, self::SECRET, tolerance: 0));
    }

    public function testAnOldTimestampSaysWhy(): void
    {
        $this->expectException(SignatureVerificationException::class);
        $this->expectExceptionMessage('300 seconds');
        Webhook::verify(self::BODY, self::HEADER, self::SECRET, now: self::T + 301);
    }

    public function testDuringRotationAnySecretMatches(): void
    {
        $header = 't=' . self::T . ',v1=' . str_repeat('0', 64) . ',v1=ba1753e225757778aff55cb9a81cac1eb2a5f0724c32055eb8fb5e6285d54094';
        self::assertTrue(Webhook::isValid(self::BODY, $header, ['whsec_new', self::SECRET], now: self::T));
    }
}
