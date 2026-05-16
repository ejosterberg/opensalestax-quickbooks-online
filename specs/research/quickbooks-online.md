# Research — QuickBooks Online integration surface

> Notes for the captain on what QBO offers as integration points,
> assembled before locking ADR-001.

## Tax-extension surface

**There is none.** QBO does not publish a pluggable tax provider
interface analogous to WooCommerce's tax classes or Magento's
`Magento\Tax\Model\Calculation` extension points. The Accounting API
exposes tax data as objects (`TaxRate`, `TaxCode`, `TaxAgency`,
`TaxService` for sandbox-only `TaxRate` creation) but no in-process
hook lets a third party compute tax for an invoice as it is being
saved.

Intuit's own Automated Sales Tax (AST) is wired in at the application
layer (not exposed as an API third parties can implement). When AST is
ON, the merchant sees QBO compute tax automatically based on Intuit's
tax-rate database. When AST is OFF (and on every QBO invoice the
merchant edits via the API), tax is whatever the API caller writes via
the `TxnTaxDetail` field on the invoice.

**Implication:** the connector cannot be a drop-in tax provider.
It has to listen for invoice-saved events, fetch the invoice, compute
tax, and write back via the public `Invoice` update endpoint.

## Webhook surface (inbound)

Intuit supports webhook subscriptions per app via the Developer
Portal's **Webhooks** tab. Per Intuit's docs:

- Events available include `Invoice.Create`, `Invoice.Update`,
  `Invoice.Delete`, `Customer.Create`, `Customer.Update`,
  `Estimate.Create`, etc.
- Delivery is HTTPS POST to a single endpoint URL the merchant
  configures.
- **Signing:** every webhook POST carries an `intuit-signature`
  header containing a base64-encoded HMAC-SHA256 of the raw request
  body, keyed by the **Webhook Verifier Token** Intuit shows the app
  owner in the Developer Portal. Verification is constant-time
  comparison of the base64-decoded signature against the raw body's
  HMAC.
- **Retry:** Intuit retries on non-2xx responses (typically 3 retries
  with exponential backoff). 4xx and 5xx both trigger retry.

The body shape is a multi-realm batch:

```json
{
  "eventNotifications": [
    {
      "realmId": "1234567890",
      "dataChangeEvent": {
        "entities": [
          {"name": "Invoice", "id": "145", "operation": "Create",
           "lastUpdated": "2026-05-15T12:34:56.000-08:00"}
        ]
      }
    }
  ]
}
```

The webhook does NOT include the invoice payload itself — just the
entity id. The sidecar must turn around and fetch the invoice via the
QBO API (`GET /v3/company/{realmId}/invoice/{id}`).

## OAuth 2.0 dance

Intuit uses standard OAuth 2.0 authorization-code flow:

1. **Authorization request:** redirect the merchant's browser to
   `https://appcenter.intuit.com/connect/oauth2?client_id=...&response_type=code&scope=com.intuit.quickbooks.accounting&redirect_uri=...&state=...`.
   `state` is a CSRF token the sidecar generates and remembers.
2. **Authorization grant:** Intuit redirects the merchant to the
   `redirect_uri` with `?code=...&realmId=...&state=...`.
3. **Token exchange:** sidecar POSTs the code to
   `https://oauth.platform.intuit.com/oauth2/v1/tokens/bearer` with
   client_id+client_secret (Basic auth) → receives
   `access_token` (TTL 3600s) + `refresh_token` (TTL 100 days, rotates
   on every refresh).
4. **Refresh:** before any QBO API call, if the access token is within
   ~5 minutes of expiry, the SDK calls the same endpoint with
   `grant_type=refresh_token` to get a new token pair. Old refresh
   tokens are immediately invalidated.

Intuit's `quickbooks/v3-php-sdk` provides `OAuth2LoginHelper` to do
all of this.

## TxnTaxDetail schema (write-back)

To write tax onto an invoice we PUT/POST the invoice with a
`TxnTaxDetail` block:

```json
{
  "TxnTaxDetail": {
    "TotalTax": 9.03,
    "TaxLine": [
      {
        "Amount": 9.03,
        "DetailType": "TaxLineDetail",
        "TaxLineDetail": {
          "TaxRateRef": {"value": "<TaxRateId>"},
          "PercentBased": true,
          "TaxPercent": 9.025,
          "NetAmountTaxable": 100.00
        }
      }
    ]
  }
}
```

`TaxRateRef` must point to an existing `TaxRate` entity in the
company. v0.1 either:

- Uses a single OST-managed `TaxRate` entity the operator pre-creates
  (named "OpenSalesTax"), OR
- Falls back to setting `TxnTaxDetail.TotalTax` only, omitting `TaxLine`
  (acceptable for sandbox testing but loses the per-line breakdown).

The v0.1 implementation uses the second path because it lets the
sidecar work against any QBO company with no pre-setup. v0.2 can add a
"create the OpenSalesTax TaxRate on first run" CLI command.

**Important:** when modifying an existing invoice via the QBO API, the
update is "sparse" only when `sparse: true` is set in the body. Without
sparse mode, the API requires the FULL invoice payload (otherwise
fields not in the request are zeroed). The sidecar uses sparse update.

Reference:
https://developer.intuit.com/app/developer/qbo/docs/api/accounting/all-entities/invoice

## SDK choice

`quickbooks/v3-php-sdk` is Intuit's official PHP client (BSD-3-Clause
licensed, currently at version 6.x as of this research). Key entry
points:

- `DataService::Configure([...])` — session factory.
- `DataService::FindById('Invoice', $id)` — fetch invoice.
- `DataService::Update($invoice)` — write invoice (sparse if the entity's
  `sparse` property is set).
- `OAuth2LoginHelper` — auth URL construction, token exchange, refresh.

The SDK historically uses internal HTTP (curl) which is awkward to
mock. v0.1 wraps the SDK behind a thin `QboClient` class so unit tests
can stub the SDK behaviors with anonymous-class doubles.

## Why a sidecar, not an in-process integration

QBO is a hosted Intuit product. Merchants do not host QBO themselves
and cannot install third-party PHP code into Intuit's stack. The only
two integration shapes available are:

1. **Marketplace app** — multi-tenant SaaS we host, every merchant's
   data flows through us. Requires Intuit review.
2. **Sidecar** — single-tenant service the merchant runs on their own
   infra, talking to Intuit's public APIs.

Shape 2 wins on every dimension that matters to OpenSalesTax (no
merchant-data exposure to the OST author, no Intuit gatekeeping of
the connector's release cadence). See `decisions/001-sidecar-vs-app.md`.
