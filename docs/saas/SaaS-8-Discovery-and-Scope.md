# SaaS.8 — Discovery, gap ranking and scope

Recorded on 8 October 2026 at `cc039cb` (branch `feature/oct_1_phase_1`, clean tree). Every finding was checked against the code. Four read-only audits ran in parallel: tenant lifecycle and operators; operations and production readiness; data governance and integrations; scale and tenant isolation. Their claims were then verified by reading the code, and for one of them by running EXPLAIN on MySQL. Where an audit was wrong, this document says so.

## 1. Where SaaS.8 sits

| Item | Value |
|---|---|
| Roadmap | SaaS.1 §23. WS1 to WS5 are delivered by SaaS.2 to SaaS.7 (WS4 enforcement is still in shadow mode). WS6 (signup), WS7 (export, offboarding, retention), WS8 (control plane) and WS9 (validation) remain |
| Minimum for a sales-led launch (§23) | WS1 → WS2 → WS3 → WS4 (billed metric) → WS5 (manual collection) → **WS7** → **WS8 (core operations)** → WS9. WS6 follows (D-9: sales-led first) |
| Provisional labels in the SaaS.7 baseline | SaaS.8 signup, SaaS.9 offboarding and retention, SaaS.10 control plane. **Re-sequenced here** (§6) |
| SaaS.7 | Complete (technical). Not reopened. No regression was found during this work |

## 2. Answers to the discovery questions

**A. What is actually built?**
- **People core and HCM:** organisation, employees, lifecycle, attendance, leave, payroll and statutory compliance, performance, learning, talent, workforce, compensation, service desk, engagement, exit (Phases 0 to 14, UX programme).
- **Platform:**
  - fail-closed tenancy;
  - two audit hash chains (tenant and platform);
  - SaaS.2 identity: invitations, MFA, session epochs, suspension, audited operator tenant entry;
  - entitlements in shadow mode, plans, subscriptions;
  - SaaS.7 billing, tax and payments with maker-checker.

**B. What is the tenant lifecycle today?**
- `trial | active | suspended` on `tenants.status`.
- PROVISION exists: one transaction, then an invitation e-mail. INVITE, VERIFY and MFA come from SaaS.2. ACTIVATE is acceptance of the invitation.
- CONFIGURE is the tenant's own settings. SUBSCRIBE is SaaS.6 (operator-run). OPERATE is the product.
- SUSPEND and REACTIVATE exist: `TenantSuspensions`, sessions ended.
- EXPORT, OFFBOARD and RETAIN/DELETE do not exist. There is no tenant export, closing state, deletion, legal hold or certificate.

**C. Who controls what?**
- **Customers (tenant administrators):** their users, roles, settings, retention windows for operational logs, API keys and webhooks.
- **Platform operators:** tenants, plans, subscriptions, billing, tax, payments and configuration (platform-only pages). They also have audited, time-boxed tenant entry.
- **No self-service:** no signup, plan change, export or deletion.

**D. Is every operator action authorised in its service and on the platform chain?**
- **Commercial operations: yes** (`OperatorChange`).
- **Tenant lifecycle: no.**
  - `TenantSuspensions` accepted any actor. Only the page was platform-only.
  - Provisioning was audited only on the tenant's own chain.
  - Tenant edits were plain model saves with an optional reason, on the tenant chain only.
  - There was no viewer for the platform chain (WS1 G-PLAT-1 left it open).

**E. Is queued and scheduled work tenant-safe?**
- Every queued job is `TenantAwareJob` with `BindTenantContext` (architecture test). Scheduled commands use `TenantRunner`.
- **Defect:** a job reaching a worker while its tenant was suspended was *acknowledged and lost* (`BindTenantContext` returned `null`). It never ran after reactivation.

**F. Are notifications reliable?**
- Transactional only. No marketing channel exists, and none was added.
- Deliveries are claimed `queued → sending`. A worker that died after the claim left the delivery `sending` forever. A lost job left it `queued` forever.
- A failed delivery could not be sent again.

**G. Are integrations and webhooks safe?**
- **Outbound webhooks:** signed (HMAC with timestamp and a stable delivery id, so receivers can be replay-safe), sent through the SSRF guard, payload cleaned by `PayloadGuard`.
- **Inbound events:** tenant API key plus HMAC, idempotency keys and leased claims.
- **RMS:** outside PeopleOS (architecture test: no RecruitmentEdge or RMS dependency). Only the Integration Hub contract crosses the boundary.
- **Defect:** the workflow webhook node sent the run context *without* `PayloadGuard`.
- **Defect:** the API rate-limit bucket was taken from the *presented* key prefix before verification.

**H. Is the platform observable and recoverable?**
- **Present:** health probes, readiness report, correlation ids, redacted logs, scheduler heartbeat.
- **Missing:**
  - per-tenant health;
  - a failed-job view;
  - scheduled audit verification (`peopleos:audit:verify` existed but was never scheduled);
  - pruning of failed jobs and batches.
- **Readiness page:** re-hashed every audit event of every tenant on each load.
- **Backups:** documented in `backup-and-disaster-recovery.md`. A restore is unverified in any target environment, and that is operator evidence, not code.

**I. Can tenants infer each other's data?**
- **Cache keys:** all tenant-keyed.
- **Commercial data:** pricing, invoices, tax, payments, usage and plans have no tenant-facing surface.
- **Defect (P0):** Filament `unique()` is a raw database rule that ignores the tenant scope. On `employees.employee_code` and `legal_entities.code` (unique per tenant in the database), it refused another tenant's value. Two consequences:
  - Employee saves failed whenever another tenant held the code. Codes default to `EMP00001…` in every tenant.
  - Probing codes revealed another tenant's codes, and so roughly its headcount.
- **Known and decided elsewhere:**
  - `users.email` is globally unique (D-17, open).
  - SSO slugs are global.
  - Auto-increment ids reveal platform-wide volume.

## 3. Production readiness snapshot (unchanged by discovery)

| Area | State |
|---|---|
| Application | Production config validator, readiness report; statutory gate 0/24 rules verified (BLOCKED) |
| Data | MySQL 8.4; migrations additive; audit chains intact |
| Operations | Scheduler and queue rules documented and tested; no per-tenant operations view; failed jobs never pruned |
| E-mail | Real transport required in production (readiness FAIL otherwise); invitation e-mail failure left a half-provisioned tenant |
| Backup / DR | Documented; restore and DR exercise **unverified** (operator evidence) |
| Security | MFA for operators; SSRF guard; redaction. **No security headers** (the panel could be framed: clickjacking) |
| Credentials | A leaked credential is still accepted by the `admin@fynnedge.com` account in the local `hcm` and `hcm_ux_showcase` development databases. **Release blocker (owner action)**. `platform@markedge.local` in the same databases accepts the demo fixture password. Neither value is reproduced anywhere |

## 4. Audit claims checked and corrected

| Claim | Verdict |
|---|---|
| Suspended-tenant jobs dropped | **Confirmed** (`BindTenantContext` returned `null`; the queue deletes an acknowledged job) |
| Provisioning: invitation failure escapes after commit | **Confirmed** (`InvitationLink` is sent synchronously after the transaction) |
| `TenantSuspensions` without actor authorisation; reactivation writes `active` | **Confirmed** |
| Notifications stuck in `sending`; no resend | **Confirmed** (`Notifier::deliver` claims, `DeliverNotification` handles `queued` only) |
| API limiter keyed on unverified prefix | **Confirmed** (and random tokens each got their own bucket) |
| Workflow webhook payload not cleaned | **Confirmed** |
| No security headers | **Confirmed** (no middleware, no web-server config in the repository) |
| `audit:verify` / queue pruning not scheduled | **Confirmed** |
| Warehouse feed: every tenant daily, no opt-in, never purged | **Confirmed** |
| Tenant-blind `unique()` | **Confirmed**, and reproduced by tests before the fix |
| Audit chain-head read scans other tenants' rows | **Confirmed on MySQL** (EXPLAIN: `key PRIMARY`, `Backward index scan`, under `FOR UPDATE`) |
| Webhook endpoint secret revealed on the edit form | **Not confirmed**: `secret` is a hidden attribute, so the edit form is not filled with it |
| SSO nonce not verified | Known and documented as deferred (SaaS.2 W3); not re-opened here |
| Platform-admin bypasses in AI and analytics | Operator design (`Gate::before`); documented. **Payroll four-eyes bypass for operators is not a permission but a segregation-of-duties exception: fixed** |

## 5. Ranked gaps

| Rank | Gap | Why |
|---|---|---|
| **P0** | Tenant-blind uniqueness on tenant-owned tables | Cross-tenant inference (codes, headcount) and broken saves |
| **P0** | Suspended-tenant queued work lost | Silent data loss (notifications, webhooks, payroll calculation, certificates) |
| **P1** | Tenant lifecycle authorised only on the page; reactivation loses `trial`; provisioning half-fails on mail; no platform-chain record of provisioning or edits | Operator chain integrity (WS1 G-PLAT-1, WS8) |
| **P1** | Notifications stuck forever; no resend | Transactional reliability |
| **P1** | No security headers; API limiter exhaustible by a known prefix; workflow webhook payload unguarded; operator could prepare and approve payroll alone | Security |
| **P1** | No platform audit viewer; no per-tenant operations view; failed jobs invisible | WS8 core ("operate without database access") |
| **P1** | Audit verification never scheduled; readiness re-hashes per load; chain-head scan across tenants | Integrity evidence and scale |
| **P1** | Warehouse feed without opt-in, never purged | Data governance |
| P2 | Failed jobs and batches never pruned; unbatched retention deletes | Hygiene |
| P2 | Audit filters (`whereDate`, `DISTINCT module`), in-memory audit export, `notifications` table growth | Scale; not measured with audit volume |
| P2 | `users.email` global oracle; SSO slug collision 500 | Waits for D-17 |
| P3 | Global auto-increment ids reveal platform volume | Low |
| Out of scope | Tenant export, closing, deletion, legal hold, certificate | WS7: needs D-8 |
| Out of scope | Self-service signup | WS6: D-9 (sales-led first), D-17, terms |

## 6. Scope chosen: Platform Operations and Tenant Lifecycle Reliability

The smallest coherent scope closes every P0 and P1 above. It finishes the WS1 items SaaS.2 left open (G-PLAT-1 viewer and tenant edits on the platform chain; G-JOB-1 `audit:verify` scheduled and pruning) and delivers the WS8 core: "every lifecycle operation possible without database access, with audit".

| Item | What |
|---|---|
| S1 | Tenant-scoped uniqueness (`TenantUnique`) and an architecture guard |
| S2 | Deferred suspended-tenant work (`tenant_deferred_jobs`), released once on reactivation |
| S3 | Lifecycle integrity: operator guard in the services, interrupted status restored, provisioning on the platform chain, atomic invitation with operator resend, tenant edits on both chains |
| S4 | Notification recovery sweep and audited resend (`notification.resend`) |
| S5 | Security headers; API limit on verified keys; workflow webhook `PayloadGuard`; payroll four-eyes without operator exception |
| S6 | Scheduled, recorded audit verification; readiness reads results; failed-job pruning; audit chain-head index, named by the recorder's read |
| S7 | Warehouse feed opt-in and snapshot retention; batched retention deletes |
| S8 | Platform operations page and platform audit trail |

**Re-sequencing of the provisional labels:**
- SaaS.8 is WS8 core plus the WS1 remainder. Every WS7 operation (export, closing, deletion) is an operator action that needs exactly these foundations:
  - service-level operator guard;
  - platform-chain record and viewer;
  - deferred work;
  - per-tenant operations view.
- **Next: WS7 export, offboarding and retention (SaaS.9)**, which depends on D-8. WS6 signup follows after D-9 and D-17.

## 7. Excluded, with reasons

| Excluded | Reason |
|---|---|
| WS6 self-service signup | D-9 (sales-led first), D-17 (global e-mail uniqueness), legal terms |
| WS7 tenant export, closing, deletion, legal hold, certificate | D-8 (retention, statutory handling at deletion) is open; recommended as SaaS.9 |
| Closing / pending-deletion tenant states | Arrive with WS7 (SaaS.1 Lifecycle §2) |
| Operator roles and dual control for lifecycle | D-15 open; every operator is equal today, as in SaaS.7 |
| Entitlement enforcement | D-6, D-7, D-16 |
| Backup automation and DR exercise | Infrastructure and operator evidence, not application code |
| SSO hardening (nonce, PKCE, ID token) | SaaS.2 W3, deferred and documented |
| Audit partitioning or archive, 100k-employee scale, queue fairness | Need measurements with audit volume and many tenants (staging) |
| Full script CSP | Needs Filament / Livewire nonces; framing is closed with `frame-ancestors` |
| RMS / recruitment | Out of PeopleOS by contract |
