# Changelog

All notable changes to `domdanao/finfactory-qrph` are recorded here. The project follows
[Semantic Versioning](https://semver.org/).

## 0.1.0 — unreleased

First release.

- `QrPh::svg()`: draw a QR Ph code as SVG from a payload or a FinFactory payment intent, with the QR Ph
  logo in the centre, at error correction level H. Options: `logo`, `margin`, `foreground`,
  `background`, `title`.
- `logoModules()`: the size of the cleared centre square.
- Moved here from the FinFactory Payments SDK, so the SDK has no dependencies and this works
  without it.
