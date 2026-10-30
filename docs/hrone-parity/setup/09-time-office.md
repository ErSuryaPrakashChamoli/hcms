# HROne Setup → Time office: field-level parity

Source: HROne's own Setup screens (Time office → Shift, Weekly off, Holiday, Check in, Attendance, Leave, Short
leave, Settings), read from the compiled front end on 2026-10-28 (questions, options and form control names).
Status: **HAVE** (PeopleOS already does it), **BUILT** (built in this programme), **PARTIAL**, **PLANNED** (next
package, named), **N-A** (with the reason). PeopleOS keeps its own model: policies are versioned and assigned by
rules (HROne's "Applicable on" and "Advance applicability" steps), settings are organisation-wide.

Where HROne's check-in policy also holds request rules, PeopleOS keeps them where its engines read them:
regularisation (AR) in the **attendance** policy, on duty and shift / week-off change in the **time-office** policy.
The Setup centre (Time office) lists the policy types in HROne's order.

## Check in → Check in policy (`checkin` policy type)

| HROne setting (control) | PeopleOS | Status |
|---|---|---|
| Web check-in / Mobile check-in (enableWebCheckIn, enableMobileCheckIn) | `allowed_sources` (web, mobile, API, biometric) | HAVE |
| Timesheet / Integration with WeMe / WhatsApp / Teams check-in requests | — | N-A: timesheets are HP-5; WeMe, WhatsApp and Teams are HROne's channel integrations (no PeopleOS connector) |
| Set maximum punches allowed? → number (enableNoOfPunchInWeb, noOfPunchInWeb; …Mobile) | `web_max_punches_enabled` / `web_max_punches`, `mobile_max_punches_enabled` / `mobile_max_punches` (CheckInGuard) | BUILT |
| Restrict to a defined window → start / end (defineTimeframeFor…, …StartTime, …EndTime) | `web_window_*`, `mobile_window_*` (windows may cross midnight) | BUILT |
| Enable IP validation → IP addresses / ranges (enableIPValidation, ipAddresses, ipAddressRange) | `web_ip_validation`, `web_allowed_ips` (IPs or CIDR ranges) | BUILT |
| Capture location while marking attendance? (isLocationRequired) | `require_location` (web punches now send the browser location when needed) | HAVE + BUILT (web) |
| Capture photograph: None / Photo / Facial → number of photos (isPhotoFacialRequired, numberOfPhotos) | `photo_capture`, `photo_count`; the app sends photos (files or base64) and `face_verified`; photos kept on the punch | BUILT |
| Advanced location tracking: None / Radius based / Polygon based (allowGeoFencingLiveTracking) | `geo_fence_required` + `outside_fence_action`; each geo location is a radius or a polygon | BUILT |
| Continuous location tracking | `continuous_tracking`; app sends pings to `POST /api/v1/attendance/locations` | BUILT |
| Approve work locations only for a period → max days (restrictOnlyTempLocationApproval, specificLocationApprovalDays) | `temporary_locations`, `temporary_location_max_days`; requests carry dates, approved locations expire | BUILT |
| WiFi attendance (enableWifiBasedAttendance) | `wifi_attendance`, `wifi_networks` (SSID or BSSID) | BUILT |
| Approved devices (HROne: device binding in the app) | `single_device`, `auto_approve_first_device` | HAVE |
| AR: backdated for employee / manager / HR with days; limit per interval; after attendance process; one punch required; not on week-offs / holidays; future dated; within shift range; attachment mandatory; WhatsApp / Teams | attendance policy, section AR: `regularisation_window_days`, `ar_manager_backdated_days`, `ar_hr_backdated_days`, `ar_future_allowed`, `ar_after_process`, `ar_requires_punch`, `ar_within_shift`, `ar_not_on`, `ar_limit_period` + `ar_limit`, `ar_attachment_required` (regularisations carry attachments). WhatsApp / Teams: N-A (no channel connector) | BUILT (TO-3) |
| OD: backdated per role with days; OD types; overall weekly / monthly limit; not on week-offs / holidays; log time against project; after attendance process; future dated; manager / HR limits; attachment mandatory | time-office policy, section On duty: `on_duty_types` (the request carries its type), manager / HR backdated days, `on_duty_future_allowed`, `on_duty_after_process`, `on_duty_not_on`, per-week and per-month limits, `on_duty_limits_bind_managers` / `_hr`, `on_duty_attachment_required`. Log time against project: HP-5 (projects) | BUILT (TO-3) |
| Change shift / week off: shifts allowed (all / custom); weekly / monthly request limits; week-off change limits; backdated / future days | time-office policy, sections Change shift (`shift_change_shifts`, backdated / forward days, per-week and per-month limits) and Change week off (new `week_off_change` request: work a weekly off, take another day off, with its own days and limits; undone on cancellation) | BUILT (TO-3) |
| Request step: which of employee / manager / HR may raise each request | permissions (`attendance.regularise`, `attendance.approve`, `attendance.manage`); employee / manager / HR windows per request type | BUILT (TO-3) |
| Applicable on / Advance applicability | policy assignment rules (conditions) and effective dates | HAVE |

## Check in → Biometric server

| HROne setting | PeopleOS | Status |
|---|---|---|
| Biometric name, table name, frequency (hours), service type, server type, server name, connection string | attendance devices: push, or a sync agent reading the attendance server (server type, server name, table, connection reference — credentials stay with the agent —, frequency, column mapping) | BUILT (TO-8) |
| Email alert on data-sync failure → days, exclude days, email (allowAlertOnDataSyncFailure…) | device alert window, recipients and days without alerts; `peopleos:attendance:device-alerts` (hourly, once per silence, audited) | BUILT (TO-8) |
| Mapping (client column → field), preview | column mapping on the device; adapters map pushed payloads | BUILT (TO-8) |

## Attendance policy (`attendance` policy type)

| HROne step / setting | PeopleOS | Status |
|---|---|---|
| Basic: name, description, attendance cycle manual / calendar, cut-off day, optimistic attendance, restrict optimistic benefit days, paid days zero when absent all month (also for holidays) | pay cycle (25th–24th months), `payroll.lop_from_attendance`; optimistic attendance is covered by the pay cycle's month and attendance arrears | PARTIAL |
| Calculation: count check-in double / single / auto / none; timings strict / flexi; weekly off paid / unpaid; salary dividing factor manual / calendar (+ special factor); paid days from monthly hours; expected hours a day; hours round-off and working-hours formulas; hours within shift only; missing minutes for AR / OD / short leave | attendance policy, section Calculation: `punch_mode` (double / single / auto / none), `timing` (strict / flexi), `count_within_shift`, `weekly_off_paid`; salary dividing factor = payroll proration basis | BUILT (TO-4) |
| Weekly off / holiday entitlement: on required paid days in the week / month, leave types that count, absent days that remove it, excluded in joining month | attendance policy, section Entitlement: `weekly_off_entitlement` / `holiday_entitlement` with days present in the week, leave counted or not, joining month excluded; re-decided when a weekday changes | BUILT (TO-4) |
| Deduction: none / common / exclusive late and early rules; daily or monthly; deduct from (leave priority); grace days; alternate grace; relaxation minutes; minutes per day of deduction; deduction type and value; absent first half / full day / second half by punch | `grace_minutes`, `late_marks_threshold`, `late_mark_action`, `half_day_if_late_beyond`, `half_day_if_early_beyond`; late penalties (HP-1); absent halves by punch are the shift's absence criteria (TO-5). Deduction from leave in a priority order: open | PARTIAL |
| Absent–week off–absent / absent–holiday–absent as absent | `attendance.sandwich_rule` (weekly_off / holiday / both) | HAVE |
| Calendar visibility | attendance calendar (HP-1) | HAVE |
| Comp off: generate; hours for half / full day; prorate on week off / holiday; auto-initiate; expiry days; formula; days excluded; restrict apply before generation; advance expiry rule; shift end margin | time-office policy: claims plus `comp_off_auto` (generated for work on off days) and `comp_off_auto_approve`; FIFO expiry | BUILT (TO-4) |
| Overtime: enable; minimum hours; round-off; fixed OT; planning; excluded days; greater-than-actual rules; pay actual hours; max OT; rate; formula; deduction; shift end margin | `overtime` policy type (minimum, daily max, rounding, rates per day type, weekly max, approval); fixed OT and OT formulas: open | PARTIAL |
| Alerts: absenteeism alert (days, roles, change status after days); weekly / monthly attendance email; less-working-hours alert; one of comp off / OT per day; lock requests during processing (day, until) | `absence_alert_days` → `attendance.absence_alert` (daily command, once per run; recipients by notification rule); `request_lock` days of the month (AR, OD, short leave, shift / week-off change and leave for last month); weekly / monthly attendance mails: notification rules | BUILT (TO-4) |
| Shift allowance: interval, payout rate, on week off / holiday with minimum hours, required / max payout days | attendance policy, section Shift allowance (rate a day, minimum and maximum days, off days with minimum minutes) on shifts that earn it; `ShiftAllowanceInput` pays it | BUILT (TO-5) |

## Shift (shift master + `Shift` model)

| HROne setting | PeopleOS | Status |
|---|---|---|
| Basic: code, colour, name | `shifts.code`, `name`, `colour` | BUILT (TO-5) |
| Timings: start; first-half and second-half length; check-in allowed minutes before start; grace late / early | `start_time`, `end_time`, grace in / out, `first_half_minutes`, `early_checkin_minutes` (the day's punch window) | BUILT (TO-5) |
| Absence criteria: minimum hours for half / full day; mark half / full day absent if no attendance within N from start; mark second half absent if out before N of end; shift allowance; mark attendance by manager / HR (future, days, hours) | `full_day_minutes`, `half_day_minutes`, `absent_first_half_after`, `absent_full_day_after`, `absent_second_half_before`; manager / HR marking attendance: HR day overrides (HP-1) | BUILT (TO-5) |
| Advance: break deduction rules (name, start, duration); deduct time before shift start; cut-off after which hours do not count; visible in work-plan requests; allow short leave / AR / OD / leave from this shift | shift breaks (paid / unpaid), `ignore_before_start`, `cutoff_time`, `requestable`, `allow_short_leave`, `allow_regularisation`, `allow_on_duty`, `allow_leave` | BUILT (TO-5) |
| Weekly off for this shift (as per policy, or defined per shift) | work schedules (a schedule per shift pattern) | HAVE |

## Shift rotation and shift group

| HROne setting | PeopleOS | Status |
|---|---|---|
| Rotation: name; assign last assigned shift; manual shift; shifts per weekday / week; selected shifts and frequency; preview | multi-week rotating work schedules, rosters and roster upload (HP-1) | HAVE |
| Auto shift correction: enable; on week off / holiday; sequence of shifts tried; default shift; week-off / holiday status by shift | work schedule `auto_shift_ids`, `auto_shift_on_off_days`: the listed shift nearest the first punch | BUILT (TO-5) |
| Shift group: name; shifts in the group (employees may change only within it) | time-office policy `shift_change_shifts` (the shifts employees may change to) and `requestable` shifts | BUILT (TO-3, TO-5) |

## Weekly off

| HROne setting | PeopleOS | Status |
|---|---|---|
| Weekly off policy: name, days (with alternate weeks), applicability | work schedules: pattern, rules with conditions, effective dates | HAVE |
| Weekly off limit (max weekly offs in a week) | week-off change limits (TO-3) | PARTIAL |

## Holiday

| HROne setting | PeopleOS | Status |
|---|---|---|
| Calendar name, year; holidays from the holiday bank; notes; applicability | holiday calendars, holidays, holiday library (platform bank, tenant adopt), calendar rules | HAVE |
| Restricted holiday applicable → max in year | `optional_requires_selection`, `restricted_holiday_quota` (HP-1) | HAVE |
| Restricted holiday prorate (from month, to month, value) | holiday calendar `rh_prorate_rules` (joining-month ranges → quota) | BUILT (TO-6) |
| Restrict RH cancellation after salary for employee / manager / HR | holiday calendar `rh_cancel_locked_for` (employee / manager / HR) | BUILT (TO-6) |
| Allow RH without shift | holiday calendar `rh_without_shift` | BUILT (TO-6) |
| Holiday calendar based on financial / calendar year | `attendance.holiday_year_basis` | BUILT (TO-6) |

## Leave type and leave policy

| HROne step | PeopleOS | Status |
|---|---|---|
| Add: code, name, cycle start / end month, paid / unpaid, encashment (allowed, max per request, minimum balance after, monthly / quarterly / half-yearly / yearly encashable balance) | leave types (code, name, paid, encashable), leave year, entitlement `encashment_allowed`, `max_encash_days`, `max_encash_per_request`, `min_balance_after_encash`; quarterly / half-yearly encashable balances: open | PARTIAL |
| Eligibility: employee status, ESI / non-ESI / both, gender, age range | `applicable_gender`, `eligible_after`, `probation_eligible`, `esi_applicability`, `min_age`, `max_age`, `marital_status` | BUILT (TO-7) |
| Credit rule: when (day of month), value, new-joiner rule (rule of 15 …), depends on (attendance / days), formula, after N working days, leave types counted, for relieved employees, married / having children, opening balance, round-off, purge above limit, while resignation pending, expiry days | accrual frequency, `joiner_credit`, proration, `credit_round_off`, `credit_cap` (purge above); credit by attendance, for relieved employees, opening balance: leave balance upload (HP-1b) / open | PARTIAL |
| Request: half day; visible to employee; max requests per year / month / tenure; min / max per request; max per year / month; backdated (employee / manager / HR days); prior notice days; future dated per role; document after N days; after attendance process; while resignation pending; privacy (private / employee defined / public); after N days from joining; include absent days | `half_day_allowed`, `min_notice_days`, `max_consecutive_days`, `document_required_after_days`, leave on present day, `max_requests_per_year` / `_month` / `_in_tenure`, `max_days_per_year` / `_month`, `backdated_days` + manager / HR, `future_allowed`, `not_while_resigning`, `privacy` (stored), request lock | BUILT (TO-7) |
| Advance request (per role overrides) | manager / HR backdated days | BUILT (TO-7) |
| Availment: specific employee status; ESI; gender; birthday / wedding anniversary leave (days before / after) | `eligible_states`, `esi_applicability`, gender; `birthday_window_before` / `_after` (anniversary: no anniversary date on the person record) | BUILT (TO-7) |
| Balance: advance (negative) balance and limit; clubbing not allowed with other types; donation per role and balance | `negative_balance_limit`, leave donations (HP-1), `not_clubbed_with` | BUILT (TO-7) |
| Carry forward: zero / all / specific; auto or manual (day); use this year's balance in next cycle; cancel / apply backdated after carry forward; lapse alert (days, message) | `carry_forward_mode` (zero / all / up to `carry_forward_limit`), `carry_forward_expiry_months`; lapse alert: open | BUILT (TO-7) |
| Penalty: weekly off / holiday inside leave count as leave (with prior days) | leave policy `count_weekly_offs`, `count_holidays` | HAVE (prior-days rule open) |
| Leave policy: name, description, leave types, A–WO–A and A–H–A as leave | leave policy entitlements, sandwich setting | HAVE / PARTIAL |

## Short leave (`short_leave` policy type)

| HROne setting | PeopleOS | Status |
|---|---|---|
| Name; interval; total, minimum and maximum short-leave hours; backdated per role with days; more than one a day (number); deduct leave (fixed or by hours, excluded types); future dated; after attendance process | `enabled`, `min_minutes`, `max_minutes`, `max_per_month`, `max_minutes_per_month`, `window_days` per role, `multiple_per_day`, `over_limit`, `deduct_leave_code`, `deduct_days` (HP-1), `short_leave_future_allowed`, `short_leave_after_process` (TO-3) | BUILT |

## Time office → Settings (organisation settings)

| HROne setting | PeopleOS | Status |
|---|---|---|
| Attendance correction arrear → arrear up to (months) | `attendance.allow_corrections_after_lock` (HP-1); a month limit is open | HAVE |
| Shift scheduled for (none / scheduled time; week / month start day) | rosters are date-based in PeopleOS | N-A |
| Single punch / auto punch counts the shift's hours | attendance policy `punch_mode` | BUILT (TO-4) |
| Absenteeism penalties (A–WO–A) | `attendance.sandwich_rule` | HAVE |
| Pay employees joining after cut-off in the same month (optimistic) | pay cycle month; attendance arrears | PARTIAL |
| Weekly off limit → maximum weekly offs in a week | week-off change limits per week / month (TO-3) | PARTIAL |
| Allow users to edit OT hours | overtime claims (approve as claimed); HR approves calculated OT on the day | HAVE |
| Apply AR, OD, short leave after OT and comp off are generated | `attendance.requests_after_benefits` | BUILT (TO-3) |
| Leave on present days | `leave.block_on_present_day` (HP-1) | HAVE |
| Holiday calendar based on financial / calendar year | `attendance.holiday_year_basis` | BUILT (TO-6) |
