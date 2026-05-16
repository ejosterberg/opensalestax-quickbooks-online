# Decision 001 — Sidecar over Intuit Marketplace app

**Status:** Accepted
**Decided:** 2026-05-15
**Decider:** Eric Osterberg (delegated to captain orchestrator)

## Context

QuickBooks Online is a hosted SaaS product. Merchants don't host QBO
themselves and cannot install third-party code into Intuit's stack.
Two integration shapes are available:

- **Shape A — Intuit Marketplace app.** Build a multi-tenant SaaS we
  host. Merchants discover the app on Intuit's App Store, click
  "Get App Now," and authorize their QBO company against our hosted
  service. Every merchant's invoice events flow through our
  infrastructure. Requires Intuit's app review (initial + per major
  feature change). Requires us to maintain SLAs, security
  attestations, and a public privacy policy explaining what merchant
  data we touch.
- **Shape B — Self-hosted sidecar.** Ship a PHP package the merchant
  runs on their own infrastructure (`composer create-project`). The
  merchant registers their own private app in the Intuit Developer
  Portal, takes the OAuth client_id / client_secret, and points the
  webhook subscription at their own sidecar URL. Each merchant's
  data stays on their own infra; OpenSalesTax has no callback into
  it.

## Decision

**Ship Shape B.** Shape A is out of scope for v0.1 and likely
forever. Revisit only if a meaningful number of merchants explicitly
request a hosted offering (and even then, the sidecar should remain
the canonical distribution).

## Rationale

1. **Merchant-data sovereignty (constitution §9).** Shape A would
   put OpenSalesTax in the path of every merchant's invoice — we
   would see customer ZIPs, line item amounts, invoice numbers, and
   anything else Intuit's webhook surfaces. That's the opposite of
   the OpenSalesTax value proposition (which is precisely "tax
   calculation that doesn't require sending your books to a third
   party"). Shape B keeps merchant data on merchant infra.

2. **No Intuit review gating.** Shape A requires Intuit to approve
   each release. Shape B doesn't (the merchant's own app is private
   to them; Intuit only cares about apps in the public Marketplace).
   This means the OST team can ship features and bug fixes on its
   own cadence.

3. **No multi-tenant operational burden.** Shape A would need
   24/7 monitoring, multi-region failover, GDPR/CCPA data-
   processing agreements, SOC 2 attestation if any large merchant
   asks. Shape B has none of that — the merchant runs the sidecar
   in their own ops envelope.

4. **Architectural symmetry with the rest of the OST portfolio.**
   `opensalestax-invoice-ninja` is also a sidecar; the WooCommerce,
   Magento, OpenCart, and Bagisto connectors are in-process plugins
   inside merchant-hosted code. None of them are hosted SaaS. The
   QBO sidecar fits the "merchant-hosted" pattern even though the
   merchant doesn't host QBO itself — the merchant DOES host the
   sidecar.

5. **No platform-fee economics.** Shape A would make OpenSalesTax
   responsible for the costs of running a public service (compute,
   bandwidth, on-call). Shape B makes the merchant responsible.

## Trade-offs accepted

- **Operator runs an extra process.** Mitigated by shipping
  `bin/console webhook:listen` for dev (PHP built-in server), and a
  documented PHP-FPM example for production.
- **Operator manages OAuth credentials.** Each merchant has to
  register their own app in the Intuit Developer Portal (free,
  takes ~5 minutes). Mitigated by step-by-step README instructions.
- **No central error reporting / version-rollout signal.** We can't
  see which version is in the field or aggregate error metrics
  across deployments. Mitigated by structured stderr logging
  merchants can collect locally.
- **Inbound HTTP surface to defend.** Mitigated by HMAC signature
  verification, replay cache, rate limiter, and TLS-on-by-default
  — all exercised by unit tests and documented in `SECURITY.md`.

## Revisit triggers

- A meaningful number of merchants explicitly request a hosted
  offering AND are willing to accept the data-flow trade-off.
- Intuit publishes an in-process tax-provider SPI (currently does
  not exist; nothing in their public roadmap suggests it will).
- A named maintainer wants to operate a hosted variant and signs up
  for the on-call burden.
