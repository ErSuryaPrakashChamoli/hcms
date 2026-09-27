# PeopleOS Phase 3 Report — Leave Management Foundation

Branch `main`, from HEAD 68ec840 (Phase 2). Architecture: `docs/architecture/leave-architecture.md`. Attendance, payroll, compliance, Integration Hub, AI, RMS and Legal Entity work were not touched beyond the existing `LeaveDayResolver` contract.

## Discovery

A Leave module already existed from the earlier build: leave types, policy-engine entitlements per type, an append-style ledger with accrual keys, cached balances, annual/monthly/quarterly accrual with join-year proration, carry-forward and lapse, requests with notice/consecutive/document/probation/overlap/balance checks, approve/reject/cancel with reversal entries, workflow bridge, encashment, comp-off, the Phase 2 `AttendanceLeaveDayResolver`, and 12 tests. Gaps: eligibility embedded in the request method, no lifecycle or service-period eligibility, ledger not immutable, no locking (concurrent requests/approvals could overspend), no cancellation review, no carry-forward expiry, no daily proration, no opening balances, generic audit actions, approvals not scope-aware, read-only API, no calendar or ledger view, no payroll contract.

## Implemented

- Leave types: description, unit (days/hours), min/max per request, requires document, requires approval, cancellation policy, effective dates.
- `LeaveEligibility` with reasons: type in effect, gender, policy grant, lifecycle states (configurable), probation, service period (days/months) or confirmation.
- Ledger: append-only (model + builder guards), new `opening` and `expiry` types, operation ids, `leave.balance_changed` events; balances recomputed from the ledger.
- Accrual: `proration` monthly/daily/none; `expireCarryForward()`; year-end carry-forward and lapse audited; accrual command wrapped in one audit operation per tenant.
- Requests: eligibility-driven, overlap + balance re-checked under a per-employee row lock, auto-approval for types without approval, `Idempotency-Key`, contact details; approval re-reads the request and re-checks the committed balance; cancellation per type policy (self, approval → `cancel_requested`, HR only) with compensating reversal entries.
- Audit actions LEAVE_REQUESTED, LEAVE_APPROVED, LEAVE_REJECTED, LEAVE_CANCEL_REQUESTED, LEAVE_CANCELLED, LEAVE_BALANCE_ADJUSTED, LEAVE_ACCRUED, LEAVE_EXPIRED, LEAVE_CARRIED_FORWARD; events leave.cancel_requested, cancellation_rejected, balance_changed, expired, carried_forward.
- `LeaveOutput` payroll contract (paid, unpaid, approved, reversed days).
- Policy: approve/view/cancel require organisation + relationship scope; managers approve direct reports only.
- API `/api/v1/leave`: types, balances, transactions, requests (+show), calendar (no reasons), POST requests, POST cancel.
- UI: leave type form fields, cancellation review actions, scoped Leave calendar page, read-only Transactions register.

## Database

Migration `2026_10_02_100001_extend_leave_foundation` (additive): leave type columns, request cancellation/idempotency columns with unique `(tenant, employee, idempotency_key)` and `(tenant, employee, status)` index, ledger `operation_id`. Replay into an empty MySQL database: 175 tables, identical columns and indexes to dev (the pre-existing stray `tenants.base_currency` aside). Audit chains verified.

## Tests

369 passed, 3,433 assertions (previous 359 / 3,234). New: `LeaveFoundationTest` (9 cases: eligibility, proration + idempotent accrual, ledger immutability + opening + carry-forward + expiry, reservation and approval re-check, cancellation review and reversal, resolver integration and punch safety, payroll output, scope/tenant security, API) and one leave architecture invariant. No existing test was changed.

## Deferred

Hourly leave requests; sandwich and prefix/suffix rules; opening-balance file import (service exists); grid-style team calendar; notification templates per new event (events are registered); statutory leave rules; encashment amounts.

## Known limitations

- Row locks are no-ops on SQLite; concurrency is guaranteed on MySQL by the employee lock and unique indexes.
- Leave periods are tenant-wide (start month), not per policy.
- Pending requests reserve balance at request time; approval checks only the committed balance, so an older pending request can be approved while a newer one then fails.
- Rehire keeps the same Employee and ledger; a same-period rehire does not re-credit the annual accrual (idempotent key), and service-period eligibility restarts from the new joining date.
