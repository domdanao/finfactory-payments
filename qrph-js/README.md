# finfactory-qrph for JavaScript

Draw a **QR Ph** code as SVG, with the QR Ph logo in the centre.

```sh
npm install @domdanao/finfactory-qrph
```

It works with **any QR Ph payload**: from the [FinFactory Payments SDK](https://github.com/domdanao/finfactory-payments/tree/main/js), or from any other
provider that gives you the payload text. It needs no API key and makes no network calls. The same
package exists for [PHP](https://github.com/domdanao/finfactory-payments/tree/main/qrph-php), with the same options and the same output.

> **v0.1: early release.** The options may still change before 1.0.

## Requirements

Node.js 20.19 or later, Deno, Bun, Cloudflare Workers, or a browser (through your bundler). Installs
[`qrcode-generator`](https://github.com/kazuhikoarase/qrcode-generator), the QR encoder; nothing else.

## Drawing a code

```js
import { qrphSvg } from "@domdanao/finfactory-qrph";

const logo = { data: await readFile("qrph-logo.png"), type: "image/png" }; // or a URL: "/img/qrph-logo.png"

const html = `<div style="width: 280px">${qrphSvg(payload, { logo })}</div>`;
```

The SVG scales to its container; show it at 250px or more across.

## From a FinFactory payment intent

```js
import FinFactory, { qrphExpiresAt } from "@domdanao/finfactory-payments";
import { qrphSvg } from "@domdanao/finfactory-qrph";

const intent = await finfactory.paymentIntents.create({
  amount: 500,
  currency: "php",
  payment_method_data: { type: "qrph" },
  confirm: true,
});

const svg = qrphSvg(intent, { logo });     // null unless the intent is waiting for QR Ph
const expiresAt = qrphExpiresAt(intent);   // for a countdown
```

It reads `next_action.qrph_display_qr_code.data` from the intent, so it works with the SDK's
result or with plain JSON. It does not need the SDK.

## The logo

The BSP asks that merchant (P2M) QR Ph codes show the QR Ph logo in the centre
([QR Ph Rebranding FAQ](https://www.bsp.gov.ph/Media_And_Research/Primers%20Faqs/FAQs-QR-Ph-Rebranding.pdf), item 4). This package does not include the logo: it is
the BSP's mark, so get the image from your acquirer or the BSP.

The code is drawn at error correction level H, which survives up to 30% damage. A square of
about a quarter of the code's width is cleared in the centre and the logo is drawn one module
inside it, so banking apps read the code past the logo. Without a logo, the code is drawn whole.

## Options

`qrphSvg(source, options)`: `source` is the payload text, or a payment intent.

| Option | Default | |
| --- | --- | --- |
| `logo` | none | A URL or `data:` URI, used as it is; or `{ data, type }`, with `data` a `Uint8Array` or `ArrayBuffer`, embedded |
| `margin` | `4` | Light border around the code, in modules |
| `foreground`, `background` | `#000`, `#fff` | Colours. Keep the code dark on light |
| `title` | `QR Ph code` | Accessible name of the image |

An empty payload, a margin that isn't a whole number of 0 or more, or a malformed logo throws
`TypeError`. Values written into the SVG are escaped.

## In the browser

The package holds no secret and makes no network calls, so it runs in a browser too. Your server
sends the page only the payload, and the page draws the code:

```js
import { qrphSvg } from "@domdanao/finfactory-qrph"; // through your bundler

document.querySelector("#qr").innerHTML = qrphSvg(payload, { logo: "/img/qrph-logo.png" });
```

## Development

```sh
npm install && npm test
```

The tests decode the codes they draw, with and without a logo, and run the shared cases in
[`spec/conformance/qrph.json`](https://github.com/domdanao/finfactory-payments/blob/main/spec/conformance/qrph.json) that every finfactory-qrph package passes.

## License

MIT. The QR Ph logo is the BSP's and is not covered by this license.
