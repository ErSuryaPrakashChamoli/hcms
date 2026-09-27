# Phase 6 — Lifecycle and Onboarding

Implements blueprint §121 Phase 6: RMS integration, preboarding, onboarding, background
verification, document collection, joining, probation and confirmation. Domains:
`App\Domain\Onboarding`, `App\Domain\Documents`, `App\Domain\Bgv`, `App\Domain\Integration`.

## RMS hand-over and the integration API (§87, §89)

- `routes/api.php` under `/api/v1`, authenticated by tenant API keys (`X-Api-Key: <prefix>.<secret>`).
  Keys are issued from Integrations → API keys with scopes (`rms.write`, `rms.read`, `bgv.write`);
  only a SHA-256 hash of the secret is stored and the plaintext is shown once. `AuthenticateApiKey`
  binds the key's tenant, checks scopes, stamps the audit source as `api:<key name>`.
- `POST /api/v1/pre-employees` → `CreatePreEmployeeAction`: person + employee (`source = rms`,
  `external_reference`, `expected_joining_date`, offer acceptance) + first position resolved from
  codes (company, location, department, designation, level, grade, employment type) + line manager
  by employee code, then lifecycle pre-employee → preboarding. Idempotent on the offer reference.
  `GET /api/v1/pre-employees/{reference}` returns status and onboarding progress.

## Onboarding (§21)

- `onboarding_templates` carry rule-engine conditions and a priority, like policies;
  `onboarding_template_items` define phase (preboarding, day one, first week, 30/60/90 from
  `config('peopleos.onboarding.phases')`), type (task, document, form, acknowledgement), owner
  (employee, manager, buddy, HRBP, role, user), due offset and whether mandatory.
- `Onboarding::start()` picks the best template, creates `onboarding_plans` +
  `onboarding_tasks` with due dates from the joining (or expected joining) date and resolved
  owners, writes the timeline and notifies owners. `complete()` / `skip()` enforce ownership or
  `onboarding.manage`, keep the plan's progress, and complete the plan when nothing mandatory is
  open (timeline, `onboarding.completed` event).
- `AutoStartOnboarding` starts a plan when an employee enters preboarding or joins. HR can also
  start one from the Employee 360; the inbox has an "Onboarding tasks" tab.

## Documents (§39, §83)

- `document_types` (seeded per tenant from config: PAN, Aadhaar, passport, address proof, degree,
  relieving letter, payslips, bank proof, offer, BGV consent) and `employee_documents` metadata.
- Files go to the private `peopleos.documents.disk` under `tenants/{id}/employees/{id}/…`; the
  path is never exposed and is excluded from audit diffs. Re-uploading a type bumps the version
  and archives the previous one.
- Downloads: authenticated request → policy → `URL::temporarySignedRoute` (10 minutes) →
  `DocumentDownloadController`. Sensitive categories (identity, tax, statutory, medical) log a
  VIEW audit event on download.
- Verification (verify / reject with note) writes the timeline; `documents.expiring` reminders
  come from the daily sweep.

## Background verification (§22)

`bgv_cases` (provider, external reference, consent timestamp/document, status, overall result)
with `bgv_checks` per type (identity, address, education, employment, reference, criminal,
document). `Bgv::initiate()` demands consent and blocks a second open case; `recordCheck()`
progresses the case and, once every check is closed, sets the overall result to the worst
individual one, writes the timeline and fires `bgv.completed`. Providers are adapters in
`config('peopleos.bgv.providers')` (manual in-house for now); vendors post results to
`POST /api/v1/bgv/cases/{reference}/checks` with a `bgv.write` key.

## Joining, probation, confirmation

- "Mark as joined" on the Employee 360 stamps the joining date and moves pre-employee /
  preboarding → joined → probation.
- `peopleos:lifecycle:reminders` (daily 06:00) emits `employee.joining_due`,
  `employee.probation_ending`, `employee.probation_overdue` and `document.expiring` as
  `EmployeeReminderDue` / `DocumentExpiring` events. Windows are tenant settings
  (`employee.joining.reminder_days`, `employee.probation.reminder_days`,
  `documents.expiry.reminder_days`). Notification rules and workflow triggers react; the seeded
  "Probation confirmation" workflow is the manual path, and a workflow on
  `employee.probation_ending` automates it.
- The dashboard's People Control Centre widget shows joiners this week, probations ending and
  overdue, overdue onboarding tasks, open verifications, documents to verify and pending approvals.

## Permissions

`onboarding.{view,manage,act}`, `document.{view,upload,verify,delete,types}`, `bgv.{view,manage}`,
`api_key.manage`. Employees get `onboarding.act`; managers `onboarding.view/act`.

## Not in this phase

- Employee self-service preboarding (the candidate filling their own data) waits for the
  employee portal (Phase 12); today HR captures on their behalf and the checklist tracks it.
- Real BGV vendor drivers and e-signature (§22, §40) arrive with the Integration Hub.
- Virus scanning of uploads (§82) is an infrastructure task on the storage disk.

## Tests

`tests/Feature/{Api,Onboarding,Documents,Bgv,Lifecycle}` and
`tests/Feature/Admin/OnboardingPagesRenderTest.php`: key auth and scopes, pre-employee creation
and idempotency, code validation, tenant isolation, revocation; template selection, task
generation, ownership, progress, auto-start, cancel; private storage, versioning, verification,
signed downloads and sensitive access audit, expiry sweep, deletion; BGV consent, aggregation,
vendor callback, close; reminder events feeding rules and workflows; page renders, inbox
completion, mark-joined and key issuance.
