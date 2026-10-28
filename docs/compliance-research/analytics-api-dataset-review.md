# Analytics read API: dataset review and volume-test plan

Prepared 2026-10-28 for the owner's review of the read-API fix. The field lists are generated from the code, not
copied from a report (`test-runs/analytics-dataset-review-from-code-2026-10-28.md`, by a script that reads only the
dataset definitions and the configuration). **Status: fix implemented and tested. Owner review and the volume test
are open.** No excluded dataset or field may be restored without the owner's explicit approval.

## How every API report run is controlled (verified in code)

`GET /api/v1/reports/{report}/run` (`ReadController::runReport`, `ReportRunner::unattendedFields`). The checks run in
this order:

| # | Check | When it fails |
|---|---|---|
| 1 | The API key is valid, active, not expired, and its tenant is accessible (`AuthenticateApiKey`) | 401 |
| 2 | The key holds `reports.run` | 403 |
| 3 | The report exists in the key's tenant | 404. Another tenant's report is never found |
| 4 | The report is shared | 403, audited |
| 5 | The dataset is listed in `peopleos.analytics.unattended_datasets` | 403, audited (`dataset_not_reviewed`) |
| 6 | The key holds every data scope listed for that dataset | 403, audited (`scope_missing`) |
| 7 | The owner (if the report has one) is an active user of this tenant | 403, audited (`owner_inactive`) |
| 8 | The owner may use the dataset (`allowedFor`) | 403, audited (`refused`) |
| 9 | Every selected, filtered, grouped or aggregated field is on the reviewed list. A listed field that is sensitive or permission-gated is never used | 403, audited (`refused`), before any row is read |

- **Rows:** the run executes as the owner (`auth()->setUser`, restored afterwards), so the owner's organisation scope
  applies to employee-scoped models. Starter reports with no owner run with the key's tenant-wide reach.
- **Audit:**
  - every successful run records `EXPORT` (`channel: api`, key, owner, rows, total, columns);
  - every refusal records `REJECTED` (`channel: api`, key, refusal code);
  - a refusal returns a message and no rows.
- **Revoked access.** A revoked or expired key gets 401. An owner who is suspended or deleted, or who lost the dataset
  permission, gets 403.

## Dataset by dataset

| Dataset (purpose) | Key scopes besides `reports.run` | Fields returned | Removed: sensitive or permission-gated | Removed: not reviewed for the API | Rows |
|---|---|---|---|---|---|
| **employees** (employee master, headcount, tenure) | `employees.read` | 34: code, name, gender, work email and phone, lifecycle state, hire source, joining, probation, confirmation and exit dates, tenure and anniversary figures, the organisation units, manager, headcount | date of birth, blood group, personal email, personal phone, annual CTC | age, age band, birthday, birthday this year, next birthday (with age they rebuild the date of birth); marital status; nationality; expected joining date; cost centre; FTE; has a login | Tenant and owner's scope |
| **attendance** (one row per employee per day) | `attendance.read`, `employees.read` | 33: date, day and month, employee and organisation, status, shift and times, hours, late, early leaving, overtime, absence, leave, missed punch, regularised, holiday, exceptions | none | the free-text note | Tenant and owner's scope |
| **leave** (leave requests) | `leave.read`, `employees.read` | 22: dates, month, employee and organisation, type and code, paid, sessions, days, status, reason, reviewed-on, review note, on leave today | none | applied-on, pending days, backdated, reviewer's name | Tenant and owner's scope |
| **payroll** (finalised and paid salary register) | `payroll.read`, `employees.read` | 30: period, employee and organisation, days, gross, earnings, deductions, net, employer cost, basic, HRA, PF, ESI, PT, LWF, TDS, entry and run status | UAN, ESI number, bank, IFSC, account number (even masked) | payment date, taxable earnings | Tenant and owner's scope; finalised and paid runs only |
| **exits** (exit cases) | **excluded** | none | — | all 26, the dataset is excluded. It carries the exit-interview reason, rehire eligibility and the settlement amount, and no API scope covers exit cases | — |
| **performance** (appraisals) | `performance.read`, `employees.read` | 8: cycle, employee code and name, department, status, final rating, final label, promotion recommended | none | manager, goal score, competency score, self rating, manager rating | Tenant and owner's scope |
| **learning** (enrolments) | `learning.read`, `employees.read` | 13: course, category, employee code and name, department, status, mandatory, progress, score, due, completed | none | none | Tenant and owner's scope |
| **assets** (asset register) | `assets.read` | 8: tag, name, category, status, condition, location, warranty until, count | none | custodian, purchase date, purchase cost | **Tenant only:** the asset model has no organisation scope, for signed-in users either |
| **service desk** (HR tickets) | `servicedesk.read` | 11: number, service, category, employee code, priority, status, raised on, month, resolution hours, SLA breached, count | none | agent, satisfaction | Tenant and owner's scope; restricted tickets and drafts never enter |

All 32 report-library datasets added on 2026-10-27 are excluded from the API: family, emergency contacts, statutory
and bank details, CTC, revisions, payslips, tax, settlements, improvement plans, users and the rest.

**Review standard.** A field is listed only when the endpoints of the scopes the key must hold already return it, or
when it is computed from such fields or from reference data (calendar, organisation units, shift definitions).
Masking was not treated as protection: masked identifiers are removed.

## Test evidence

`tests/Feature/Enterprise/ReadApiReportAccessTest.php`, 9 tests, all passing. On the original code 6 of them fail
(`test-runs/analytics-fix-mutation-original-code.txt`).

| Required case | Test |
|---|---|
| Unauthenticated; invalid, revoked or expired credentials | "refuses a request without a usable key…" (none, malformed, wrong secret, revoked, expired: 401, no data) |
| Key without `reports.run` | "refuses a key without reports.run…" |
| `reports.run` but an unapproved dataset | "refuses reports on datasets not reviewed…" (family, CTC, statutory and bank, exits) |
| Allowlisted dataset, insufficient permission | "refuses a reviewed dataset when the key lacks its data scope, the owner lacks the dataset, or the owner is inactive" |
| Cross-tenant | "never runs another tenant's report" |
| Employee-level row restrictions | "returns only the rows the report owner may see" |
| Sensitive fields and exports | "returns only reviewed fields…", including the owner's own signed-in export, which keeps what the owner may see |
| Audit of permitted and denied runs | "audits every API run…"; the refusal tests assert `REJECTED` with the refusal code |
| No-user runs and the list's integrity | "refuses a run without a user unless…"; every listed field exists and is not flagged, every scope is a real API scope |

## Volume-test plan (not run; needs the owner's authorisation and a disposable environment)

**Why.** Each run streams the whole dataset (500 rows at a time), filters and groups in PHP, and stops at 10,000
matching rows (`analytics.max_rows`). The API returns at most 5,000 rows. There is no database-side pagination and no
cursor.

**Environment.**
- A disposable MySQL 8.4 database on hardware like production, never a shared one.
- One synthetic tenant: 50,000 employees across 3 companies and 40 locations, 24 months of attendance (about 24
  million rows), 12 finalised payroll runs (600,000 entries with lines), 200,000 leave requests, 2,000 appraisals and
  5,000 assets.
- One unscoped owner, and one owner scoped to a single location.
- A seeding script under `tools/`, reviewed first.

**Cases.** For each of the eight allowed datasets:
- the starter report and a 5,000-row flat report;
- a grouped report;
- a report whose filters match nothing (the full scan);
- for attendance and payroll, a run that hits the 10,000-row cap (truncation flagged).

Run each as the unscoped owner and as the scoped owner, at concurrency 1, 5 and 20.

**Measure.**
- Latency p50, p95 and p99.
- PHP peak memory per run (`memory_get_peak_usage`).
- Database load: queries per run, rows examined (`performance_schema`), lock waits.
- Worker and HTTP timeouts.
- The 120-per-minute API rate limit under concurrency.
- Correctness: row counts against a direct SQL count for the scoped owner (organisation scope holds); no field outside
  the reviewed list in any response; every run audited exactly once.

**Proposed acceptance thresholds** (for the owner to confirm):
- p95 under 10 s for grouped reports and under 20 s for 5,000-row flat reports at concurrency 5;
- peak memory under 256 MB per run;
- no timeout at concurrency 5;
- zero access-control or audit deviations.

**Likely follow-ups if thresholds fail:**
- database-side filters for indexed columns (date, status);
- a cursor parameter for the API instead of `limit`;
- a smaller API cap;
- a queued export for large API results.

**Not done:** no large-tenant test has been run; it must not run against a shared environment without authorisation.
