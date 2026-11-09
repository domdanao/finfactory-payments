# ATSI web svc: JavaScript sample

A one-page web site that charges a payer ₱5.00 for **ATSI web svc** by **QR Ph on its own
page**, using [`@domdanao/finfactory-payments`](../js). It is the same site, route for route, as
[the PHP sample](../sample-php). The payer never leaves the site: it asks
the API for the QR payload, draws the code itself and watches for the payment. Node's built-in
`http` module, no framework: everything is in [`server.mjs`](server.mjs).

FinFactory's hosted checkout is still there as **Other ways to pay** (`/pay`), for card and
the other methods.

## Run it

You need Node.js 20.19 or later and a **test** key (`ci_test_...` / `sk_test_...`) from the
merchant dashboard.

```sh
# Build the SDK and finfactory-qrph once; this sample links to them.
(cd ../js && npm install && npm run build)
(cd ../qrph-js && npm install && npm run build)

cp .env.example .env    # fill in FINFACTORY_CLIENT_ID and FINFACTORY_CLIENT_SECRET
npm install
npm start               # node --env-file=.env server.mjs
```

Open <http://acacia.localhost:3000> and click **Pay ₱5.00 with QR Ph**. In test mode the code
is a placeholder no banking app can scan; use the **Simulate payment** and **Simulate expiry**
buttons under it. `npm run start:live` uses `.env.live` instead.

> **Why `acacia.localhost` and not `localhost`?** It matters only for the hosted checkout link.
> The API's firewall refuses any request whose `return_url` or `cancel_url` points at
> `localhost`, `127.0.0.1` or a private network address (`192.168.x.x`, `10.x.x.x`), with a bare
> `403 {"message":"Forbidden"}`. Names ending in `.localhost` aren't blocked, and browsers send
> them to your own computer without any setup. The QR Ph flow sends no URLs, so it works from
> any address, and in live mode it needs no `https` tunnel.

## What happens

1. **`GET /`** shows the product and a **Pay ₱5.00 with QR Ph** button. It's a `POST` form
   carrying a random attempt id, so prefetchers and crawlers can't create payments.
2. **`POST /qrph`** creates the payment intent with `payment_method_data[type] = qrph` and
   `confirm = true`, in one call. The attempt id is the idempotency key, so a double click gets
   the same payment back instead of a second one. It redirects to the QR page, so reloading
   doesn't post again.
3. **`GET /qrph?payment_intent=pi_...`** retrieves the intent and draws
   `next_action.qrph_display_qr_code.data` as an SVG QR code, with the amount and a
   countdown to `expires_at` (30 minutes). The QR Ph logo ([`assets/qrph-logo.png`](assets/qrph-logo.png))
   sits in the middle, as the BSP asks of merchant (P2M) codes: they "feature the QR Ph logo at
   the center of the QR code"
   ([QR Ph Rebranding FAQ](https://www.bsp.gov.ph/Media_And_Research/Primers%20Faqs/FAQs-QR-Ph-Rebranding.pdf),
   item 4). The separate package [`@domdanao/finfactory-qrph`](../qrph-js) draws it:
   `qrphSvg(intent, { logo: { data, type: "image/png" } })`. An expired or canceled intent gets a **Get a new QR
   code** button instead.
4. The page's script asks **`GET /qrph/status`** for the intent's status every 3 seconds. When
   it's `succeeded` or `processing`, the payer goes to **`/return`**, which retrieves the intent
   again and shows the result.
5. **`POST /qrph/simulate`** (test mode only) pays or expires the code with the test helper.

The SDK keeps the Access Token in memory for as long as the process runs, so the 3-second polls
don't fetch new tokens.

## Settings

| Variable | Meaning |
| --- | --- |
| `FINFACTORY_CLIENT_ID`, `FINFACTORY_CLIENT_SECRET` | Your API key. Use a test key until you go live. |
| `PUBLIC_URL` | The address payers reach the site at; used for the hosted checkout's `return_url` and `cancel_url`. Default `http://acacia.localhost:3000`. |
| `PORT` | The port to listen on. Default `3000`. |
| `FINFACTORY_BASE_URL` | Optional: a different API host. |

`npm start` reads them from `.env` with Node's `--env-file`. Real environment variables win.

## Before going live

- **Fulfil from a webhook, not the page.** The payer can close the tab after scanning, and the
  QR page only learns of the payment by polling. Listen for `payment_intent.succeeded`; see the
  [webhook example](../js/examples/webhook-server.mjs).
- **Use your order as the idempotency key** (for example `order-1042-1`) once you have orders,
  instead of a random attempt id.
- **Don't let anyone look up any payment.** `/qrph` and `/qrph/status` answer for whatever
  `pi_...` they are given. Tie the payment to the payer's session or order before showing it.
- **Share the token cache** across processes when you run more than one, for example in Redis;
  see the [SDK README](../js/README.md#authentication-and-access-tokens).
- **For the hosted checkout link, use HTTPS.** Live mode accepts only `https` return and cancel
  URLs, so `PUBLIC_URL` must be an `https` address.

## Using the packages from outside this repository

This sample installs the SDK and `finfactory-qrph` with `"file:../js"` and `"file:../qrph-js"`.
In another project, install packed `.tgz` files of both (`npm pack` in each folder) now, and from
npm once they're published.
