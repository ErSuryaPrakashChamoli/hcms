# PeopleOS × HROne Setup parity — programme report (for independent review)

Prepared 2026-10-29. Repository: PeopleOS (Laravel 12, Filament 5, Livewire, Pest), branch `feature/oct_22_salary_module`,
last commit `4e84f1ea`. **All work described here is uncommitted in the working tree** (≈ 695 changed/new paths, including
earlier uncommitted programmes). Recruitment is out of scope by the owner's decision (a separate RMS product).

## 1. How to use this report

This report is meant to be cross-checked. Each section states what was built, the defaults, and how it was verified, with
file paths so claims can be checked against the code. Section 12 lists questions a reviewer should probe. Where something
was **not** verified (e.g. MySQL), it says so.

## 2. Goal, scope and method

**Owner's request.** Explore the HROne portal (app.hrone.cloud) read-only, find everything PeopleOS lacks, and build it —
"go deep into every Setup module, sub-module, section and field; nothing should be left; in our own style". Later: every
policy must be drafted/updated as a step-by-step wizard like HROne's policy screens.

**In-scope HROne Setup sections (14):** Core HR, Automation, Forms & templates, Governance, Access rights, Integration,
Workforce, Time office, Payroll, Expense, Performance, Engage, Asset, Helpdesk. (Recruitment excluded.)

**Research method.**
- HROne was explored read-only (navigation, list APIs). No HROne data was changed. Credentials were never stored.
- The HR-admin account could not open HROne's policy editors ("no page access rights"), so field-level specs were mined
  from HROne's compiled front-end bundles (each setup form's questions, options and control names) and the list APIs.
  Labels not captured are marked *(inferred)* in the matrices.
- Result: six field-by-field gap matrices, ~1,700 rows, each row marked HAVE / PARTIAL / MISSING / N-A with a build note:
  `docs/hrone-parity/setup/01-core-hr-access-integration.md`, `02-automation-forms-governance.md`,
  `08-workforce-payroll.md`, `09-time-office.md`, `11-expense-asset-helpdesk.md`, `12-performance-engage.md`.
- Each matrix ends with an ordered "Build packages" table; the live status of every package is in
  `docs/hrone-parity/setup/00-build-status.md`. The engineering record of everything built is
  `docs/architecture/hrone-parity.md` (one section per package).

**Build method.** Package by package. From mid-programme, packages were built by sub-agents in isolated copies of the
working tree (own SQLite database, no access to the dev MySQL), then merged into the working tree by hand (conflicts
resolved per file) and regression-tested there before being marked Done.

## 3. Status summary (2026-10-29)

| | Count |
|---|---|
| Build packages marked **Done** (merged + regression green in the working tree) | 31 lines in the status register (≈ 45 packages) |
| **Building** now (not merged) | 2 builders: Performance HP-8g/8h/8i/8j; Core HR CA-6/9/10/11/16 |
| **Pending** | Payroll BP-10, 11, 12, 14, 16, 17 (+ BP-7/8 remainders, BP-15 registers); Core HR CA-12, 13, 17 (+ seams of CA-7, 8, 14, 15); Governance P6, P7, P8, P10, P11, P12 |
| **Blocked** (owner decision / external / statutory verification) | BP-15 rule data; P15, P16, P17; HP-8k; CA-7/8/14/15 external parts; map distance; email-to-expense/ticket; voucher provider; WhatsApp provider; PDF library |
| Matrix rows explicitly marked "→ built" | Core HR/Access/Integration 37 · Automation/Forms/Governance 67 · Workforce/Payroll 31 (+ banners) · Expense/Asset/Helpdesk 187 · Performance/Engage 152 · Time office: all rows resolved (no MISSING left) |
| Migrations added by this programme | 50 (listed in §10) |
| HROne-parity test files | 68 in `tests/Feature/HroneParity` (437 tests in the latest full run) |

Rough completeness: Time office ~100 %, Workforce ~95 %, Expense/Asset/Helpdesk ~90 % (travel/projects/map/email
exceptions), Performance/Engage ~80 % (HP-8g–8j building), Governance ~65 %, Payroll ~55 %, Core HR/Access/Integration
~40 % (CA-6/9/10/11/16 building). These are estimates from the registers, not measurements.

## 4. Engineering rules applied to every package

- Additive and default-off: every new setting's default reproduces existing behaviour; no existing feature, view or
  column removed. Where a default deliberately changes behaviour it is listed in §7/§8.
- Multi-tenant, fail-closed: tenant-owned models use `BelongsToTenant` + `Auditable` (+ `HasEffectiveDates` where history
  matters); employee data is access-scoped; every new table is classified in `DataInventory`; every resource has a Gate
  policy; permission keys live in `config/peopleos.php` and are mirrored by `peopleos:sync-permissions`.
- Statutory values (PF/ESI/PT/LWF/TDS rates, slabs, ceilings) are never hard-coded or tenant-entered — they come only from
  versioned, verified rule packs. Finalized payroll is never reopened. Audit is an immutable hash chain.
- Payroll money from other modules enters only through tagged extension points (`PayrollInputSource`,
  `SettlementSource`): lines carry source ids, records are settled only when the run is finalized, nothing is paid twice,
  and the payroll engine version stayed `payroll-2.5` (no existing calculation changed).
- Compensation is read only through the `CompensationOutput` contract; architecture tests enforce module boundaries
  (e.g. no payroll words in Attendance/Leave code, no compensation models outside Compensation).
- Per-group options are "policy types" (typed questions, sections, follow-up questions) versioned and assigned by rules;
  every policy type automatically gets the step wizard. Organisation-wide switches live in the Setup centre.

## 5. What was built — by area

### 5.1 Setup centre and policy engine (cross-cutting)
- **Setup centre** (`app/Filament/Pages/SetupCentre.php`, `config/peopleos_setup.php`): every module's settings as typed
  forms, its policy types and configuration screens. Setting types: boolean, number, text, textarea, select, multiselect,
  roles, date, **matrix** (rows × yes/no), **file** (signed, audited download), grouped headings, nullable values,
  `locked_after` (e.g. reward-point value locks once points exist).
- **Policy form engine** (TO-1): HROne-style questions — yes/no, radio, checkboxes, time, tags (IP lists), sections,
  follow-up questions (`visible_when`), option sources.
- **Policy wizard** (every policy type): `app/Filament/Pages/PolicyWizard.php` + `PolicySetup` service. Steps: Policy →
  one step per section → Applicability (everyone / selected groups by company, BU, division, department, location, grade,
  level, designation, employment type, category, work mode, lifecycle state, gender / only named employees) → Advance
  applicability (named employees, end date) → Review (Save as draft / Publish from a date, reason required). Published
  versions are immutable (editing starts a new draft); rules the wizard adopts keep their name and priority; policies with
  rules it cannot express are left to the rule editor. "Duplicate" on every policy (HP-8d). Test: `PolicyWizardTest`.

### 5.2 Time office (TO-1 … TO-8) — Done
Check-in policy (web/mobile punch limits and windows, IP/CIDR, photos/face flag, Wi-Fi, polygon and temporary geo-fences,
location pings); request rules for regularisation, on duty, shift change, week-off change, short leave (role-based
backdating, future dates, day types, limits per week/month/year, attachments, punch required, within shift, after
processing); attendance policy (single/double/auto/none punch modes, flexi, count within shift, unpaid weekly off,
weekly-off/holiday entitlement, comp-off generation, absenteeism alerts, request lock during processing); shift master
(auto shift, absence criteria, cut-off, per-shift request switches, shift allowance paid via `ShiftAllowanceInput`);
holiday calendars (restricted-holiday proration, cancel lock); leave-type rules (ESI/age/marital eligibility, carry
forward modes, credit caps, encashment rules); biometric devices (sync servers, silent-device alerts).

### 5.3 Workforce — Done (WF-1, WF-2, WF-3)
Confirmation policy + confirmation reviews (confirm / extend / terminate, letters, manager feedback before decision);
separation policy wired (notice by status, backdating, retirement-due job, clearance checklist, letters, exit interviews
by employee / manager / HR on feedback templates); transfer policy + transfer requests (Approval Center), reasons master,
transfer allowances paid via `TransferAllowanceInput`; induction (onboarding template kind, start N days before joining,
induction evaluation); onboarding task groups, declaration text, "issue to current employees".

### 5.4 Payroll — partly Done
- **PS-1 pay component flags:** PT/LWF/ESI-eligibility wage flags, show on payslip/register, joining/exit month,
  rounding, fixed/variable nature, tax exemption by regime, claim rules.
- **PS-2 tax switches:** declaration day window, payroll may edit, Form 12BB visibility, regime estimator, Form 16
  self-service, new-regime bills. **PS-3** My compensation switches (via `PaidPayReader` contract).
- **HP-2/HP-3 (earlier):** loans, reimbursement claims, variable pay, salary holds, tax income entries (perquisites,
  outside income), statutory overrides, PF/VPF options, investment proofs, Form 12BB, payroll uploads.
- **BP-2** `input` components (CTC formula tags), structure balancing component, CTC mode (CTC or components).
- **BP-9** loan policy completion (request windows, yearly limit, EMI share of net, approver edits, guarantors with
  accept/decline), one open claim per component, claim not above bills, claim reminders, variable pay plans.
- **BP-13 / CA-5** pay modes (bank/cash/cheque, effective-dated per employee), company debit accounts, bank file layouts
  as data, per-bank account-number rules, cash & cheque register.
- **Proof of "no change by default":** `PayrollCompletionDefaultsTest` computes a run before/after with every new default
  and gets identical lines, net, engine version and a byte-identical bank file.

### 5.5 Expense & travel — Done (EX-A … EX-G)
New module `app/Domain/Expense`: expense groups, categories (39 HROne category rules), limits by grade/location group and
period (incl. % of CTC via `CompensationOutput`), location groups, tax types (names only, no rates), exchange rates;
expense and advance policies (wizard); receipts with capture-time rules (limits, frequency, attendance, tenure, before
joining, declaration, receipts above amount, duplicates, expiry, currency); reports split by workflow group, approve per
line / return / reject / cancel; advances ledger with FIFO adjustment and write-off; payroll payout/recovery and exit
settlement via input/settlement sources; travel requests (multi-leg, backdating, overlap, booking via helpdesk,
cancellation, attendance calendar overlay) with **per-workflow-group approval parts** (owner decision); 12 expense + 4
travel report templates, expense dashboard. Payment step on by default (reports wait on "Pay expenses").

### 5.6 Asset & Helpdesk — Done (HD-A/B/C, AS-A/B)
Helpdesk: category purpose / RCA required / feedback, root cause on resolve, SLA visible to employee, resolver rules
(priority, specificity, intimation role), service hours on SLA policies, `helpdesk` access policy. Asset: sub types,
duplicate-serial rule, acknowledgement tasks and reminders, product fields and documents, owner role, fulfilment days,
exit recovery switches, `asset` entitlement policy, asset requests (new / return / replacement) with Approval Center
source, joiner provisioning, exit return requests **on by default with exceptions by exit type and asset type** (owner
decision).

### 5.7 Performance — Done HP-8a…8f (HP-8g…8j building)
Masters (perspectives, initiative types, PIP reasons, units), terminology labels / OKR terms, rating publish (employees see
nothing until published, in manual mode), goal-edit window, decimals/rounding, 1-on-1 scope and feedback, check-in
prompts, rating-scale ranges, per-cycle competency scale, KPI rating formulas (suggest/prefill/lock, reverse KPIs); goal
sheet policy (creation mode, field rights per role, weightage/count rules, approval, review levels incl. approver review,
competencies, hidden scorecard parts, copy last level), **manager-owned system mapping with employee proposals** (owner
decision), goal templates, PIP policy (duration, extensions, letters, checklists, plan and outcome approval). Defect fixed:
competency weightage now used in scoring (finalized/acknowledged appraisals are never re-scored).

### 5.8 Engage — Done (HP-9a … HP-9f)
Work and wedding anniversaries, welcome message, handbook acknowledgement due days and download, per-employee
acknowledgement list; mission statement and core values (Home card); badges (9 system + custom), monthly quota,
certificates via Letters, LinkedIn share, wall of fame; reward points (budget, credits, peer giving, badge points, expiry,
optional payroll redemption — off by default, payroll unchanged when off); social wall (posts from events, reactions,
comments, moderation); happiness index / mood check-ins through the anonymity design (identity stored apart from answers,
privacy threshold with complementary suppression).

### 5.9 Core HR — Done CA-1…CA-5 (CA-6/9/10/11/16 building)
Setup plumbing (matrix/file types, Access rights module), company/enterprise fields (industry, contacts, logo, social),
GSTIN registration type (format validation data-driven), sub-departments (also in rules and reports), designation fields,
qualifications with specialisations, separation reasons with usage rules, rehire-block reasons, editable option lists,
document rules (sample file, employee view/upload, mandatory documents + missing-documents report, expiry alerts,
documents required with profile fields, optional block on joining).

### 5.10 Automation, Forms & templates, Governance — Done P1, P2, P3, P4, P5, P9, P13, P14
Password policy completion (expiry **enforced** — previously configured but not enforced — warnings, history, symbol,
personal-detail block; disable login by lifecycle state), number series (loans, letters, exits, alumni, tickets,
employee codes by employment type — same numbers as before by default), roles (status, members, roles-by-person,
relievers, workflow fallback approver), import governance (self / maker-checker / workflow approval, Recent uploads,
audited downloads), Approval Center actions (request types, bulk approve, discussion, send back, reassign), out-of-office
delegation, inbox archive, employee self-service lock, workflow coverage for regularisation / loan / claim / variable pay /
compensation change / letter / resignation / investment declaration, document node → Letters, functional-manager and
HRBP approvers, auto-decide escalation, first-match-only + priority; feedback templates + question bank.

## 6. Test evidence

All tests run on **SQLite in memory** (Pest, through a guard script that refuses any other database). Latest full runs in
the working tree after the most recent merge (governance P4/P5, 2026-10-29): HroneParity 437 · Architecture 144 · Security
68 · Identity 34 · Platform 69 · Workflow 24 · Experience 160 · Leave 21 · Attendance 62 · Payroll 66 · Compensation 42 ·
Letters 10 · Exit 12 · ServiceDesk 39 · Admin 75 · Offboarding 61 · Assets 3 · Compliance 165 · Audit 17 · Notifications 5
· Configuration 28 · Engagement 37 — all passed. Earlier merges also ran Entitlements 71, Analytics 12, Performance 25,
Onboarding 5, Employment 77, Organisation 17, Documents 8, Lifecycle 5, Api 10, Career 7, Talent 22, Learning 30,
Workforce 26, Enterprise 18 — all passed.

Two pre-existing flaky tests were found and fixed (random names with apostrophes compared against HTML-escaped output;
the ExitTest one is fixed, the LetterStudioTest one is known). Existing tests changed on purpose are noted per package in
`docs/architecture/hrone-parity.md` (e.g. CapabilityCatalogTest counts 20 modules; AdminPagesRender searches the paged
settings list; two anonymity models added to the not-auto-audited list).

**Not verified:** none of the 50 migrations has been run against MySQL (the dev database); queries and index names were
checked by hand only (names < 64 chars). No browser/visual testing of the new screens was done.

## 7. Owner decisions taken

| Date | Decision |
|---|---|
| 2026-10-29 | Password expiry: accounts with no recorded change date change their password at next sign-in when a tenant sets expiry. |
| 2026-10-29 | Goal sheet system mapping: reporting manager owns the final sheet; employee proposes/updates, manager confirms. |
| 2026-10-29 | Asset return on exit: raised by default, configurable exceptions, none when no assets held. |
| 2026-10-29 | Trips across workflow groups: one trip record; one approval part per group; approved when all approve, rejected if any rejects. |

## 8. Decisions still open for the owner

- Performance: peer reviewers no longer see colleagues' ratings (privacy fix — confirm); 1-on-1 host may update the meeting.
- Expense: return of a report discards other approvers' line decisions; over-limit claim without reason is capped (not
  refused); advances approved for payroll may be dispensed by bank before payroll; `expense.exit.pending_reports` default
  `ignore` (alternative `block`); payment step on by default; cover-letter variables in the Letter studio guide.
- Approval Center: send back keeps the request "pending" + an open return (instead of a new "returned" status); requesters
  only reply in discussions.
- Workflow: automatic decisions record no person; a rejected resignation workflow withdraws the resignation; delegations
  do not chain.
- Workforce: unpaid transfer allowances at exit → full & final settlement?
- Core HR: data-privacy acceptance enforcement; meaning of HROne "Identifier 1/2"; billing contact visible to tenants;
  GST state-code list as config reference data.
- Engage: keep seeded "Super Woman" badge name; handbook acknowledgement due date default; voucher and WhatsApp providers.

## 9. Pending and blocked work (not built)

- **Payroll:** BP-10 arrears & settlement (10(10AA) rule version needs owner verification), BP-11 employee statutory
  options & templates, BP-12 pay groups as payroll units / templates, BP-14 payslip language (password-protected PDF waits
  for a PDF-library decision), BP-15 statutory data (minimum wages, remaining states' PT/LWF — needs verified sources),
  BP-16 contracts & automatic letters, BP-17 mid-year go-live; BP-7/BP-8 remainders.
- **Core HR / Access / Integration:** CA-12 custom dimensions, CA-13 journal voucher, CA-17 projects & timesheets; CA-7
  geography (needs an approved district/city source), CA-8 Wi-Fi bank + map picker (map provider), CA-14 SAML/SCIM
  (library + IdP), CA-15 BGV/e-KYC vendors — seams only.
- **Governance:** P6 notification catalogue, P7 task automation & scheduled services, P8 letter acceptance / process /
  multi-signatory, P10 record corrections, P11 employee data export & protected files, P12 form layouts & picklists;
  P15 tenant billing view, P16 tenant mail transport, P17 transaction purge (owner decisions).
- **Performance:** HP-8k "delete transaction" (conflicts with immutability; owner decision).
- **External:** map distance, email-to-expense/ticket, voucher provider, WhatsApp provider.

## 10. Deployment steps (owner)

1. Back up the dev database. Run `php artisan migrate` (50 migrations, `2026_11_12_100001` … `2026_12_01_200001`, plus any
   from the two builders still running). **Recommended first:** run them on a scratch MySQL copy.
2. `php artisan peopleos:sync-permissions` — new keys: `payroll.override`, `expense.view/manage/claim/approve/pay/travel_desk`
   (+ role `expense-admin`), `recognition.view/give/manage/points`, `wall.view/post/moderate`, `engagement.mood`,
   `feedback.view/manage`, `asset.request`, `task.delegate`, `task.delegate_any`.
3. `php artisan optimize:clear` (config changed: settings, policy types, rule fields).
4. Seed masters for existing tenants where offered: "Add standard entries" (performance masters), "Add standard badges",
   "Add standard reasons" (mood), "Add the expense dashboard", "Feedback templates (starter)" pack.
5. New scheduled commands (documented in `docs/operations/queue-and-scheduler.md`): attendance absence-alerts and
   device-alerts, lifecycle confirmation-reviews and retirement-due, assets acknowledgement-reminders, security
   login-status, performance check-in-prompts, expense expire, onboarding start-due, payroll claim-reminders and
   variable-pay-tasks, engage credit-points, inbox archive.

## 11. Known risks and limitations

- Large uncommitted change set (≈ 695 paths across programmes); merges were done by patch with manual conflict resolution
  in shared files (`config/peopleos.php`, `ParityServiceProvider`, `DataInventory`, `SetupCatalogue`,
  `PolicySettingsSchema`). Regression suites passed after every merge, but semantic conflicts outside tested paths are
  possible.
- MySQL not exercised (see §6). SQLite does not enforce varchar lengths or catch some identifier issues.
- HROne field meanings partly inferred from control names where labels were not captured.
- Several features rely on external providers not chosen yet (maps, voucher, WhatsApp, PDF library, SAML).
- Payslip PDFs do not exist in PeopleOS (HTML only), which limits payslip protection/logo items.

## 12. Questions a reviewer should check

1. Do the six matrices cover every field of HROne's Setup for the 14 in-scope sections, or are sections/fields missing
   (e.g. anything in Integration, Access rights or Governance not listed)?
2. For a sample of rows marked "→ built", does the code named in `docs/architecture/hrone-parity.md` actually implement
   the HROne behaviour described in the matrix row?
3. Are the "default reproduces today's behaviour" claims credible (look for any default that changes behaviour beyond
   those listed in §7–§8)?
4. Payroll safety: can any new input source pay or recover twice, or alter a finalized run? (See
   `app/Domain/Payroll/Inputs/*`, `ExpensePayrollTest`, `TransferAllowancesTest`, `PayrollCompletionDefaultsTest`.)
5. Access control: do the new self-service lock, delegation, visibility and Approval Center sources fail closed (no data
   visible outside access scope, no deciding one's own request)?
6. Anonymity: can a mood check-in or survey answer be linked to a person outside the audited reveal process?
7. Are any statutory values hard-coded anywhere in the new code?
8. Which pending packages (§9) matter most for a typical Indian mid-size client go-live, and in what order?

## 13. Where to look

- Status register: `docs/hrone-parity/setup/00-build-status.md`
- Engineering record: `docs/architecture/hrone-parity.md`
- Gap matrices: `docs/hrone-parity/setup/0*.md`, `1*.md`; earlier gap register `docs/hrone-parity/HROne-Gap-Register.md`
- Tests: `tests/Feature/HroneParity/*` (68 files)
- Code: `app/Domain/{Expense,Recognition,Feedback,Performance,Assets,ServiceDesk,Workflow,Experience,Payroll/Inputs,…}`,
  `app/Filament/{Pages,Resources,Support}`, `config/peopleos.php`, `config/peopleos_setup.php`
