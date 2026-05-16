# Security Policy

## Reporting a vulnerability

Email **ejosterberg@gmail.com** with subject line starting
`[opensalestax-quickbooks-online] security:`. Include the affected
version, reproduction steps, and impact. Do not open a public
GitHub issue for security reports.

Acknowledgement target: 7 days. For critical issues
(tax-correctness, signature-bypass, OAuth-token leakage, or
unauthenticated writeback), mark `[critical]` in the subject line
and expect a faster turnaround.

## Supported versions

The latest minor on `main` is supported. Older releases are not
back-patched.

## Scope

This policy covers the OpenSalesTax sidecar for QuickBooks Online
(`ejosterberg/opensalestax-quickbooks-online`). Vulnerabilities in
upstream Intuit QuickBooks Online, the OpenSalesTax engine, or
merchant infrastructure should be reported to their respective
maintainers.

## Threat surface

The sidecar exposes:

- An inbound HTTPS webhook receiver for Intuit QBO events.
- An inbound OAuth callback URL (`/oauth/callback`).
- Outbound HTTPS to Intuit's QuickBooks Online API.
- Outbound HTTPS to the merchant's OpenSalesTax engine.
- A local at-rest token store (default: encrypted JSON file).

Defenses (each is exercised by at least one unit test):

- HMAC-SHA256 signature verification on inbound webhooks per
  Intuit's `intuit-signature` header spec, constant-time compared.
- OAuth state token verification on the callback to prevent CSRF.
- Refresh-token at-rest encryption via libsodium
  (`sodium_crypto_secretbox`).
- SSRF guard on outbound URLs (engine + Intuit API).
- Per-source-IP rate limiter on the inbound endpoint.
- TLS verification ON by default.
- Secrets redacted from logs (`Authorization`, `intuit-signature`,
  `client_secret`, `refresh_token`, etc.).
