# OpenSalesTax for QuickBooks Online

> **v0.1.0-alpha.1.** Installable via `composer create-project`; passes
> 38 unit tests; PHPStan max + PSR-12 + `composer audit` clean. Live
> Intuit-sandbox round-trip is a captain follow-up — see `specs/handoff.md`.

A free, self-hostable **webhook sidecar** that adds destination-based US
sales tax to [QuickBooks Online](https://quickbooks.intuit.com/online/)
invoices via the [OpenSalesTax engine](https://github.com/ejosterberg/opensalestax).

This is the OpenSalesTax replacement for Intuit's per-seat
**Automated Sales Tax (AST)** — the same feature, run on the merchant's
own infrastructure with the merchant's own data.

## How it works (sidecar model)

```
+---------------+   1. webhook /webhooks/quickbooks-online    +-----------+
| QuickBooks    |  --------------------------------------->   |  Sidecar  |
| Online        |                                             |   (this)  |
| (Intuit)      |   3. POST /v3/company/{id}/invoice          |           |
|               |  <----------------------------------------  |           |
+---------------+                                             +-----------+
                                                                    |
                                                  2. POST /v1/calculate
                                                                    v
                                                              +-----------+
                                                              | OpenSales |
                                                              | Tax engine|
                                                              +-----------+
```

1. QuickBooks Online fires a webhook (`Invoice.Create` or
   `Invoice.Update`) at the sidecar.
2. The sidecar fetches the invoice via the QBO API, extracts the
   destination ZIP and line items, and calls the OpenSalesTax engine for
   a tax rate.
3. The sidecar writes the tax line back to the invoice via the QBO
   API (`TxnTaxDetail`).

The whole loop completes in well under a second. If anything goes wrong
(engine unreachable, malformed payload, non-US destination) the sidecar
**fails soft** — the invoice is left untouched and the operator sees a
structured log line, rather than the customer seeing a broken invoice.

## Why a sidecar (not an Intuit Marketplace app)?

QuickBooks Online does not expose an in-process tax-extension surface
like WooCommerce's pluggable tax classes. The supported integration
surfaces are the QBO REST API and Intuit's webhook subscriber list. An
Intuit Marketplace app would require Intuit's review process plus a
hosted multi-tenant service that sees every merchant's invoices. The
sidecar pattern uses only the public API surfaces, runs entirely on the
merchant's own infrastructure, and never has the OpenSalesTax author
see merchant data. See `specs/decisions/001-sidecar-vs-app.md` for the
full architectural decision record.

## What this sidecar does NOT do

- File or remit tax (calculation only — the merchant remits)
- Validate exemption certificates
- Handle non-USD currencies or non-US destinations (returns 204, leaves
  the invoice alone)
- Validate addresses
- Ship with the engine bundled — point it at your own
  [OpenSalesTax engine](https://github.com/ejosterberg/opensalestax)

## Disclaimer

> Tax calculations are provided as-is for convenience. The merchant is
> solely responsible for tax-collection accuracy and remittance to the
> appropriate jurisdictions. Verify against your state Department of
> Revenue before remitting.

## Compatibility matrix

| Component | Tested | Notes |
|---|---|---|
| QuickBooks Online API | v3 | Tracks Intuit's `/v3/company/{realm}/...` API. |
| OpenSalesTax engine | v0.1.x | Tracks the engine's `/v1/calculate` endpoint via the PHP SDK. |
| PHP | 8.2, 8.3, 8.4 | CI matrix. |
| OS | Linux | Tested on Debian 13. Should run on any POSIX with PHP-FPM. |

## Prerequisites

Before deploying the sidecar into production, the merchant operator
needs to have these in place:

- **An Intuit Developer account** with a registered app (the source
  of `QBO_CLIENT_ID` / `QBO_CLIENT_SECRET` / `QBO_WEBHOOK_VERIFIER_TOKEN`).
- **A running OpenSalesTax engine** the merchant operates themselves.
  This sidecar does NOT include the engine. See the
  [OpenSalesTax engine repo](https://github.com/ejosterberg/opensalestax).
- **Comprehensive business insurance** covering professional liability,
  cyber liability, general liability, and (if applicable) product
  liability. This is required by Intuit Developer ToS §20.4 of any
  Developer integrating with the Intuit Developer Platform. Coverage
  must be maintained during the deployment and for **3 years
  thereafter**. The merchant is the "Developer" per Intuit's terms,
  so this is the merchant's obligation, not the OpenSalesTax
  project's. See [`specs/operations/insurance-prereq.md`](specs/operations/insurance-prereq.md)
  for full guidance, cost ranges, and the pre-deployment checklist.
- **A documented incident-response process.** Intuit ToS §13.4
  requires Security Incident notice to Intuit within **24 hours of
  discovery**, with risk-classified remediation SLAs running 7 days
  (Immediate) to 1 year (Low). The runbook in
  [`specs/operations/incident-response.md`](specs/operations/incident-response.md)
  covers what to do; adopt it as your own or use it as a template.

Sandbox / development deployments can skip the insurance and IR
requirements, but anything that handles real merchant data needs
them in place before go-live.

## Install

```bash
composer create-project ejosterberg/opensalestax-quickbooks-online /opt/ost-qbo-sidecar
cd /opt/ost-qbo-sidecar
mkdir -p var
php -r "echo base64_encode(random_bytes(32)), \"\n\";"   # paste into QBO_TOKEN_ENCRYPTION_KEY
cp .env.example .env
# edit .env with your values
```

## Configure (env vars)

| Var | Required | Default | Purpose |
|---|---|---|---|
| `OST_ENGINE_URL` | yes | — | Base URL of your OpenSalesTax engine |
| `OST_API_KEY` | no | — | Bearer token for the engine, if required |
| `OST_TIMEOUT_SECONDS` | no | `10` | Outbound HTTP timeout, range `(0, 60]` |
| `QBO_CLIENT_ID` | yes | — | Intuit OAuth client ID (Developer Portal → Keys & OAuth) |
| `QBO_CLIENT_SECRET` | yes | — | Intuit OAuth client secret |
| `QBO_REDIRECT_URI` | yes | — | OAuth redirect URI registered with Intuit |
| `QBO_ENVIRONMENT` | yes | `sandbox` | `sandbox` or `production` |
| `QBO_WEBHOOK_VERIFIER_TOKEN` | yes | — | Intuit webhook verifier token |
| `QBO_TOKEN_STORE_PATH` | no | `./var/qbo-tokens.json` | Path to the encrypted token JSON file |
| `QBO_TOKEN_ENCRYPTION_KEY` | yes | — | base64-encoded 32-byte key for at-rest encryption |
| `SIDECAR_ALLOW_PRIVATE_NETWORKS` | no | `1` | Allow RFC1918 destinations (same-VM deployment). Set `0` if exposed to the public internet. |
| `SIDECAR_TLS_VERIFY` | no | `1` | TLS peer-verify on outbound calls |
| `SIDECAR_RATE_LIMIT_PER_MINUTE` | no | `120` | Per-source-IP rate limit on the inbound webhook endpoint |
| `SIDECAR_REPLAY_WINDOW_SECONDS` | no | `300` | Max age of a webhook before it's rejected as replay |
| `OSTAX_FAIL_HARD` | no | `0` | If `1`, return 500 on engine error so Intuit retries; default leaves invoice untouched |

## Run

For local development:

```bash
bin/console webhook:listen
# starts PHP -S 0.0.0.0:8181 with bin/sidecar.php as the entry script
```

For production, behind nginx + PHP-FPM. The sidecar exposes:

- `GET /health` — health probe, returns `{"status":"ok",...}`
- `POST /webhooks/quickbooks-online` — Intuit's webhook callback
- `GET /oauth/callback` — Intuit OAuth redirect URI

## Authorize the sidecar against your QBO company

Run the OAuth dance once per company:

```bash
bin/console oauth:setup
# 1. Opens https://appcenter.intuit.com/connect/oauth2?... — visit this URL,
#    pick the company, click Authorize.
# 2. Intuit redirects back to /oauth/callback with `code` + `realmId`.
# 3. The sidecar exchanges the code for access + refresh tokens, encrypts
#    them, and persists to QBO_TOKEN_STORE_PATH.
```

After that, `bin/console webhook:listen` (or your prod nginx) is ready
to handle invoice events.

## Wire up the QBO webhook subscription

In the [Intuit Developer Portal](https://developer.intuit.com), open
your app → **Webhooks** tab:

- Endpoint URL: `https://your-sidecar-host/webhooks/quickbooks-online`
- Events: `Invoice.Create`, `Invoice.Update`
- Save the **Verifier Token** Intuit shows you and put it in
  `QBO_WEBHOOK_VERIFIER_TOKEN`.

Intuit signs every webhook POST with that token using HMAC-SHA256
(base64 in the `intuit-signature` header). The sidecar rejects any
request whose signature does not verify.

## Manually recompute one invoice

```bash
bin/console tax:recalc 145
# fetches QBO invoice 145, rebuilds payload, calls engine, writes back
```

Useful for backfilling historical invoices that pre-date the sidecar.

## Operating notes

- **The sidecar never sees the merchant's customers.** It only fetches
  invoices it's told about by Intuit's webhook events, computes tax,
  and writes back. Customer / contact tables are not read.
- **The OpenSalesTax author never sees merchant data.** The sidecar is
  the merchant's own process; OST has no callback into it.
- **Your tokens stay on your disk.** The encrypted JSON store lives at
  `QBO_TOKEN_STORE_PATH`. Back it up with the rest of `/var/`.

## Data handling

This section is the user-facing disclosure required by Intuit
Developer ToS §12.2(iii). The detailed operator-facing version lives
in [`specs/security/data-handling.md`](specs/security/data-handling.md).

**Where data is processed.** The sidecar processes invoice metadata
and customer addresses entirely on **the merchant's own
infrastructure**. Data is never processed on Intuit's infrastructure
on the merchant's behalf, and never on OpenSalesTax-hosted
infrastructure — the OpenSalesTax project does not operate a hosted
service that handles merchant data.

**Roles per Intuit ToS §12.4.** The merchant operator and Intuit are
each independent data controllers; neither processes Personal
Information on the other's behalf. The sidecar (the code in this
repository) is a tool the merchant uses to exercise their own
processing decisions — it does not change the merchant's controller
status, and the OpenSalesTax project (the entity that wrote the code)
is neither a controller nor a processor for any merchant deployment.

**What is retained at rest.** Only two things:

- **OAuth tokens**, encrypted with libsodium
  (`sodium_crypto_secretbox` / XSalsa20-Poly1305), in a JSON file
  the merchant chooses the path of (`QBO_TOKEN_STORE_PATH`).
- **A short-lived replay-defense cache** of recent webhook
  signatures, in-memory only, wiped on every process restart.

No invoice contents, no customer PII, no transaction history is
persisted to the sidecar's disk.

**Data flow.**

```
+--------------------+   1. webhook (event metadata only)   +-------------------+
|  QuickBooks Online | -----------------------------------> |  Sidecar          |
|  (Intuit-hosted)   |   4. POST writeback (TxnTaxDetail)   |  (merchant infra) |
|                    | <----------------------------------- |                   |
+--------------------+                                      +---------+---------+
                                       2. GET invoice (full record)   |
                            <-------------------------------------+   |
                                                                  |   |
                                                                  v   v
                                                              +-------------------+
                                                              |  QBO API          |
                                                              |  (Intuit-hosted)  |
                                                              +-------------------+

                                       3. POST /v1/calculate
                                       (ZIP + line subtotals only)
                                                          +
                                                          v
                                                  +-------------------+
                                                  |  OST engine       |
                                                  |  (merchant infra) |
                                                  +-------------------+
```

**What crosses each hop.**

1. **QBO → sidecar (webhook)**: event metadata only — `realmId`,
   invoice ID, `Invoice.Create` or `Invoice.Update`, timestamp,
   `intuit-signature` HMAC. No invoice content.
2. **Sidecar → QBO API (read)**: OAuth token + `realmId` +
   `invoiceId`. Response includes the full invoice; held in memory,
   not persisted.
3. **Sidecar → OST engine**: only the destination ZIP/state, line
   subtotals, and currency — the minimum the engine needs to compute
   a rate. Customer name, email, billing address street, invoice
   number, line descriptions are stripped before the call.
4. **Sidecar → QBO API (write)**: OAuth token + `realmId` +
   `invoiceId` + `TxnTaxDetail` (the computed tax line). No PII
   echoed back.

**The OpenSalesTax project sees none of this.** There is no
telemetry, no callback, no upload of any kind from the sidecar to
the project maintainer.

## Incident response

If you discover a Security Incident affecting a sidecar deployment
(token compromise, encryption-key leak, host compromise, etc.),
Intuit Developer ToS §13.4 requires you to notify Intuit within
**24 hours of discovery**. ToS §13.5 establishes risk-classified
remediation SLAs running 7 days (Immediate) to 1 year (Low).

The full runbook is in
[`specs/operations/incident-response.md`](specs/operations/incident-response.md).
It covers what counts as an incident, the 24-hour notification
timeline, a Security Incident Notice template, the risk-classification
decision matrix with sidecar-specific examples, per-incident-type
containment playbooks, a notification log template, and an annual
security-drill recommendation.

For vulnerabilities in the sidecar code itself (as opposed to
operational incidents in a deployment), see [`SECURITY.md`](SECURITY.md)
for the coordinated-disclosure process.

## License

Dual-licensed: `Apache-2.0 OR GPL-2.0-or-later`. See `LICENSE`.
