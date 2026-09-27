# Phase 7 — Attendance

Implements blueprint §121 Phase 7: shifts, work schedules, holidays, biometric abstraction,
attendance processing, regularisation, overtime and the exception centre. Domain:
`App\Domain\Attendance`.

## Shifts and schedules (§13, §14)

- `shifts`: fixed (start/end, optional cross-midnight) or flexible (hours only), with break,
  full-day and half-day minutes, grace in/out, overtime eligibility and minimum overtime.
  Nothing like "8 hours = full day" is hardcoded; every threshold is on the shift.
- `work_schedules`: `pattern = [week => [mon..sun => shift id | null]]`. One week is a weekly
  schedule; several weeks rotate from the assignment's start (the anchor).
- Who works which schedule: individual `work_schedule_assignments` (effective-dated) win;
  otherwise `work_schedule_rules` (rule-engine conditions + priority) supply the default, so a
  location or department can run six-day weeks without touching individuals.
- `ShiftResolver::resolve($employee, $date)` → shift, weekly off, or "no shift".

## Holidays (§15)

`holiday_calendars` → `holidays` (public / optional / company, half-day flag), assigned through
`holiday_calendar_rules` by priority. `HolidayResolver` answers "which calendar" and "is this
date a holiday for this person".

## Biometric Integration Hub (§24)

Device → Adapter → Raw punch → Normalisation → Engine. `attendance_devices` name an adapter
(`config('peopleos.attendance.adapters')`: generic JSON, eSSL-style push; more vendors are new
adapter classes). Devices push to `POST /api/v1/attendance/devices/{code}/punches` with an
`attendance.write` API key; `PunchIngestion` dedupes on device + external id and on same minute +
direction, records unknown employee codes, and stamps the device's last-seen time. Manual punches
(HR, with a note) go through the same service. Punches are never edited; corrections are
regularisations.

## Processing (§23, §27, §28)

`AttendanceProcessor::process($employee, $date)` builds one `attendance_records` row per day,
re-runnably, from: the resolved shift, the holiday calendar, punches within the shift window
(± `attendance.punch_window_hours`, handling cross-midnight shifts), approved regularisations,
and the resolved attendance / overtime policies from Phase 4 (`grace_minutes`,
`regularisation_allowed`, `regularisation_window_days`, `minimum_minutes`, `max_hours_per_week`,
`approval_required`).

Statuses: present, half_day, absent, incomplete (missed punch), weekly_off, holiday, wfh,
on_duty (and leave, reserved for Phase 8). Exceptions on the record: missed_punch, late,
early_leave, short_hours, absent, no_shift, holiday_work, overtime. Overtime is worked time beyond
the full day (all of it on holidays and weekly offs), subject to the minimum; approval is
recorded separately (`overtime_approved_minutes`, capped by recomputed overtime on reprocessing).
Locked records (payroll) are never rewritten.

Every processed exception and overtime fires `AttendanceEvent` (`attendance.exception`,
`attendance.overtime_recorded`), which the notification rules and workflow triggers consume like
any other event. `peopleos:attendance:process` runs daily at 02:00 for the previous day and
accepts `--date` / `--to` for ranges.

## Regularisation (§23)

`Regularisations::request()` enforces the policy (allowed, window), one pending request per day,
and payroll locks; emits `attendance.regularisation_requested` so a tenant can route approval
through a workflow. `approve()` reprocesses the day using the requested times (or marks WFH /
on-duty); `reject()` records the note. `approveOvertime()` sets approved minutes and clears the
overtime exception. Employees request from their Employee 360 attendance tab
(`attendance.regularise`); managers and HR approve (`attendance.approve`).

## Admin

Attendance group: Exception centre (records with open exceptions, filter by type, badge),
Shifts, Work schedules (pattern builder + "applies to" rules), Holiday calendars (holidays +
rules), Devices, Attendance records (date/status/department filters, manual punch, process day),
Regularisations (approve/reject, badge). Employee 360 gains an Attendance tab and an "Assign work
schedule" life event. The People Control Centre shows exceptions in the last week and pending
regularisations.

## Not in this phase

- Leave integration (status `leave`) and LOP treatment arrive with Phase 8 and payroll.
- GPS geofencing validation: coordinates are stored on punches; enforcement is a policy for the
  mobile phase.
- Statutory overtime ceilings are expressed through the overtime policy today; the compliance
  layer (Phase 9) will protect them.

## Tests

`tests/Feature/Attendance/*` and `tests/Feature/Admin/AttendancePagesRenderTest.php`: weekly and
rotational schedules, effective-dated assignments, schedule and holiday rules; present, late,
early, half-day, missed punch, absent, weekly off, holiday, holiday work, cross-midnight, overtime
with policies and approval; regularisation request, approval, rejection, windows and locks; event
fan-out; the processing command; device adapters, deduplication and the punch API; page renders,
employee request → HR approval, schedule assignment, permissions.
