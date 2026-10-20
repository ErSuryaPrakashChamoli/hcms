# PHASE 12 — EMPLOYEE EXPERIENCE & HR SERVICE DELIVERY FOUNDATION

## Status

```text
Phase 12: COMPLETE
Statutory production readiness: NOT DECLARED
PeopleOS production readiness: NOT DECLARED
```

Stopped for architectural review. Phase 13 has not been started.

Architecture: `docs/architecture/employee-experience.md`. This report separates:
- Phase 12 functionality (§7–§22);
- pre-existing domain control gaps corrected during Phase 12 (§23);
- deferred work (§32);
- statutory blockers (§33).

## 1–5. Git

| | |
|---|---|
| 1. Starting HEAD | `8448291` on `feature/oct_1_phase_1` (Phase 11 approved). Clean tree, 0 pushes |
| 2. Ending HEAD | the phase-12.7 documentation commit (the commit that adds this report) |
| 3. Branch | `feature/oct_1_phase_1` |
| 4. Commits | 7 on top of `8448291`:<br>• `66b04a7` 12.1 People / Employment domain change actions and the five domain control fixes<br>• `260fa46` 12.2 service catalogue, canonical request lifecycle and intake<br>• `a3cbd22` 12.3 workflow, SLA, assignment, escalation and domain hand-off<br>• `3c5f41e` 12.4 employee experience, knowledge review and policy acknowledgement<br>• `928fe5a` 12.5 case access model, HR service UI, read API and integration privacy<br>• `f076f05` 12.6 hardening: invariants, scale and MySQL concurrency<br>• 12.7 documentation and this report |
| 5. Pushes | 0. Nothing was pushed, merged or deployed, and no history was rewritten (12.1 `66b04a7` unchanged) |
| Working tree | clean at the start; clean after the final commit |

The commits are logical review units and depend on each other: 12.2's intake calls 12.3's SLA clock,
assignment and executor, and every commit shares the configuration. The validation in this report was
run on the complete Phase 12 code (the final state), not on each intermediate commit.

## 6. Discovery findings

Recorded in `docs/architecture/employee-experience.md` §1–§2 and Appendix A.

**What already existed, reused and not duplicated:**
- **Service desk:** `ticket_categories`, `tickets` (new → open → pending → resolved → closed),
  `ticket_comments` with an internal flag.
- **Grievances:** confidential categories, explicit access list, audited reads.
- **Knowledge base:** articles, a version snapshot per publish, reads per version.
- **Configuration Forms:** versioned fields with `rules()`.
- **Workflow engine:** `WorkflowCompleted` bridges.
- **Employee timeline.**
- **Document domain:** private disk, signed links, re-authorised downloads.
- **Self-service pages:** My Day, My Team and others.

**Gaps found:**
- no service catalogue and no transition map;
- no row locks;
- ticket and grievance numbers read "last + 1" without a lock;
- calendar-hours-only SLA without pause;
- no idempotency;
- no domain hand-off;
- grievance managers unscoped by organisation;
- a broken grievance attachment link;
- published knowledge editable in place, with no second-person review.

**Stop (§69).** Bank, PAN / UAN / ESIC, address, emergency-contact and family data had no safe domain
entry point: only direct Employee 360 saves.

**Owner's decision (2026-10-02):**
- Add minimal People / Employment domain actions as the one write path: each with its own security
  chain, value-free events, linked audit and idempotent use; no duplicate tables.
- Fix the five control gaps found in the domains the desk calls (§23).

Discovery confirmed the expected owners (Employment: bank, statutory; People: address, emergency,
family). No ownership surprise stopped the work.

## 7. Service Desk architecture

**Service Delivery owns:**
- requests, cases and the catalogue;
- intake, routing, assignment and SLA;
- case communication and service status;
- the employee-facing request experience;
- service analytics.

**It never owns:** Employee, People, Employment, Position, Attendance, Leave, Payroll, Compensation,
Performance, Learning, Career, Talent, Succession, Documents, Assets, Exit or Compliance.

**How a domain change happens:**
1. A service names a **domain action** (handler).
2. The handler calls the owning domain's action or contract.
3. The desk keeps only:
   - the action key and its status;
   - the approval and execution facts;
   - the resulting record reference.

**Principal services** (`App\Domain\ServiceDesk\Services`):
- `ServiceCatalogue`: versions;
- `ServiceForms`: form fields and classification;
- `ServiceRequests`: intake;
- `RequestLifecycle`: the only status writer;
- `CaseAccess`: the access model;
- `CaseAssignment`;
- `SlaCalendars` / `SlaClock`;
- `DomainActions` / `DomainActionExecutor`;
- `ServiceDesk`: case operations;
- `ServiceDeskProcessor`: the scheduled run;
- `ServiceDeskBulk`;
- `ServiceDeskAnalytics`.

**Listeners:**
- `ServiceDeskWorkflowBridge`: approval outcome, with a separation-of-duties re-check;
- `ServiceDeskDomainListener`: follows the outcomes of leave, regularisation and letters.

## 8. Request model

`tickets` stays the one canonical request / case (extended, not replaced).

**Added columns:**
- pinned service and service version;
- source;
- form data: encrypted, and purged after a domain decision;
- confidentiality and visibility to the employee;
- team, agent and owner;
- resolver and approver;
- SLA policy and mode, pause start and paused minutes;
- escalation level;
- submitted / acknowledged / assigned / status-changed / cancelled times;
- domain action, its status and resulting reference, executor and time;
- idempotency key, correlation id, operation id;
- `lock_version`.

**Indexes:**
- `tickets_idempotency_unique` (tenant, employee, key);
- SLA (tenant, status, due_at);
- team, service, priority, created and resolved.

**New tables:**
- `ticket_transitions`: append-only status history;
- `ticket_access_grants`: explicit access to restricted cases;
- `service_desk_reminder_logs`: idempotency of reminders and escalations;
- `number_sequences`: locked numbering for TKT and GRV.

**Comment visibility:** `ticket_comments.visibility` is employee / internal / restricted, plus
attachment size, type and SHA-256. Comments are append-only.

**Status migration:** existing statuses were renamed reversibly: new → submitted, open → in_progress,
pending → waiting_employee.

## 9. Catalogue

`service_definitions` and `service_definition_versions` use the Phase 11 configuration lifecycle
unchanged: Draft → Pending approval → Approved (Scheduled / Active) → Superseded, or Archived.
- prepared by `servicedesk.manage`, approved by a different `servicedesk.catalogue_approve` holder;
- frozen from submission, checksummed;
- closed once, never deleted;
- requests pinned to their version.

**A version defines:**
- the form: a published Configuration Form version, plus field classification
  (standard / sensitive / restricted, employee-visible);
- availability: employee / manager / HR;
- eligibility: lifecycle states, organisation scope and a rule;
- the attachment rule;
- approval: the workflow key;
- the domain action;
- the SLA policy;
- default priority;
- the assignment team;
- confidentiality, visibility to the employee and to managers, and whether it is listed.

**The catalogue refuses:**
- approval on top of a domain that approves itself;
- a missing or unpublished workflow;
- audiences a handler does not accept, so profile changes are never offered to managers for reports.

**Starter content:** 13 starter services are seeded as drafts at provisioning, with a starter
business-hours SLA policy.

**UI:** a catalogue screen with versions and lifecycle actions, and SLA policies.

## 10. Workflow

- The existing `WorkflowEngine` runs approvals; there is no second approval engine.
- The request waits in `awaiting_approval`, and the SLA is paused.
- `ServiceDeskWorkflowBridge` decides under the request lock:
  - **approved by a third person:** ready, and back to work;
  - **approved by the requester or the employee concerned:** refused (`REQUEST_APPROVAL_SOD_REFUSED`),
    and HR may restart approval;
  - **rejected:** resolved as not approved, with values purged.
- The executor of a domain change is never the requester, the employee or an approver
  (`DomainActionExecutor`).
- Legacy categories with a `workflow_key` still start their workflow as automation, without gating the
  request.

## 11. SLA

- **Policies:** `service_sla_policies` are effective-dated, with per-priority first-response and
  resolution targets, business or calendar mode, warning share, escalation role / repeat / maximum
  level, and optional pause statuses and service hours. A policy in use is not edited.
- **Calendar:** the `SlaCalendar` contract has two implementations:
  - **BusinessHoursCalendar** counts only:
    - service days and hours;
    - outside the employee's attendance holidays (`HolidayResolver`): public and company holidays,
      with half days keeping the first half;
    - in the work location's timezone (`AttendanceTimezone`).
  - **CalendarHoursCalendar** counts 24 × 7, for legacy categories.
  There is no second holiday calendar.
- **Clock (`SlaClock`):**
  - due times are snapshotted at submission;
  - the pause statuses (default `waiting_employee` and `awaiting_approval`) stop the clock;
  - resuming moves the due times later by the paused service minutes;
  - reopening restarts the clock.
- **Tests:** weekend, holiday, half-day and timezone arithmetic, and pause / resume.

## 12. Escalation

`peopleos:service-desk:process` runs hourly, `withoutOverlapping()->onOneServer()`; with `--queue` it
dispatches the tenant-bound `ProcessServiceDesk`. Each run:
- promotes due catalogue versions;
- warns once at the policy's warning share;
- escalates breaches by one level per repeat interval up to the maximum, to holders of the escalation
  role who can see the case and to the assignee;
- reminds requests waiting for the employee (every 3 days, at most 3 times) and waiting for HR (every
  24 hours);
- auto-closes resolved requests after 5 days;
- escalates overdue grievances daily;
- sends a weekly policy-acknowledgement sweep.

Every notification first claims a unique `service_desk_reminder_logs` bucket, so a repeated, retried
or concurrent run sends nothing twice. Levels move under the request lock. The legacy
`peopleos:servicedesk:tick` is an alias.

## 13. Employee Experience

- **My HR** (Me → My HR) has seven tabs:
  - **Requests:** through CaseAccess.
  - **Tasks:** `ExperienceTasks` over domain sources (workflow tasks, service desk, policy
    acknowledgement) and the Phase 13 survey hook, which is null for now. Needs Attention appears
    there too.
  - **Approvals.**
  - **Documents:** own documents through the Document domain's own-document policy and signed links,
    plus letter status.
  - **Policies:** published versions with acknowledgement.
  - **Services:** the catalogue's availability and eligibility, with a Request form built from the
    version.
  - **Notifications.**
- **My Requests:** the same resource as the HR queue, limited to own requests by CaseAccess.
- **Team HR requests:** status only; manager-available services can be raised for a report.
- **Employee 360 → Requests** shows service history through CaseAccess, with the service and status
  but never the free-text subject.
- **Timeline** gets references only ("HR request … raised / resolved / cancelled") for standard,
  employee-visible requests. Profile changes record their own sensitive-category entries.

## 14. Knowledge Base

- **Lifecycle:** Draft → Review → Approved → Published → Archived. The reviewer and approver
  (`kb.review`) are never the author.
- **Versions:** publishing creates an immutable `article_versions` row (title, summary, body, SHA-256
  hash, reviewer, approver); versions are never updated or deleted.
- **What readers see:** readers, search (SQL on the published versions) and acknowledgement always use
  the published version. A revision is a draft while the published version stays served.
- **Audience:** the rule-engine audience (tenant and organisation conditions).

## 15. Policy acknowledgement

- Acknowledgement is per version and records the version id, hash, source and IP address.
- It is locked: a concurrent repeat records one acknowledgement.
- It applies only within the audience. Version 1 never counts for version 2.
- Acknowledgement rows are never changed or deleted.
- `POLICY_ACKNOWLEDGED` is audited, and the weekly reminder is idempotent.

## 16. Confidential cases

**Restricted (employee-relations) services:**
- They are disciplinary, harassment and investigation cases, usually invisible to the employee and
  available to HR only.
- Access follows Tenant → `servicedesk.confidential` → classification → explicit scope (assignee,
  owner, which is the HR person who opened the case, or a reasoned, revocable grant) → record.
- There is no "HR sees everything" and no platform-admin bypass.
- Every read of a sensitive or restricted case is audited (`CONFIDENTIAL_CASE_VIEWED`).
- Grant and revoke lock the case, and access is re-checked under the request lock.
- Grant reasons are masked in audit.

**Restricted cases never reach:**
- the API or reports;
- Needs Attention;
- the AI assistant;
- the employee's timeline.

**Grievances, extended:**
- managers are scoped by organisation;
- no platform-admin bypass;
- only a handler with access grants access;
- signed, audited attachment route;
- locked numbering.

## 17. Integrations

| Domain | How the desk reaches it |
|---|---|
| Leave | `Leaves::request` at submission (idempotency key `sd-{correlation}`). Leave approves; the case resolves on `leave.approved` / `rejected` / `cancelled` |
| Attendance | `Regularisations::request` at submission. Attendance reviews (reviewer recorded, never one's own); the case follows its events |
| Letters | `Letters::generate` (source = the request). Letters approves (approver ≠ requester) and issues; the case resolves on `letter.issued`. The desk retrieves no salary or employment data |
| Compensation | The desk links a proposal made in Compensation by an HR proposer (never the requester). The Compensation chain continues there. No salary write from the desk |
| Payroll | A payroll query is a case. No payroll run, calculation, payslip or statutory rule is touched |
| Documents | My Documents reads through `EmployeeDocumentPolicy` (`document.own`) with signed, audited downloads. Case attachments use the same private disk |
| People / Employment | The six profile actions plus `ChangeManagerAction::change`, run after approval by a third-person executor (§24) |
| Employee 360 | Requests tab; profile tabs call the actions |
| Notifications | `ServiceDeskEvent` → rules / in-app with references only. Webhooks get allow-listed names and scalar context |
| Workflow | Approvals and the bridge (§10) |

## 18. Security

| Layer | Result |
|---|---|
| Tenant | Global tenant scope. Another tenant's request → 404 (Filament and API) |
| Organisation | Ticket `ScopedByEmployee` plus `CaseAccess::visible` in SQL. Out-of-scope agents can neither see nor be assigned |
| Relationship | Manager team view via `PerformanceRelationships` (line types only, never mentor / buddy / project) |
| Self-service | Requests for one's own record only (A → B refused) |
| Field security | Sensitive values masked without the domain's view permission. Restricted values need explicit access. HR-only fields hidden from the employee. Form data encrypted at rest and masked in audit |
| Confidential | §16 |
| API | §19 |
| Attachments | Private disk; signed, re-authorised, visibility-checked, audited; no IDOR (another ticket's comment id → 404) |
| Internal notes | Never shown to the employee, the manager or the API |
| Auditor | `servicedesk.view` reads; `servicedesk.agent` works |

Security tests: 31/31 (§30).

## 19. API

`GET /api/v1/service-desk/{services, requests, requests/{number}, requests/{number}/comments, knowledge, tasks}`
(scope `servicedesk.read`):
- Read-only, with no write endpoints. Profile data has no API write path.
- Never returned:
  - restricted cases (404) and drafts;
  - internal or restricted notes, and comments on sensitive cases;
  - attachments;
  - free-text subjects, descriptions and resolutions;
  - sensitive or HR-only fields;
  - workflow internals.
- Cross-tenant ids → 404.

## 20. Notifications

- Context and titles carry references only: number, service, status and priority. Never subjects,
  descriptions, form data, comments, resolutions or grievance details.
- The new event names are listed in the architecture document (§9 there).
- A test asserts that no subject or resolution text reaches events or notifications.

## 21. Audit

The existing immutable hash chain is used; there is no new audit table.

**Actions:**
- REQUEST_CREATED, REQUEST_SUBMITTED, REQUEST_ASSIGNED, REQUEST_REASSIGNED, REQUEST_STATUS_CHANGED,
  REQUEST_ESCALATED, REQUEST_RESOLVED, REQUEST_CLOSED, REQUEST_CANCELLED;
- COMMENT_CREATED, ATTACHMENT_UPLOADED, ATTACHMENT_DOWNLOADED, CONFIDENTIAL_CASE_VIEWED;
- KNOWLEDGE_PUBLISHED, POLICY_ACKNOWLEDGED.

**Linkage and masking:**
- Profile changes carry the request number (`approval_reference`) and the request's operation id: the
  answer to "which request caused this change".
- Bulk runs carry one operation id plus a BULK_OPERATION summary.
- Masked: ticket form data, subject, description and resolution; grant reasons; profile values.

## 22. Queue / scheduler

- `peopleos:service-desk:process` runs hourly, `withoutOverlapping()->onOneServer()`, per tenant.
- With `--queue` it dispatches `ProcessServiceDesk`: `TenantAwareJob` with `BindTenantContext`, and
  `ShouldBeUnique` per tenant.
- Reads go through the `(tenant, status, due_at)` index in id chunks (no full scans), one request per
  transaction.

## 23. Pre-existing domain control gaps corrected during Phase 12

These were found during discovery in domains the desk calls, and fixed in their own domains (12.1).
They are corrections to existing controls, not Service Desk functionality.

| Domain | Gap | Correction | Regression test |
|---|---|---|---|
| Letters | The requester could approve their own letter | `Letters::approve` refuses approver = requester (demo seeder and Alumni adjusted) | `Exit/ExitTest`, `Admin/ExitPagesRenderTest` |
| Attendance | Regularisation approval recorded no actor and had no "not own" rule | The reviewer is required and recorded, one's own is refused, and the policy denies own | `Employment/ProfileChangeActionsTest` (domain control fixes) |
| Documents | The uploader could verify their own upload | `Documents::review` refuses verifier = uploader and records the verifier | `Documents/DocumentsTest` |
| Manager change | `ChangeManagerAction` checked no permission | `ChangeManagerAction::change` requires `employee.position` and both people in scope | `Employment/ProfileChangeActionsTest` |
| Own documents | Employees could not open their own documents | `document.own` plus `EmployeeDocumentPolicy::isOwn`; the end-to-end HTTP path is tested | `Employment/ProfileChangeActionsTest`, `Documents/OwnDocumentAccessTest` |

Domain-hardening suite: 24/24 (§30).

## 24. New People / Employment domain actions

The one write path for profile data. Employee 360, the Service Desk and any future API call it; there
is no `UpdateEmployeeProfile`.

| Action | Owner | Permission | Event |
|---|---|---|---|
| `Employment\Actions\ChangeBankAccountAction` (add / update / remove) | Employment | `employee.sensitive.update` | `employee.bank_account_changed` |
| `Employment\Actions\ChangeStatutoryIdentityAction` (PAN, Aadhaar reference, UAN, PF, ESIC) | Employment | `employee.sensitive.update` | `employee.statutory_identity_changed` |
| `Employment\Actions\ChangeStatutoryApplicabilityAction` (PF / ESIC / PT applicability, PT state, tax regime) | Employment | `employee.sensitive.update` | `employee.statutory_applicability_changed` |
| `People\Actions\ChangeAddressAction` (add / update / remove) | People | `employee.create` / `.update` / `.delete` | `employee.address_changed` |
| `People\Actions\ChangeEmergencyContactAction` (add / update / remove) | People | as above | `employee.emergency_contact_changed` |
| `People\Actions\ChangeFamilyMemberAction` (add / update / remove) | People | as above | `employee.family_member_changed` |
| `Employment\Actions\ChangeManagerAction::change` (checked entry point) | Employment | `employee.position` | `employee.manager_changed` (existing) |

**Shared support:**
- `ProfileChangeGuard`: active user, permission, organisation scope;
- `ChangesProfileData`: one rule set, an employee row lock, timeline and value-free event;
- `ChangeOrigin`: source, request reference, operation id;
- `ChangePersonRecordAction`: belongs-to check;
- `ProfileChangeRefused`.

**Service Desk handlers** (`ServiceDesk\DomainActions`), which call the owning domains:
`profile.bank_account`, `profile.statutory_identity`, `profile.address`, `profile.emergency_contact`,
`profile.family_member`, `employment.manager_change`, `leave.request`, `attendance.regularisation`,
`letter.request`, `compensation.proposal`.

## 25. Concurrency

Real MySQL with forked processes and slowed model events (`tests/MySql/ServiceDeskConcurrencyTest.php`):
14 races.

**The races:**
- the 10 required races: idempotent submission, claim, double resolve, reassign vs resolve,
  escalation vs pause, overlapping SLA runs, duplicate execution, concurrent acknowledgement,
  concurrent assignment, revoked grant mid-flight;
- 4 from the People-actions decision: duplicate bank account, simultaneous statutory changes,
  concurrent catalogue approval, execution retry by the same executor.

**Normal implementation:**

| Run | Database | Result |
|---|---|---|
| Run 1 | `hrms_p89_concurrency` | 14/14 PASS (141.5 s) |
| Run 2 | `hrms_p89_concurrency` | 14/14 PASS (143.2 s) |
| Fresh dedicated database | `hcm_p12_concurrency` | 14/14 PASS (153.6 s) |
| Supplementary run for the concurrency audit chain | `hcm_p12_concurrency` | 14/14 PASS (103.2 s) |

Before Run 1, the first MySQL run found a genuine defect (13/14): the duplicate-account check read a
snapshot taken before the employee lock and let a second account in. It was fixed with a locking read
(the idempotency re-check was changed the same way). Runs 1 and 2 are on the fixed code.

**Locks deliberately removed** (`hcm_p12_concurrency`): each lock was removed, its races run, and the
file restored. These failures are the expected proof that the lock matters, not product failures.

| Lock removed | Expected race failure observed |
|---|---|
| Request row lock (`RequestLifecycle::move`) | Double claim, double resolve, reassignment after resolution, escalation after pause, lost assignment update (5 of the 6 dependent races; the revoked-grant race did not fail in that run) |
| Execution lock (`DomainActionExecutor`) | The change executed twice (second address / second bank account attempted) |
| Acknowledgement lock (`KnowledgeBase::acknowledge`) | Two POLICY_ACKNOWLEDGED audits |
| Employee lock (`ChangeBankAccountAction`) | Deadlock / duplicate path |
| Employee and detail locks (statutory actions) | Unique-key violation (two statutory rows attempted) |
| Version lock (`ServiceCatalogue::approve`) | The version was approved twice |
| Employee lock (submission) | Deadlock (the unique index remains the backstop) |

Result: 7/7 lock groups produced their expected failures. The original code passed again after
restoration.

A first lock-removal attempt on the shared `hrms_p89_concurrency` database is **not used as evidence**.
An external process was rebuilding that database at the time: schema errors, and the database was later
removed by a process outside this session.

## 26. Scale

`tests/Feature/ServiceDesk/ServiceDeskScaleTest.php` builds 15, then 150 requests across 5 employees,
with caches warmed before measuring:

| Measurement | Queries at 15 requests | Queries at 150 requests |
|---|---|---|
| HR queue list page | 47 | 47 |
| HR queue search | 47 | 47 |
| `GET /api/v1/service-desk/requests` | 7 | 7 |

Query counts are constant (no N+1). No timing benchmarks were taken.

## 27. Migration replay

**Database:** `hcm_p12_replay`, created for this replay:
- disposable and dedicated;
- not dev (`hcm`) and not shared;
- no other connections;
- `APP_ENV=local` on 127.0.0.1.

| Step | Result |
|---|---|
| Fresh migrate + seed | PASS: 101 migrations, demo seed |
| Snapshot after seed | 276 tables, 4,225 columns, 1,462 indexes (461 unique), 1,058 foreign keys, 23 CHECK constraints |
| Cycle 1: rollback (the 3 Phase 12 migrations), then re-apply | PASS. Statuses reverted to legacy names on rollback and back on re-apply |
| Snapshot after cycle 1 | identical to the seeded snapshot |
| Cycle 2: rollback, then re-apply | PASS |
| Final snapshot | identical to cycle 1 |
| Final replay vs development (`hcm`) | Identical tables, column types / nullability / defaults / generated expressions, indexes, unique keys, foreign keys (with delete / update rules) and CHECK constraints. **One difference:** `tenants.base_currency varchar(3) NOT NULL DEFAULT 'INR'` exists only in development — the known historical difference, left unchanged |

`hcm_phase12_replay` is a partial, abandoned replay database (101 tables) left by an interrupted earlier
command. It is not used as evidence and was left untouched.

## 28. Audit chain

| Database | Result |
|---|---|
| Development (`hcm`) | PASS: platform 40 events, demo tenant 587 events verified |
| Replay (`hcm_p12_replay`) | PASS: platform 2, demo tenant 663 events verified (includes REQUEST_CREATED / SUBMITTED, KNOWLEDGE_PUBLISHED) |
| Concurrency (`hcm_p12_concurrency`, after the supplementary 14/14 run) | PASS: platform 14 plus 14 race tenants, 3,345 events verified |

Details:
- No tenant-module audit event lacks its tenant.
- Phase 12 sensitive values (ticket form data, subject, description, resolution; profile values;
  compensation) are masked.
- One pre-Phase 12 development row (2026-09-26) holds an unmasked ticket subject, written before
  Phase 12 masked subjects. Audit rows are immutable, so it is recorded here, not changed.

Failed jobs: development 0, replay 0, concurrency 0.

## 29. Tests

**Authoritative full suite.** It ran on the final Phase 12 code, after the last code change:
`php artisan test` (Pest, SQLite in-memory), 2026-10-02 20:59:24 → 21:20:41.

| Tests | Passed | Skipped | Failed | Assertions | Duration |
|---|---|---|---|---|---|
| 777 | 740 | 37 | 0 | 7,680 | 1,275.4 s |

The 37 skipped tests are the MySQL-only concurrency suites (Phases 8–12). SQLite runs skip them by
design; they ran on MySQL separately (§25).

**Earlier runs, not authoritative:**
- one started before the closeout fixes (grant-reason masking, own-document test): 775 / 738 / 37 / 0;
- one started before the seeder fix.

Neither is used as the result.

**New Phase 12 tests:**
- `ServiceDesk/` (39 tests):
  - intake: 8
  - domain hand-off: 7
  - security: 9
  - SLA / processor: 4
  - knowledge and grievance: 5
  - experience pages: 5
  - scale: 1
- `Architecture/ServiceDeskInvariantsTest`: 16 (the 20 Phase 12 invariants and the 19 People-actions
  invariants)
- `MySql/ServiceDeskConcurrencyTest`: 14 races
- `Employment/ProfileChangeActionsTest`: 6
- `Documents/OwnDocumentAccessTest`: 2

Existing tests were adapted only where Phase 12 changed a rule:
- renamed statuses;
- `servicedesk.agent`;
- knowledge review before publish;
- second-person letter / document approval;
- attachment audit action;
- the architecture test's append-only list.

No assertion was weakened.

## 30. Validation summary

| Check | Result |
|---|---|
| Architecture suite | PASS: 79/79 (894 assertions) |
| Security suite (desk, attachments, access scope, API) | PASS: 31/31 (210 assertions) |
| Service Desk suite | PASS: 39/39 (411 assertions) |
| Domain hardening suite | PASS: 24/24 (325 assertions) |
| Scale | PASS: 1/1, constant 47 / 47 / 7 queries |
| Experience / knowledge legacy suites | PASS: 18/18 |
| Pint (`--test`) | PASS |
| Full suite (authoritative) | PASS: 777 tests, 740 passed, 37 skipped (MySQL-only), 0 failed, 7,680 assertions |
| Concurrency | 14/14, 14/14, fresh database 14/14; lock removal 7/7 expected failures |
| Migration replay | PASS, twice |
| Audit chain | Development PASS, replay PASS, concurrency PASS |
| Failed jobs | 0 |

## 31. Known limitations

- **Intermediate commits:** commits 12.2–12.6 were not individually tested; validation ran on the
  complete Phase 12 code.
- **Revoked-grant race:** the race did not fail when the request row lock was removed. Its protection
  (access re-checked under the lock, grant and revoke locking the case) holds in the normal runs, but
  this lock-removal proof is NOT VERIFIED for that race.
- **Starter catalogue:** the starter SLA policy and draft services seed at tenant provisioning and
  `db:seed`. The development tenant that existed before Phase 12 has none; HR creates services.
- **API context:** API keys are tenant-level with no employee context, so the knowledge endpoint lists
  only audience-free articles.
- **Knowledge audience:** evaluated per article in PHP over the published, tenant-bound set (search
  itself is SQL).
- **Service hours:** business hours use the employee's holiday calendar and location timezone.
  Per-HR-team service calendars are not modelled.
- **Manager visibility:** reuses the performance manager relationship types, as Phase 11 does.
- **UX checks:** keyboard, mobile and screen-reader behaviour: NOT VERIFIED beyond server-side render
  and Livewire action tests.
- **Webhooks:** payload privacy is verified through the event context and the allow-list; no live
  webhook delivery was exercised.
- **Historical audit row:** one pre-Phase 12 development audit row holds an unmasked ticket subject
  (§28).

## 32. Deferred work

- **Surveys and engagement (Phase 13):** only the `SurveyTaskProvider` hook exists.
- **Write APIs for requests and profile changes:** explicit action endpoints, once they can enforce the
  same workflow and separation of duties.
- **Payroll corrections from a case:** an approved Payroll action link (today a payroll query is a
  case only).
- **Employee document upload in My HR:** beyond the existing `document.upload` holders, and
  document-request automation.
- **Per-team service calendars.**
- **Backfill of the starter catalogue** for tenants created before Phase 12.

## 33. Statutory status

```text
24 rules
0 verified
5 open notices
UNCHANGED
Production statutory readiness: NOT DECLARED
```

Phase 12 changed no statutory rule, marked no evidence verified, did not implement the EPF September
2026 split and did not modify TDS v3. Statutory remediation remains a separate controlled track.
