# Leave Management — architecture

Phase 3 (27 September 2026). Describes what is implemented; items marked *deferred* are not built.

## 1. Flow

```
LeaveType (tenant-configurable)                   leave_types
Leave policy (policy engine, type "leave")        policies / policy_versions / assignment rules
  └─ per-type entitlement rows                    entitlements[] in the policy version settings
LeaveEligibility::check(employee, type, date)     → Eligibility{eligible, reason, rule}
LeaveAccrual (annual / monthly / quarterly, pro-rated) ─┐
LeaveAdjustments (adjust, opening, comp-off, encashment)├─► LeaveBalances::post()  → leave_ledger_entries (append-only)
Leaves (request / approve / reject / cancel …) ─────────┘                          → leave_balances (cached, recomputed)
Approved leave ─► AttendanceLeaveDayResolver (implements Attendance\Contracts\LeaveDayResolver) ─► Attendance engine
LeaveOutput ─► future Payroll (quantities only)
```

## 2. Leave types

`leave_types`: name, description, code, category (earned, casual, sick, … unpaid, comp_off), `unit` (days | hours), min/max per request, paid flag, half-day flag, encashable, `requires_document`, `requires_approval`, `cancellation_policy` (self | approval | not_allowed), gender applicability, colour, order, status, effective dates. Nothing is hard-coded by name; the provisioned set is only a starting configuration. **Hours:** the unit exists so types can be configured, but hourly requests are *deferred* and refused with a clear message.

## 3. Policies and versioning

Entitlements live on a leave policy of the platform policy engine (Phase 4): versioned, effective-dated, assigned by rules over company, location, department, designation, level, grade, employment type, category, work mode, lifecycle, gender, tenure. Per type: days per year, accrual frequency, `proration` (monthly | daily | none, with `prorate_on_join`), `eligible_after` (immediate | days | months | confirmation) + value, probation eligibility, optional `eligible_states`, carry-forward limit and expiry months, encashment, negative balance limit, half-day, minimum notice, maximum consecutive days, document threshold. Policy changes publish new versions; historical ledger entries are never touched, and accrual/requests use the version effective on their date. Policy-level: count weekly offs / holidays inside a span.

## 4. Eligibility

`LeaveEligibility` checks in order: type active and in effect → gender → a policy grants the type (unpaid types need none) → lifecycle state in `peopleos.leave.eligible_states` (joined, probation, confirmed, active, on_leave, notice_period) or the entitlement's override → probation → service period / confirmation. Every refusal carries the reason; requests, the API and the UI all use it.

## 5. Periods, accrual, carry-forward, expiry

Leave year = tenant setting `leave.year_start_month` (calendar year when 1, financial year when 4, …). Accrual is idempotent per `accrual_key` (unique index): annual credit at period start (pro-rated for joiners by remaining months or remaining days), monthly/quarterly slices. Year close (auto on the first run of a new period, or `--close-year`): carry forward up to the limit into the next period (`carry_forward`), lapse the rest (`lapse`). `expireCarryForward()` expires carried days not used within N months of the period start (`expiry`, consumed-first). The nightly `peopleos:leave:accrue` (01:00, overlap-guarded, one server) runs accrual, year close and expiry per tenant inside one audit operation; ledger rows carry its `operation_id`.

## 6. Ledger and balances

`leave_ledger_entries` types: opening, accrual, comp_off_credit, adjustment, usage, reversal, carry_forward, lapse, expiry, encashment. Entries are append-only (model events + immutable builder refuse update/delete); corrections are compensating entries. `leave_balances` is a cache recomputed from the ledger on every post (opening + carry_forward, accrued, adjusted, used = −(usage+reversal), encashed, lapsed = −(lapse+expiry), closing, pending). `LeaveBalances::recompute()` rebuilds it at any time.

## 7. Requests, approval, cancellation

Statuses: pending → approved | rejected; pending → cancelled (withdrawn); approved → cancelled (reversed) or approved → cancel_requested → cancelled | approved. `approved` and `cancel_requested` count as taken leave; `pending`, `approved`, `cancel_requested` block overlapping dates. Types without `requires_approval` auto-approve. Approval uses the existing workflow bridge when a workflow is configured, otherwise the in-app action; policies decide who may approve (permission + organisation/relationship scope, never self).

## 8. Concurrency

Every balance-changing operation (request, approve, reject, cancel, adjust, accrual, year close, expiry) runs in a transaction that first locks the employee row (`LeaveBalances::lockEmployee`). Requests re-check overlap and balance under the lock (pending requests reserve balance); approval re-reads the request (double decisions refused) and re-checks the committed balance. The accrual unique key and the API idempotency key are enforced by database indexes.

## 9. Attendance integration

Attendance calls only `LeaveDayResolver::approvedLeaveOn()`; the Leave-side `AttendanceLeaveDayResolver` returns the request id, session (full / first_half / second_half), paid flag and type code for approved (or cancel-requested) leave. Pending and rejected leave are invisible to attendance. Approval and cancellation reprocess past attendance days; Leave never writes punches and never computes worked/late/overtime minutes.

## 10. Payroll-ready contract

`LeaveOutput::forEmployee(employee, from, to)` → per type: is_paid, approved_days, paid_days, unpaid_days, reversed_days (approved then cancelled). No amounts.

## 11. Security

`LeaveRequestPolicy`: view = `leave.view` + scope, or own; approve = `leave.approve` + scope, never own; cancel = `leave.manage` + scope, or own with `leave.apply`. Leave requests, balances and ledger entries carry the access scope; the transactions register and the request register show only own rows without `leave.view`; the leave calendar never shows reasons; the API binds the tenant from the key, returns reasons only on single reads, and 404s other tenants' ids.

## 12. API (`/api/v1/leave/…`)

Read (`leave.read`): types, balances, transactions, requests, requests/{id}, calendar. Write (`leave.write`): POST requests (honours `Idempotency-Key`), POST requests/{id}/cancel (idempotent).

## 13. Troubleshooting and configuration

| Symptom | Check |
|---|---|
| "No leave policy grants …" | A published leave policy with an assignment rule matching the employee and an entitlement row for the type |
| "available from …" | `eligible_after` / value on the entitlement; the employee's joining or confirmation date |
| Balance differs from expectations | Transactions register for the employee/type/period; `recompute()` rebuilds the cache |
| Approval says insufficient balance | Another approval or adjustment used the committed balance meanwhile |
| Attendance still shows absent | Leave must be approved; past days are reprocessed on approval; future days when they are processed |

Configure: leave types → leave policy (entitlements) → assignment rules → `leave.year_start_month` → holiday calendars and schedules (for day counting) → notification rules for `leave.*` events.

## 14. Deferred

Hourly leave requests; sandwich / prefix-suffix rules (today: count or exclude weekly offs and holidays inside a span); leave import of opening balances from files (the `opening()` service exists); encashment amounts (Payroll); statutory leave law; team calendar grid view (a scoped list is provided).
