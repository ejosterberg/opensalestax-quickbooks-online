# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [0.1.0-alpha.4] — 2026-05-19

### Changed

- **CP-8 Phase 5D: bumped `ejosterberg/opensalestax` constraint to `^0.2.0`.**
  Picks up the new `OpenSalesTax\Client::capabilities()` /
  `OpenSalesTax\Client::capabilitiesCached()` helpers for engine v0.59.0's
  `/v1/capabilities` endpoint. No merchant-visible behavior change in
  this release — the helper is available to sidecar code but not yet
  wired into any feature path. Constraint bump only; Test Connection
  surface enrichment deferred to v-next.

## [0.1.0-alpha.3] — 2026-05-19

### Added

- **`bin/console health:check` CLI subcommand (CP-4).** New command-line
  equivalent of the admin "Test Connection" button shipped in WooCom v0.5
  / Vendure v1.3 / Saleor v1.0. The QBO sidecar has no admin UI (it's a
  headless webhook listener), so the CLI is the right surface here:
  ```
  $ bin/console health:check
  ✓ Engine v0.59.0 reachable — status=ok database=connected (RTT 41 ms)
  ```
  Reads the same `OST_ENGINE_URL` + `OST_API_KEY` + `OST_TIMEOUT_SECONDS`
  + SIDECAR_ALLOW_PRIVATE_NETWORKS env vars the sidecar uses at runtime,
  so a successful health check guarantees the same auth + URL path the
  webhook handler will use. Exit codes: 0 (reachable), 1 (config error —
  missing/invalid env var), 2 (engine unreachable). Catches typo'd
  engine URLs at deploy time rather than at first webhook delivery.
  Wired via:
  - `Cli\HealthCheckCommand` — pure command class (testable in
    isolation via Guzzle MockHandler; respects the same SSRF URL
    validator the webhook handler uses).
  - `bin/console health:check` — fourth subcommand on the existing CLI
    router (alongside `oauth:setup` / `webhook:listen` / `tax:recalc`).
  - 5 unit tests exercising healthy / non-200 / transport-error /
    URL-rejected / db-disconnected shapes.

## [0.1.0-alpha.2] — 2026-05-17

### Added

- **Data-handling disclosure (QBO-1, Intuit ToS §12.2(iii) compliance).** New `README.md` Data handling section + `specs/security/data-handling.md` explicitly clarify that the sidecar acts as an independent data controller; merchant data flows merchant → sidecar → engine without OpenSalesTax-hosted infrastructure ever processing it.
- **Incident-response runbook (QBO-2, Intuit ToS §13.4–13.5 compliance).** New `specs/operations/incident-response.md` covers the 24-hour Intuit notification window, risk classification (Immediate/High/Medium/Low SLAs), per-incident-type remediation playbooks, and annual drill guidance.
- **Insurance prerequisite documentation (QBO-3, Intuit ToS §20.4 compliance).** New `specs/operations/insurance-prereq.md` flags the merchant's obligation to maintain professional/cyber/general/product liability insurance during the deployment Term + 3 years after. README Prerequisites section updated.

### Notes

- All three additions are documentation only. No runtime / functional code changes.

## [0.1.0-alpha.1] — 2026-05-15

First public release of the OpenSalesTax sidecar for QuickBooks Online.

### Added

- Sidecar HTTP service exposing `GET /health`, `POST /webhooks/quickbooks-online`,
  and `GET /oauth/callback`.
- HMAC-SHA256 signature verification on inbound Intuit webhooks per the
  Intuit `intuit-signature` header spec, constant-time compared against the
  `QBO_WEBHOOK_VERIFIER_TOKEN`.
- OAuth 2.0 authorization-code flow against Intuit's QuickBooks Online API,
  using the official `quickbooks/v3-php-sdk` (`OAuth2LoginHelper`).
- At-rest encryption of OAuth refresh tokens via `sodium_crypto_secretbox`
  (XSalsa20-Poly1305) with a base64-encoded 32-byte key (`QBO_TOKEN_ENCRYPTION_KEY`).
- Token persistence to a JSON file (`QBO_TOKEN_STORE_PATH`, default
  `./var/qbo-tokens.json`); merchant can swap the store implementation by
  injecting a different `TokenStore` if they want a database backend.
- Auto-refresh of expiring access tokens on every QBO API call via the SDK's
  `OAuth2LoginHelper::refreshToken()` path.
- Webhook event router: `Invoice.Create` and `Invoice.Update` events fetch
  the invoice via QBO API, recompute tax via the OST engine, and write back
  via `IPPInvoice` `TxnTaxDetail`.
- US-only / USD-only gates: the sidecar inspects the invoice's
  `BillAddr.CountryCode` (preferring `ShipAddr` when present) plus the
  `CurrencyRef.value`. Out-of-scope invoices are left untouched and a
  `204` is returned.
- Fail-soft default: any engine error or QBO API error logs at error level
  and leaves the invoice untouched. Set `OSTAX_FAIL_HARD=1` to escalate to
  a `500` response (Intuit will retry).
- `bin/console` CLI with three subcommands:
  - `oauth:setup` — interactive walkthrough of the Intuit OAuth dance,
    prints the authorization URL and exchanges the returned code for tokens.
  - `webhook:listen` — runs the sidecar via PHP's built-in dev server on
    `0.0.0.0:8181` for local testing.
  - `tax:recalc <invoice_id>` — manually recompute tax on a single invoice.
- SSRF defense on outbound URLs (engine + Intuit API) with
  `SIDECAR_ALLOW_PRIVATE_NETWORKS=0` opt-in for hostile network deployments.
- Per-source-IP rate limiter (token bucket, 120 req/min default).
- Stderr JSON logger with redaction of `Authorization`, `intuit-signature`,
  `client_secret`, `refresh_token`, and similar.
- 38 PHPUnit unit tests covering: config, SSRF defense, webhook signature
  verification (constant-time + replay), token store encryption, payload
  builder (US/USD gate, ZIP extraction, line item mapping), tax applier,
  OAuth flow URL construction + token-exchange + refresh, engine gateway
  fail-soft, webhook router, and the full pipeline.

### Architecture decisions

- **ADR-001:** Sidecar (PHP webhook service the merchant runs on their own
  infra) over an Intuit Marketplace app (would require Intuit review + a
  hosted multi-tenant service). See
  `specs/decisions/001-sidecar-vs-app.md`.

### Known limitations (deferred to v0.2)

- Single tax line per invoice (no per-jurisdiction breakdown — QBO supports
  multiple `TaxLine` entries; v0.1 ships a single weighted-average rate).
- Manual reconciliation only — no scheduled re-fetch of historical invoices.
- Single-process replay/rate-limit cache (in-memory; multi-replica needs
  Redis backing).
- No live Intuit sandbox integration test in CI; sandbox round-trip is a
  captain follow-up.
- Token store is JSON-file only; database/Redis backends are a v0.2 concern.
- `Estimate` and `SalesReceipt` events are not yet handled (only `Invoice`).

### Security

- HMAC verification, replay window, SSRF guard, rate limit, TLS-on-by-default,
  refresh-token at-rest encryption, secrets redacted in logs — each exercised
  by at least one unit test.

[Unreleased]: https://github.com/ejosterberg/opensalestax-quickbooks-online/compare/v0.1.0-alpha.1...HEAD
[0.1.0-alpha.1]: https://github.com/ejosterberg/opensalestax-quickbooks-online/releases/tag/v0.1.0-alpha.1
