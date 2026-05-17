# Insurance prerequisite — opensalestax-quickbooks-online

> Compliance baseline: Intuit Developer ToS §20.4.
> Audience: the merchant operator who runs the sidecar in production.

## The obligation in one paragraph

Anyone who deploys an application against Intuit's Developer Platform
agrees to maintain comprehensive insurance covering **professional
liability**, **cyber liability**, **general liability**, and (if
applicable) **product liability**. The coverage must address claims
related to errors, cyber threats, data security, bodily injury,
property damage, and product-related damages. Per Intuit's own
wording, the obligation runs not only during your deployment but for
"three (3) consecutive years thereafter" — a tail that survives
termination.

Paraphrased from Intuit Developer ToS §20.4. The single direct
quotation above is the term Intuit uses for the post-termination
window; the rest of the obligation is restated in plain language for
operator readability.

## Who this applies to

This obligation applies to whoever is the **Developer** in Intuit's
sense — the entity whose Intuit Developer account hosts the
registered app (the one with the `QBO_CLIENT_ID` / `QBO_CLIENT_SECRET`).
For this sidecar's deployment model, that is **the merchant**, not
the OpenSalesTax project.

Because the sidecar is self-hosted on the merchant's infrastructure:

- The **merchant** is the Developer per Intuit ToS. The merchant
  registered the app in the Intuit Developer Portal. The merchant
  accepted the ToS during enrollment.
- The **OpenSalesTax project** ships code. It does not run any
  Intuit-integrated app, does not hold Intuit credentials, and is
  not a Developer in the ToS sense for any production deployment.

Therefore the insurance obligation is **the merchant's**, on a
per-deployment basis. Every self-hosting merchant carries it
independently. There is no umbrella policy from the OpenSalesTax
project that covers downstream merchants — and even if there were,
it would not satisfy Intuit's per-Developer requirement.

## Coverage types and why each matters

| Coverage | Why Intuit requires it | What a typical sidecar incident triggers |
|---|---|---|
| **Professional liability** (a.k.a. Errors & Omissions / E&O) | Covers claims arising from professional mistakes in the service you provide. | Wrong tax calculated → customer over- or under-charged → customer sues. |
| **Cyber liability** | Covers claims from data breaches, ransomware, unauthorized access, regulatory response. | OAuth token compromise leading to QBO data exposure → notification costs, regulatory fines, credit monitoring. |
| **General liability** | Covers third-party bodily injury and property damage claims. | Less common for a pure SaaS sidecar, but Intuit requires it as a backstop. |
| **Product liability** ("if applicable") | Covers claims that a product caused harm. | Applies if the sidecar is sold or distributed as a product to third parties. Less relevant for a self-hosted internal tool; more relevant if you're a consultancy deploying it for clients. |

For a typical merchant running the sidecar to compute their own
sales tax in their own QBO company, the practical day-to-day risk
is concentrated in **professional liability** and **cyber liability**.
General and product liability are required by the ToS but rarely
the policies that pay out for sidecar-related incidents.

## Cost guidance

These are ranges, not quotes. Talk to a broker.

- **Cyber liability** for a small business handling moderate
  volumes of customer financial data: typically **$1,000 to $3,000
  per year** for $1M of coverage. Higher if revenue or record
  count is large; lower if you can prove robust controls (MFA
  everywhere, encryption at rest, documented IR plan — the very
  things this sidecar's `specs/` already documents).
- **Professional liability / E&O** for a small e-commerce or
  bookkeeping operation: similar range, **$1,000 to $2,500 per
  year** for $1M of coverage.
- **General liability**: often included with a Business Owner's
  Policy (BOP), commonly $500 to $1,500 per year for a small
  business.
- **Product liability**: included with general liability in most
  small-business policies; specialized only if you ship physical
  goods or distribute software at scale.

A bundled BOP + cyber package from a single carrier is the most
common shape for a small merchant. Budget **$2,500 to $5,000 per
year all-in** for a small operation; scale up with revenue and data
volume.

These numbers are illustrative and US-market-centric as of late 2026.
They are not professional advice. Per ToS §20.5, Intuit does not
provide professional advice and neither does this document. **Consult
an independent insurance broker** licensed in your jurisdiction
before binding any policy. A broker can:

- Identify gaps between your existing business policies and the
  specific Intuit ToS §20.4 requirements.
- Get quotes from multiple carriers (price varies 2-3x for the same
  coverage depending on carrier appetite).
- Advise on the right per-claim and aggregate limits given your
  revenue and customer count.
- Flag specific carriers that have written claims against breaches
  involving QBO data — useful experience signal.

## The 3-year tail

ToS §20.4's "three consecutive years thereafter" is **not** the
remaining life of whichever insurance policy you bought. It is a
separate, distinct, post-termination clock that begins running on
the day you stop using the Intuit Developer Platform — i.e., the
day you uninstall the sidecar, revoke the OAuth tokens, and
disconnect the app from QBO.

Practical implications:

- You cannot let coverage lapse the day you uninstall. The tail
  obligation continues independently of any single policy term.
- Most cyber and E&O policies offer an "extended reporting period"
  (ERP) or "tail" endorsement. Buy one when the underlying policy
  doesn't auto-renew. Typical ERP cost: 100-200% of the annual
  premium for 1 year of tail; 200-300% for 3 years.
- If you switch carriers mid-deployment, ensure the new policy is
  written on a **retroactive date** that covers the period the old
  policy did. Otherwise you have a gap that the new carrier will
  not cover for acts occurring before the new policy's effective
  date.
- Document the start of the tail clock in your incident-response
  log when you eventually disconnect. The clock can run for 3
  years; you need to know when it ends.

The tail exists because data breach claims often surface long after
the breach itself — average detection-to-disclosure time is in the
months-to-years range across industries. Intuit (and any
sophisticated counterparty) wants assurance that liability coverage
will be available even if you've moved on by the time a claim is
filed.

## Why this matters even for "I'm just self-hosting for my own
small business" deployments

Tempting interpretation: "I'm a one-person shop running this on a
small VM to compute my own tax. I don't need enterprise insurance."

Counter-arguments:

1. **Indemnification is uncapped on your side.** ToS §16 obligates
   the Developer to defend and indemnify Intuit against a broad
   sweep of claims — third-party IP infringement, Security
   Incidents, breaches of the agreement, violations of Applicable
   Law. There is no cap on your indemnification obligation. Intuit's
   own liability to you, by contrast, is capped at $500 (ToS §17).
   The asymmetry is the entire reason §20.4 exists: Intuit wants you
   to have coverage that can actually pay if the indemnification is
   ever triggered.

2. **A QBO data breach is a regulated event.** State data-breach
   notification laws (all 50 US states have one), CCPA / CPRA in
   California, GDPR if you have any EU customers — each can impose
   notification costs, regulatory fines, and class-action exposure.
   Without cyber coverage, those costs come out of your own funds.
   $1M of cyber coverage costs roughly 1/10th of one small-state
   breach-notification campaign.

3. **The ToS makes coverage mandatory regardless of company size.**
   §20.4 does not have a small-business exemption. Operating without
   coverage is a breach of the agreement, which exposes you to
   termination under §18.3(i) and further liability under §16.

4. **You don't get to negotiate after an incident.** Insurance is
   purchased before the loss event, not after. Once you've had an
   incident, your premiums spike (or you become uninsurable for the
   relevant coverages).

## What this document does NOT do

- It does not name specific carriers, brokers, or policy products.
  Carrier appetites change; recommendations would go stale. Use a
  broker.
- It does not provide legal advice. The ToS interpretation in this
  document is the operator-facing read of §20.4. A lawyer should
  validate it for your specific deployment.
- It does not constitute a binding compliance attestation. You are
  responsible for your own compliance posture; this document is
  documentation, not a certificate.
- It does not extend any insurance coverage from the OpenSalesTax
  project to the merchant. The project carries no insurance on
  behalf of downstream deployments — see the dual-license
  disclaimer of warranties in `LICENSE`.

## Pre-deployment checklist

Before going live with the sidecar in production:

- [ ] You hold an active professional liability / E&O policy with
      coverage limits appropriate for your revenue.
- [ ] You hold an active cyber liability policy with coverage for
      data-breach response (notification costs, regulatory response,
      forensics, credit monitoring).
- [ ] You hold an active general liability policy (often part of a
      BOP).
- [ ] If you distribute or resell the sidecar (e.g., a consultancy
      deploying it for clients), you also hold product liability
      coverage.
- [ ] Your policy renewal dates are calendared with a 30-day buffer
      so you don't go bare during a renewal gap.
- [ ] You have a plan for the 3-year tail when you eventually
      decommission the sidecar (ERP endorsement, claims-made
      policy with appropriate retroactive dates, or successor
      policies).
- [ ] Your broker has reviewed your policies against Intuit ToS
      §20.4 specifically — not against a generic small-business
      checklist.

## Cross-references

- README "Prerequisites" section — short version of this for
  end-users surveying whether to adopt the sidecar.
- `specs/operations/incident-response.md` — what triggers a claim;
  what your insurance is for; how to coordinate notice with
  Intuit when you're also notifying your carrier.
- `specs/security/data-handling.md` — what data the sidecar
  handles; useful when filling out cyber-liability applications,
  which usually ask about data types and volumes.
- `specs/handoff.md` — pre-graduation blocker tracker. The
  v0.1.0-stable graduation flags this insurance prereq as
  merchant-level table-stakes.
