# Phase 13 — Exit and Alumni

Blueprint §40, §59–§62, §121 Phase 13. Built 2026-09-27.

## What exists

**Exit management (`App\Domain\Exit`)**

| Model | Purpose |
|---|---|
| `ExitCase` | `EXIT-YYYY-NNNNN`; type (resignation, termination, retirement, contract expiry, absconding, death, mutual, other), reason, resignation / decision date, notice days, last working day, manager, knowledge-transfer target, rehire eligibility. `initiated → notice → clearance → settlement → completed`, or `withdrawn` / `cancelled`. |
| `ExitClearance` | Five stages from config (manager, IT, finance, asset, HR) with owner user or role, a checklist, remarks, a recoverable amount and cleared-by. The asset stage's checklist is generated from `Assets::clearanceFor`. |
| `ExitInterview` | Reason, 1–5 ratings on seven dimensions, would recommend / rejoin, free text; `source` = `employee` or `hr_inferred`. |
| `FinalSettlement` + `FinalSettlementLine` | `draft → calculated → approved → paid`; every line has a type, code, amount, basis and source (`auto` or `manual`). |

`Exits` service: `initiate` (validates, builds clearances, moves the employee to `notice_period`, stamps the planned `exit_date`, starts clearance immediately for immediate types or when inside the lead window), `resign` (self-service; last day capped at the notice period), `startClearance` (notifies each owner), `clearStage` (asset stage refuses while assets remain in custody unless a recoverable amount is recorded; last stage flips the case to `settlement` and creates the draft settlement), `blockStage`, `markNotApplicable`, `withdraw` (resignations only; employee back to `active`), `complete` (all stages cleared, settlement approved unless explicitly skipped; employee `exited` on the last working day; login suspended), `createAlumni` (employee `alumni`, profile created, login re-enabled with only the Alumni role), `tick` (daily auto-start of clearance).

`FinalSettlements`: `calculate` runs the last month through the payroll calculator (so proration by exit date, LOP and statutory deductions match a normal run; skipped when that month is already finalized), adds leave encashment for encashable paid types (balance × per-day on basic or gross per `exit.encashment_basis`), notice shortfall recovery for resignations (`exit.notice_recovery`), asset recovery from clearances; manual lines survive recalculation. `approve` freezes lines and posts encashment to the leave ledger; `markPaid` records the reference.

`ExitInterviews`: `submit` (employee or HR), `analytics` (reasons split by source, average ratings from employee answers only, would-recommend / rejoin percentages).

**Letter factory (`App\Domain\Letters`)** — `LetterTemplate` (type, subject and markdown body with `{{ employee.name }}`-style variables, approval flag, auto-versioned on content change), `Letter` (`LTR-YYYY-NNNNN`, rendered subject and body frozen at generation with the context snapshot, `draft → pending_approval → approved → issued`, or `rejected`, linked source such as the exit case or alumni request). `Letters` service: `context`, `generate`, `approve`, `reject`, `issue` (stores an HTML document under the employee's documents with a `LETTER_<TYPE>` document type and notifies them), `html`. Nine starter templates per tenant.

**Alumni (`App\Domain\Alumni`)** — `AlumniProfile` (contact, last role, dates, rehire eligibility, portal flag, consent), `AlumniRequest` (`ALR-YYYY-NNNNN`; experience / relieving letter, employment verification, salary certificate, payslip copy, Form 16, reference, other; `submitted → verified → approved → generated → delivered`, or `rejected`). `Alumni` service: `request`, `verify`, `approve` (generates and issues the mapped letter automatically), `deliver`, `reject`.

**Events** — `ExitEvent` (`exit.*`, `letter.*`, `alumni.*`) with recipient user ids; bridged to notification rules with in-app fallback. `exit.initiated`, `letter.requested` and `alumni.request.created` are workflow trigger events.

**Automation** — `peopleos:exit:tick` daily at 04:00.

## Admin UI

- **Exit**: Exit cases (HR sees all; employees "My exit"; managers and clearance owners the cases they act on) with actions initiate, start clearance, exit interview, generate letter, withdraw, complete, create alumni; tabs Clearance (clear with checklist and recoverable amount, block, reopen, not applicable), Full & final (start, breakdown modal, calculate, add line, approve, mark paid), Letters. Exit insights page.
- **Letters**: Templates; Letters (generate, approve, reject, issue, download HTML). Employees see "My letters" (issued only).
- **Alumni**: Alumni directory with profile view and the Requests tab (verify, approve & generate, deliver, reject, open letter). Alumni users get the **Alumni portal** under "Me" (profile, documents, payslips, request form).
- My Day gains a **Resign** quick action. The People Control Centre shows exits in progress and those leaving this week.

**Permissions** — `exit.view|manage|clear|settle|interview|resign`, `letter.view|manage|issue`, `alumni.view|manage|portal`. New system role template **Alumni** (`alumni.portal`, `payroll.payslip`). Employee gains `exit.resign`; Manager gains `exit.clear` and `exit.resign`.

**Settings** — `exit.notice_days` (30), `exit.clearance_lead_days` (7), `exit.encashment_basis` (`basic` | `gross`), `exit.notice_recovery` (true), `exit.it_clearance_role` (`asset-admin`) / `exit.finance_clearance_role` (`payroll-admin`) / `exit.hr_clearance_role` (`tenant-hr-admin`) as role slugs.

## Conventions

- The planned last working day is written to `employees.exit_date` at initiation so payroll prorates the final month; withdrawal clears it. The lifecycle engine stamps it again on completion.
- Completing an exit suspends the login; creating the alumni profile re-enables it with only the Alumni role, so the same person and user record carry through (§62 "exit is not the end of the relationship").
- Letters are HTML documents; PDF rendering is not installed (no PDF library in the project) and is listed as a gap.
- Tests: `tests/Feature/Exit/ExitTest.php`, `tests/Feature/Admin/ExitPagesRenderTest.php`.

## Known gaps / deferred

- PDF generation and e-signature for letters; branding beyond the company name in the HTML header.
- Gratuity in the settlement (the compliance rule exists; years-of-service calculation and eligibility are not wired).
- Exit checklists are global per tenant; per-department or per-role checklists are not configurable yet.
- Alumni public employment-verification links for third parties are not built; verification happens through requests.
