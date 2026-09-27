# Phase 8 — Leave

Implements blueprint §121 Phase 8: leave types, policies, accrual, balances, requests, approval,
encashment and the register. Domain: `App\Domain\Leave`.

## Types and policies (§25, §26)

- `leave_types`: category (paid, unpaid, comp-off, restricted, special), paid flag, half-day and
  encashment flags, optional gender applicability, colour. Nine defaults are seeded per tenant
  (EL, CL, SL, ML, PL, BL, CO, RH, LWP); tenants add their own.
- Entitlements are **not** on the type. A *leave policy* is a Phase 4 policy of type `leave`
  whose settings hold `entitlements`: one row per leave type with days per year, accrual
  frequency (annual, monthly, quarterly), pro-rating in the joining year, carry-forward limit and
  expiry, encashment allowance, probation eligibility, negative balance limit, half-day
  permission, minimum notice, maximum consecutive days and document threshold. Who gets which
  policy is a policy assignment rule (`IF Company = ABC AND Location = Delhi THEN Standard Leave`),
  so grade-, location- and employment-type-specific leave is configuration. `PolicySettingsSchema`
  learned a repeater field type for this; the seven packs ship entitlement-based leave policies.
- `LeaveEntitlements` reads the resolved policy; `LeaveYear` maps dates to the tenant's leave
  year (`leave.year_start_month`, e.g. April for an Indian financial year).

## Ledger and balances

`leave_ledger_entries` is the authoritative, append-only account: accrual, carry_forward, lapse,
usage, reversal, adjustment, encashment, comp_off_credit, each with days (signed) and an
optional idempotency `accrual_key`. `leave_balances` is a cached summary per employee, type and
year (opening, accrued, adjusted, used, pending, encashed, lapsed, closing) recomputed by
`LeaveBalances` after every movement; *available* = closing − pending.

`LeaveAccrual::accrue()` credits everything due up to a date (annual at year start, monthly or
quarterly slices, pro-rated for joiners) and is safe to repeat; `closeYear()` carries forward up
to the limit and lapses the rest. `peopleos:leave:accrue` runs daily and auto-closes the previous
year on its first run after year end.

## Requests (§25)

`Leaves::request()` validates type availability and gender, policy grant, probation, half-day
permission, notice, consecutive-day and document rules, overlap with pending or approved leave,
payroll-locked days and balance (with the negative limit); `LeaveDayCounter` turns the span into
dated sessions, skipping weekly offs and holidays unless the policy counts them. Approval posts
usage to the ledger (split across leave years when a span crosses one), writes the timeline and
reprocesses attendance; rejection recomputes pending; cancellation of approved leave posts a
reversal and reprocesses. Every step fires `LeaveEvent` (`leave.requested`, `leave.approved`,
`leave.rejected`, `leave.cancelled`, encashment and adjustment events) into notification rules
and workflow triggers, and `LeaveWorkflowBridge` turns a finished workflow on a leave request
into the approval or rejection, so tenants choose between direct manager approval and a
multi-step workflow without code.

## Attendance integration

`AttendanceProcessor` asks `Leaves::approvedOn()`: full-day leave becomes status `leave` (paid)
or `unpaid_leave`; half-day leave halves the thresholds for the remaining session and records
`is_half_day_leave`; the record links to the request.

## Adjustments and encashment

`LeaveAdjustments`: manual credits/debits with a reason (audited on the employee), comp-off
credits, and encashment requests capped by the policy's `max_encash_days` and by the available
balance, approved into the ledger. Amounts are Phase 9's concern.

## Admin

Leave group: requests register (own requests only without `leave.view`; approve / reject /
cancel; apply on behalf), balances (adjust, encash, run accrual), encashments, leave types. The
Employee 360 gains a Leave tab whose heading shows this year's available balance per type and an
"Apply for leave" action for the employee or HR. The People Control Centre shows who is on leave
today and pending requests.

## Permissions

`leave.view`, `leave.apply` (own), `leave.approve` (never one's own), `leave.manage`.

## Tests

`tests/Feature/Leave/*` and `tests/Feature/Admin/LeavePagesRenderTest.php`: seeded types,
annual/monthly/quarterly accrual with pro-rating and idempotency, carry-forward and lapse,
April leave years, the command; day counting with holidays and half days; every validation rule;
approve → ledger → attendance → cancel reversal; half-day and unpaid leave in attendance;
rejection and self-approval; adjustments, comp-off, encashment limits; workflow-driven approval;
page renders, employee apply and manager approve, visibility restrictions.
