# Owner decision sheet (2026-10-28)

Every decision still open before the compliance work can be released, in one place. **Release status: NO-GO.**

Every "recommendation" below is a recommendation, not a decision. Deadlines are reported deadlines, not legal
conclusions. The legal review pack has **not** been sent and will not be sent until you name the reviewer and
authorise sending.

## Decided on 2026-10-28 (your answers in this session)

| Decision | Your answer | Effect |
|---|---|---|
| Theme tokens for the iris button | Approve and apply | Applied; the design-system test passes; pixels identical in light and dark |
| Shared test helper `employeeWithUser()` company order | Approve and apply | Applied; verification on MySQL: see the test matrix §15 |
| Ordinary arrears and recoveries for mistakes in a finalised month | Keep them available | Only corrections caused by a wrong statutory profile wait for C12-D1. App messages and the proposed guide text say so |

## Urgent: needs action this week

| # | Question | Options | Recommendation | Evidence still needed | Lawyer? | Deadline; consequence of delay |
|---|---|---|---|---|---|---|
| D6 | Who reviews the legal content, and may the pack be sent? | Name a reviewer and authorise; wait | Name a qualified Indian labour-law professional now (checklist: legal pack §7.1) | — | — | **Now.** A5, A6, A8 and C12-D1 all need the reviewer; without one, none of them can close |
| A8 | Odisha PT: is the Repeal Act in force? | (a) confirm with the department, plus (b) prepare an inactive version; (c) wait | (a) + (b) | Assent, Act number, Gazette; Art. 213 text; counsel | Yes | **About 3 Nov 2026** (calculated). If the tax revives, PeopleOS under-deducts from that date |
| A3 | West Bengal PT: switch on the new slabs from 1 Oct 2026? | (a) get the final Schedule, then switch on; (b) use the draft; (c) wait | (a), urgently | Final Schedule 1607-F.T. | Only for (b) | **Now.** Each month over-deducts ₹110–₹130 per employee earning ₹10,001–₹20,000 |
| A5 | Bonus: make results final after counsel answers B1–B3? | (a) S.O. 4711(E) + counsel, then switch on; (b) the old Act's version only; (c) stay provisional | (a) | S.O. 4711(E) on the e-Gazette; B1–B3; a minimum-wage source | Yes | **30 Nov 2026.** Bonus is paid on provisional figures, with a risk of underpayment |

The other A-series items (A1, A2, A4, A6, A7, A9, A10, A11), each with its question, options, recommendation, evidence,
lawyer need, deadline and consequence of delay, are in `owner-decision-register-a-series.md`.

## Payroll and compliance design

| # | Question | Options | Recommendation | Evidence still needed | Lawyer? | Deadline; consequence of delay |
|---|---|---|---|---|---|---|
| C12-D1 | How is money settled when the statutory set-up was wrong for a finalised month? | B forward adjustment; D reopen (closed on 2026-10-28); C supplementary run; A keep blocked | **B**, with A for any statute counsel does not clear (`c12-d1-decision-brief.md`) | Counsel's answers to S1–S6 (legal pack §4.8); payroll lead on accounting | Yes, per statute | No fixed date. Until decided, such corrections stay approved but unapplied, and packs keep the original applicability |
| C12 review, R2–R5 | Accept the C12 invariant and guard; R2 (guard the company settings), R3 (re-check the statutory context at finalisation), R5 (the profile reopen and delete feature) | Accept as recommended, or other options | Accept; R2 and R3 as recommended (decisions paper, C12 §10) | An independent code review | No | No fixed date. Months calculated from company settings stay unprotected (R2) |
| P1 | Accept the payroll behaviour change: finalised and paid runs can never be reopened, recalculated, changed or deleted | Accept; ask for a controlled reopen (that is C12-D1 option D) | Accept | — | No | Before release. Payroll teams lose "reopen unpaid month"; corrections go into a later month |

## Analytics

| # | Question | Options | Recommendation | Evidence still needed | Lawyer? | Deadline; consequence of delay |
|---|---|---|---|---|---|---|
| AN1 | Accept the read-API fix and its reviewed-field lists (`analytics-api-dataset-review.md`) | Accept; ask for changes (no excluded field or dataset returns without your approval) | Accept | — | No | Before release. Integrations must add data scopes; exits reports no longer run through the API |
| AN2 | The permission that gates each of the 32 report-library datasets (for example tax investments, family and emergency contacts) | Keep as built; change per dataset | Review per dataset with HR and privacy | — | Possibly, for personal data (privacy) | Before the report library ships. It stays out of any release candidate |
| AN3 | Authorise a volume test on a disposable, production-like environment | Authorise; defer | Authorise (plan in `analytics-api-dataset-review.md`) | Thresholds to confirm | No | Before release. Large-tenant performance is unknown |

## Documentation and release

| # | Question | Options | Recommendation | Evidence still needed | Lawyer? | Deadline; consequence of delay |
|---|---|---|---|---|---|---|
| G1 | Update the customer guide's payroll chapter and rebuild the handbook (regenerates chapter 8, the diagrams, the HTML and the PDF) | Apply the proposal and rebuild; apply after C12-D1 | Apply the proposal now (`test-runs/proposed-customer-guide-payroll-correction.patch`, preview `customer-guide-proposal/`), then rebuild | — | No | Before customers read it. The current guide still shows reopening a finalised run |
| R1 | Commit, so that CI (including the catalogue job) runs | Commit; wait | Commit once the other sessions' work is settled | — | No | Blocks every CI-based gate |
| R2 | Migrations M1–M5 and the permission sync, per environment (`compliance-migration-runbook.md`) | Approve per environment; wait | Development first, then staging | Backups; pruning preview | No | Blocks deployment |
| C1 | Make establishment configuration (state, type, appropriate governments) mandatory, or verify it per customer | Mandatory; verify per customer | Mandatory | — | No | Before release. A missing value shows as configuration required, never as compliant |
