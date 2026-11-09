<?php

declare(strict_types=1);

namespace FinFactory\Payments\Tests;

use FinFactory\Payments\Collection;
use FinFactory\Payments\Resource\Event;
use FinFactory\Payments\Resource\PaymentIntent;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** The shared cases in spec/conformance/helpers.json, which every FinFactory SDK passes. */
final class ConformanceTest extends TestCase
{
    /** @return array<string, mixed> */
    private static function cases(): array
    {
        return json_decode((string) file_get_contents(__DIR__ . '/../../spec/conformance/helpers.json'), true, flags: JSON_THROW_ON_ERROR);
    }

    /** @return iterable<string, array{0: array<string, mixed>, 1: array<string, mixed>}> */
    public static function paymentIntents(): iterable
    {
        foreach (self::cases()['payment_intents'] as $c) {
            yield $c['name'] => [$c['intent'], $c['expect']];
        }
    }

    #[DataProvider('paymentIntents')]
    public function testPaymentIntentHelpers(array $intent, array $expect): void
    {
        $pi = PaymentIntent::constructFrom($intent);
        foreach ($expect as $helper => $expected) {
            self::assertSame($expected, $pi->{$helper}(), $helper);
        }
    }

    public function testListHelpers(): void
    {
        foreach (self::cases()['lists'] as $c) {
            $page = Collection::constructFrom($c['page']);
            self::assertSame($c['expect']['hasMore'], $page->hasMore());
            self::assertSame($c['expect']['nextCursor'], $page->nextCursor());
        }
    }

    public function testEventHelpers(): void
    {
        foreach (self::cases()['events'] as $c) {
            self::assertSame($c['expect']['dataObjectId'], Event::constructFrom($c['event'])->dataObject()->id);
        }
    }
}
