# Constitution — opensalestax-quickbooks-online

Non-negotiable principles for this connector. Inherits the
[parent constitution](https://github.com/ejosterberg/open-sales-tax-integrations)
and adds connector-specific clauses.

## §1. Dual license

The connector ships under `Apache-2.0 OR GPL-2.0-or-later` (recipient
picks). DCO sign-off on every commit. No AI co-author trailers.

## §2. Sidecar pattern, not a Marketplace app

The integration uses Intuit's public surfaces only: the QuickBooks
Online REST API (outbound) and the Webhooks subscriber list (inbound).
The sidecar is a separate process the merchant runs on their own
infrastructure. **We do not ship a hosted multi-tenant service that
sees merchant data.** Rationale in `decisions/001-sidecar-vs-app.md`.

## §3. Calculation only

The sidecar calculates and writes tax-rate metadata back to invoices.
It never files returns and never remits. The merchant remits.

## §4. US + USD only

The sidecar's gates reject non-US destinations and non-USD currencies
with a structured 204. Other currencies / countries are an explicit
non-goal until the engine supports them.

## §5. Fail-soft by default

If the engine is unreachable, the sidecar returns 200 with
`applied: false, reason: engine_unavailable` and leaves the invoice
untouched. We never block invoice creation on engine availability.
Operators who prefer Intuit retry semantics can set `OSTAX_FAIL_HARD=1`
to escalate engine errors to a `500` (which Intuit will retry per
their webhook delivery policy).

## §6. Security primitives (cannot be downgraded without a recorded exception)

- HMAC-SHA256 signature verification on every inbound Intuit webhook
  per the `intuit-signature` header spec, constant-time compared.
- OAuth `state` parameter verified on the callback (CSRF defense).
- Refresh tokens stored encrypted at rest using libsodium
  (`sodium_crypto_secretbox`) with a 32-byte secret key.
- TLS verification ON by default on outbound calls.
- SSRF validator runs on every outbound URL.
- Per-source-IP rate limit on inbound endpoint.
- No secrets or PII in logs; redaction list enforced by `StderrLogger`.

Each is exercised by at least one unit test.

## §7. Test coverage minimum

Connector v0.1 ships with ≥30 unit tests. Each security primitive in
§6 has ≥1 dedicated test. PHPStan max + PHPCS PSR-12 + `composer audit`
clean are release-blocking.

## §8. SDK boundary

The sidecar depends only on:

- `ejosterberg/opensalestax` ^0.1 for engine calls
- `quickbooks/v3-php-sdk` for Intuit OAuth + Accounting API

It does not import OpenSalesTax engine internals. The two SDKs are
the only contracts.

## §9. Merchant-data sovereignty

The sidecar runs on the merchant's own infrastructure. The
OpenSalesTax author and the OpenSalesTax engine see only the
calculation request payload (destination ZIP + line subtotals).
They never see customer identities, invoice numbers, or any other
merchant data. This is the moral basis of the sidecar pattern and
it cannot be diluted.

## §10. Disclaimer text (constitution-required)

Every webhook response that surfaces tax output contains:

> Tax calculations are provided as-is for convenience. The merchant
> is solely responsible for tax-collection accuracy and remittance to
> the appropriate jurisdictions. Verify against your state Department
> of Revenue before remitting.

The README repeats the same text.
