<?php

declare(strict_types=1);

namespace FinFactory\Payments\Tests;

use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;
use FinFactory\Payments\QrPh;
use FinFactory\Payments\Resource\PaymentIntent;
use PHPUnit\Framework\TestCase;

final class QrPhTest extends TestCase
{
    // Shaped like a real QR Ph payload (EMVCo), 197 characters.
    private const PAYLOAD = '00020101021228760011ph.ppmi.p2m0111ABCDPHM1XXX03150000123456789010415ABCDEF1234567890503031520400005303608540425005802PH5913ACACIA STORE 6011MANDALUYONG62310010ph.ppmi.p2m0513REF01A1BF6674A6304ABCD';

    /** @return array<string, mixed> */
    private static function intent(?array $nextAction): array
    {
        return [
            'id' => 'pi_1', 'object' => 'payment_intent', 'amount' => 2500, 'amount_received' => 0,
            'currency' => 'php', 'payment_method_types' => ['qrph'], 'status' => 'requires_action',
            'next_action' => $nextAction,
        ];
    }

    /** Dark modules drawn by the SVG's path, as "x,y" keys in SVG units. @return array<string, true> */
    private static function drawn(string $svg): array
    {
        preg_match('/<path d="([^"]*)"/', $svg, $m);
        preg_match_all('/M(\d+) (\d+)h(\d+)v1h-\d+z/', $m[1], $runs, PREG_SET_ORDER);
        $cells = [];
        foreach ($runs as [, $x, $y, $w]) {
            for ($i = 0; $i < (int) $w; $i++) {
                $cells[((int) $x + $i) . ',' . $y] = true;
            }
        }
        return $cells;
    }

    /** Scans the SVG: draws its modules as a PNG and reads it back with a QR decoder. */
    private static function scan(string $svg): string
    {
        if (!\function_exists('imagecreatetruecolor')) {
            self::markTestSkipped('needs ext-gd to scan the code');
        }
        preg_match('/viewBox="0 0 (\d+) \d+"/', $svg, $m);
        $scale = 8;
        $img = imagecreatetruecolor((int) $m[1] * $scale, (int) $m[1] * $scale);
        imagefill($img, 0, 0, imagecolorallocate($img, 255, 255, 255));
        $black = imagecolorallocate($img, 0, 0, 0);
        foreach (array_keys(self::drawn($svg)) as $cell) {
            [$x, $y] = array_map('intval', explode(',', $cell));
            imagefilledrectangle($img, $x * $scale, $y * $scale, ($x + 1) * $scale - 1, ($y + 1) * $scale - 1, $black);
        }
        ob_start();
        imagepng($img);
        $png = (string) ob_get_clean();
        return (string) (new QRCode(new QROptions(['readerUseImagickIfAvailable' => false])))->readFromBlob($png);
    }

    public function testDrawsAScannableLevelHCodeWithTheMargin(): void
    {
        $svg = QrPh::svg(self::PAYLOAD);
        self::assertMatchesRegularExpression('/^<svg xmlns="http:\/\/www.w3.org\/2000\/svg" viewBox="0 0 85 85"/', $svg); // version 15: 77 + 2 × 4
        self::assertStringNotContainsString('<image', $svg);
        self::assertSame(self::PAYLOAD, self::scan($svg));
    }

    public function testClearsTheMiddleForTheLogoAndStillScans(): void
    {
        $svg = QrPh::svg(self::PAYLOAD, ['logo' => '/img/qrph-logo.png', 'margin' => 2]);
        $hole = QrPh::logoModules(77);
        $from = (77 - $hole) / 2;
        foreach (array_keys(self::drawn($svg)) as $cell) {
            [$x, $y] = array_map(static fn ($v) => (int) $v - 2, explode(',', $cell));
            self::assertFalse($x >= $from && $x < $from + $hole && $y >= $from && $y < $from + $hole, "module {$cell} is under the logo");
        }
        self::assertStringContainsString(\sprintf('<image x="%1$d" y="%1$d" width="%2$d" height="%2$d" href="/img/qrph-logo.png"/>', $from + 3, $hole - 2), $svg);
        self::assertSame(self::PAYLOAD, self::scan($svg));

        // A short payload, where the logo takes a larger share of the code.
        self::assertSame('simulated_qr_payload:qrsim_af5e42dd0e368cf4637c2402aaa46e68', self::scan(QrPh::svg('simulated_qr_payload:qrsim_af5e42dd0e368cf4637c2402aaa46e68', ['logo' => 'x'])));
    }

    public function testTheClearedSquareStaysNearAQuarterOfTheWidth(): void
    {
        self::assertSame(7, QrPh::logoModules(21));
        self::assertSame(9, QrPh::logoModules(37));
        self::assertSame(19, QrPh::logoModules(77));
        self::assertSame(43, QrPh::logoModules(177));
    }

    public function testEmbedsLogoBytes(): void
    {
        $svg = QrPh::svg(self::PAYLOAD, ['logo' => ['data' => "\x89PNG\x00\xff", 'type' => 'image/png']]);
        self::assertStringContainsString('href="data:image/png;base64,iVBORwD/"', $svg);
        self::assertSame(self::PAYLOAD, self::scan($svg));
    }

    public function testEscapesWhatGoesIntoAttributes(): void
    {
        $svg = QrPh::svg(self::PAYLOAD, ['logo' => 'x"><script>', 'title' => 'A & B', 'foreground' => '#123', 'background' => 'red']);
        self::assertStringContainsString('href="x&quot;&gt;&lt;script&gt;"', $svg);
        self::assertStringContainsString('aria-label="A &amp; B"><title>A &amp; B</title>', $svg);
        self::assertStringContainsString('fill="red"', $svg);
        self::assertStringContainsString('fill="#123"', $svg);
        self::assertStringNotContainsString('<script', $svg);
    }

    public function testFromAPaymentIntent(): void
    {
        $intent = PaymentIntent::constructFrom(self::intent(['type' => 'qrph_display_qr_code', 'qrph_display_qr_code' => ['data' => self::PAYLOAD, 'expires_at' => 1794214000]]));
        self::assertSame(self::PAYLOAD, $intent->qrphData());
        self::assertSame(1794214000, $intent->qrphExpiresAt());
        self::assertSame(QrPh::svg(self::PAYLOAD), $intent->qrphSvg());
        self::assertSame($intent->qrphSvg(), QrPh::svg($intent));

        $card = PaymentIntent::constructFrom(self::intent(['type' => 'redirect_to_url', 'redirect_to_url' => ['url' => 'https://example.test']]));
        self::assertNull($card->qrphData());
        self::assertNull($card->qrphExpiresAt());
        self::assertNull($card->qrphSvg());
        self::assertNull(QrPh::svg($card));
        self::assertNull(PaymentIntent::constructFrom(self::intent(null))->qrphSvg());
    }

    /** @return iterable<string, array{0: string, 1: array<string, mixed>}> */
    public static function badInput(): iterable
    {
        yield 'empty payload' => ['', []];
        yield 'negative margin' => [self::PAYLOAD, ['margin' => -1]];
        yield 'fractional margin' => [self::PAYLOAD, ['margin' => 1.5]];
        yield 'empty logo' => [self::PAYLOAD, ['logo' => '']];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('badInput')]
    public function testRefusesBadInput(string $data, array $options): void
    {
        $this->expectException(\InvalidArgumentException::class);
        QrPh::svg($data, $options);
    }
}
