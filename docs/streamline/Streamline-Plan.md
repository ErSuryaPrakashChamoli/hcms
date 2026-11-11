# PeopleOS streamline programme — draft plan

Owner request, 2026-11-05: run the full suite; review the whole product as a user; remove duplication; put every page
under the right module; make every form a wizard; give every page a clear, distinctive header and sub-header; one way
to reach each page; and rebuild the payroll run as a guided flow — attendance first, then a mandatory pre-payroll
checklist, then run, close and publish payslips, with the option to reopen last month. HROne's own payroll screens
(from the research store in `hrms/hrone-research`) are the reference for the payroll flow.

This file is the draft. Each work item is done in order, ticked here when done, and summarised in
`docs/streamline/Streamline-Report.md` at the end.

## Ground rules

- Existing behaviour stays unless an item below changes it on purpose; every deliberate change is listed in the report.
- Decisions the owner left open are decided by best practice (Workday, SAP SuccessFactors, Darwinbox, HROne) and
  recorded with their reasons in the "Decisions" section below.
- Statutory values still come only from verified rules.
- Tests run in a separate clone of the tree (`build-clones/`), one Pest process at a time, never against the dev
  database.

## Work items

### S1 — Baseline: full test suite and a page-by-page check
- [x] Full Pest suite on an unchanged copy of the tree (`build-tools/chain/nov5-base.sh`): 2,996 tests, 2,995 passed; the
      one failure (S3 pre-signed URL expiry 299 vs 300 s on a busy machine) is a timing flake, fixed in the test.
- [x] Open every page as an HR administrator and as an employee: `tests/Feature/Admin/EveryPageOpensTest.php` opens every
      page and every register's list and create page and fails on any server error.

### S2 — Payroll: one guided run, from attendance to payslips
HROne's "Process salary" screen is a step card (mandatory / non-mandatory steps), a warning card of employees who
cannot be paid (attendance not processed, statutory details, salary structure, bank, PAN, Aadhaar, UAN, ESI number,
ESI over the ceiling, invalid CTC, below minimum wage, salary unprocessed last month), Run payroll, publish salary
slips, release salary, and "Unprocess salary". PeopleOS's engine already calculates, validates, approves (by a second
person) and finalizes; what is missing is the guided flow, the hard checks, a separate publish step and reopening.

- [x] **Fix three defects found in review first:**
  - full and final settlement can pay the final month twice (a settlement paid before the month's run, then the run);
  - payslips are only protected from deletion, not from being changed;
  - the "before you run payroll" checks always look at the pay month containing today, not the month being run.
- [x] **Run payroll wizard** (one page, step rail on the left like every other wizard):
  1. *Month* — company and pay month; shows where that month stands.
  2. *Attendance* — attendance must be processed for the whole month first (and the month closed where month close
     is switched on): days without attendance, pending leave / regularisation requests, arrears held; process or
     close it from here.
  3. *Pre-payroll checks* — every check must be reviewed and ticked before the run starts; blocking checks must be
     cleared. New joiners · exits and full & final settlements · salary uploads and one-time inputs (variable pay,
     arrears, adjustments) · salary revisions · statutory details (PAN, Aadhaar, UAN, ESI number, statutory rules) ·
     attendance and paid days · salary structures and CTC · bank details · loans and advances · reimbursements and
     claims · salary on hold · investment declarations and proofs · leave encashment · last month's unpaid employees.
     A tick records who checked, when and the count seen; it lapses if the count changes.
  4. *Run payroll* — calculate (or in the background) and see employees, gross, net, exceptions and the change from
     last month.
  5. *Review & approve* — validate, then approval by a second person (separation of duties stays).
  6. *Close payroll* — lock the month (today's finalize): attendance locked, inputs settled.
  7. *Publish payslips* — a separate step: employees see payslips only once published.
  8. *Pay* — bank file, journal voucher, mark as paid.
- [x] **Reopen last month** — the latest closed month can be reopened to change details, with a reason, until it is
      marked paid or a statutory return / challan is filed from it. Reopening gives back everything closing did:
      attendance unlocked, the period reopened, payslips withdrawn (kept on record), and every input the month settled
      (loans, claims, variable pay, encashments, arrears, expenses, bonus, rewards, transfers, fundraisers) returned to
      due. The run then needs a fresh calculation, approval and close; re-issued payslips carry a revision number.
- [x] Payroll desk, control room, Actions panel and command search all start the run in this wizard.

### S3 — Duplication
- [x] Remove the duplicates that had diverged or were dead (CSV guard, base currency, LIKE escaping, import row limit, dead
      classes and views, `x-pos.empty`); the rest are listed in the report as follow-ups.
- [x] Retire legacy screens kept beside their replacements ("Company statutory profiles" shows only while it holds data).

### S4 — One place for each thing (what comes under what)
- [x] Settle the "waiting for me" cluster: My work (overview), Inbox (approvals and tasks), My tasks; Approvals leaves the rail.
- [x] One destination per job where two pages share a name or a job ("My requests" ×2, "My learning" ×2, payslips ×2).
- [x] Put the four modules filed under an undeclared "Admin" group into their real module.
- [x] Launcher entries reviewed: the command-search duplicates are search phrasings (kept); approvals open the Inbox.

### S5 — Headers and sub-headers
- [x] No two pages share a nav label; every page has a one-line sub-header saying what you do there (77 added).
- [x] Our own vocabulary: no competitor names in anything a user sees.
- [x] Sentence case for labels; named workspaces (Admin Centre, Workforce Command Centre …) stay proper names.
- [x] One name per concept for the run (Run payroll) and payslips (My payslips); others listed as follow-ups.

### S6 — Wizards everywhere
- [x] Every data-entry form follows the same wizard: record pages, every drawer, settings pages and action modals with
      four fields or more. Short confirmations (a reason, a date) stay one screen.
- [x] One wizard look: the step rail, Previous / Next and the Review step are the same on every wizard.

### S7 — Verify and report
- [ ] Full suite again on the finished tree, plus the page crawl; screenshots of the payroll wizard and converted forms.
- [ ] Write the report (`docs/streamline/Streamline-Report.md`) and update the architecture records.

## Findings (from the review)
Filled in as the inventories come back; see the report for the final list.

## Decisions
| # | Question | Decision | Why |
|---|---|---|---|
| ST-1 | May a closed payroll month be reopened? (was PAY-1 "never reopen", C12-D1 open) | Yes: the owner asked for it on 2026-11-05. Only the latest closed month, before it is marked paid and before a statutory return or challan is filed from it; reason required; a dedicated permission; everything closing did is given back. | Matches HROne's "Unprocess salary" and the reopen / rollback that Workday, SAP and Darwinbox allow for the latest period. Once money has moved or a return is filed, a correction goes forward as an arrear or recovery, because the bank and the authorities already have the old figures. |
| ST-2 | When do employees see payslips? (was PAY-2) | A separate "Publish payslips" step after closing. Months already closed before this change count as published. | Leaves room to check and reopen before employees see anything. This matches HROne's publish step and the release step in other suites. |
| ST-3 | Is the pre-payroll checklist mandatory? | Yes. Every check is reviewed and ticked before the run starts. Blocking checks must be cleared. A tick lapses when what it counted changes. | The owner asked for mandatory checks; lapsing ticks stop a stale review being carried into the run. |
| ST-4 | Must attendance be done before payroll? | Yes. The wizard's Run step stays closed until the month's attendance is processed, and closed where attendance month close is switched on. | The owner asked for attendance first; paid days come from attendance. |
