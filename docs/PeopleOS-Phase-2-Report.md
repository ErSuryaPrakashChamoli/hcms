# PeopleOS Phase 2 Report — Attendance & Workforce Time Foundation

Performed on branch `main` from HEAD f8830b9 (Phase 1). Architecture detail: `docs/architecture/attendance-architecture.md`. No Phase 0/1 decision was reopened; no payroll, leave, Integration Hub, AI, RMS or Legal Entity work was started.

## Discovery

An attendance module already existed (devices + adapters, punches, shifts, weekly/rotating schedules, schedule rules, holiday calendars, processor, regularisations, exception centre, device-push API, 16 tests). Phase 2 extended it; no parallel model was created. Gaps found: no punch lifecycle or DB-level idempotency, unknown-employee punches dropped, no timezone model, single break value, fragile first/last punch logic, no calculation basis/version, overtime had no status/rejection, regularisations kept no snapshots and had no cancel, no concurrency locks, processing only nightly, attendance coupled directly to the Leave service, API read-only and payload-agnostic, employees could list other rows through the resource.

## Implemented

- Raw punches: `source_type`, `source_timezone`, `fingerprint` (unique per tenant), `received_at`, `processing_status/error/processed_at`, `correlation_id`; nullable employee for retained failed punches; `PunchIngestion::retry()`.
- Queued `ProcessAttendanceDay` (TenantAwareJob) dispatched per affected work date, chosen by the shift punch window (overnight aware).
- Engine v2: timezone (location → shift → app), transactional row lock, first IN / last OUT with `missing_in`, `missing_out`, `invalid_sequence`, unpaid breaks via `shift_breaks`, scheduled start/end/minutes, overtime rounding/daily cap/status, `calculation_version` + `calculation_basis`, events `attendance.processed` / `status_changed`.
- `LeaveDayResolver` contract (Leave-side implementation) and `AttendanceOutput` payroll-ready quantities.
- Regularisations: row-locked reviews, original/resulting snapshots, cancel, field duty; overtime approve/reject with reviewer, note, audit and events.
- Audit actions: PUNCH_RECEIVED, PUNCH_IMPORTED, PUNCH_REPROCESSED, ATTENDANCE_CALCULATED, ATTENDANCE_ADJUSTED, REGULARISATION_REQUESTED/APPROVED/REJECTED, SHIFT_ASSIGNED, SCHEDULE_ASSIGNED, OVERTIME_APPROVED/REJECTED.
- UI: shift form timezone + breaks repeater; punch register with retry and manage-only payload; punch imports; exception-centre summary; My Team attendance counts; reject-OT action; employee-only rows in records/regularisations.
- API `/api/v1/attendance/`: records, records/{code}/{date}, exceptions, punches (POST), regularisations (GET/POST), shifts, schedules.
- Punch import: staged pipeline reusing `employee_imports` (`type = punches`), fingerprint duplicate detection, default timezone, audit operation.

## Database

Migration `2026_10_01_100001_extend_attendance_foundation` (additive; fingerprints backfilled before the unique index). New table `shift_breaks`; new columns on `attendance_punches`, `attendance_records`, `shifts`, `attendance_regularisations`, `employee_imports`. Replay into an empty MySQL database: 175 tables, identical columns and indexes to the dev database (only the pre-existing stray `tenants.base_currency` differs). Audit chains verified.

## Tests

359 passed, 3,234 assertions (previous 342 / 2,915). New suites: AttendanceEngineTest (7), AttendanceWorkflowTest (7), PunchImportTest (2), one attendance architecture invariant. Two existing expectations refined intentionally: a lone IN now also carries `missing_out`; unknown-employee device punches are retained as failed evidence instead of discarded.

## Deferred

Split shifts, rosters and daily overrides; per-device timezone UI; attendance finalisation outside payroll locking; configurable status rules beyond policy settings; geofencing for mobile punches; bulk overtime approval; Excel input; per-key organisation scope for attendance APIs; workflow-routed overtime approval.

## Known limitations

- Reprocessing an old day uses the shift record as it is now if that shift row was edited in place; shift versions are effective-dated rows, and the stored basis shows the values used.
- SQLite row locks are no-ops in tests; concurrency is guaranteed on MySQL by locks and the fingerprint unique index.
- Processing on punch needs a running queue worker; the nightly command covers missed days.
