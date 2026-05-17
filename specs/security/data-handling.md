# Data handling — opensalestax-quickbooks-online

> Operator-facing companion to the README's "Data handling" section.
> If you're a merchant deciding whether to deploy this sidecar, or an
> auditor reviewing it on a merchant's behalf, read this whole
> document. It maps every piece of data the sidecar touches to the
> system that holds it, and clarifies the roles each party plays
> under the Intuit Developer Terms of Service §12.

## Why this document exists

The Intuit Developer Terms of Service §12.2(iii) requires any
Developer Application accessing User Data to provide a prominent,
accurate disclosure that clarifies the Developer is **not processing
User Data or Personal Information on Intuit's behalf**. This document
is that disclosure for `opensalestax-quickbooks-online`, written for
the merchant operator running the sidecar in production.

Per ToS §12.4, the merchant operator and Intuit are each independent
data controllers; neither is processing on behalf of the other. The
OpenSalesTax project's role is narrower still — it ships only the
code; it never runs the sidecar and never has access to any data the
sidecar touches.

## Three independent parties

The sidecar deployment involves three legal/technical entities, each
with a distinct role:

| Party | Role | What they see |
|---|---|---|
| **The merchant** (you) | Independent data controller per ToS §12.4. Runs the sidecar process on their own infrastructure. The "Developer" in Intuit ToS terminology. | Everything in their QBO company; everything the sidecar logs; the encrypted OAuth token store. |
| **Intuit** | Independent data controller per ToS §12.4. Hosts QuickBooks Online and delivers webhook notifications. | The merchant's QBO data (they host it); webhook delivery metadata. |
| **OpenSalesTax project** (maintainer) | Open-source project distributing the sidecar code. **Not a service provider, not a sub-processor, not a data controller** for any merchant deployment. | Nothing. The maintainer never sees a single byte of merchant data. |

The merchant's outbound calls go to **the merchant's own OST engine**
— see [Engine self-hosting](#engine-self-hosting) below. The
OpenSalesTax project does not operate a hosted engine that processes
merchant data.

## Data flow diagram

```
+--------------------+     1. Invoice.Create / Invoice.Update     +-------------------+
|  QuickBooks Online | ----------------------------------------> |  Sidecar          |
|  (Intuit-hosted)   |     webhook POST, HMAC-SHA256-signed       |  (merchant infra) |
|                    | <---------------------------------------- |                   |
|                    |     4. Invoice update with TxnTaxDetail    |                   |
+--------------------+     POST /v3/company/{realm}/invoice       +---------+---------+
                                                                            |
                                                  2. Invoice fetch          |
                                                  GET /v3/company/{realm}/  |
                                                  invoice/{id}              |
                                                                            v
                                                                  +-------------------+
                                                                  |  QBO API          |
                                                                  |  (Intuit-hosted)  |
                                                                  +-------------------+

                                                  3. Calculate request
                                                  POST /v1/calculate
                                                          +
                                                          v
                                                  +-------------------+
                                                  |  OST engine       |
                                                  |  (merchant infra) |
                                                  +-------------------+
```

The whole loop is the merchant talking to two systems they trust
(Intuit + their own engine). The OpenSalesTax project — the entity
that wrote the code — is not on any arrow.

## What data crosses each hop

Each hop in the diagram is documented below with the minimum payload
fields that flow across it. Anything not listed does not cross.

### Hop 1: QuickBooks Online → Sidecar (webhook)

| Field | Source | Purpose | PII? |
|---|---|---|---|
| `realmId` | Intuit | Identifies the merchant's QBO company | No (opaque company ID) |
| `name` (event type) | Intuit | Always `Invoice` for this sidecar | No |
| `operation` | Intuit | `Create` or `Update` | No |
| `id` (invoice ID) | Intuit | Opaque QBO invoice ID | No (opaque) |
| `lastUpdated` | Intuit | Event timestamp | No |
| `intuit-signature` HTTP header | Intuit | HMAC-SHA256 over the body, verified before any further processing | No |

Webhook body is a thin notification. No invoice contents, no customer
data, no line items.

### Hop 2: Sidecar → QBO API (invoice fetch)

| Field | Source | Purpose | PII? |
|---|---|---|---|
| OAuth bearer token | Sidecar token store | Authenticates the merchant's API call to their own QBO data | No (auth credential) |
| `realmId` | From hop 1 | Routes the API call to the right company | No |
| `invoiceId` | From hop 1 | Identifies the invoice to read | No |

Response (from QBO to sidecar) contains the full invoice — line items,
amounts, customer reference, `BillAddr` / `ShipAddr`, `CurrencyRef`.
The sidecar **reads** this in-memory; it does not persist it.

### Hop 3: Sidecar → OST engine (calculate request)

| Field | Source | Purpose | PII? |
|---|---|---|---|
| Destination ZIP / state | Invoice `ShipAddr` (or `BillAddr` if no ship-to) | Engine input | Postal code only; no street, no name |
| Line subtotals | Invoice line items | Engine input | No |
| Currency | Invoice `CurrencyRef` | Engine routing | No |
| Engine API key (`OST_API_KEY`) | Merchant config | Auth, if engine requires it | No |

The sidecar deliberately strips the rest of the invoice before
calling the engine. Customer name, email, phone, invoice number,
billing address street, line descriptions — none of these are sent
to the engine. The engine receives the minimum needed to compute a
rate: where the goods are shipping, how much each line is worth.

### Hop 4: Sidecar → QBO API (writeback)

| Field | Source | Purpose | PII? |
|---|---|---|---|
| OAuth bearer token | Sidecar token store | Auth | No (auth credential) |
| `realmId`, `invoiceId` | From hop 1 | Routes the update | No |
| `TxnTaxDetail` | Computed from hop 3 | The tax line(s) to attach to the invoice | No (rate + dollar amounts) |
| Idempotency hash | Sidecar | Replay defense | No |

The writeback is a partial update — only `TxnTaxDetail` is sent. The
sidecar does not echo customer fields, line descriptions, or other
PII back to QBO; it adds tax metadata to data Intuit already holds.

## What the sidecar stores at rest

Two files on the merchant's disk. That's the entire persistent
state.

### `var/qbo-tokens.json` (or wherever `QBO_TOKEN_STORE_PATH` points)

- OAuth access token, refresh token, expiry timestamps, `realmId`.
- Encrypted with libsodium `sodium_crypto_secretbox` (XSalsa20-Poly1305)
  using the 32-byte key in `QBO_TOKEN_ENCRYPTION_KEY`.
- No invoice data, no customer data.
- Back this file up like any other secrets file. If the box is lost,
  the tokens can be regenerated by re-running `oauth:setup`.

### In-process replay-defense cache

- Recent webhook signatures + receipt timestamps.
- In-memory only (lost on restart — that's fine; the replay window
  is `SIDECAR_REPLAY_WINDOW_SECONDS`, default 300s, so a fresh
  process can't be tricked with stale captures older than that).
- Wiped on every process restart.
- Not persisted to disk.

### What is NOT stored

- Invoice contents — not stored.
- Customer names, emails, phone numbers — not stored.
- Transaction history — not stored.
- Line item descriptions — not stored.
- Shipping addresses — not stored. (Used in-memory for the engine
  call, then discarded.)
- Tax calculation results — not stored. (Sent back to QBO; QBO holds
  the canonical record.)

If the sidecar's host dies, the only data lost is the OAuth token
store, which can be regenerated. No tax history is lost — Intuit
holds it.

## Engine self-hosting

The sidecar requires `OST_ENGINE_URL` to be set. This is the URL of
**the merchant's own** OpenSalesTax engine instance. Common
deployment patterns:

- **Same VM**: engine and sidecar run side-by-side on one host. The
  engine listens on `127.0.0.1:8080`; the sidecar calls
  `http://127.0.0.1:8080`. `SIDECAR_ALLOW_PRIVATE_NETWORKS=1`
  (default) permits this.
- **Internal network**: engine runs on a separate VM inside the
  merchant's VPC; sidecar calls it over an RFC1918 address.
- **Public engine** (less common): merchant runs the engine on a
  public hostname with TLS. Set `SIDECAR_TLS_VERIFY=1` (default) and
  `SIDECAR_ALLOW_PRIVATE_NETWORKS=0`.

The OpenSalesTax project does **not** operate a hosted engine. There
is no `https://api.opensalestax.com` to point this at. If you do not
operate your own engine, this sidecar will not function — that is
intentional. See [opensalestax engine repo](https://github.com/ejosterberg/opensalestax)
for the engine itself.

## Roles under Intuit ToS §12.4 — independent data controllers

ToS §12.4 establishes that the Developer (merchant operator) and
Intuit are independent data controllers. Each party determines, on
its own, the purposes and means of processing Personal Information
it touches. Neither is acting on the other's behalf.

For this sidecar:

- **The merchant decides** what to send to the engine (a subset of
  invoice data — ZIP, line subtotals, currency). The merchant runs
  the sidecar configuration; the merchant controls the engine
  endpoint; the merchant retains the encrypted token store.
- **Intuit decides** what data it provides via webhooks and the QBO
  API. Intuit is the controller of the data it hosts.
- **OpenSalesTax (the project)** is neither a controller nor a
  processor. It is a code distributor. The merchant chooses to
  deploy the code; the merchant operates it.

If a regulator or User exercises a data-subject right (access,
deletion, rectification) under GDPR / CCPA / similar, the request
goes to **the merchant** for data the merchant holds (the encrypted
token file; the in-memory replay cache, which auto-expires anyway),
and to **Intuit** for data Intuit holds (the invoice itself). The
OpenSalesTax project cannot service such requests because it has no
data to act on.

## What the sidecar logs

Per the project constitution §6 ("No secrets or PII in logs;
redaction list enforced by `StderrLogger`"), the sidecar log output
contains:

- Webhook event types + opaque IDs (`Invoice.Create`, invoice ID).
- Engine call latencies + HTTP status codes.
- QBO API call latencies + HTTP status codes.
- Errors (with stack traces; redaction list strips bearer tokens
  and HMAC secrets).

It does **not** log:

- Invoice contents.
- Customer names, emails, phone numbers, addresses.
- OAuth tokens (access or refresh).
- Webhook bodies (only the verified event metadata).
- Engine request/response bodies in full (only summary metrics).

Operators who want body-level logging for debugging can enable it
temporarily, but the default ships off and we don't recommend
turning it on against production traffic.

## Data-subject deletion requests (GDPR Art. 17 / CCPA §1798.105)

If a User exercises a deletion right that touches the sidecar:

1. The data the User is requesting deletion of is held by **Intuit**
   (the invoice) or by **the merchant's other systems** (the
   customer record in their CRM, etc.) — not by the sidecar.
2. The sidecar holds no PII to delete. The encrypted token store is
   tied to the merchant's QBO company, not to any individual User.
3. The merchant should rotate the OAuth tokens and the
   `QBO_TOKEN_ENCRYPTION_KEY` as good hygiene if a deletion event
   is large in scope (e.g., a class of users), but this is not
   strictly required to comply with the request.

## Updating this document

If the sidecar starts handling new data classes (e.g., adding
`Estimate` or `SalesReceipt` events per the v0.2 roadmap), update
this document **in the same release** that introduces the new
handling. The disclosure must remain accurate at all times.

## Cross-references

- README "Data handling" section — short version for end users.
- `specs/constitution.md` §9 (merchant-data sovereignty) — the
  principle this document operationalizes.
- `specs/operations/incident-response.md` — what to do if any of
  the above data is accessed without authorization.
