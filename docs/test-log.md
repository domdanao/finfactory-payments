# Test log

What has been run against the real FinFactory API (test environment), by whom, and with what
result. Unit tests against a fake server are not listed here.

## 2026-11-09

| What | SDK / sample | Payment | Result | By |
| --- | --- | --- | --- | --- |
| Access token, list payment intents | JS | — | Works | Claude |
| Hosted checkout, QR Ph (simulated) | sample-js | `pi_01a1bef2705666367f204a315f32` | Succeeded, return page correct | Claude |
| Hosted checkout, **card** | sample site | `pi_01a1bef5e41a7f7a6144b7922a02` | Succeeded | **Dom** |
| Hosted checkout, QR Ph (simulated) | sample-php | `pi_01a1bef9510f3ad887a029e9da40` | Succeeded, return page correct | Claude |
| Hosted checkout, QR Ph | sample site | `pi_01a1befa96c09f82d0cecd8870c7` | Succeeded | Dom (assumed) |
| Hosted checkout over an `https` tunnel, QR Ph (simulated) | sample-js | `pi_01a1bf059332293a228144c033a2` | Succeeded, return page correct | Claude |
| Canceling on hosted checkout, over the tunnel | sample-js | — | Back on the product page with "Payment canceled" | Claude |
| **LIVE mode**: hosted checkout over the tunnel, QR Ph, real ₱15.00 | sample-js | `pi_01a1bf099d2fb20b4779e77b50fd` | Succeeded, ₱15.00 received (fee ₱0.23, net ₱14.77 per dashboard), return page correct | Dom paid; Claude ran the sample and checked |
| **LIVE mode**: hosted checkout over the tunnel, QR Ph, real ₱15.00 | sample-php | `pi_01a1bf0ec32e59bf1bc7875f45f1` | Succeeded, ₱15.00 received, return page correct | Dom paid; Claude ran the sample and checked |
| **LIVE mode**: hosted checkout over the tunnel, **card**, real ₱25.00 | sample-php | `pi_01a1bf13221f117ca580cf7b0ec7` | Succeeded (checked through the API) | Dom paid; Claude ran the sample and checked |
| Own QR Ph page (no hosted checkout): create and confirm in one call, render the code, poll status | sample-php | `pi_01a1bf66e61630b752abb42bf78c` | Paid by the simulator from outside the page; the page noticed within one poll and showed "Thank you!" | Claude |
| Own QR Ph page, **Simulate expiry** button | sample-php | `pi_01a1bf674a1345b11f4a9c7a3054` | `requires_payment_method`; page offers a new code | Claude |
| **LIVE mode**: own QR Ph page over the tunnel, code drawn by the SDK with the QR Ph logo in the centre, scanned with a real banking app, real ₱5.00 (ATSI web svc) | sample-php | `pi_01a1bf89f02a1084b4ccfafa7739` | Succeeded, ₱5.00 received (checked through the API) | Dom paid; Claude ran the sample and checked |
| **LIVE mode**: own QR Ph page over the tunnel, code drawn by the JS QR Ph code (the same drawing code since moved to `@domdanao/finfactory-qrph`), scanned with a real banking app, real ₱5.00 (ATSI web svc) | sample-js | `pi_01a1bf93dd99dfa787b66eb0f9f1` | Succeeded, ₱5.00 received (checked through the API) | Dom paid; Claude ran the sample and checked |
| Installed from the public registries into empty projects: npm `@domdanao/finfactory-payments` 0.1.0 + `@domdanao/finfactory-qrph` 0.1.0; Packagist `domdanao/finfactory-payments` v0.1.1 + `domdanao/finfactory-qrph` v0.1.0. Listed payment intents against the test API, drew a QR Ph SVG, verified a webhook signature | all four | — | Works; Composer archives hold only `src/`, README, CHANGELOG, LICENSE, `composer.json` | Claude |

Problems found on FinFactory's side:

- The API's firewall answers `403 {"message":"Forbidden"}` when `return_url` or `cancel_url`
  points at `localhost`, `127.0.0.1` or a private network address, although the docs allow
  `http` in test mode. Names ending in `.localhost` get through. The samples use
  `acacia.localhost`.
- The hosted checkout page sometimes fails to load: its server answers 500 on some of its own
  scripts and styles when the browser requests many at once. Reloading helps.
- In test mode the QR Ph payload is a placeholder (`simulated_qr...`), not a real QR Ph code,
  so a banking app can't scan it. Use the simulator.

Local setup:

- A Cloudflare quick tunnel answers 404 when `~/.cloudflared/config.yml` exists (it takes that
  file's routing rules). Start it with `cloudflared tunnel --config /dev/null --url ...`.

## Not yet run against the API

- Refunds, disputes and webhook endpoints, called from code
- Webhook signature check on a real event
