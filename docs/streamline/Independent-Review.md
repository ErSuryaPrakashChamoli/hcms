# PeopleOS streamline programme — independent review and acceptance (2026-11-10)

Second, evidence-based review of the 2026-11-05 programme (`Streamline-Plan.md`, `Findings.md`, `Streamline-Report.md`),
against the owner's review brief of 2026-11-10. The brief arrived cut off in section 8D ("Provide employee-level drill…");
sections 1 to 8D are covered here, and the rest is open until the owner sends it. Every status below rests on a test, a
browser check or the code; where there is no evidence the status says so.

## 1. Environment and change safety

| Check | Finding |
|---|---|
| Repository / branch / HEAD | `/home/administrator/Documents/hrms/hcms`, `main`, `5204b2fb` (no commit since the programme began). |
| Working tree | Uncommitted: the programme's changes plus 11 staged deletions (dead code removed with `git rm`); no stash; one worktree. Nothing was reset, stashed, committed, pushed or deployed. |
| Concurrent work | Session `hcms-9a` works in the same tree on rail navigation (`ExperienceNavigation.php`, sidebar item view, rail JS/CSS, `RailSectionsTest`); asked and confirmed — no overlap; those files were not touched in this review. Three `artisan serve` processes (ports 8001–8003) serve this tree. |
| Isolation | Pest runs on SQLite in memory with cached config / routes / events bypassed (`build-tools/pest1.sh`, `pest.sh`); full suites run in separate clones (`build-clones/nov5-base`, `build-clones/nov10-final`); MySQL checks on a scratch database (`hcm_s2_scratch`, dropped after) and a disposable showcase (`hcm_s2_visual_showcase`). Test workers run with `oom_score_adj` 1000 so the kernel kills them first if the shared machine runs out of memory. |
| Not done (by the brief) | No production migration, no permission sync, no external message, no payment operation. The local Filament component cache (stale since 2 Nov, hid every page added since) was refreshed during the programme; the old file is kept in the session scratchpad. |

## 2. Requirement traceability (the 2026-11-05 request)

| ID | Original instruction | Implementation and location | Evidence | Status |
|---|---|---|---|---|
| R1 | Run the full suite | Baseline on an unchanged clone; final run on the finished tree | Baseline 2,996 tests, 2,995 passed (one timing flake, fixed). Final: FINAL_RESULTS | FINAL_STATUS |
| R2 | Find and remove duplication | Diverged or dead copies consolidated: CSV guard (`App\Support\Csv\SpreadsheetCell`, 14 copies, 5 unguarded exports), base currency (`CurrencyRates::baseCurrency`), LIKE escaping, import row limit, dead classes and views, `x-pos.empty` | `SpreadsheetCellTest`; suite | PARTIALLY COMPLETE — identical-behaviour copies remain (report §9) |
| R3 | What should come under what | Four registers out of an undeclared "Admin" group; payslip register for HR only; module ownership map (§5) | Group scan: every resource in a declared group | PARTIALLY COMPLETE — Expense stays in the Pay area by design (§5) |
| R4 | Every function works, with its dependencies | Every page and register (list, create) opens for an administrator and for an employee | `EveryPageOpensTest` (2 tests, all screens, 0 server errors); suite | COMPLETE AND VERIFIED for opening every screen; actions are covered by the module suites, not by one crawl |
| R5 | A wizard everywhere, one look | Record pages always; drawers and action modals with sections or ≥ 4 fields; settings pages; one rail style | `FormWizardTest` (11), browser (Run payroll, Setup centre, 9-field modal, drawer) | COMPLETE AND VERIFIED (rule narrowed on review, §4) |
| R6 | Headers and sub-headers, distinct names | 77 sub-headings; 10 menu-name collisions (19 entries) made distinct; no competitor name on screen; one name for the payroll overview | Crawl renders every page; token scan for competitor names (search aliases only) | COMPLETE AND VERIFIED (visual check sampled, not every page) |
| R7 | One way to each page | Run payroll is the one door; one inbox in the rail; one payslip entry per person | Navigation code + tests (`Ux19ExperienceTest`, `PayrollDeskReadinessTest`, `MySalaryYtdTest`) | PARTIALLY COMPLETE — Approvals page kept for links; Task inbox views not yet in Inbox |
| R8 | Review as a user, make it world-class | This document and the programme report | — | PARTIALLY COMPLETE (open items §8) |
| R9 | Payroll works | Engine unchanged; three defects fixed (F&F double pay, payslip immutability, readiness month, plus a locked-row recheck) | `tests/Feature/Payroll` passes in full (round 2 of the programme), `PayrollGuidedRunTest` and `RunPayrollPageTest` (17 tests after this review), on SQLite and on MySQL 17/17 | COMPLETE AND VERIFIED |
| R10 | Mandatory checks before running payroll | `PayrollRunChecklist` (20 checks, statuses, ticks that lapse); Calculate refuses on every screen and in the scheduled recalculation until ready | Tests above + `PayrollRunCockpitTest` | COMPLETE AND VERIFIED |
| R11 | Close payroll and publish payslips | Close = finalize; `PayslipPublishing` (separate step, employee notice on publish) | `PayrollGuidedRunTest`, `RunPayrollPageTest`, `MyPayPagesTest`, `PayrollRunTest` | COMPLETE AND VERIFIED |
| R12 | Reopen last month to modify it | `PayrollMonthReopen` (latest month, not paid / filed, reason, permission, full give-back, revision numbers) | Give-back tested end to end for leave encashment, variable pay, attendance arrears and loan payout; the other ten sources by code review only | COMPLETE AND VERIFIED for those four; IMPLEMENTED BUT NOT VERIFIED for the other ten sources |
| R13 | Attendance first, then payroll | Attendance step gate (processed, not processing, closed when month close is used) | `PayrollGuidedRunTest`, `RunPayrollPageTest` | COMPLETE AND VERIFIED |
| R14 | Check joiners, exits, F&F, uploads, statutory, revisions, attendance, everything mandatory | 20 checks (§7) | Tests above | COMPLETE AND VERIFIED |
| R15 | Research HROne and build accordingly | HROne research store (process salary, warning card, step card, unprocess, publish); live HROne not re-crawled (no credentials in this session) | `hrone-research/components.json`, `menu-permission-tree.md` | COMPLETE from the stored research; live portal not re-checked |
| R16 | Draft first, then one by one, then report | `Streamline-Plan.md` → items → `Streamline-Report.md`; this review | — | COMPLETE |

## 3. Architecture and functionality (§4 of the brief)

- **Every screen opens.** `EveryPageOpensTest` opens every Filament page and every register's list and create page
  (over 300 screens) as an administrator holding every permission, and every page an employee may open, and fails on any
  server error: none.
- **Boundaries hold.** `tests/Feature/Architecture` (module contracts, tenancy, data inventory, Livewire reserved names)
  passes; the review moved two new pieces onto the contracts it requires: attendance unlocking into `PayrollRuns` (the one
  payroll file that locks days) and salary revisions through `CompensationOutput::pendingChanges()`.
- **Placeholders / empty pages.** None found by the crawl (each page answered 200, 302 or 403 and rendered its content).
- **Authorization.** New screens and actions: `RunPayroll` needs `payroll.view` with an operator right; tick needs
  `payroll.calculate`; publish `payroll.publish`; reopen `payroll.reopen_closed`; Calculate keeps `payroll.calculate`;
  approval keeps the separation of duties. Covered by `RunPayrollPageTest` ("only for people who run payroll").
- **Duplicate routes.** None (route names and paths unique; checked during the programme).

## 4. Forms and wizards (§5B, §5C)

| Class of form | Rule | Examples |
|---|---|---|
| Record pages (create / edit) | Always a wizard ending in Review | Employee hire, shift, leave type, policies |
| Policy configuration | Policy wizard (Policy → its sections → applicability → Review; Save as draft / Publish) | Attendance, leave, shift, expense, travel, asset, performance, loan policies |
| Complex administrative configuration | Wizard over its sections, saved on Review | Setup centre (payroll in 8 named steps), security policy, pay cycle, expense / travel forms |
| Multi-step business workflows | Own steps, same rail | Run payroll, hire wizard, investment declaration, assign salary, data import |
| Transaction and lifecycle forms in drawers / modals | Wizard when two titled sections or four fields or more | Leave on behalf, process attendance, new position, salary proposal, exit initiation |
| Simple single-action forms and quick edits | One screen (with the review panel in drawers) | A reason, a note, a date, a status change, a 2–3 field master |

- **Review change (2026-11-10):** the programme had made every record drawer a wizard; the brief says a quick edit stays
  simple, so drawers follow the same rule as modals (`FormWizard::wants()`: ≥ 2 sections or ≥ 4 fields). A two-field
  drawer (mood reasons) is one screen again (`FormWizardTest`).
- **Standard:** steps named after their sections (long runs of fields split at most six per step), Previous / Next with
  Next validating the step, Review listing what was filled in or changed, the action's own button on Review, server-side
  validation unchanged, and Filament's disabled-while-saving buttons against double submission.
- **Drafts:** policies save as drafts and resume; other record wizards save in one go (no draft state on those models).

## 5. Terminology, navigation and module ownership (§5A, §5D, §6)

| Function | Canonical place | Other ways in (same screen) | Owner |
|---|---|---|---|
| Run a pay month | Pay › Run payroll | Payroll desk item, run list and overview header, Actions panel, command search, phone bar, Home / My work | Payroll |
| See every company's month | Pay › Payroll overview (read-only) | Payroll desk item | Payroll |
| Act on approvals and tasks | My work › Inbox | Notifications; Approvals page (links only); `UnifiedInboxTest` | Workflows |
| Own payslips | Me › My payslips | My space, command search (latest payslip) | Payroll |
| Payslip register | Pay › Payslips (HR only) | Employee profile | Payroll |
| Statutory profiles | Setup › Payroll › Statutory profiles (establishments) | Older company profiles only while they hold data | Compliance |
| Analytics (by area) | Pay / Performance / Talent / Workforce analytics | Insights section | Each module |
| Overviews | Learning / Talent / Workforce overview | — | Each module |
| Cycles, templates, budgets | Pay review cycles, Review cycles; Letter templates, Review templates; Pay budgets, Headcount budgets | — | Compensation / Performance / Letters / Workforce |
| Feedback | Continuous feedback, Feedback requests | — | Performance |
| Employee help | My HR tickets (helpdesk), My requests (every request type) | — | Helpdesk / Workflows |

Rail sub-modules never repeat their module's name (session `hcms-9a`, owner request, same week:
`ExperienceNavigation::AREA_LABELS` — the My work overview reads "Overview", Organisation's area "Structure",
Communication's "Messaging", Workflows' "Process flows").

Rules: sentence case for labels; named workspaces are proper names (Admin Centre, Workforce Command Centre, Change Centre,
Organisation Designer, Payroll Auditor, Workforce Intelligence); no competitor names on screen; a page's sub-heading says
what you do there. Module ownership follows `ModuleCatalogue::SPACES`; Expense & travel sits in the Pay area beside
payroll because reimbursements and advances are paid through it (kept). RMS stays a separate product.

## 6. Dependencies (§7) — payroll

```
Attendance (processed, closed) ─┐
Leave (approved, unpaid) ───────┤
Joining / exit / lifecycle ─────┤                         ┌─> Payslips (published) ─> Employee notice
Compensation (CompensationOutput, effective-dated) ──────>│
Statutory rules (verified) ─────┼─> PayrollRunChecklist ─> PayrollRuns.calculate ─> validate ─> approve ─> finalize ─┤
14 payroll input sources ───────┤        (gate)            (entries, lines)                                         ├─> Inputs settled (settle)
Full & final (settlement) ──────┘                                                                                  ├─> Attendance locked
                                                                                                                   └─> Bank file, JV, mark paid
PayrollMonthReopen: finalize ⟲ (unsettle every source, unlock attendance, withdraw payslips, period open, run to draft)
```

| Dependency | Contract | Order / safety | Tested |
|---|---|---|---|
| Attendance → payroll | `AttendanceMonthDesk`, `AttendancePeriodState`; days locked only by `PayrollRuns` | Gate before Calculate; locked at close, unlocked on reopen | Yes |
| Compensation → payroll | `CompensationOutput` (fingerprints re-checked at close) | Changed compensation after calculation refuses close | Yes (existing) |
| Inputs → payroll | `PayrollInputSource` (`inputsFor`, `settle`, `unsettle`) | Settle on `payroll.finalized`; reopen gives back on the locked run; ledger keys carry a generation so a second close posts again | 4 of 14 sources end to end |
| F&F ↔ payroll | Run leaves out a leaver whose settlement pays the month; settlement approval refuses a month closed meanwhile | Both directions | Yes |
| Payroll → statutory returns | Reopen refused once a challan or an exported / filed return covers the month | Locked-row check | Code review |
| Scheduled recalculation | Same gate as a person's Calculate; skipped months audited with why | — | Yes |

No circular dependency was introduced: reopening calls the input sources through the contract; the expense domain still
never names payroll runs (`ExpenseInvariantsTest`).

## 7. Payroll — deep audit (§8 A–D)

**A. Sequence.** 1 attendance processing → 2 attendance closure (where used) → 3 readiness → 4 inputs validated (checks)
→ 5 calculation → 6 review → 7 approval (second person) → 8 close → 9 publish → 10 bank file / JV / paid — in that
order on the Run payroll rail; calculation refuses on incomplete attendance (gate in `PayrollActions` and in the
scheduled recalculation). Gap: a run calculated before the review was added is not recalculated automatically when a
check later lapses — closing re-checks compensation and engine version, not every input.

**B/C. Readiness.** 20 checks, each with status (PASS, WARNING, PENDING_APPROVAL, BLOCKED, NOT_APPLICABLE), description,
count, the people concerned, source module, a link to where it is resolved (when the person may open it), blocking or not,
and "as of" time; a summary bar counts each status and what is left to tick. BLOCKED stops the run; WARNING and
PENDING_APPROVAL are acknowledged by ticking (organisation setting, on by default); PASS and NOT_APPLICABLE need nothing.

| Brief's list | Covered by |
|---|---|
| Joiners, exits, notice, hold, status changes | New joiners; Exits and people leaving (notice, pending exit, suspended, long leave); Salary on hold |
| Transfers, department / location changes | Transfers and moves (new) |
| Salary assignments, revisions, structure, uploads, pending approvals, missing salary | Salary revisions; Salary uploads; Salary structure missing (BLOCKED); CTC and wage checks |
| Attendance processing, missing, regularisation, unprocessed, LOP | Attendance step (processed, pending requests, close); Leave and unpaid days (new) |
| Leave pending, unpaid, encashment | Leave and unpaid days; Leave encashment |
| PF / ESI / PT / LWF / TDS, regime, declarations, proofs, rule versions | Statutory details (identifiers + unverified rules); Tax regime and declarations (new); Investment declarations and proofs |
| Loans, reimbursements, expenses, advances, variable pay, deductions, recoveries, F&F, arrears | Loans and advances; Reimbursements and claims; One-time pay inputs; Full and final settlements; Arrears from earlier months (new) |
| Bank details, last month | Bank details; Last month's payroll |
| Not yet as separate checks | Pay group / payroll calendar (the month's dates show on step 1), duplicate payroll inputs, shift / weekly-off issues (on Run attendance), LWF applicability (inside statutory rules) |

**D. Review before approval** (Run payroll › Review and approve): employees, gross, net, employer cost with the change
from the last closed month; deductions, LOP days, PF (employee / employer), ESI, PT, TDS, reimbursements, loan and advance
recoveries, joiners / leavers, blocking exceptions / warnings, new in the run / missing since last month, and the
employees whose net pay moved 20 % and 1,000 or more; each employee's breakdown on the run page.
(`PayrollRunReview`; `PayrollGuidedRunTest`, `RunPayrollPageTest`.)

## 8. Changes made in this review

1. Readiness statuses, modules, links, "as of", summary; four new checks (transfers and moves, leave and unpaid days,
   arrears from earlier months, tax regime); ticks only where there is something to look at.
2. Review before approval (`PayrollRunReview` + `run-payroll/review.blade.php`).
3. The scheduled recalculation honours the same gate and records why it skipped.
4. Wizards: one rule for drawers and modals (≥ 2 sections or ≥ 4 fields); quick edits stay one screen.
5. One name for the read-only payroll page (Payroll overview); the payslip register is HR's menu entry only.
6. End-to-end reopen test for variable pay, attendance arrears and loan payout.

## 9. Open items

- The brief's text after section 8D (employee-level drill and any later sections) — awaiting the owner.
- Reopen give-back: ten sources (loan recovery, claims and their ledger, expense reports, advance payout and recovery,
  bonus, rewards, transfers, fundraisers, shift allowance) are reviewed in code but not tested end to end.
- Inbox lacks the Task inbox's *Sent back to me*, *Handled by me* and *All open tasks* views.
- Duplicates with identical behaviour (report §9); record-wizard drafts outside policies.
- Readiness checks not yet separate: pay group, duplicate inputs, shift / weekly-off issues.
