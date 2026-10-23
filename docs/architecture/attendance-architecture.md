# Attendance & Workforce Time — architecture

Frozen in Phase 2 (27 September 2026). Describes what is implemented; planned items are marked *deferred*. Contract references point at `peopleos-architecture-contract.md`.

## 1. Pipeline

```
Attendance source (device adapter · API · web · mobile · manual · import)
  └─ PunchIngestion::record()            raw punch, fingerprint-unique per tenant, never rewritten
       └─ ProcessAttendanceDay (queued, TenantAwareJob)    work date chosen by punch window
            └─ AttendanceProcessor::process()
                 ├─ ShiftResolver         schedule assignment / rule → shift for the date (effective-dated)
                 ├─ AttendanceTimezone    location tz → shift tz → app tz
                 ├─ HolidayResolver       calendar by rule
                 ├─ PolicyResolver        attendance + overtime policy versions effective on the date
                 ├─ LeaveDayResolver      contract implemented by the Leave domain
                 ├─ approved regularisations (override IN/OUT, WFH / on duty / field duty)
                 └─ AttendanceRecord      status, times, minutes, exceptions, overtime, basis, version
                      ├─ exceptions → Exception Centre, notifications, workflows
                      ├─ regularisation → approval → recalculation (snapshots kept)
                      ├─ overtime review → approved quantity
                      └─ AttendanceOutput → payroll-ready quantities
```

Vendor neutrality: devices are `attendance_devices` rows with an `adapter` key (`generic`, `essl`); adapters implement `BiometricAdapter::normalise()` and return employee code, timestamp, direction, external id. The domain never references a vendor.

## 2. Raw punches (`attendance_punches`)

| Column | Meaning |
|---|---|
| employee_id (nullable) | resolved employee; null when the code was unknown (the punch is kept as `failed`) |
| punched_at | stored in the application timezone (UTC in production) |
| source / source_type | legacy source label / canonical type: biometric_device, mobile, web, api, manual, import |
| source_timezone | timezone the source reported in, when given |
| attendance_device_id, external_id | device registry and the device's punch id |
| fingerprint | sha256(tenant + device:external_id) or sha256(tenant + employee/code + minute + direction); **unique per tenant** |
| payload | what the source sent (only visible with `attendance.manage`) |
| received_at, processing_status, processing_error, processed_at | lifecycle: received → normalized → processed / failed / ignored |
| correlation_id | request id or import/API correlation |

Idempotency: the pre-check plus the unique index make retries safe for devices, API, queue retries and imports; a lost race throws `UniqueConstraintViolationException`, which ingestion treats as a duplicate. Punches are never updated except for their processing state. `PunchIngestion::retry()` re-resolves a failed punch from its payload.

## 3. Shifts, breaks, schedules, weekly offs, holidays

- `shifts`: fixed or flexible; `start_time`/`end_time` with `crosses_midnight`; `timezone` (optional); grace in/out; full/half-day minutes; overtime eligibility and minimum; effective dates. `Shift::startsAt($date, $tz)` / `endsAt()` compute the scheduled instants in the business timezone and return application-timezone instants.
- `shift_breaks`: named breaks with duration and paid/unpaid; `Shift::unpaidBreakMinutes()` sums unpaid breaks, falling back to the single `break_minutes` when none are configured.
- `work_schedules`: one or more weekly patterns (`[['mon' => shiftId|null, …], …]`); multi-week patterns rotate from the assignment anchor, which expresses second/fourth-Saturday and rotating weekly offs; `null` = weekly off.
- `work_schedule_assignments` (per employee, effective-dated) and `work_schedule_rules` (organisation conditions, priority) resolve the schedule for a date; `holiday_calendars` + rules resolve holidays. Leave is consulted through `LeaveDayResolver`.
- Holiday library (`HolidayLibrary`): platform configuration, no `tenant_id`. Operators upload a CSV per country and year on **Platform → Holiday library** (`date,name,type,half_day,region_code`; an older file's `region_name` column is accepted and ignored). Any ISO 3166-1 country works (`App\Support\Geography\Countries`, from ICU via ext-intl). A blank region is the national list. A region code must be active on the country's region list, with or without the country prefix (`US-CA` = `CA`), and the region's name comes from that list. Dates are `YYYY-MM-DD`, `YYYY/MM/DD`, or with an English month word, day or month first (`26 Jan 2027`, `Jan 26, 2027`); never ambiguous `01/05/2027`. Types are `public` (or national, statutory, gazetted, bank, federal) and `optional` (or restricted, floating). The upload dialog shows this format column by column with a sample file, built from `HolidayLibrary::FORMAT` and `templateCsv()`, and offers the template download. Each region becomes a `holiday_library_lists` row (country, region `*` = national, year) with its `holiday_library_entries`. One invalid row refuses the whole file. Uploads land as draft and replace that region's list (entries for unchanged dates keep their ids); tenants see only published lists. Publish → withdraw → delete; a published list must be withdrawn before deletion. Audited explicitly on the platform chain.
- Region list (`country_regions`, `CountryRegions`; platform configuration, no `tenant_id`): every country's regions, pre-filled by migration with the 3,590 top-level ISO 3166-2 subdivisions of 200 countries from `database/data/countries/regions.php` (generated from Debian iso-codes 4.20.1, LGPL-2.1-or-later; first level only, so the UK has its four nations; bracketed alternates dropped; Indian names without transliteration marks). Codes carry no country prefix and never change. Operators manage it on **Platform → Regions**: add a region, rename it (the name follows into its holiday lists), retire or restore it (a retired region cannot be used in new uploads; its lists stay), and add any standard regions the list lacks (`syncStandard`, which never renames, restores or removes). Audited explicitly on the platform chain. `CountryRegions::match()` maps free text to a region by code, prefixed code or name, ignoring case and accents. Locations' state code field suggests the codes of the location's country. Note: the bundled ISO data codes Telangana `TS`, while `peopleos.compliance.states` uses `TG`; locations match by name either way.
- Tenants adopt from published lists with **Holiday calendars → Create from holiday library**, or **Add from holiday library** on a calendar's holidays tab: country → year → region (the national holidays plus the region's), then tick the holidays the organisation observes (public ones start ticked). The picker starts on the tenant's company country, and on the region its active locations in that country are in when they are all in one region; each region names the tenant's locations in it (`HolidayLibrary::locationRegions`). `HolidayLibrary::adopt` copies them into the tenant's `holidays` with `holiday_library_entry_id` (null on delete), skips dates the calendar already has, refuses entries of unpublished lists, and requires `attendance.manage` on the calendar. Copies are the tenant's: later uploads, withdrawals or deletions never change a tenant calendar. Every holiday in a calendar, optional ones included, is a day off for everyone the calendar applies to (no per-employee choice of restricted holidays yet).
- Deferred: split shifts, daily overrides/rosters, compressed weeks (the pattern model supports them additively).

## 4. Calculation engine (`AttendanceProcessor`, engine version `2.0`)

Deterministic and transactional (row lock on the record). Steps: resolve shift/timezone/holiday/policies/regularisations/leave → punch window (shift ± `attendance.punch_window_hours`, or the local calendar day for flexible/no shift) → first IN / last OUT (`resolveInOut`: directed punches win, earliest IN, latest OUT; single punch, only-OUT, only-IN and reversed sequences raise `missing_in`, `missing_out`, `invalid_sequence` and mark the day `incomplete`) → regularisation overrides → late/early with grace → worked = span − unpaid breaks → status → overtime → record.

Statuses: present, half_day, absent, incomplete (missing punch), weekly_off, holiday, leave, unpaid_leave, wfh, on_duty, field_duty. Exceptions: missed_punch, missing_in, missing_out, invalid_sequence, late, early_leave, short_hours, absent, no_shift, holiday_work, overtime. Regularisation-pending and regularised are derived (`is_regularised`, pending requests).

Each record stores `scheduled_start/end/minutes`, `break_minutes`, `timezone`, `calculation_version` and `calculation_basis` (schedule, shift, policy version ids, holiday, grace, breaks, thresholds, punch ids, regularisation ids, leave request, window), so any day can be explained and reproduced. Historical days use the assignment/shift/policy versions effective on that date; changing a shift's grace today does change a *reprocessed* old day — the basis shows which values were used, and reprocessing is an explicit action.

Overtime: eligible when the shift or an overtime policy says so; minutes beyond the full day (beyond zero on holidays/weekly offs) above the minimum; policy rounding (`rounding_minutes`) and daily cap (`max_daily_minutes`); `overtime_status` none → pending (approval required) → approved / rejected; a review survives reprocessing while the calculated quantity is unchanged.

## 5. Regularisation lifecycle

`Regularisations::request()` (policy window, one pending per day, locked days refused) → pending → `approve()` (row lock; stores `original_snapshot`, recalculates, stores `resulting_snapshot`) / `reject()` / `cancel()` by the requester. Types: missed_punch, late, early_leave, wfh, on_duty, field_duty, absent. Audit actions `REGULARISATION_REQUESTED/APPROVED/REJECTED`; events `attendance.regularisation_requested/approved/rejected/cancelled`. Raw punches are never edited.

## 6. Overtime boundary

Attendance produces `overtime_minutes` and the reviewed `overtime_approved_minutes` (+ status, note, reviewer, time). Audit `OVERTIME_APPROVED/REJECTED`; events `attendance.overtime_approved/rejected`. No rates, wages or earnings exist in the domain (architecture test).

## 7. Payroll-ready contract

`AttendanceOutput::forEmployee(employee, from, to)` → `AttendanceDay` objects: status, scheduled/worked/break/late/early minutes, overtime and approved overtime minutes with status, paid-day and half-day flags, regularised/locked flags, calculation version, adjustments (leave request, half-day leave, holiday). `summary()` aggregates paid days, absences, minutes, unprocessed days. Payroll locks days (`is_locked`) when a run is finalised; locked days are not recalculated.

## 8. Leave-ready contract

`App\Domain\Attendance\Contracts\LeaveDayResolver::approvedLeaveOn(employee, date): ?LeaveDay` (request id, session full/first_half/second_half, paid flag, type code). The Leave domain binds `AttendanceLeaveDayResolver`; Attendance never reads balances or approvals directly.

## 9. Security model

Tenant scope on every table; `ScopedByEmployee` on punches, records, regularisations and schedule assignments; `AttendanceRecordPolicy` checks permission **and** `AccessScopes::allows()` (organisation + relationship scope) for view/update/approve/regularise, and self-access for employees; the records and regularisations resources restrict employees without `attendance.view` to their own rows server-side; the API binds the tenant from the key and never returns payloads; payload column is visible only with `attendance.manage`; punch imports need `attendance.manage` + `employee.import` and use the private disk.

## 10. Queue and scheduler

`ProcessAttendanceDay` implements `TenantAwareJob` with `BindTenantContext`; it is dispatched per affected work date on every accepted punch (`attendance.process_on_punch` setting) and by retries; a failure marks the day's punches `failed` with the error. `peopleos:attendance:process` (02:00, overlap-guarded, one server) recalculates the previous day for every employed employee per tenant.

## 11. Troubleshooting

| Symptom | Check |
|---|---|
| Day shows `incomplete` | Punches register: direction and window; a lone or only-OUT punch; regularise or add the missing punch |
| Punch `failed` with unknown code | Employee code in the device; retry after fixing the employee |
| Late minutes look wrong by hours | Work location timezone vs device timezone (`source_timezone`); the record's `timezone` and `calculation_basis.window` |
| Overnight shift punches land on the wrong day | Shift `crosses_midnight`; the window is start−4h … end+4h |
| Duplicate punches on repeated sync | Not possible for same device+external id; same-minute duplicates are dropped |
| Nothing recalculates after a punch | Queue worker not running (`docs/operations/queue-and-scheduler.md`) |
| Record cannot be changed | `is_locked` by a finalised payroll run |

## 12. Configuration guide

Shifts (timezone, breaks, grace, thresholds, overtime) → work schedules (patterns, weekly offs) → assignments or rules → holiday calendars → attendance policy (grace, regularisation window, approval) and overtime policy (minimum, rounding, daily cap, weekly cap, approval) → devices (adapter, timezone in settings) → settings `attendance.punch_window_hours`, `attendance.process_on_punch`, `attendance.regularisation.window_days`.
