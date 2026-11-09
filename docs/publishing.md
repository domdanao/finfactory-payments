# Publishing

How to release the SDKs, `@domdanao/finfactory-payments` to npm and
`domdanao/finfactory-payments` to Packagist, and the QR Ph packages, `@domdanao/finfactory-qrph`
and `domdanao/finfactory-qrph`.

## Before the first release: decisions and accounts

| | npm | Packagist |
| --- | --- | --- |
| Name | `@domdanao/finfactory-payments` | `domdanao/finfactory-payments` |
| Who owns the name | The `@domdanao` scope is the npm user `domdanao`: `npm login` as that user. Scoped packages are private by default; `publishConfig` already makes this one public. | The `domdanao` **vendor** is claimed by the first package submitted under it: submit while logged in to Packagist with the `domdanao` GitHub account. |
| Account security | Turn on 2FA for every owner. Prefer **trusted publishing** from GitHub Actions (no long-lived token), or an automation token stored as `NPM_TOKEN`. | Log in with GitHub; let Packagist install its GitHub webhook so tags publish automatically. |
| Source repository | Can be a subfolder of this repository. | **Must be the root of a Git repository.** Packagist reads `composer.json` from the repository root, so the `php/` folder is pushed to its own repository (see below). |

Check, then fill in before publishing:

- [x] The names above. (The PHP namespace stays `FinFactory\Payments`, named for the API.)
- [x] The license: **MIT**.
- [x] Create the repositories the metadata points at: `github.com/domdanao/finfactory-payments`
      and the PHP mirror `github.com/domdanao/finfactory-payments-php` (or change the URLs in
      `js/package.json` and `php/composer.json`). npm provenance requires `repository` to match the
      repository that publishes.
- [ ] The default API host, `https://api.payments.finfactory.dev`. Platforms that run their own host pass
      `baseUrl` / `base_url`.

## Repository layout

This repository holds both SDKs:

```text
js/       → npm: @domdanao/finfactory-payments
php/      → mirrored to its own repository (e.g. github.com/domdanao/finfactory-payments-php) → Packagist
qrph-js/  → npm: @domdanao/finfactory-qrph
qrph-php/ → mirrored to github.com/domdanao/finfactory-qrph-php → Packagist
spec/    → the OpenAPI snapshot both were built from
docs/    → this file and the SDK design contract
```

The alternative is one repository per SDK from the start. It costs nothing at Packagist and keeps
each SDK's issues and releases apart, at the price of keeping shared docs and the spec in sync by
hand. Either works; the release workflow here assumes the monorepo plus a PHP mirror.

## Releasing JavaScript

1. Update `version` in `js/package.json` and `VERSION` in `js/src/client.ts`, and the changelog.
2. Check what will be published:

   ```sh
   cd js && npm test && npm run typecheck && npm pack --dry-run
   ```

   The tarball holds only `dist/`, `README.md`, `CHANGELOG.md`, `LICENSE` and `package.json`.
3. Commit, then tag and push: `git tag js-v0.1.0 && git push origin js-v0.1.0`.
4. `.github/workflows/release.yml` tests and runs `npm publish --access public --provenance`.

By hand instead: `cd js && npm publish --access public` (with 2FA).

## Releasing PHP

1. Update `VERSION` in `php/src/FinFactory.php`, and the changelog. (Composer takes the version
   from the Git tag, not from `composer.json`.)
2. Check:

   ```sh
   cd php && composer validate --strict && vendor/bin/phpunit
   ```

3. Commit, then tag and push: `git tag php-v0.1.0 && git push origin php-v0.1.0`.
4. The release workflow pushes `php/` to the mirror repository (`vars.PHP_SPLIT_REPO`, with a
   token in `secrets.PHP_SPLIT_TOKEN`) and tags it `v0.1.0`. Packagist sees the tag through its
   webhook.

First time only: create the mirror repository, run step 4 once, then submit its URL at
<https://packagist.org/packages/submit>.

The mirror already has a `v0.1.0` tag, pushed by hand for private use before any Packagist
release. Step 4 can't push a tag that already exists, so the first Packagist release should be
`php-v0.1.1` or later if `php/` has changed since then. If it hasn't changed, submit the mirror
to Packagist without tagging.

`.gitattributes` keeps `tests/`, `examples/` and tooling out of the archive Composer downloads.

## Releasing the QR Ph packages

They are versioned on their own, independently of the SDKs.

- **JavaScript:** update `version` in `qrph-js/package.json` and `VERSION` in
  `qrph-js/src/index.ts`, and the changelog. Tag `qrph-js-v0.1.0`; the release workflow publishes
  it to npm.
- **PHP:** update `VERSION` in `qrph-php/src/QrPh.php`, and the changelog. Tag
  `qrph-php-v0.1.0`; the release workflow pushes `qrph-php/` to the mirror in
  `vars.QRPH_PHP_SPLIT_REPO` (e.g. `domdanao/finfactory-qrph-php`, with the same
  `secrets.PHP_SPLIT_TOKEN`) and tags it `v0.1.0`.

First time only: create the mirror repository `github.com/domdanao/finfactory-qrph-php`, set
`QRPH_PHP_SPLIT_REPO`, then submit the mirror to Packagist.

## First releases (2026-11-09)

Published by hand from a Mac, not by the release workflow, so without npm provenance:
npm `@domdanao/finfactory-payments` 0.1.0 and `@domdanao/finfactory-qrph` 0.1.0; Packagist
`domdanao/finfactory-payments` v0.1.1 and `domdanao/finfactory-qrph` v0.1.0, from the public
mirrors, which have the Packagist webhook. To release through the workflow later, add
`NPM_TOKEN` (or set up npm trusted publishing), `PHP_SPLIT_TOKEN` and the `PHP_SPLIT_REPO` /
`QRPH_PHP_SPLIT_REPO` variables.

## After a release

- Install it in a clean project and run the quickstart example against a test key.
- Watch the `spec-drift` CI job: it fails when the published OpenAPI document changes, which is
  the signal to add the change to both SDKs.
