# PeopleOS streamline programme — report

Request (owner, 2026-11-05): run the full suite; review the whole product as a user and correct it; remove duplication;
put everything under the right module; make every form a wizard; give pages clear, distinctive headers and sub-headers;
one way to reach each page; and rebuild the payroll run so attendance comes first, a mandatory pre-payroll checklist
follows, then the run, closing, publishing payslips — with the option to reopen last month. Plan:
`Streamline-Plan.md`; what the four reviews found: `Findings.md`; the independent review of 2026-11-10 with the
requirement traceability: `Independent-Review.md`. Nothing is committed or pushed.

## 1. Tests

| Run | Result |
|---|---|
| Baseline, unchanged tree (2026-11-05/06, `build-clones/nov5-base`) | 2,996 tests, 2,995 passed. The one failure was a timing flake in `StorageProductionTest` (an S3 link signed one second late on a busy machine: 299 instead of 300 s), now tolerated in the test. |
| Finished tree | FINAL_RESULTS |
| New tests | `PayrollGuidedRunTest` (13), `RunPayrollPageTest` (4), `EveryPageOpensTest` (2: every page and register for an administrator, every page for an employee), `SpreadsheetCellTest`, three new `FormWizardTest` cases. MYSQL_RESULTS |
| Browser (disposable showcase database, Chromium) | Run payroll: every step opens and shows the month's state; Setup centre as a wizard; a nine-field action modal as Details → More details → Review; a short drawer as Exchange rate → Review; no browser errors. |

## 2. Payroll: one guided run (S2)

**Run payroll** (Payroll → Run payroll; also from the payroll desk, the control room, the run list, the Actions panel,
command search, the phone bar, Home and My work) takes one company's pay month through eight steps, in a step rail
like every other wizard:

1. **Pay month** — company and month, the dates, where the run stands; *Start {month}* creates the run.
2. **Attendance** — people in the month, fully processed, days without attendance, requests still pending, attendance
   month close. *Process attendance* and *Close attendance month* are here. The run waits until the month has ended and
   every day is processed for everyone (and the month is closed where month close is used).
3. **Pre-payroll checks** — 20 checks, each with a status (Pass, Warning, Pending approval, Blocked, Not applicable),
   its module, count, the people concerned, a link to where it is fixed and the time it was read: new joiners; exits and
   people leaving; transfers and moves; full and final settlements; salary uploads not imported; one-time inputs waiting
   for approval; salary revisions not in force; salary structure missing (*blocked*: payroll cannot pay them); CTC and
   wage checks; leave and unpaid days; arrears from earlier months; statutory details and rules; tax regime and
   declarations; bank details; loans and advances; reimbursements; salary on hold; investment declarations and proofs;
   leave encashment; people missing from last month's payroll. Warnings and pending approvals are ticked as checked (a
   tick records who and what they saw, and lapses as soon as that changes); passing and not-applicable checks need
   nothing.
4. **Run payroll** — calculate now or in the background; employees, gross, deductions, net pay, employer cost and
   exceptions. Calculate refuses on **every** screen until steps 2 and 3 are done.
5. **Review and approve** — the month against the last closed one (gross, net, employer cost and their change; PF,
   ESI, PT, TDS, LOP, reimbursements, recoveries, joiners and leavers, exceptions) and the employees whose net pay moved
   20 % and 1,000 or more; validate; someone other than the person who ran it approves.
6. **Close payroll** — the month locks: attendance, the month's inputs (loans, claims, variable pay, encashments …)
   and the payslips.
7. **Publish payslips** — employees see their payslips, and are told, only now.
8. **Pay** — bank file, journal voucher, mark as paid.

**Reopen month** (on steps 6–7 and on the run page; permission *Reopen the latest closed payroll month*): the latest
closed month goes back to draft with a reason — until it is marked paid or a challan or return has been filed from it.
Everything closing did is given back: attendance unlocks, the payslips are withdrawn (kept on record with the reopening),
and every loan instalment, claim, variable pay, encashment, arrear, expense, advance, bonus, reward redemption, transfer
allowance and fundraiser pledge the month settled is due again. The month then needs a fresh calculation, approval and
close; re-issued payslips carry a revision number (`…-R1`).

**Defects fixed:**
- A leaver's final month could be paid twice — by the full and final settlement and by the month's run. The run now
  leaves out a leaver whose approved settlement already pays the month, and approving a settlement whose month was
  closed meanwhile is refused.
- Issued payslips were protected from deletion only; their snapshot and number can no longer be changed.
- The pre-run checks always looked at the month holding today, not the month being run.
- Setting a run's payment date re-checks the run after locking it (a run approved meanwhile is no longer dropped back).

**Settings** (Setup → Payroll → Payroll run / Payslips): *Run payroll only after the month's attendance is processed*,
*Tick every pre-payroll check before running payroll* (both on), *When employees see their payslips* (when published).

## 3. Wizards everywhere (S6)

- Every drawer and action modal with two sections or four fields or more follows the same wizard ending in Review
  (about 180 action modals among them: leave on behalf, process attendance, new position, salary proposals …); a short
  note, status change or quick edit stays one screen (narrowed on review, 2026-11-10).
- Long forms without sections are split a few fields at a time (at most six per step).
- Setup centre, Pay cycle and the expense / travel form setup are wizards with Review; payroll's 51 settings are in
  eight named steps (Payroll run, Payslips, Bank and accounts, Leave encashment and bonus, Reimbursements, Variable pay,
  Income tax, Employees' view of pay).
- One look: the 39 modals that had their own steps, the investment declaration wizard and the hire wizard (which now
  ends in Review too) use the same step rail as every other wizard.

## 4. Headers and sub-headers (S5)

- 77 pages that had no sub-heading have one: one plain sentence saying what you do there.
- Pages that shared a menu name have their own: Pay / Performance / Talent / Workforce analytics; Learning / Talent /
  Workforce overview; Pay review cycles and Review cycles; Letter templates and Review templates; Pay budgets and
  Headcount budgets; Continuous feedback and Feedback requests; *My HR tickets* (was a second "My requests");
  *My courses* (was a second "My learning"); Financial approvals; Country regions.
- Sentence case for labels ("My team", "My day", "Packs & blueprints"). Named workspaces keep capitals as proper names
  (Admin Centre, Workforce Command Centre, Change Centre, Organisation Designer, Payroll Auditor, Workforce Intelligence).
- No competitor name appears on screen any more (the Engage desk, seed dialogs, an import example, the payroll run).

## 5. One place for each thing (S4)

- Running payroll has one door: Run payroll. The read-only view of every company is *Payroll overview* everywhere (it
  carried three names). The scheduled recalculation waits for the same checks a person's Calculate does.
- One inbox: the rail shows *My work* (overview), *Inbox* (approvals and tasks together) and *My tasks*; *Approvals*
  left the rail (its links and notifications still open it), and the command search's approvals open the Inbox.
- Payslips appear once per person: employees use *My payslips* (My space and the Me menu); the payslip register is
  HR's menu entry only.
- Four registers filed under a group that did not exist ("Admin", shown as "Other") are in their modules: question banks
  and feedback templates (Customisation), clearance checklists (Exit), workforce reasons (People setup).
- *Company statutory profiles* (the older setup) is listed only while it still holds profiles; Setup → Payroll
  → *Statutory profiles* opens the establishment profiles that replaced it.

## 6. Duplication (S3)

- **One CSV formula-injection guard** (`App\Support\Csv\SpreadsheetCell`) instead of 14 copies with two rules; five
  exports that had none are guarded (birthdays, acknowledgements, 360 feedback, compensation in force, reports).
- **One base currency** (`CurrencyRates::baseCurrency()`: the organisation's currency, then its setting) instead of
  three ways; compensation, loans, salary uploads and bulk salary no longer read the platform default directly.
- Employee searches in access groups and the report library treat a typed % or _ as a character.
- Generic uploads have their own row limit setting (they borrowed attendance's).
- Removed dead code: the change-feed panel and its two views, a superseded survey task provider, an unused learning
  cost service, three unused chart components, an unused journey view, Laravel's welcome view, and `x-pos.empty`
  (all its uses now `x-pos.state`).

## 7. Decisions taken (owner asked for best practice)

| # | Decision | Why |
|---|---|---|
| ST-1 | The latest closed month can be reopened until paid or filed; everything is given back. Supersedes PAY-1. | HROne's "Unprocess salary"; the reopen/rollback of the latest period in Workday, SAP and Darwinbox. After payment or filing, the bank and the authorities hold the figures, so corrections go forward. |
| ST-2 | Payslips are published in their own step (setting allows "as the month closes"); months closed before count as published. Supersedes PAY-2. | Time to check and reopen before anyone sees a payslip. |
| ST-3 | The checklist is mandatory; ticks lapse when what they counted changes. | Owner asked for mandatory checks; a stale tick must not carry into the run. |
| ST-4 | Attendance comes first. | Paid days come from attendance. |
| ST-5 | Action modals with four fields or more are wizards; shorter ones stay one screen. | A wizard for a single reason field adds a click and nothing else. |
| ST-6 | Edit pages keep "Edit {record}"; named workspaces keep capitals. | Universally understood; they are product names used in permissions and help. |

## 8. Before you use it locally

- Run `php artisan migrate` (one new migration: publish / reopen columns, `payroll_prerun_checks`, `payroll_reopenings`)
  and `php artisan peopleos:sync-permissions` (`payroll.publish`, `payroll.reopen_closed`; payroll admins get both).
- Your local Filament component cache (`bootstrap/cache/filament`) was from 2 Nov and hid every page added since; it was
  refreshed (the old file is kept in the session scratchpad). Production rebuilds it in `deploy.sh`.

## 9. Not done / follow-ups

- The Inbox does not yet have the Task inbox's *Sent back to me*, *Handled by me* and *All open tasks* views, so
  *My tasks* stays in the rail.
- Duplicates left (behaviour is the same in each copy): financial-year label parsing (~15 places), pay-month parsing
  (~31), two employee label formats and 13 unbounded employee pickers, six import pipelines beside `RowImports`,
  the organisation-unit and master-data resources that are byte-identical clones, attachment path checks (9 copies),
  18 private copies of `rescue()`.
- A reopened month does not reopen advance recovery schedules that the recovery's closing of the advance had closed.
- Some concepts still carry two names (e.g. audit: *Change history* in the menu, *Audit events* in breadcrumbs).
