<?php

declare(strict_types=1);

namespace FinFactory\Payments;

use FinFactory\Payments\Exception\SignatureVerificationException;
use FinFactory\Payments\Resource\Event;

/**
 * Verifies webhook deliveries.
 *
 *     $event = Webhook::constructEvent(
 *         file_get_contents('php://input'),
 *         $_SERVER['HTTP_WEBHOOK_SIGNATURE'] ?? null,
 *         getenv('FINFACTORY_WEBHOOK_SECRET'),
 *     );
 */
final class Webhook
{
    public const DEFAULT_TOLERANCE = 300;

    /**
     * Check a delivery's Webhook-Signature header against its raw body.
     *
     * @param string $payload The raw request body, exactly as it arrived, before json_decode.
     * @param string|list<string> $secret The endpoint's signing secret; during a rotation, both the new and the old.
     * @param int $tolerance Reject a timestamp further than this from now, in seconds. 0 turns the check off.
     * @param int|null $now "Now", as a Unix timestamp. For tests.
     *
     * @throws SignatureVerificationException
     */
    public static function verify(string $payload, ?string $signatureHeader, string|array $secret, int $tolerance = self::DEFAULT_TOLERANCE, ?int $now = null): void
    {
        $secrets = array_values(array_filter((array) $secret, static fn ($s) => \is_string($s) && $s !== ''));
        if ($secrets === []) {
            throw new SignatureVerificationException('No webhook signing secret given.');
        }
        if ($signatureHeader === null || $signatureHeader === '') {
            throw new SignatureVerificationException('No Webhook-Signature header.');
        }

        [$timestamp, $signatures] = self::parseHeader($signatureHeader);
        if ($timestamp === null || $signatures === []) {
            throw new SignatureVerificationException('The Webhook-Signature header is not shaped like t=<timestamp>,v1=<signature>.');
        }

        $now ??= time();
        if ($tolerance > 0 && abs($now - $timestamp) > $tolerance) {
            throw new SignatureVerificationException("The signature's timestamp is more than {$tolerance} seconds from now.");
        }

        $signed = $timestamp . '.' . $payload;
        foreach ($secrets as $s) {
            $expected = hash_hmac('sha256', $signed, $s);
            foreach ($signatures as $signature) {
                if (hash_equals($expected, $signature)) {
                    return;
                }
            }
        }
        throw new SignatureVerificationException("No signature matches the body and secret. Check you pass the raw body and this endpoint's secret.");
    }

    /**
     * Like verify(), but answers true or false.
     *
     * @param string|list<string> $secret
     */
    public static function isValid(string $payload, ?string $signatureHeader, string|array $secret, int $tolerance = self::DEFAULT_TOLERANCE, ?int $now = null): bool
    {
        try {
            self::verify($payload, $signatureHeader, $secret, $tolerance, $now);
            return true;
        } catch (SignatureVerificationException) {
            return false;
        }
    }

    /**
     * Verify a delivery, then decode it into an Event. `$event->data->object` is a PaymentIntent,
     * Refund or Dispute.
     *
     * @param string|list<string> $secret
     *
     * @throws SignatureVerificationException
     */
    public static function constructEvent(string $payload, ?string $signatureHeader, string|array $secret, int $tolerance = self::DEFAULT_TOLERANCE, ?int $now = null): Event
    {
        self::verify($payload, $signatureHeader, $secret, $tolerance, $now);
        try {
            $data = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new SignatureVerificationException('The webhook body is not valid JSON.', 0, $e);
        }
        if (!\is_array($data)) {
            throw new SignatureVerificationException('The webhook body is not a JSON object.');
        }
        return Event::constructFrom($data);
    }

    /** Make a Webhook-Signature header for a body. For your own tests only: the platform signs real deliveries. */
    public static function generateTestHeader(string $payload, string $secret, ?int $timestamp = null): string
    {
        $timestamp ??= time();
        return 't=' . $timestamp . ',v1=' . hash_hmac('sha256', $timestamp . '.' . $payload, $secret);
    }

    /** @return array{0: int|null, 1: list<string>} */
    private static function parseHeader(string $header): array
    {
        $timestamp = null;
        $signatures = [];
        foreach (explode(',', $header) as $part) {
            $pair = explode('=', $part, 2);
            if (\count($pair) !== 2) {
                continue;
            }
            [$key, $value] = [trim($pair[0]), trim($pair[1])];
            if ($key === 't' && ctype_digit($value)) {
                $timestamp = (int) $value;
            } elseif ($key === 'v1') {
                $signatures[] = strtolower($value);
            }
        }
        return [$timestamp, $signatures];
    }
}
