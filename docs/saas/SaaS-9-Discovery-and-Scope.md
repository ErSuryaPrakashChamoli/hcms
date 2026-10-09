# SaaS.9: discovery and scope (data export, offboarding, retention)

Starting point: `ae35542` on `feature/oct_1_phase_1` (SaaS.8 complete). Discovery ran before any SaaS.9 code was written.
Architecture: `docs/architecture/saas-9-offboarding.md`. Per-table inventory: `docs/saas/evidence/SaaS-9-data-inventory.md`.

## 1. Questions and findings

| # | Question | Finding | Consequence |
|---|---|---|---|
| D1 | How many tables does a tenant own? | 334 tables at the start: 299 with `tenant_id`, 35 without. After SaaS.9: 340 (the six new exit tables) | Classify every table, not just tables with `tenant_id`. Pivots such as `role_user` carry no tenant id but belong to one |
| D2 | How are tables linked? | 1,255 single-column foreign keys: 444 cascade, 495 set null, 311 restrict, 5 no action. 18 self-references, 7 cycles. No composite keys | Do not rely on `ON DELETE CASCADE` (a restrict grandchild blocks it). Order deletion children first from the live graph. Clear only nullable edges inside cycles (19 columns) |
| D3 | Where are files? | 12 tables hold stored paths (documents, imports, learning evidence and certificates, service desk and grievance attachments, announcements, report runs, statutory exports, compliance evidence…). Stored folders are per tenant: `tenants/{id}/…`, `learning/certificates/{id}`, `statutory-exports/{id}`, `compliance-evidence/{table}/{id}`, `warehouse/{slug}` | Export and delete both the files referenced by rows and the stored prefixes. Prefixes are **exact**: `compliance-evidence/layouts/{layout}` is platform evidence beside the tenant's, so a wildcard would delete it |
| D4 | What decides access? | `Tenant::isAccessible()` (SaaS.2), used by the panel, API key resolution, the job middleware and sessions | Closing a tenant only has to make it inaccessible. No new access check is needed |
| D5 | Is the audit trail deletable? | `AuditEvent` uses an immutable builder. Two hash chains (tenant, platform) with chain-lock rows. No database triggers | A tenant's chain can only go as a whole, newest first, after its head hash is recorded, through one sanctioned code path (architecture-guarded). Exit events must also go to the platform chain |
| D6 | What blocks deletion financially? | SaaS.7: draft and issued invoices, payments, refunds, exceptions. SaaS.6: subscription timelines | Markedge's billing, invoice, payment and subscription records are never deleted with a tenant. Open items block deletion and are never cleared. Closing needs the subscription ended first |
| D7 | Is there any retention configuration? | Only operational purge windows (SaaS.8: AI interactions, notification log, report runs, webhook log, warehouse snapshots). No legal retention anywhere (D-8 open) | Build retention as approved configuration. Ship no period and no legal minimum. Show `LEGAL_MINIMUM_NOT_CONFIGURED` |
| D8 | What does a suspended tenant do? | SaaS.8 defers its jobs (`tenant_deferred_jobs`) and releases them on reactivation. `RunsForSuspendedTenants` marks jobs that run anyway | A closed tenant reuses this. The export and deletion jobs are marked to run |
| D9 | Can an operator act on one tenant only? | `PlatformOperator::assert` (SaaS.8). Maker-checker exists for payroll approval and plans. D-15 (operator roles) is open | Maker-checker on deletion, retention policies and hold release uses "a different platform operator", without new roles |
| D10 | What data exists in the showcase? | 1 tenant (`demo`), 22 employees, 1 payroll run (5 entries), about 950 audit events, no subscription or invoice | Good for an end-to-end test fixture. It is never used for a real deletion |
| D11 | Which secrets live in tenant tables? | Password hashes, remember tokens, TOTP secrets and recovery codes, API key hashes, SSO client secrets, integration inbound secrets, webhook signing secrets, lookup hashes (UAN, PAN, IP number) | Exclude them from exports by column name (10 secret names, plus 7 per-table exclusions). Clear them on anonymisation |

## 2. State model decision

The proposed linear chain (ACTIVE → … → DELETION_CERTIFIED) was checked against the lifecycle that already exists. The
architecture document, §2, maps each proposed state to where it now lives:

- **Tenant status.** It decides access, so it gains only `closed` and `deleted`.
- **Offboarding case.** One case per exit: requested, closed, completed or cancelled.
- **Exports, holds and deletions.** Separate records, because they overlap in time.
- **Retention and eligibility.** Derived when needed, never stored, because they change whenever a policy, a hold or a
  payment changes.

## 3. Inventory summary

36 categories. Per-table detail (dependencies, files, excluded columns, anonymisation, audit references) is in the
appendix.

| Group | Categories | Default in a deletion |
|---|---|---|
| Customer HR data | identity, people, employment, organisation, attendance, leave, documents, performance, goals, learning, career, talent, compensation, assets, service desk, grievances, engagement, communication, notifications, workflows, configuration, analytics, integrations, api | delete once retention ends; identity is anonymised when retained records reference it |
| Statutory and financial employee data | payroll, statutory, tax, banking | delete only once an approved retention period ends (these are the categories most likely to have legal minimums: an owner input) |
| Evidence | audit (the tenant's chain) | delete only as a whole chain, through its step, when its retention ends; otherwise retained and identity anonymised |
| Markedge's records | billing, invoices, payments, subscriptions | always retained |
| Platform | operational state (deleted), offboarding records (retained), warehouse snapshots (delete) | as stated |

Distinctions applied:
- **DELETE:** hard delete.
- **RETAIN:** kept, with the reason.
- **ANONYMISE:** `users` only.
- **EXPORT:** every customer category, plus Markedge's commercial records concerning the customer (their invoices and
  payments). Platform state is not exported.
- **LEGAL HOLD:** overrides all of the above.

## 4. Scope (delivered)

- **The offboarding case.** Request, cancel, close (end of service), reopen (until a deletion is planned), with tenant
  statuses `closed` and `deleted`.
- **The full export.**
  - Asynchronous, resumable, idempotent, one in flight at a time.
  - Encrypted package with a signed manifest.
  - Private storage, links bound to one person, audited downloads and passphrase reveals, expiry.
  - A customer self-service page for holders of `data.export`.
- **Retention policies.** Versioned, effective-dated, per jurisdiction, maker-checker, legal-minimum floor,
  `LEGAL_MINIMUM_NOT_CONFIGURED`.
- **Legal holds.** Immediate placement, two-person release, enforced in planning, approval, every deletion batch and
  every purge batch.
- **The deletion planner.** Gates, per-category reasons, dependency protection, fingerprint.
- **Deletion.** Two operators, cooling-off, environment switch.
- **The executor.** Batches under the exit lock, safe stop, resume, verification, anonymisation, files, audit chain,
  external dependencies, signed certificate.
- **Pages.** Operator offboarding page, retention policies page, customer data export page.
- **Integration.** Sessions, API keys, deferred jobs, the warehouse feed, subscriptions (no new entitlement for a closed
  tenant), financial gates, the operational purge (holds).
- **Tests.** SQLite feature tests, 11 MySQL races, mutation, browser, accessibility, visual, performance.

## 5. Excluded (with reason)

| Excluded | Why |
|---|---|
| Enabling deletion in any environment | The production validation gate and the owner's decision come first. The switch defaults off |
| Legal retention periods and minimums | D-8: legal input. Nothing is invented |
| Deleting backups, provider copies and receivers' copies | Outside PeopleOS. Tracked as external dependencies, never claimed |
| D-15 operator roles (separate exit and deletion roles) | Not yet decided. Maker-checker between any two operators is the interim boundary |
| Customer-initiated closure or deletion | Only Markedge operators close or delete. Customers export |
| Self-service signup, SaaS.10, RMS | Out of phase (instruction) |
| Soft-delete copies of customer data | Retained categories stay as they are. Deleted ones are gone. No third state |
| Restoring a deleted tenant | Not offered. A deletion is final by design |

## 6. Owner inputs needed

1. **D-8 retention.** For each category and jurisdiction: the company policy and any verified legal minimum, especially
   payroll, statutory, tax, banking, attendance and leave registers, and the audit trail.
2. **Production enablement of deletion.** Decided after the SaaS.9 gate (`PEOPLEOS_TENANT_DELETION_ENABLED`).
3. **D-15.** Whether deletion approval needs a dedicated operator role.
4. **Backup expiry evidence.** How long backups keep a deleted tenant, so the "backups" external dependency can be
   confirmed.
5. **The leaked showcase credential** (from SaaS.7/SaaS.8) is still a release blocker. See the final report §27 and §38.
