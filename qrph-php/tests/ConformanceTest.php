<?php

declare(strict_types=1);

namespace FinFactory\QrPh\Tests;

use FinFactory\QrPh\QrPh;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** The shared cases in spec/conformance/qrph.json, which every finfactory-qrph package passes. */
final class ConformanceTest extends TestCase
{
    /** @return array<string, mixed> */
    private static function cases(): array
    {
        return json_decode((string) file_get_contents(__DIR__ . '/../../spec/conformance/qrph.json'), true, flags: JSON_THROW_ON_ERROR);
    }

    public function testSizeLogoSquareAndEmbeddedLogo(): void
    {
        $q = self::cases();
        $size = $q['expect_viewbox_size_margin_4'];
        self::assertStringContainsString("viewBox=\"0 0 {$size} {$size}\"", (string) QrPh::svg($q['payload']));
        foreach ($q['logo_modules'] as $n => $side) {
            self::assertSame($side, QrPh::logoModules((int) $n));
        }
        $logo = ['data' => base64_decode($q['logo_bytes_base64']), 'type' => $q['logo_type']];
        self::assertStringContainsString("href=\"data:{$q['logo_type']};base64,{$q['logo_bytes_base64']}\"", (string) QrPh::svg($q['payload'], ['logo' => $logo]));
    }

    public function testReadsAPaymentIntentOrGivesNull(): void
    {
        $q = self::cases();
        self::assertSame(QrPh::svg($q['payload']), QrPh::svg($q['intent']));
        foreach ($q['not_qrph_intents'] as $intent) {
            self::assertNull(QrPh::svg($intent));
        }
    }

    /** @return iterable<string, array{0: array<string, mixed>}> */
    public static function badInput(): iterable
    {
        yield 'empty payload' => [['payload' => '']];
        foreach (self::cases()['bad_options'] as $bad) {
            $options = $bad['options'];
            if (\is_array($options['logo'] ?? null)) {
                $options['logo'] = array_filter(['data' => base64_decode($options['logo']['data_base64']), 'type' => $options['logo']['type'] ?? null], static fn ($v) => $v !== null);
            }
            yield $bad['name'] => [$options];
        }
    }

    #[DataProvider('badInput')]
    public function testRefusesBadInput(array $options): void
    {
        $payload = $options['payload'] ?? self::cases()['payload'];
        unset($options['payload']);
        $this->expectException(\InvalidArgumentException::class);
        QrPh::svg($payload, $options);
    }
}
