# Bulk uploads

Written 2026-10-26. Describes what is implemented.

Five CSV uploads share one staged pipeline on `employee_imports` / `employee_import_rows`:

```
upload → inspect (stage rows) → map columns (+ choices) → validate (decide each row) → approve → run → audit
```

Nothing outside staging changes before **Run**. Each run is one `AuditRecorder::operation`, and each
row keeps its decided action, its errors or notes, and its result.

| Upload | Screen | `type` | Service | Writes through | Access |
|---|---|---|---|---|---|
| Employees | People › Employee imports | `employees` | `Employment\Imports\EmployeeImports` | `HireEmployeeAction`, `UpdatePersonAction` | `employee.import` + platform flag `employee.import_export` |
| Bank details | People › Bank details uploads | `bank_accounts` | `Employment\Imports\BankAccountImports` | `ChangeBankAccountAction` | `employee.import` + `employee.sensitive.update`; uploader only |
| CTC (salaries) | Compensation › Salary uploads | `salaries` | `Compensation\Imports\SalaryImports` | a draft `salary_upload` compensation cycle | `compensation.cycles` + `compensation.propose`; uploader only |
| Monthly attendance | Attendance › Monthly attendance uploads | `attendance_month` | `Attendance\Imports\AttendanceSheetImports` | `attendance_marks`, then `AttendanceProcessor` | `attendance.manage` + `employee.import` |
| Punches | Attendance › Punch imports | `punches` | `Attendance\Imports\PunchImports` | `PunchIngestion` | `attendance.manage` + `employee.import` |

`Filament\Support\ImportActions` maps each `type` to its service (`TYPES`). It also holds the shared
pieces: the view-page actions, the mode field, the file field and the format guide.
`EmployeeImportPolicy` applies the access column above.

## New or existing (`ImportMode`)

Every upload except punches asks what the file is for. The person uploading chooses; the choice can be
changed when mapping. It is stored as `options.mode`:

| Mode | Meaning |
|---|---|
| `new` | Only records not on file yet. Rows for records on file are skipped. |
| `update` | Only records on file. Rows for records not on file are skipped. |
| `both` | Add where missing, update where on file (the default for API and test callers). |

"On file" means something different for each upload:

| Upload | On file means |
|---|---|
| Employees | A person matched by `PersonMatcher` |
| Bank details | The employee has a bank account |
| CTC | The employee has an active salary assignment |
| Monthly attendance | An earlier upload marked that employee's day (decided per day) |

A row outside the mode becomes `skip`, with the reason in `match.reason`. The rows table shows that
reason under *Errors / notes*, and it is never treated as an error. Files staged before modes existed
carry `options.on_duplicate`; `ImportMode::of()` reads `skip` as `new` and anything else as `both`.

Employees in `update` mode:
- Only **Employee code** is required (`EmployeeImports::required()`), and it alone finds the employee.
  A row whose code matches nobody is skipped, never created.
- Blank cells keep what is on file.
- Updating changes person and contact fields only.
- Review decisions are limited to the mode (`resolveReview`).

Punches have no mode. They are append-only events: a punch already loaded is skipped by fingerprint,
and a day is corrected with a missing punch or a regularisation.

## Formats

Each service has a `format()` method that returns columns (label, required, what to enter, example)
and notes. The monthly attendance upload also returns its day codes and a sample sheet. Each service
also has a `template()` method that returns a downloadable CSV whose headers map automatically.
`resources/views/filament/imports/csv-format.blade.php` renders the guide in two places:
- the **File format** header action on every upload list;
- a folded section of every upload dialog.

**Download template** sits beside both.

## Bank details

- **Columns:** employee code, account holder name, bank name and account number (required); IFSC,
  branch and account type (optional; blank keeps the value on file).
- **Clean-up:** spaces are removed from account numbers, and IFSC is upper-cased.
- **Validation:** each row runs through `ChangeBankAccountAction::rules()` and `ProfileChangeGuard`
  (permission and organisation scope, as the uploader). Rows are refused for:
  - a number the spreadsheet turned into a float (`5.01E+13`);
  - the same employee twice in the file;
  - the same account number on two employees in the file.
- **Add:** used when the employee has no account. The new account becomes the primary (salary)
  account.
- **Update:** changes one account in place. It picks the account with the row's number, else the
  primary account, else the only account. If there are several accounts and none is primary, the row
  is refused. If the row matches what is on file, it is skipped.
- **Secrets:** account numbers are never staged in clear. `inspect()` stores each row in
  `employee_import_rows.sealed_data` (cast `encrypted:array`, hidden) and leaves `data` empty. Screens
  show `code · name · bank ••••1234` (`BankAccountImports::summary`). Run and discard null the sealed
  copies and delete the uploaded file. Errors never echo the account number.
- **Run:** each row goes through `ChangeBankAccountAction::add` / `update` with
  `ChangeOrigin('bulk_upload', 'Bank details upload #N', operationId)`. That gives each change the
  same duplicate check, audit (number masked), timeline entry and `employee.bank_account_changed`
  event as Employee 360.

## Monthly attendance

This upload is for organisations whose attendance no device captures. The person uploading picks:
- **Attendance month:** a `Y-m` value naming a payroll month under the pay cycle (payroll-architecture §1.1). It is never a month that has not started. On a 25th-to-24th cycle, `2026-11` runs from 25 October to 24 November. The template's columns then run 25 … 24, and a day number maps to its date in the month. A long changeover month (25 Oct – 30 Nov) repeats day numbers. The template heads the first of each pair with its ISO date, which maps to field `date.2026-10-25`, and the bare number means the later one. The upload keeps the dates it was made for (`options.from` / `options.to`), so a later change of cycle never moves its days.
- **Mode:** see *New or existing* above.
- **Blank days:** how an empty cell is read (`calculate` or `present`, below).

The sheet has one row per employee and one column per day. A day column's header can be the day
number (`5`), `Day 5`, `5 Thu`, or an ISO date inside the month's window.

**Day codes** (any case; `AB` and the words are accepted too):

| Code | Status |
|---|---|
| `P` | present |
| `A` | absent |
| `HD` | half day |
| `WO` | weekly off |
| `H` | holiday |
| `WFH` | work from home |
| `OD` | on duty |

**Halves:** a cell can give each half of the day its own code, first half first: `P|A`, `A|WFH`, `WO|P`.
- A half takes any code but `HD`, which is a whole day (a present and an absent half); `AB|HD` is refused.
- The mark keeps `first_half` and `second_half`. Its `status` is the day they make
  (`AttendanceMark::combine`): two absent halves make `absent`, one makes `half_day`. Otherwise the
  first match of present, work from home, on duty, holiday and weekly off wins.
- On a day of approved half-day leave, only the other half of a split mark counts
  (`AttendanceMark::statusOn`). For example, afternoon leave with `P|A` makes the day present with
  half-day leave.

**Blank cells:**
- `calculate`: the day is worked out from punches, the schedule and holidays as usual. Without
  punches, a working day is absent.
- `present`: the cell becomes a `working` mark. On a working day that is present; on a weekly off or
  holiday it is the schedule's status. A blank cell never replaces a day an earlier upload marked.

**Leave is never marked by upload.** Employees apply for leave on the portal.
- A leave-like cell (`L`, `CL`, `SL`, `EL`, `PL`, `ML`, `LWP`, `CO`, or any text containing "leave")
  is refused with that reason.
- Days of approved full-day leave are left alone, whatever the sheet says, and the row notes them
  (`LeaveDayResolver::approvedLeaveBetween`, one read per row).
- On a half-day leave, the code is for the other half.
- Approving or cancelling leave later recalculates its days, so leave always takes over from a mark.

**Validation errors:**
- an unknown code or a leave code;
- a day beyond the month's length;
- a code before the joining date, after the exit date, or after today;
- a code on a day locked by a finalised payroll run;
- the same employee twice in the file.

**Run:**
1. Upserts `attendance_marks`: one per employee and date, unique per tenant, with `source`,
   `employee_import_id` and `marked_by`.
2. Dispatches `ProcessAttendanceRange` over the employee's first to last changed date.

The file never writes `attendance_records`.

**How the engine applies a mark** (`AttendanceProcessor`):
- Precedence: approved full-day leave, then the mark, then punches and regularisations.
- A marked day has no punch exceptions, lateness or overtime.
- `calculation_basis` records `attendance_mark_id` and `attendance_mark` (`present`, or `present|absent` for halves).
- Payroll reads the result through `AttendanceOutput` as before (`PayrollCalculator::lossOfPay`, payroll-2.5):
  - an `A` (or `A|A`) day is 1 day of loss of pay;
  - an `HD` day, or a split day with one absent half, is 0.5, or nothing when the day also has half-day leave
    (the leave is the other half);
  - half-day leave with the other half absent is 0.5 for that half;
  - the leave half follows the leave: paid costs nothing, unpaid adds 0.5.

**Audit:** marks are not audited row by row (documented in `ArchitectureTest`). The upload is one
audited operation whose rows keep what they marked, and each recalculated attendance record is
audited.

## CTC (salary uploads)

See `compensation.md` § Salary uploads. The mode is checked in `validate()` before the proposal rules:
- `new`: employees with no active salary assignment, with change type `hire` by default.
- `update`: employees with one, with change type `revision` by default.
