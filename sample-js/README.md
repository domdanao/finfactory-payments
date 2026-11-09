# Acacia 15 SVC: JavaScript sample

A one-page web site with a link that charges a payer ₱15.00 for **Acacia 15 SVC**, using
[`@domdanao/finfactory-payments`](../js) and FinFactory's hosted checkout. It uses Node's built-in
`http` module and no other packages.

## Run it

You need Node.js 20.19 or later and a **test** key (`ci_test_...` / `sk_test_...`) from the
merchant dashboard.

```sh
# Build the SDK once; this sample links to it.
cd ../js && npm install && npm run build && cd ../sample-js

cp .env.example .env    # fill in FINFACTORY_CLIENT_ID and FINFACTORY_CLIENT_SECRET
npm install
npm start
```

Open <http://acacia.localhost:3000> and click **Pay ₱15.00**.

> **Why `acacia.localhost` and not `localhost`?** The API's firewall refuses any request whose
> `return_url` or `cancel_url` points at `localhost`, `127.0.0.1` or a private network address
> (`192.168.x.x`, `10.x.x.x`). It answers with a bare `403 {"message":"Forbidden"}`, which
> the SDK reports as an `authorization_failure`. Names ending in `.localhost` aren't blocked,
> and browsers send them to your own computer without any setup.

## What happens

1. **`GET /`** shows the product and a **Pay ₱15.00** link to `/pay`.
2. **`GET /pay`** creates a payment intent for 1500 centavos (`php`) with no payment method,
   so the payer chooses card, QR Ph or another method on FinFactory's hosted checkout page. The
   server redirects to `next_action.redirect_to_url.url`.
3. The payer pays, or cancels, on the hosted checkout page.
4. **`GET /return?payment_intent=pi_...`** is where checkout sends the payer back. Arriving here
   proves nothing by itself, so the server retrieves the intent and shows its status.
   A payer who cancels goes to `/?canceled=1` instead.

All of it is in [`server.mjs`](server.mjs).

## Settings

| Variable | Meaning |
| --- | --- |
| `FINFACTORY_CLIENT_ID`, `FINFACTORY_CLIENT_SECRET` | Your API key. Use a test key until you go live. |
| `PUBLIC_URL` | The address payers reach the site at; used for `return_url` and `cancel_url`. Default `http://acacia.localhost:3000`. |
| `PORT` | The port to listen on. Default `3000`. |
| `FINFACTORY_BASE_URL` | Optional: a different API host. |

`npm start` reads them from `.env`. Real environment variables also work.

## Before going live

- **Use HTTPS.** Live mode accepts only `https` return and cancel URLs, so `PUBLIC_URL` must be
  an `https` address.
- **Fulfil from a webhook, not the return page.** A payer can close the tab before coming back,
  and a QR Ph or cash payment can finish later. Listen for `payment_intent.succeeded`; see the
  [webhook example](../js/examples/webhook-server.mjs).
- **Pass your own idempotency key** derived from your order (for example
  `{ idempotencyKey: "order-1042-1" }`) once you have orders, so a double click or a retry
  can't create two charges.
- **Consider a button instead of a link.** `/pay` is a plain `GET` link, as asked. It moves no
  money by itself, but link prefetchers and crawlers can follow it and create stray intents.
  A `POST` form avoids that.

## Using the SDK from outside this repository

This sample installs the SDK with `"file:../js"`. In another project, use one of the ways in the
[SDK README](../js/README.md) (a packed `.tgz` now, npm once it's published).
