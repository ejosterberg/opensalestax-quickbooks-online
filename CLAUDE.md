# CLAUDE.md — opensalestax-quickbooks-online

> Project memory for Claude sessions on the QBO sidecar. Read this AND
> `specs/constitution.md` + `specs/handoff.md` before writing code.

## Mission

Ship a free, self-hostable PHP sidecar that routes QuickBooks Online's
sales-tax calculation through an OpenSalesTax engine for
destination-based US sales tax — replacing Intuit's per-seat
Automated Sales Tax (AST) with merchant-owned infrastructure.

## Stack

- **Language:** PHP 8.2+
- **Architecture:** Standalone HTTP sidecar (Shape B). Not an Intuit
  Marketplace app, not in-process. See
  `specs/decisions/001-sidecar-vs-app.md`.
- **QBO client:** Intuit's official `quickbooks/v3-php-sdk` (the
  canonical PHP client for the QuickBooks Online Accounting API + OAuth)
- **Engine client:** `ejosterberg/opensalestax` ^0.1 (the OST PHP SDK)
- **HTTP/router:** Tiny in-house router (mirrors Invoice Ninja sidecar
  shape — Slim/Lumen would be overkill for three routes)
- **Distribution:** Packagist as
  `ejosterberg/opensalestax-quickbooks-online` (`composer create-project`)
- **License:** `Apache-2.0 OR GPL-2.0-or-later` dual
- **Tests:** PHPUnit + Guzzle MockHandler

## Architectural anchors

- **Webhook-driven sidecar.** Intuit fires `Invoice.Create` and
  `Invoice.Update` events at the sidecar; sidecar fetches the invoice
  via QBO API, recomputes tax, writes back via `TxnTaxDetail`.
- **OAuth 2.0 authorization-code flow.** One-time per merchant; tokens
  persisted encrypted at rest. Refresh-token rotation handled by the
  Intuit SDK on every API call.
- **USD-only / US-only.** Non-USD `CurrencyRef` or non-US `BillAddr`/
  `ShipAddr` short-circuit with a `204`.
- **Fail-soft default.** Engine error → log + leave invoice untouched.
  `OSTAX_FAIL_HARD=1` flips to `500` (Intuit retries).
- **Calculation only.** No filing, no remittance, no exemption
  certificates.
- **Inbound surface defended:** HMAC verification, replay window,
  per-IP rate limiter, SSRF guard on outbound, TLS-on-by-default,
  secrets redacted in logs.

## File layout

```
opensalestax-quickbooks-online/
├── CLAUDE.md                      # this file
├── README.md                      # user-facing
├── LICENSE                        # dual-license declaration
├── LICENSE-APACHE.txt
├── LICENSE-GPL.txt
├── CONTRIBUTING.md                # DCO sign-off mandatory
├── SECURITY.md
├── CHANGELOG.md
├── composer.json
├── phpstan.neon
├── phpunit.xml.dist
├── phpcs.xml
├── .env.example
├── .github/workflows/ci.yml
├── bin/
│   ├── console                    # CLI entry: oauth:setup, webhook:listen, tax:recalc
│   └── sidecar.php                # HTTP entry script (php -S / php-fpm)
├── specs/
│   ├── constitution.md
│   ├── current-state.md
│   ├── handoff.md
│   ├── research/
│   │   └── quickbooks-online.md
│   └── decisions/
│       └── 001-sidecar-vs-app.md
├── src/
│   ├── Config/                    # env-var loader
│   ├── Http/                      # framework-agnostic Request/Response
│   ├── Logging/                   # stderr JSON logger w/ redaction
│   ├── Sdk/                       # OST EngineGateway
│   ├── Security/                  # SignatureVerifier, ReplayCache, RateLimiter, UrlValidator
│   ├── Service/                   # WebhookHandler, Bootstrap, OAuthCallbackHandler
│   ├── Oauth/                     # TokenStore, OAuthFlow
│   └── QuickBooksOnline/          # QboClient, InvoicePayload, InvoicePayloadBuilder, TaxApplier
└── tests/Unit/                    # mirror of src/, plus TestSupport/
```

## What NOT to do

- Don't build an Intuit Marketplace app. The captain's
  ADR-001 locks in the sidecar shape.
- Don't ship a copy of the OST engine — point at the merchant's
  instance via `OST_ENGINE_URL`.
- Don't accept commits without DCO sign-off (`-s` flag).
- Don't add AI co-author trailers.
- Don't bypass `--no-verify` or `--no-gpg-sign` on commits.
- Don't log raw tokens, signatures, or `client_secret` — the redaction
  list in `StderrLogger` blocks them.
- Don't stash refresh tokens unencrypted on disk.
- Don't read or modify QBO objects other than `Invoice` in v0.1
  (estimates, sales receipts, customers — out of scope).

## Releasing

- Semver tags `vX.Y.Z` on the single `main` branch.
- GitHub release on each tag.
- Publish to Packagist via the captain's Safe API token after every tag
  push (per the global Packagist auto-refresh playbook in
  `~/.claude/proxmox-playbook.md` style — actually documented in
  `opensalestax-Odoo/portfolio/policy.md`).

## Sibling-project map

- `opensalestax-invoice-ninja` — closest sibling (also PHP webhook
  sidecar). Architecture borrowed from there.
- `opensalestax-php` — the engine SDK we depend on.
- `opensalestax-woocommerce` — same dual-license shape (Apache + GPL).
- See `opensalestax-Odoo/portfolio/state.md` for the full portfolio map.
