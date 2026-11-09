# finfactory-qrph for PHP

Draw a **QR Ph** code as SVG, with the QR Ph logo in the centre.

```sh
composer require domdanao/finfactory-qrph
```

It works with **any QR Ph payload**: from the [FinFactory Payments SDK](https://github.com/domdanao/finfactory-payments/tree/main/php), or from any other
provider that gives you the payload text. It needs no API key and makes no network calls. The same
package exists for [JavaScript](https://github.com/domdanao/finfactory-payments/tree/main/qrph-js), with the same options and the same output.

> **v0.1: early release.** The options may still change before 1.0.

## Requirements

PHP 8.1 or later. Installs [`chillerlan/php-qrcode`](https://github.com/chillerlan/php-qrcode) 5, the QR encoder; nothing else.

## Drawing a code

```php
use FinFactory\QrPh\QrPh;

$logo = ['data' => file_get_contents(__DIR__ . '/qrph-logo.png'), 'type' => 'image/png'];

echo '<div style="width: 280px">' . QrPh::svg($payload, ['logo' => $logo]) . '</div>';
```

The SVG scales to its container; show it at 250px or more across.

## From a FinFactory payment intent

```php
use FinFactory\Payments\FinFactory;
use FinFactory\QrPh\QrPh;

$intent = $finfactory->paymentIntents->create([
    'amount' => 500,
    'currency' => 'php',
    'payment_method_data' => ['type' => 'qrph'],
    'confirm' => true,
]);

$svg = QrPh::svg($intent, ['logo' => $logo]); // null unless the intent is waiting for QR Ph
$expiresAt = $intent->qrphExpiresAt();         // for a countdown
```

It reads `next_action->qrph_display_qr_code->data` from the intent, as an object or an array, so
it works with the SDK's `PaymentIntent` or with decoded JSON. It does not need the SDK.

## The logo

The BSP asks that merchant (P2M) QR Ph codes show the QR Ph logo in the centre
([QR Ph Rebranding FAQ](https://www.bsp.gov.ph/Media_And_Research/Primers%20Faqs/FAQs-QR-Ph-Rebranding.pdf), item 4). This package does not include the logo: it is
the BSP's mark, so get the image from your acquirer or the BSP.

The code is drawn at error correction level H, which survives up to 30% damage. A square of
about a quarter of the code's width is cleared in the centre and the logo is drawn one module
inside it, so banking apps read the code past the logo. Without a logo, the code is drawn whole.

## Options

`QrPh::svg($source, $options)`: `source` is the payload text, or a payment intent.

| Option | Default | |
| --- | --- | --- |
| `logo` | none | A URL or `data:` URI, used as it is; or `['data' => bytes, 'type' => 'image/png']`, embedded |
| `margin` | `4` | Light border around the code, in modules |
| `foreground`, `background` | `#000`, `#fff` | Colours. Keep the code dark on light |
| `title` | `QR Ph code` | Accessible name of the image |

An empty payload, a margin that isn't a whole number of 0 or more, or a malformed logo throws
`InvalidArgumentException`. Values written into the SVG are escaped.

## Development

```sh
composer install && vendor/bin/phpunit
```

The tests decode the codes they draw, with and without a logo, and run the shared cases in
[`spec/conformance/qrph.json`](https://github.com/domdanao/finfactory-payments/blob/main/spec/conformance/qrph.json) that every finfactory-qrph package passes.

## License

MIT. The QR Ph logo is the BSP's and is not covered by this license.
