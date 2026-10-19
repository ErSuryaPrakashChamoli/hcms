# Documentation gaps and product findings

**Product:** Markedge PeopleOS · **Build:** `1844ce6` · **Date:** 19 October 2026

**For:** the product owner and the engineering team. What the customer documentation could not show or verify, the
product defects and inconsistencies found while documenting (those that affect customers are stated as limitations
in the customer chapters), and the information still needed to finalise the buyer's guide.

## 1. Product findings

Severity is the documentation team's view of the customer impact (Critical: a core task cannot be completed from the
screen; High: a feature is broken or a control is weaker than it appears; Medium: a setting or option does not do what
its label says; Low: cosmetic or minor). "Verified by" names who confirmed it: *orchestrator* = confirmed by reading
the code during the final review; *writer* = observed by the chapter writer in the live demonstration or the code.

| # | Severity | Area | Finding | Evidence | Verified by |
|---|---|---|---|---|---|
| F1 | Medium | Sign-in | Sign-out reasons (account not active, organisation suspended, session ended, idle timeout) are flashed as session `status` but no view renders them; users land on the sign-in page with no message. | app/Http/Middleware/EnsureSessionIsValid.php, EnforceSecurityPolicy.php; no `session('status')` in resources/views | ws writer + orchestrator grep |
| F2 | Medium | Security policy | "Expire after days" (security.password_expiry_days) is stored but nothing enforces it. | app/Filament/Pages/SecurityPolicyPage.php; no reader of the key | ws writer + orchestrator grep |
| F3 | Medium | Roles | Performance Admin role template has only company.view / organisation.view / people_setup.view, despite "Manages performance cycles". | config/peopleos.php roles.performance-admin; inventory r-performance-admin (22 modules) | orchestrator |
| F4 | Medium | Roles | Auditor ("Read-only access") includes webhook.manage. | config/peopleos.php roles.auditor | orchestrator |
| F5 | Low | Roles | Tenant HR Admin has role.view only (cannot edit role permissions); only Tenant Super Admin can. Intentional? | config/peopleos.php | orchestrator |
| F6 | Low | Home | After a decision the Home side panel says "The requester is notified." but the requester is notified only when a notification rule exists. | ws writer | ws writer |
| F7 | Low | Preferences | "Restore hidden Home cards" exists but Home has no control to hide a card. | ws writer | ws writer |
| F8 | Medium | Workforce / Employee 360 | FTE inputs: minValue(0.01) + step(0.05) → browser accepts only 0.01+k·0.05, so 1.0 (and 0.5) are rejected. | app/Filament/Resources/Employees/Pages/ViewEmployee.php:203 and the New position form | org writer + orchestrator |
| F9 | Low | Organisation | Feature flag `organisation.designer` (default off) has no effect; the Organisation Designer opens by role. | org writer | org writer |
| F10 | Low | Organisation | Locations cannot be linked to an establishment on screen; State list holds only 13 Indian states. | org writer | org writer |
| F11 | Low | Docs | docs/PeopleOS-Administrator-Guide.{html,pdf} (27 Sep 2026) predate the UX redesign and SaaS phases; superseded. | git log | orchestrator |
| F12 | Low | Payroll | Statutory issue codes appear unlabelled in the payroll run's Issues column (e.g. `unverified_statutory_rule`). | pay writer (live) | pay writer |
| F13 | Medium | Tax | Employees cannot submit a tax declaration (stays Draft until payroll staff change status); TDS investment proofs do not feed the tax estimate; no proof document upload on these screens. | pay writer | pay writer |
| F14 | Low | Tax | Lower-case "tds" in some labels (e.g. "New tds profile"). | pay writer (live) | pay writer |
| F15 | Low | Roles | Payroll Admin cannot open statutory return screens (EPF/ESI/PT/LWF/TDS returns) by default — only Tenant HR Admin / Super Admin. | role-access.md | orchestrator |
| F16 | Medium (external) | Compliance | Statutory return export is blocked until the production gate passes; 0/24 rule versions verified, 5 notices open; every employee in the demo October run has blocking "unverified rule" issues. | pay writer (live) | pay writer |
| F17 | High | Engagement | **My HR › Give feedback** on MySQL: submitting shows "Not allowed" plus a database error although the feedback is saved; HR is not notified — the feedback's text (UUID-style) id does not fit the delivery log's numeric subject column. SQLite tests miss it. | svc writer (live, demo DB); schema confirms: notification_deliveries.source_id is bigint (nullableMorphs) while employee_feedback.id is uuid | svc writer + orchestrator |
| F18 | Medium | Notifications | No tenant role can create or delete notification rules/templates (no create/delete permission declared); only edit works. | svc writer | svc writer |
| F19 | Medium | Notifications | SMS, WhatsApp and Push are offered as channels but send nothing. | svc writer | svc writer |
| F20 | Medium | Performance | Cycle options "Show self review to manager" and "Peer reviews anonymous by default" are saved but never applied; "Allow reviewers to resubmit" has no visible effect. | grow writer | grow writer |
| F21 | Medium | Performance | Scheduled cycles never launch automatically; stages never advance by date. | grow writer | grow writer |
| F22 | Low | Learning | Training-session waiting list: no action to cancel a place, so the list never moves up from the screens. | grow writer | grow writer |
| F23 | Low | Goals | No "submit goal plan" action; weight total checked on each goal save. | grow writer | grow writer |
| F24 | Low | Roles | HR Manager has no performance, learning or talent administration permissions. | grow writer + config | grow writer |
| F25 | Critical | Imports (critical) | **Map columns** on employee imports (and punch imports, same code) fails with "Undefined variable $svc": the `action(function (EmployeeImport $record, array $data) {…})` closure does not `use ($svc)`. Imports cannot progress past "inspected" from the screen. | app/Filament/Support/ImportActions.php:39-44 (confirmed by reading the code); ppl writer reproduced live | ppl writer + orchestrator |
| F26 | Low | Audit history | History tab on a letter throws a lazy-loading violation outside production (strict mode). | app/Filament/RelationManagers/AuditHistoryRelationManager.php:38 (ppl writer) | ppl writer |
| F27 | Low | Employee 360 | Action and More menus open underneath the sticky section bar (visual). | ppl writer (live) | ppl writer |
| F28 | Low | Roles / Exit | By default Asset Admin and Payroll Admin cannot clear exit stages; HR must. | ppl writer | ppl writer |
| F29 | High | Change Centre | Only the main edit form of certain records waits for approval; Settings, Feature flags, creates, deletes and tab edits apply immediately; with approval off nothing is recorded there; no guard against approving one's own change. | adm writer | adm writer |
| F30 | Medium | Settings | 11 setting keys have no effect (incl. branding, locale, base currency, two leave keys, password expiry); "off" values display as empty. | adm writer | adm writer |
| F31 | Low | Feature flags | Description column always "—"; `organisation.designer` and `security.mfa` have no effect. | adm writer | adm writer |
| F32 | Medium | Custom fields | "Visible to employee/manager/HR" switches are saved but hide nothing. | adm writer | adm writer |
| F33 | Medium | Workflows | The "Generate document" step type is skipped at run time. | adm writer | adm writer |
| F34 | Low | SSO | "Show SSO as the primary login" has no visible effect; SAML not supported (OIDC only). | adm writer | adm writer |
| F35 | Low | Exchange rates | No screen uses exchange rates yet. | adm writer | adm writer |
| F36 | Low | Roles | HR Manager and Auditor can open the Forms and Workflows lists but not the records; HR Managers start workflows from the Employee 360. | adm writer | adm writer |
| F37 | High | API keys (security) | API key scopes are not limited by the issuing administrator's own permissions (a key can carry scopes broader than its creator holds). Recommend review. | adm writer; confirmed: ApiKeyResource.php:41 CheckboxList options = all config(peopleos.api.scopes) | adm writer + orchestrator |
| F38 | High | Leave (critical for HR) | **Leave requests › Apply on behalf** throws a server error on open: "Call to a member function getFormData() on array" (`$this->mountedActions[0]?->getFormData()` — mountedActions holds arrays in Filament 5). Workaround: Employee 360 › Records › Time › Leave › Apply for leave. | app/Filament/Resources/LeaveRequests/Pages/ListLeaveRequests.php:27 (confirmed by reading the code); time writer reproduced live | time writer + orchestrator |
| F39 | Medium | Holidays | Holiday type and the Half day switch are ignored: every calendar date counts as a full holiday. | time writer (code) | time writer |
| F40 | Medium | Attendance | Late-mark actions in the attendance policy are not applied. | time writer (code) | time writer |
| F41 | Medium | Leave | Encashments are recorded by HR only, with no amount and no payroll posting. | time writer (code) | time writer |
| F42 | Low | Attendance | Employees cannot withdraw an attendance correction (regularisation). | time writer (code) | time writer |
| F43 | Low | Leave | Hourly leave, sandwich rules and a calendar grid view are not available. | time writer (code) | time writer |
| F44 | High | Reports (significant) | Report builder: on New/Edit report the field lists for Filters, Group by, aggregation field, Chart axis/value and Sort by are empty. `$get('dataset', true)` resolves an absolute path `dataset`, but the form state lives under `data.` so it returns null (the Fields list, which uses a relative `$get('dataset')`, works). Editing a starter report shows no grouping. | app/Filament/Support/ReportBuilderSchema.php:25 (confirmed by reading the code); ins writer reproduced live | ins writer + orchestrator |
| F45 | Medium | Roles / Analytics | Executive role can open Reports but has no dataset permission: dataset list empty; HR overview dashboard tiles show "You cannot report on the … dataset." | ins writer (live) | ins writer |
| F46 | Medium | Reports | Scheduled runs use the report owner's access; starter reports have no owner, so their scheduled runs fail; notices are in-app only; only the owner can download a scheduled export. | ins writer (code) | ins writer |

### Recommended fixes first

1. **F25** — capture `$svc` in the Map columns action (`use ($svc)`): unblocks employee and punch imports, which customers need for onboarding.
2. **F38** — read the Apply-on-behalf form state through the action's schema instead of `mountedActions[0]->getFormData()`.
3. **F44** — use a relative `$get('dataset')` (or `data.dataset`) in the report builder so filter/group/sort lists fill.
4. **F17** — give the notification delivery a string-compatible source id (or map UUID subjects) so Give feedback succeeds and HR is notified; add a MySQL test.
5. **F37, F29** — limit API key scopes to the issuer's permissions; extend the Change Centre to settings/flags and block self-approval.
6. **F3, F4, F45** — correct the role templates (Performance Admin permissions, Auditor without webhook.manage, Executive dataset access) via the template_keys mechanism.
7. **F1, F2, F8, F30–F33, F39–F41** — show sign-out reasons; implement or hide no-effect settings and options; fix the FTE step.

## 2. What could not be verified live

These behaviours are documented from the code and the automated tests. They were not exercised in the demonstration
because doing so needs a token or secret, a record in a specific state, or would change data other screens rely on.

### 5.1 Your workspace

- Invitation acceptance, reset-password form (token pages), e-mail verification prompt, MFA set-up modal, MFA challenge: described from code and Filament strings, not screenshotted (no token / would expose a secret / no unverified or MFA-enabled demo account).
- E-mails themselves (invitation, reset) were not observed; wording taken from `InvitationLink` / `PasswordResetLink`.
- Sign-out reasons ("Access to your organisation is suspended.", "You were signed out after N minutes of inactivity.") are flashed by the code but no login view displays them; the chapter only says the person is returned to the sign-in page.
- Task inbox rows and Team HR requests rows: empty in the demo.
- Asking the assistant a question: not done (records an AI interaction); covered by the Insights chapter.

### 5.2 People and lifecycle

- Alumni portal (no alumni sign-in in the demo); described from code and ExitTest.
- Background check case view and Record result (no cases in the demo; none created, to avoid changing seeded people).
- Onboarding task Done/Skip (not clicked on seeded plans).
- Mark as joined (no pre-joiner in the demo).
- Import steps after Map columns (blocked by the defect).
- History tab content (lazy-loading error in the demo; production mode is not strict, so it probably works, unverified).

### 5.3 Time and leave

- Check in / Check out, Manual punch, Process day, Reprocess, Approve OT / Reject OT, Retry, Run accrual now, Encash,
  Approve / Reject (leave, regularisation, encashment): not executed, to avoid changing seeded records other writers
  photograph. Behaviour documented from code and tests.
- Attendance records, punches and exceptions with real data: the demo contains no punches or attendance records.
- Physical device connection (API push) — not testable here.
- E-mail notifications for leave events: depend on notification rules; demo has only "Welcome every joiner".

### 5.4 Pay

- Payslip list/view content, Approve, Finalize & generate payslips, Bank file, Mark as paid, Reopen: no finalised run can exist (rules unverified, enforcement on) and seeded records must not be changed. Behaviour taken from `PayrollRuns.php`, `PayrollActions.php` and tests.
- Statutory return lifecycle (validate → approve → export → submission → acknowledgement → reconcile), TDS ledger verification and Form 130, parallel payroll compare/reconcile: need finalised payroll. Taken from `StatutoryReturns.php`, `StatutoryReturnActions.php`, `ComplianceReadiness.php`, `ParallelPayroll.php`, `TdsCertificates.php` and Compliance tests.
- Compensation change steps beyond the propose form (review, approve, execute), cycles, budgets and ranges create/approve: from `CompensationChanges.php`, resource actions and Compensation tests; the seeded five changes were made through the full four-person chain by the seeder.
- Directory row click on /admin/people did not navigate (likely opens a peek panel); the chapter therefore points to People › Lifecycle › Employees for the employee record.

### 5.5 Growth

- No state-changing action was executed (launch, advance, submit review, calibrate, finalize, acknowledge, check-in, enrol, approve, attendance, publish, designate). All are verified from code and feature tests only.
- Private manager notes on one-on-ones (field appears only on an existing meeting; none in demo).
- Waitlist promotion for training sessions is not reachable from the screens (no cancel-place action).
- Approval workflow integration for learning requests, talent reviews and succession plans (needs a configured workflow; none in demo).
- Certificate document download (no certificates in demo).
- Email/in-app notification delivery for performance and learning events.

### 5.6 Organisation

- Team positions page: not reachable in the demo (no positions held or managed by Amit) — described from code.
- Budgets: Planned vs actual modal and approval not exercised live (no budget; creating one not needed) — described from code and tests.
- Position lifecycle beyond Draft (Propose/Approve/Open/Freeze/Abolish/Close), Change requests, filling a seat via Transfer / promote: not exercised live (would require a second approver and changing an employee record) — described from code and tests.
- Legal entity / establishment Submit for verification and Verify: not executed (would change seed records).
- Delete buttons on organisation/people-setup edit pages: present; behaviour when a unit is in use not tested.
- UI defect: FTE number fields with step 0.05 and minimum 0.01 refuse whole numbers such as 1 (browser validation). Workaround documented: leave blank.
- Organisation Designer feature flag has no effect (see above).
- No screens failed to load, apart from expected 403 pages (Kavya on Legal Entities; Neha on Organisation Designer and Positions; Amit on Team positions).

### 5.7 Services and communication

- Request a service modal and record-change execution (no approved services in demo; DomainHandOffTest, ServiceRequestsTest).
- Ask HR submission, case status moves, Close & rate, Reopen (not clicked on the seeded case).
- Grievance case screen and actions (no cases in demo).
- Survey approve/publish/open/close, Take survey, Survey results page (no open/closed survey; would affect other personas' task lists).
- Campaign and audience forms (lists empty; not created).
- Service analytics figures (only the small-group suppression note shows).
- Delivery log "Send again" (no failed deliveries).

### 5.8 Insights

- Export CSV, scheduled runs and re-download not exercised live (would write run records against seeded reports); behaviour taken from code and `ReportingTest`.
- Saving a report or dashboard not exercised (no fictional record created).
- External language-model answers (feature `ai.llm` off; no provider configured) — described from code and `AiTest` ("uses the language model only when enabled and falls back when it fails").
- Manager Assistant and Payroll Auditor answers not asked (only the assistant tabs were checked).
- Switch dashboard button not visible (only one dashboard exists).

### 5.9 Administration

- SSO sign-in round trip (no connection in demo; would need a real identity provider).
- SCIM and the read/write API (described from openapi.json and config scopes only).
- Webhook delivery and retries, inbound event processing (no endpoints/systems in demo).
- Data export generation, download and passphrase (deliberately not requested).
- Change Centre approval flow live (approval is off in the demo; behaviour from code and tests).
- Getting started menu entry and Home banner (only for self-registered tenants; signup disabled).
- Exact login failure wording for a suspended user (inferred from the panel's standard behaviour: generic failure).
- Warehouse feed delivery to customers (server-side disk; arrangement with Markedge unknown).

## 3. Screens that failed to load or errored

### 5.1 Your workspace

None.

### 5.2 People and lifecycle

- /admin/employee-imports as hr (Neha, HR Manager): 403 "This page is not available to you" — expected (no employee.import).
- Employee import › Map columns › Submit: notification "Failed — Undefined variable $svc" (app/Filament/Support/ImportActions.php, 'map' action closure). Affects punch imports too.
- /admin/letters/1: History relation (loads automatically on the letter page) → 500 "LazyLoadingViolationException: Attempted to lazy load [fieldChanges] on model AuditEvent" (app/Filament/RelationManagers/AuditHistoryRelationManager.php:38). Non-production strict mode only (`Model::shouldBeStrict(! production)`); every page using the History tab is potentially affected in non-production.
- Employee 360: Action / More dropdown renders under the sticky section bar (z-index), hiding one menu item at the top of the page.

### 5.3 Time and leave

- **Apply on behalf** (Leave requests, as hr): Internal Server Error — "Call to a member function getFormData() on
  array", app/Filament/Resources/LeaveRequests/Pages/ListLeaveRequests.php:27 (POST /livewire/update).
- **Punch imports › Map columns**: "Undefined variable $svc" (app/Filament/Support/ImportActions.php map action;
  reported by the coordinator, confirmed in code; not reproduced in the demo by instruction).

### 5.4 Pay

None. All Pay screens opened for the personas listed in role-access.md. Empty states (no data in the showcase): Payslips, Adjustments, Tax declarations, TDS investment proofs, TDS deductor profiles, all five return lists, TDS certificates, Parallel payroll, Cycles, Budgets, Pay ranges.

### 5.5 Growth

None. Expected "This page is not available to you" for: employee → /admin/my-team, /admin/talent-pools; HR Manager → /admin/performance-cycles.

### 5.7 Services and communication

- **Give feedback submit** → toast "Not allowed" + `SQLSTATE[01000]: Warning: 1265 Data truncated for column 'source_id'` (insert into notification_deliveries, source_type EmployeeFeedback, source_id = UUID). Feedback is stored; notification and success message fail. Chapter carries a "known issue" callout.
- **Notification rules / templates**: no create or delete action for any tenant role (missing permission keys) — documented as a limitation.
- No screen failed to load.

### 5.8 Insights

None. (Interaction records created by this session: AI interaction log entries from asking "What is our attrition?", two "Summarise EMP00004" and "Explain my leave balance"; audit "View" events from opening Priya Nair's record. No business record was created, changed or deleted.)

### 5.9 Administration

None. Note: the "Settings" view of a policy version shows raw structured data (screenshot discarded as misleading); Feature flags Description column is empty on screen (defect).

## 4. Screenshots that could not be captured

| Screen | Why | How the documentation covers it |
|---|---|---|
| Accept invitation, reset password form, e-mail verification | Need a one-time token from an e-mail | Described from code with exact messages (chapter 5.1) |
| Authenticator set-up (QR code) and MFA challenge | Would enable MFA on a shared demo account / expose a secret | Described from code (5.1) |
| Single sign-on round trip, SCIM, API, webhooks, inbound events in action | Need an external identity provider or system | Described from code and tests (5.9) |
| Payslip, approved/finalised/paid payroll run, statutory return lifecycle, Form 130 | No run can be finalised: verified-rule enforcement is on and 0 rules are verified | Described from code and tests (5.4) |
| Alumni portal | No alumni sign-in in the demonstration | Described from code (5.2, 6.14) |
| Background check case and result screens | No cases in the demonstration | Described from code (5.2) |
| Attendance punches and records with data | The demonstration has no punches | Empty lists shown and stated (5.3) |
| Service requests in progress, grievance handling, survey results | Starter services are drafts; no cases or closed surveys | Described from code and tests (5.7) |
| Full data export package | Would create an export in the shared demo | Request dialog shown, then cancelled (5.9) |
| Markedge platform console (tenants, plans, subscriptions, billing) | Operator-only and not customer-facing | Described as provider responsibilities only |

## 5. Information needed to finalise the buyer's guide

Everything below is marked **"To be confirmed with the SaaS provider"** in the customer documentation. No source in the
repository states it (the only contact addresses found are placeholders such as `markedge.example`).

- Contact details for demonstrations, sales and support; support channels and hours.
- Prices, pricing basis, plans and included modules, trial or pilot terms, contract term and renewal (owner decision B-4 pending).
- General availability date (release gate NO-GO), hosting provider and region, data residency, sub-processors.
- Backup frequency, RPO/RTO and restore-test evidence; service levels (availability, response, resolution).
- Security certifications and independent security review results (gate 4 not engaged).
- Statutory rule verification status and timeline (0 of 24 verified; owner decisions O-5, O-12, O-18).
- Data retention after contract end and deletion terms (deletion switched off; retention decisions D8 pending).
- Onboarding services (data migration, configuration, training) and the data processing agreement.
- Official browser support statement (Safari and Android Chrome untested).
- Official logo/brand guidelines for documents (the handbook uses the in-product wordmark).

## 6. Documentation maintenance

- Sources: `docs/customer-guide/*.md` and `modules/*.md`; `08-workflow-diagrams.md` is generated.
- Rebuild: `docs/handbook/tools` (`npm ci && node build.mjs`) — see `docs/handbook/README.md`.
- Screenshots: `docs/handbook/tools/screenshots/` (Playwright scripts per area) against a disposable `*_showcase` database.
- When a defect in section 1 is fixed, update the "Status" line and the limitation callout in the chapter, the
  capability tables in chapters 1 and 2, and re-capture the affected screenshots.
- `docs/PeopleOS-Administrator-Guide.{html,pdf}` (27 September 2026) predate the redesign; consider removing them.