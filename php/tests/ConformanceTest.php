<?php

declare(strict_types=1);

namespace FinFactory\Payments\Tests;

use FinFactory\Payments\Collection;
use FinFactory\Payments\QrPh;
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

    public function testQrphSvg(): void
    {
        $q = self::cases()['qrph_svg'];
        $size = $q['expect_viewbox_size_margin_4'];
        self::assertStringContainsString("viewBox=\"0 0 {$size} {$size}\"", (string) QrPh::svg($q['payload']));
        foreach ($q['logo_modules'] as $n => $side) {
            self::assertSame($side, QrPh::logoModules((int) $n));
        }
        $logo = ['data' => base64_decode($q['logo_bytes_base64']), 'type' => $q['logo_type']];
        self::assertStringContainsString("href=\"data:{$q['logo_type']};base64,{$q['logo_bytes_base64']}\"", (string) QrPh::svg($q['payload'], ['logo' => $logo]));
        $succeeded = array_values(array_filter(self::cases()['payment_intents'], static fn ($c) => $c['name'] === 'succeeded'))[0];
        self::assertNull(QrPh::svg(PaymentIntent::constructFrom($succeeded['intent'])));
    }

    /** @return iterable<string, array{0: array<string, mixed>}> */
    public static function badQrphOptions(): iterable
    {
        yield 'empty payload' => [['payload' => '']];
        foreach (self::cases()['qrph_svg']['bad_options'] as $bad) {
            $options = $bad['options'];
            if (\is_array($options['logo'] ?? null)) {
                $options['logo'] = array_filter(['data' => base64_decode($options['logo']['data_base64']), 'type' => $options['logo']['type'] ?? null], static fn ($v) => $v !== null);
            }
            yield $bad['name'] => [$options];
        }
    }

    #[DataProvider('badQrphOptions')]
    public function testQrphSvgRefuses(array $options): void
    {
        $payload = $options['payload'] ?? self::cases()['qrph_svg']['payload'];
        unset($options['payload']);
        $this->expectException(\InvalidArgumentException::class);
        QrPh::svg($payload, $options);
    }
}
