# Current state — opensalestax-quickbooks-online

> Updated 2026-05-15 at v0.1.0-alpha.1 release.

## Shipped

- **v0.1.0-alpha.1** — first public alpha. Sidecar architecture
  (Shape B). 38 unit tests. PHPStan max clean. PHPCS PSR-12 clean.
  `composer audit` clean. Not yet validated against a live Intuit
  sandbox company — that is the captain follow-up to graduate to
  v0.1.0 stable.
  - Inbound: `POST /webhooks/quickbooks-online` with HMAC-SHA256
    signature verification per Intuit's `intuit-signature` spec.
  - Inbound: `GET /oauth/callback` for the Intuit OAuth flow.
  - Outbound: QBO Accounting API via `quickbooks/v3-php-sdk` (read +
    update Invoice).
  - Outbound: OST `/v1/calculate` via `ejosterberg/opensalestax` ^0.1.
  - Token store: encrypted JSON file (`sodium_crypto_secretbox`).
  - CLI: `bin/console oauth:setup` / `webhook:listen` / `tax:recalc`.

## In progress

None — release branch.

## Open / deferred (next-release candidates)

- Live Intuit sandbox round-trip: provision a sandbox company in
  Intuit Developer Portal, run the OAuth flow against it, fire a
  test `Invoice.Create` event, verify the tax line lands. Captain
  follow-up — needs Eric's Intuit Developer Portal access.
- Per-jurisdiction tax breakdown via multiple `TaxLine` rows on the
  `TxnTaxDetail` (currently single weighted-average rate).
- `Estimate` and `SalesReceipt` events (currently only `Invoice`).
- Database / Redis-backed token store (currently file only).
- Multi-replica replay / rate-limit state (currently per-process
  in-memory).
- Refund / credit-memo tax handling.
- Trusted-proxy list for `X-Forwarded-For` so per-IP rate limit works
  behind a reverse proxy.
- Dependabot config + per-PHP-version build matrix expansion if PHP 8.5
  ships before v0.2.

## Pending publishing

- Packagist registration of the new package. The captain's Safe API
  token cannot create new packages — needs Eric's one-time submission
  via the Packagist UI after the v0.1.0-alpha.1 tag lands. After that,
  every subsequent tag push auto-refreshes via the Safe token.

## Sibling-project map

- `opensalestax-invoice-ninja` — closest sibling (also PHP webhook
  sidecar). Architecture borrowed from there.
- `opensalestax-php` — the engine SDK we depend on.
- `opensalestax-woocommerce` — same dual-license shape.
- `opensalestax-bagisto` / `opensalestax-opencart` — other sibling
  PHP-tier connectors.
- See `opensalestax-Odoo/portfolio/state.md` for the full portfolio.
