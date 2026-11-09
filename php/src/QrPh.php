<?php

declare(strict_types=1);

namespace FinFactory\Payments;

use chillerlan\QRCode\Common\EccLevel;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;
use FinFactory\Payments\Resource\PaymentIntent;

/**
 * Draws a QR Ph code as SVG, for your own payment page:
 *
 *   $logo = ['data' => file_get_contents(__DIR__ . '/qrph-logo.png'), 'type' => 'image/png'];
 *   echo QrPh::svg($intent, ['logo' => $logo]);   // or $intent->qrphSvg(['logo' => $logo])
 *
 * Needs chillerlan/php-qrcode 5 (`composer require chillerlan/php-qrcode:^5`); the rest of the SDK
 * doesn't.
 *
 * Options:
 * - `logo`: the QR Ph logo, drawn in the middle. Merchant (P2M) QR Ph codes carry it there, per
 *   the BSP's QR Ph rebranding. Either a URL or `data:` URI, used as the image's `href` as it is,
 *   or ['data' => bytes, 'type' => 'image/png'], embedded as a `data:` URI. The SDK doesn't ship
 *   the logo: it's the BSP's mark, not ours to license.
 * - `margin`: light border around the code, in modules. Default 4, what the QR standard asks for.
 * - `foreground`, `background`: colours. Default `#000` on `#fff`; keep it dark on light.
 * - `title`: accessible name of the image. Default "QR Ph code".
 *
 * Without a logo the code is drawn whole. Error correction is level H either way, so scanners
 * read the code past the logo. The SVG scales; show it at 250px or more across.
 */
final class QrPh
{
    /**
     * The QR Ph code as an <svg> element. Pass a payment intent (null is returned when it isn't
     * waiting for a QR Ph payment) or the QR payload itself.
     *
     * @param array{logo?: string|array{data: string, type: string}, margin?: int, foreground?: string, background?: string, title?: string} $options
     */
    public static function svg(PaymentIntent|string $source, array $options = []): ?string
    {
        $data = \is_string($source) ? $source : $source->qrphData();
        if ($data === null) {
            return null;
        }
        if ($data === '') {
            throw new \InvalidArgumentException('FinFactory: QrPh::svg needs a QR payload; got an empty string.');
        }
        if (!class_exists(QRCode::class) || !method_exists(QRCode::class, 'getQRMatrix')) {
            throw new \LogicException('FinFactory: drawing a QR Ph code needs chillerlan/php-qrcode 5. Run: composer require chillerlan/php-qrcode:^5');
        }

        $margin = $options['margin'] ?? 4;
        if (!\is_int($margin) || $margin < 0) {
            throw new \InvalidArgumentException("FinFactory: QrPh::svg's margin must be a whole number of modules, 0 or more.");
        }

        $qr = new QRCode(new QROptions(['eccLevel' => EccLevel::H, 'addQuietzone' => false]));
        $qr->addByteSegment($data);
        $matrix = $qr->getQRMatrix();
        $n = $matrix->getSize();

        $href = self::logoHref($options);
        $hole = $href === null ? 0 : self::logoModules($n);
        $from = intdiv($n - $hole, 2);
        $to = $from + $hole;
        $inHole = static fn (int $x, int $y): bool => $y >= $from && $y < $to && $x >= $from && $x < $to;

        // One path; each row's runs of dark modules become one rectangle each.
        $d = '';
        for ($y = 0; $y < $n; $y++) {
            for ($x = 0; $x < $n;) {
                if (!$matrix->check($x, $y) || $inHole($x, $y)) {
                    $x++;
                    continue;
                }
                $start = $x;
                while ($x < $n && $matrix->check($x, $y) && !$inHole($x, $y)) {
                    $x++;
                }
                $run = $x - $start;
                $d .= 'M' . ($start + $margin) . ' ' . ($y + $margin) . "h{$run}v1h-{$run}z";
            }
        }

        $size = $n + 2 * $margin;
        $image = $href === null
            ? ''
            // One module inside the cleared square, leaving a light border around the logo.
            : \sprintf('<image x="%1$d" y="%1$d" width="%2$d" height="%2$d" href="%3$s"/>', $from + $margin + 1, $hole - 2, self::attr($href));
        $title = self::attr($options['title'] ?? 'QR Ph code');

        return "<svg xmlns=\"http://www.w3.org/2000/svg\" viewBox=\"0 0 {$size} {$size}\" shape-rendering=\"crispEdges\" role=\"img\" aria-label=\"{$title}\">"
            . "<title>{$title}</title>"
            . "<rect width=\"{$size}\" height=\"{$size}\" fill=\"" . self::attr($options['background'] ?? '#fff') . '"/>'
            . "<path d=\"{$d}\" fill=\"" . self::attr($options['foreground'] ?? '#000') . '"/>'
            . $image
            . '</svg>';
    }

    /** Side of the cleared middle square, in modules: about a quarter of the code, odd so it centers. */
    public static function logoModules(int $moduleCount): int
    {
        $side = max(7, (int) round($moduleCount * 0.25));
        return $side % 2 === 1 ? $side : $side - 1;
    }

    /** @param array<string, mixed> $options */
    private static function logoHref(array $options): ?string
    {
        if (!isset($options['logo'])) {
            return null;
        }
        $logo = $options['logo'];
        if (\is_string($logo)) {
            if ($logo === '') {
                throw new \InvalidArgumentException("FinFactory: QrPh::svg's logo is an empty string.");
            }
            return $logo;
        }
        if (\is_array($logo) && \is_string($logo['data'] ?? null) && \is_string($logo['type'] ?? null) && $logo['data'] !== '') {
            return "data:{$logo['type']};base64," . base64_encode($logo['data']);
        }
        throw new \InvalidArgumentException("FinFactory: QrPh::svg's logo must be a URL, a data: URI, or ['data' => bytes, 'type' => 'image/png'].");
    }

    private static function attr(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }
}
