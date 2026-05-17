# Handoff — opensalestax-quickbooks-online

> Updated 2026-05-17 at v0.1.0-alpha.2 release.

## Pick up here

1. **Live Intuit sandbox round-trip.** v0.1.0-alpha.1 ships passing
   38 unit tests, but no live invoice has actually round-tripped
   through a real QBO company yet. To close the loop:
   1. In the [Intuit Developer Portal](https://developer.intuit.com),
      create a sandbox app under Eric's account; copy
      `Client ID`, `Client Secret`, and the **Webhook Verifier Token**
      into a `.env` file on a test VM.
   2. Provision a test VM via the Proxmox playbook (range 900-999),
      install PHP 8.2, install the sidecar via `composer create-project`,
      and start it under the built-in dev server (`bin/console webhook:listen`).
   3. Tunnel the VM's port 8181 to a public hostname (ngrok, cloudflared,
      etc.) so Intuit's webhook delivery can reach it.
   4. Run `bin/console oauth:setup`, complete the OAuth dance against
      Intuit's sandbox company, confirm tokens land in
      `var/qbo-tokens.json`.
   5. In the Developer Portal, configure the Webhooks tab to point at
      the tunneled URL with `Invoice.Create` + `Invoice.Update` events.
   6. Create an invoice in the sandbox company UI with a US ZIP +
      USD currency, watch the sidecar log the event arrival, the
      engine call, and the writeback. Confirm in the QBO sandbox UI
      that the invoice now has an OpenSalesTax `TxnTaxDetail`.
   7. Document the test VM (VMID, IP, OAuth client ID, sandbox company
      realm ID) in this handoff and graduate to v0.1.0 stable.

2. **Packagist new-package submission.** The captain's Safe API token
   can refresh existing packages but cannot CREATE new ones. After the
   v0.1.0-alpha.1 tag lands on GitHub, Eric needs to:
   - Visit https://packagist.org/packages/submit
   - Paste `https://github.com/ejosterberg/opensalestax-quickbooks-online`
   - Click Submit
   - Confirm the package appears at
     https://packagist.org/packages/ejosterberg/opensalestax-quickbooks-online
   After that, future tag pushes auto-refresh via the captain's Safe
   token per `opensalestax-Odoo/portfolio/policy.md` "Packagist
   auto-refresh" section.

3. **Hub repo connector matrix.** Add v0.1.0-alpha.1 row to the hub
   repo's "OpenSalesTax connectors" table. Note: alpha — pending live
   sandbox validation.

## Pre-graduation blockers for v0.1.0-stable

Items that must be in place before the alpha can graduate to stable.
Tracked here so future captain sessions don't accidentally promote
an incomplete release.

1. **Live Intuit sandbox round-trip** (item 1 above). Functional gate.
2. **Insurance prerequisite called out in upgrade docs.** Intuit ToS
   §20.4 obligates the merchant (the "Developer" under Intuit's
   terms) to maintain professional / cyber / general / product
   liability insurance during the deployment and for **3 years
   after** termination. The merchant-facing documentation in
   `specs/operations/insurance-prereq.md` covers the obligation in
   depth and the README "Prerequisites" section now flags it before
   Install. When writing v0.1.0-stable upgrade notes / release blog
   / hub-repo entry, repeat the insurance-prereq pointer prominently
   — merchants should not be surprised by this at deploy time.
   Documentation gate (already met by v0.1.0-alpha.2); ensure the
   stable release notes carry the same callout.
3. **Incident-response runbook visibility.** `specs/operations/
   incident-response.md` exists as of v0.1.0-alpha.2. v0.1.0-stable
   release notes should explicitly point merchants at it; the
   24-hour Intuit-notification clock under ToS §13.4 is a surprise
   the first time you hit it. Documentation gate (met); just need
   the visibility in stable release notes.
4. **Data-handling disclosure visibility.** `specs/security/
   data-handling.md` + the README "Data handling" section satisfy
   ToS §12.2(iii) as of v0.1.0-alpha.2. Documentation gate (met);
   keep the disclosure accurate as new event types (`Estimate`,
   `SalesReceipt`, `CreditMemo`) get added in v0.2.

## v0.2 priorities (rough ordering)

1. Per-jurisdiction `TaxLine` breakdown on `TxnTaxDetail` (QBO supports
   multiple `TaxLine` rows per `TxnTaxDetail` — v0.1 collapses to a
   single weighted-average rate).
2. Database / Redis-backed token store (alternative to JSON file for
   merchants who want central token management).
3. `Estimate` + `SalesReceipt` event handling (mirrors `Invoice`).
4. Refund / credit-memo (`CreditMemo`) tax handling.
5. Multi-replica replay + rate-limit state (Redis backing) — matches
   the v0.2 deferred work in opensalestax-invoice-ninja.
6. Trusted-proxy list for `X-Forwarded-For`.
7. Category mapping (QBO `Item.SalesTaxCodeRef` → engine categories).

## Decisions

- **Decision 1 (ADR-001) — Sidecar over Marketplace app.** Decided
  2026-05-15. See `specs/decisions/001-sidecar-vs-app.md`. Rationale:
  Intuit Marketplace apps require Intuit's review process plus a
  hosted multi-tenant service that would see every merchant's invoices.
  The sidecar pattern uses only the public webhook + REST API surfaces
  and keeps merchant data on merchant infrastructure.

## Captain follow-ups (deferred from v0.1.0-alpha.1)

- Live Intuit sandbox round-trip (item 1 above)
- Packagist new-package submission (item 2 above) — needs Eric's UI work
- Hub repo connector matrix update (item 3 above)
- Decide whether to auto-publish to Packagist on tag push (after the
  package exists on Packagist, the auto-refresh playbook applies)

## Open items for Eric

- Submit `ejosterberg/opensalestax-quickbooks-online` to Packagist via
  https://packagist.org/packages/submit (one-time; subsequent refreshes
  are autonomous).
- (Optional) Provision an Intuit Developer Portal sandbox app pair so
  the captain can run the live sandbox round-trip without further Eric
  involvement.

## Known limitations carried into v0.2 release notes

- Live sandbox round-trip not yet performed (alpha → stable graduation
  blocker).
- Single tax rate per invoice (no per-jurisdiction breakdown).
- File-based token store only.
- Single-process replay / rate-limit cache (multi-replica needs Redis).
- Only `Invoice` events handled.

## What did NOT change in v0.1 (intentionally)

- No engine internals imported. SDK is the contract.
- No filing / remittance.
- No non-USD support.
- No exemption-certificate validation.
- No customer-record reads (only invoices).
