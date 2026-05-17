# Incident-response runbook — opensalestax-quickbooks-online

> Operator-facing runbook for handling security incidents in a
> production sidecar deployment. Compliance baseline: Intuit
> Developer ToS §13.4–13.6.

This runbook is for the **merchant operator** running the sidecar in
production. The OpenSalesTax project does not operate any sidecar
deployment and cannot respond to incidents on a merchant's behalf —
incident response is the merchant's responsibility under ToS §13.

If you are a maintainer who learns of a vulnerability in the
sidecar code itself (as opposed to a deployed instance), see
`SECURITY.md` for the coordinated-disclosure process. This runbook
covers operational incidents in a running deployment.

## What counts as a Security Incident

Intuit's ToS §21 defines a Security Incident broadly. Paraphrasing
the relevant pieces in plain language: it covers any event that
compromises — or is reasonably likely to compromise — the
confidentiality, availability, or integrity of systems involved in
delivering your Developer Application, **plus** any actual or
suspected leak, theft, loss, unauthorized disclosure, alteration,
ransomware, or misuse of User Data accessed through Intuit's
services. Routine background noise (port scans, failed login
attempts, unsuccessful intrusion probes) is explicitly excluded.

For this sidecar specifically, the following events meet the bar:

| Event | Why it qualifies |
|---|---|
| OAuth refresh token compromise (e.g., `var/qbo-tokens.json` exfiltrated, key leaked) | Attacker can act as the merchant against QBO until tokens are revoked. Unauthorized access to User Data. |
| `QBO_TOKEN_ENCRYPTION_KEY` leaked (in logs, git, backups, environment dump) | Same effect as token compromise — encryption is bypassed. |
| Webhook replay attack succeeds (replay-window check defeated, replayed event triggered a writeback) | Integrity compromise; an attacker influenced merchant data. |
| Sidecar host compromise (RCE, container escape, supply-chain attack landing in `vendor/`) | Confidentiality + integrity compromise across the deployment. |
| Mock-engine compromise (if a non-production engine was wired into prod by accident) | Wrong tax data written to invoices; integrity compromise. |
| Unauthorized config change (someone edits `.env` and points the sidecar at a hostile engine or changes the verifier token) | Integrity compromise; possibly confidentiality if outbound traffic redirected. |
| Sidecar deployment of unreviewed code (CI bypassed, manual `composer require` of a typosquatted package, dependency confusion) | Integrity compromise; possibly confidentiality. |
| QBO API responses showing data that shouldn't be accessible (e.g., wrong company's invoice surfaced through a tenancy bug) | Confidentiality compromise even if root cause is on Intuit's side — still report. |
| Backup of `var/qbo-tokens.json` exposed (S3 bucket public, etc.) | Same as direct token compromise. |

If you're unsure whether something qualifies, **err on the side of
notifying**. Intuit's stance is that under-reporting is worse than
over-reporting, and the 24-hour clock starts at discovery, not at
the point where you've finished classifying.

## The 24-hour notification timeline

Per ToS §13.4, you must notify Intuit no later than **24 hours after
discovery** of a Security Incident. "Discovery" means the point at
which any of your personnel has reason to believe an Incident has
occurred — not the point at which you've confirmed it.

Recommended timeline:

| Time from discovery | Action |
|---|---|
| T+0 | Discovery. Note the exact time (UTC); start the clock. |
| T+0 to T+1h | Contain (see [Containment per incident type](#containment-per-incident-type)). Do not spend more than an hour on containment before sending the initial notification — Intuit prefers a partial early notice over a complete late one. |
| T+1h to T+4h | Draft initial Security Incident Notice using the template below. Fill in what you know; mark unknowns as "under investigation." |
| T+4h to T+24h | Send the Security Incident Notice to Intuit's security contact (the email address Intuit provided in your Developer account; if missing, the address on `https://security.intuit.com`). Log the send time. |
| T+24h | **Hard deadline.** Notice must be sent by this point regardless of how complete the investigation is. |
| T+24h onward | Continue investigation, send follow-up notices as new facts emerge, remediate per the SLA timeline in [Risk classification](#risk-classification-decision-matrix). |

### Security Incident Notice template

Copy this into your incident-response toolkit and fill in the
bracketed fields before sending.

```
To: <intuit-security-contact>
From: <merchant operator security contact>
Subject: Security Incident Notice — <merchant company name> —
         <YYYY-MM-DD HH:MM UTC discovery>

Per Intuit Developer Terms of Service §13.4, this is notice of a
Security Incident affecting our Developer Application
(<app name as registered in Intuit Developer Portal>,
client ID <QBO_CLIENT_ID prefix>).

Discovery: <YYYY-MM-DD HH:MM UTC>
Reporter: <name, role, contact>
Notification sent: <YYYY-MM-DD HH:MM UTC>

1. Description of the Security Incident
   <Plain-language summary. What happened, what we know, what we
   don't yet know.>

2. Data subject to the Incident
   <List each data class. Use this checklist as a starting point:
   - Intuit Content: [yes/no/unknown]
   - User Data (QBO invoice contents): [yes/no/unknown]
   - Personal Information (customer name/email/address from QBO):
     [yes/no/unknown]
   - EU Personal Data (any EU data subjects affected): [yes/no/unknown]
   - OAuth tokens / API credentials: [yes/no/unknown]
   - Encryption keys: [yes/no/unknown]
   For each "yes", state the volume — number of records, number of
   affected QBO companies, date range.>

3. Affected Intuit identifiers
   <List affected realmIds. If volume is too large for a list, give
   a count + criteria.>

4. Timeline
   - Earliest evidence of compromise: <UTC timestamp or "unknown">
   - Discovery: <UTC timestamp>
   - Containment actions taken so far: <bullet list with timestamps>

5. Containment status
   <What has already been done. Common items:
   - OAuth tokens revoked at <time>?
   - QBO_TOKEN_ENCRYPTION_KEY rotated at <time>?
   - Webhook subscription paused at <time>?
   - Affected hosts taken offline at <time>?
   - Forensic image taken at <time>?>

6. Preliminary risk classification
   <One of: Immediate / High / Medium / Low — see ToS §13.5. Note
   that Intuit reserves the right to override this classification.>

7. Planned remediation
   <Bullet list of actions planned + estimated completion times,
   aligned to the SLA in §13.5.>

8. Cooperation
   We will continue investigating and provide follow-up notices as
   new facts emerge. We will cooperate fully with any Intuit
   investigation per ToS §13.6.

Contact for follow-up:
   Name: <name>
   Email: <email, monitored 24/7>
   Phone: <number, monitored 24/7>
   Time zone: <TZ>
```

**Do not** delay sending in pursuit of completeness. Send what you
have at T+20h at the latest; iterate with follow-up notices.

**Do not** make external public statements (press release, blog
post, X/Twitter, customer email) before coordinating with Intuit
per ToS §13.6 — Intuit may want to coordinate the User-facing
notification.

## Risk classification decision matrix

ToS §13.5 establishes four classifications with SLAs counted from
date of discovery:

| Classification | SLA | What it means |
|---|---|---|
| Immediate | 7 days to remediate | Active, ongoing compromise. Confirmed data loss / unauthorized access to User Data. Customer-facing impact. |
| High | 30 days to remediate | Confirmed vulnerability with no confirmed exploit. Or limited exploit with no customer-facing impact. Or potential exposure of credentials with no confirmed misuse. |
| Medium | 90 days to remediate | Misconfiguration or non-exploitable weakness in a security control. No data exposure. |
| Low | 1 year to remediate | Defense-in-depth weakness, documentation gap, missing monitoring control. No User Data implications. |

Intuit reserves the right to reclassify in its sole discretion. The
classification on your initial notice is **preliminary** — expect
Intuit to weigh in.

### Classification examples for sidecar incident types

| Incident | Default classification | Rationale |
|---|---|---|
| OAuth refresh token exfiltrated, confirmed misuse against QBO API | Immediate | Active unauthorized access to User Data. |
| OAuth refresh token exfiltrated, no evidence of misuse yet | Immediate | Confirmed credential loss; misuse window is open until rotation. |
| `QBO_TOKEN_ENCRYPTION_KEY` leaked in a git commit (still in history, not yet rotated) | Immediate | Effectively equivalent to token exfiltration once an attacker pulls the repo. |
| `QBO_TOKEN_ENCRYPTION_KEY` leaked but encrypted token file is on an isolated host the attacker cannot reach | High | Defense layer still intact, but assume not for long. |
| Webhook replay attack succeeds (replayed event triggered a writeback) | High | Integrity compromise; sidecar wrote tax data based on an event it should have rejected. Data is recoverable but trust in the pipeline is broken. |
| Webhook replay attempt detected and rejected (replay-window did its job) | Low or "not an incident" | This is the control working as designed. Log it; if the attempt is part of a larger pattern, escalate to High. |
| Mock engine accidentally pointed at by production sidecar (wrong `OST_ENGINE_URL`) | High | Integrity compromise — wrong tax was written. Recoverable via `bin/console tax:recalc`, but the merchant has remitted based on bad data possibly. |
| Sidecar host compromised (RCE) | Immediate | Confidentiality + integrity compromise across the whole deployment. Assume tokens and keys exfiltrated. |
| Container/VM compromised but sidecar process didn't have write access to token store | Immediate | Still treat as worst-case until forensics confirm scope. Don't downgrade based on initial scans. |
| Unauthorized config change (someone edited `.env` and rebooted) | Depends — High if the change introduced a vulnerability; Medium if it was reverted before any data flowed; Immediate if the change pointed outbound traffic at an attacker-controlled URL | Classify on impact, not on the change itself. |
| CI bypassed for a sidecar code deployment (unreviewed code in prod) | High by default; Immediate if the unreviewed code is confirmed malicious | Treat as supply-chain incident. |
| Outdated dependency with a known CVE landed in `vendor/` and is exploitable in the sidecar's attack surface | High | Window of exposure is roughly the time since the CVE was published. |
| Outdated dependency with a CVE that does not apply to the sidecar's usage | Low | Document with a `composer audit` suppression entry referencing the analysis. |

When in doubt, classify one level higher than feels right. The
remediation SLAs are deadlines, not targets — finishing early is
fine.

## Containment per incident type

What to do in the first hour for each common incident class.

### OAuth token compromise (any cause)

1. **Revoke at Intuit.** Open https://developer.intuit.com → your
   app → Connected accounts → revoke for the affected `realmId`(s).
   This is the single most important action. Until it's done, the
   attacker can call the QBO API as the merchant.
2. **Stop the sidecar.** `systemctl stop ost-qbo-sidecar` (or
   equivalent). The token file is now useless; stopping the process
   prevents accidental re-authorization.
3. **Move the token file out of place.** `mv var/qbo-tokens.json
   var/qbo-tokens.json.compromised.$(date +%s)` — preserves
   forensic evidence; prevents accidental reuse.
4. **Rotate `QBO_TOKEN_ENCRYPTION_KEY`.** Generate a fresh 32-byte
   key (`php -r "echo base64_encode(random_bytes(32)), \"\n\";"`),
   update `.env`. Old key is now an IOC for forensics.
5. **Re-authorize from scratch.** Once Intuit has acknowledged the
   incident notice and you're cleared to bring the sidecar back up,
   run `bin/console oauth:setup` to mint fresh tokens with the new
   encryption key.

### Encryption-key compromise (key leaked, file not yet exfiltrated)

1. **Rotate the key immediately.** Decrypt the token file with the
   old key, re-encrypt with a new key. Or just nuke the token file
   and re-authorize from scratch — faster and forensically cleaner.
2. **Audit where the leaked key went.** Logs? Git history? Backup?
   Assume any place the key reached is now an IOC.
3. **If the key was in git history**, the entire history is now
   compromised. Force-push a rewrite (`git filter-repo`) is
   tempting but doesn't help — assume the key is public.

### Webhook replay attack succeeded

1. **Identify the abused event(s).** Search logs for the replayed
   webhook ID. Note which invoice(s) were written back.
2. **Stop the sidecar** to prevent further writebacks while you
   investigate the root cause.
3. **Manually verify** the affected invoices in QBO. If incorrect
   tax was written, fix it through the QBO UI (do not use
   `tax:recalc` yet — the engine input may have been crafted by the
   attacker).
4. **Diagnose the replay-window bypass.** Was the verifier token
   leaked? Was the replay-window cache cleared (process restart in
   a multi-replica deployment without shared state)? Was the
   signature check disabled in a config change?

### Sidecar host compromise

1. **Cut network access** to the host (firewall the box; don't shut
   it down — preserves volatile memory for forensics).
2. **Revoke OAuth tokens at Intuit** (as above).
3. **Snapshot the host** for forensic imaging before any cleanup.
4. **Assume everything on the host is compromised**: tokens,
   encryption key, environment variables, any other secrets in
   `.env`, application logs, the codebase itself (attacker may
   have planted backdoors in `vendor/`).
5. **Rebuild from clean media.** Do not "clean" the compromised
   host; provision a fresh VM, fresh `composer install` from a
   known-good lock file, fresh secrets.

### Unauthorized config change

1. **Diff the current config against the last known-good config.**
   What changed? When? By whom (audit logs)?
2. **Revert.** Restore from version control or backup.
3. **Restart the sidecar** with the reverted config.
4. **Investigate the access path.** How did the attacker (or
   misguided employee) reach the config? Plug that hole.

### Deployment of unreviewed code

1. **Roll back to the previous release.** `git checkout v0.1.0`,
   `composer install`, restart.
2. **Audit the unreviewed code** for malicious behavior. Diff
   against the prior release; look for anything network-bound or
   anything touching `var/qbo-tokens.json`.
3. **If malicious code ran in production**, treat as host compromise
   (above) — assume secrets were exfiltrated.

### Engine misconfiguration (wrong `OST_ENGINE_URL` in prod)

1. **Correct the URL in `.env`** and restart the sidecar.
2. **Identify affected invoices** — anything processed during the
   misconfigured window. Logs should show the engine URL on each
   call.
3. **Run `bin/console tax:recalc <invoice-id>`** for each affected
   invoice to recompute tax against the correct engine.
4. **If invoices were remitted** based on incorrect tax, the
   merchant has a remittance correction to file with the relevant
   state DOR — that's outside the sidecar's scope, but flag it to
   the accounting team.

## Eradication and recovery

After containment, before resuming operations:

1. **Root-cause analysis.** Write the RCA up; date it; include the
   timeline of events. Save under
   `docs/incidents/YYYY-MM-DD-<slug>.md` in the merchant's internal
   ops repo (or wherever you keep ops history).
2. **Patch the root cause.** Don't just patch the symptom. If the
   incident was credential theft, the root cause might be a leaky
   logging config — fix the config, then rotate.
3. **Verify the fix** under simulated conditions. If the incident
   was a webhook replay, re-test the replay-window logic with a
   crafted request.
4. **Resume operations** with monitoring dialed up for 30 days
   post-incident.

## Post-mortem and reporting

Within 30 days of incident closure, write a post-mortem covering:

- What happened (factual timeline).
- Why it happened (root cause — go five-whys deep).
- What was impacted (data, customers, dollars if known).
- What we did (containment, eradication, recovery).
- What we changed to prevent recurrence (controls, automation,
  alerting).
- What we'd do differently (process improvements).

Send the post-mortem to Intuit as a follow-up to the original
Security Incident Notice. This satisfies the §13.6 remediation
reporting requirement.

## Notification log

Maintain this table — append a row per incident. Never delete or
edit historical rows (the audit trail is the value).

| Incident ID | Discovery (UTC) | Classification | Notice sent (UTC) | SLA deadline | Resolved (UTC) | Post-mortem link |
|---|---|---|---|---|---|---|
| _example: INC-2026-001_ | _2026-XX-XX HH:MM_ | _Immediate_ | _2026-XX-XX HH:MM_ | _2026-XX-XX (+7d)_ | _2026-XX-XX_ | _docs/incidents/2026-XX-XX-...md_ |
|  |  |  |  |  |  |  |

Incident ID format suggestion: `INC-YYYY-NNN`, numbered sequentially
per calendar year, reset January 1.

## Annual security drill

ToS §13 puts the merchant on the hook for "implementing policies
and procedures to detect, prevent, respond to, remediate, and
otherwise address" incidents. A runbook that has never been
exercised is unproven. Run a tabletop drill **at least once per
year**, ideally quarterly.

### Drill format

1. **Pick a scenario** from the incident-type table above. Rotate
   through scenarios over multiple drills — don't keep drilling the
   same one.
2. **Set a clock.** The drill starts at T+0; participants react in
   real time.
3. **Walk through the runbook** as if the scenario were real.
   - Who notices first? How? (Tests detection / alerting.)
   - Who runs containment? Do they have the right access? (Tests
     access management.)
   - Who drafts the Intuit notice? Do they have the template
     handy? (Tests playbook accessibility.)
   - Can the notice get sent within 24h with the people currently
     in the on-call rotation? (Tests staffing.)
4. **Stop at T+8h or when the scenario is contained.** No need to
   simulate the full 7-day Immediate-SLA remediation.
5. **Debrief.** What worked? What didn't? What needs to change in
   the runbook, the alerting, the tooling, the staffing?
6. **Document.** Append a drill record to the table below.

### Drill log

| Drill date | Scenario | Participants | Outcome | Runbook updates triggered |
|---|---|---|---|---|
| _example: 2026-XX-XX_ | _OAuth token compromise via leaked backup_ | _SRE on-call, security lead_ | _Containment in 35min; notice drafted in 90min; would have sent at T+2.5h._ | _Added `var/` to backup exclusion list; updated runbook OAuth-revocation URL._ |
|  |  |  |  |  |

## Cross-references

- README "Incident response" section — short version pointing here.
- `specs/security/data-handling.md` — what data is at risk;
  classifies which assets matter.
- `specs/constitution.md` §6 — security primitives that, if any
  are bypassed or fail, count as Incidents.
- `SECURITY.md` — for vulnerabilities in the sidecar code itself
  (as opposed to operational incidents in a deployment), use the
  coordinated-disclosure process there.
- `specs/operations/insurance-prereq.md` — cyber-liability
  insurance is one of the things you'll want in place before the
  first Incident lands, not after.
