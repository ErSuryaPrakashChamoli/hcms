# HROne parity: open owner decisions

Date: 2026-10-29

This is one list of every open owner decision raised in the HROne parity programme. It covers the Setup matrices
(`setup/01`, `02`, `08`, `09`, `11`, `12`, `13`), the module and self-service matrices (`modules/20` … `27`, including matrix 20's re-check section), the build
status file (`setup/00-build-status.md`) and `HROne-Setup-Parity-Report.md`. When several matrices raised the same
question, it appears here once, with all its source IDs. Every decision has a default that keeps today's behaviour:
new options are built switched off, or in the safe form shown. Builders go ahead on that default until the owner
answers. If a recommendation would change today's behaviour, the row says so ("Recommended: …"). Statutory rule
checks are listed too, because only the owner, with legal or payroll advisers, can sign them off. Until then payroll
does not use unverified values.

**How to answer.** For each ID, write "default", "recommended" or your own choice in the answer sheet at the end.

**Source key.** `D-MS…`, `D-WO…`, `D-TX…`, `D-PO…`, `D-XO…`, `D-GO…` are the decision IDs in matrices 22–27.
"Report §8" means `HROne-Setup-Parity-Report.md` §8. "Matrix 26 §26.4.3 #9" means section 26.4.3, row 9 of that
matrix. Package IDs (BP-, CA-, P, SV-, XC-, HP-, RQ-, AN-, MS-, WO-, TX-, PO-, XO-, GO-) come from the source files.

## Already decided

| Date | Topic | Decision |
|---|---|---|
| 2026-10-29 | Password expiry for accounts with no recorded change date | When a tenant sets an expiry period, these accounts must change their password at the next sign-in (built). |
| 2026-10-29 | Goal sheet system mapping (HP-8d) | The reporting manager owns the final mapped goal sheet. The employee may propose or update goals, and the manager confirms them. |
| 2026-10-29 | Asset return on exit (AS-B) | Return requests are raised at exit by default, with configurable exceptions. No request is raised when the employee holds no company assets. |
| 2026-10-29 | Trips spanning several workflow groups (EX-F) | One trip is one record. Approval goes to every relevant workflow group, and each group approves only its own legs and expenses. The trip is approved when every group has approved. |

## Open decisions

### 1. Data visibility and privacy

| ID | Decision needed (one plain question) | Options | Recommended default (keeps today's behaviour unless noted) | Blocks packages | Source IDs |
|---|---|---|---|---|---|
| PRV-1 | May employees see their own PAN, Aadhaar reference, UAN and bank account numbers? | Hidden · masked (last 4) · masked with an audited "reveal" | Hidden (today). Recommended: masked, with an audited reveal (new permission in the employee template). HR access stays as it is. | MS-2 | D-MS1 |
| PRV-2 | May employees share a profile card or QR code outside the organisation (LinkedIn, WhatsApp, Naukri …)? | Internal link only · public profile card | Internal link only. | MS-6, MS-17 | D-MS3 |
| PRV-3 | Should employees see separation reasons and rehire status on their own profile? | Show all · own stated reason only · hide | Hidden (today). Recommended: show only the employee's own stated reason, never the organisation's reason or a rehire block. | MS-2 | D-MS5 |
| PRV-4 | Should employees re-enter their password before opening pay pages (CTC, payslips, salary YTD)? | Off · on | Tenant setting, off (today). | MS-9, MS-10 | D-MS10 |
| PRV-5 | Should uploads also accept spreadsheets, BMP images and archives (zip, rar), as HROne does? | Keep the document allowlist · widen | Keep the allowlist. Archives and spreadsheets can carry active content. | MS-3 (uploads) | D-MS9 |
| PRV-6 | May managers see or act on their team members' tax declarations? | No · yes, behind a policy switch | No (today; privacy). | None (§25.14 #3–4 stay N-A) | D-PO14 |
| PRV-7 | Should face-recognition data be kept for check-in (face registration per employee)? | No · yes | No. Check-in photos only, to keep biometric data to a minimum. | None (row stays N-A) | D-WO12 |
| PRV-8 | Must employees accept a privacy notice (personal-data consent) before using PeopleOS? Who supplies the notice text? | Off · on, with a versioned notice | Off (today). The notice text must come from the owner or legal and is never hard-coded. | SV-14; privacy acceptance (setup 01 §1.2) | SV-14 (CH-4); setup 01 §1.2 data privacy; Report §8 (Core HR); matrix 22 §22.2.0 #10 |
| PRV-9 | May business partners (consultants, vendors, BGV agents, customers) sign in without an employee record? Which roles may they hold, and do they count against the plan? | Not allowed · allowed with partner-only roles | Off (today). If turned on, partners get only roles marked "partner-assignable" and never `employee.sensitive.*`. The owner states how they count against the plan. | WO-15; SV-7 (AR-1) | D-WO2; gap IP-04 |
| PRV-10 | Should page access per My space item (on each role) also block the page itself, not only leave it out of My space, its favourites and search? | Leave out of My space · also block the page | Leave out of My space; the page follows the role's permission keys (built, CA-9). | None | D-CA9-1 |
| PRV-11 | Should a Workforce data-access group narrow what its members see about people in every module, or only on Workforce screens? | Every module · Workforce screens only | Every module (built, CA-9), so no module shows a record whose employee is hidden. | None | D-CA9-2 |
| PRV-12 | May the Account Owner role be granted by a role membership rule? | Never · like other system roles | Never (built, SV-7: locked with Tenant Super Admin). | None | D-CA9-3 |
| PRV-13 | May a role membership rule reach people outside its author's own access scope? | Yes, as a hand grant does · only inside the author's scope | Yes (built, SV-7); the units and employees a rule names must be ones the author can see. | None | D-CA9-4 |
| PRV-14 | Should HR see today's check-ins with their location on the Geo dashboard by default (HROne's default)? | Nobody until chosen · HR by default | Nobody until the organisation chooses (built, CA-9): no screen showed check-in coordinates before. | None | D-CA9-5 |
| PRV-15 | May holders of `task.view_all` or `audit.view` see a colleague's inbox Insight (decided count, on-time %, delayed %), within their access scope? | Yes, within scope · own Insight only | Yes, within their access scope (as built with RQ-8, 2026-10-30). | RQ-8 (built) | RQ-8 report |

### 2. Self-service editing

| ID | Decision needed (one plain question) | Options | Recommended default (keeps today's behaviour unless noted) | Blocks packages | Source IDs |
|---|---|---|---|---|---|
| SSE-1 | Which profile sections may employees edit themselves, and which edits need approval? | Per section: not editable · editable with approval · editable without approval | Nothing editable (today). Suggested tenant start: contacts, addresses, emergency contacts, family, education, experience and skills with approval; blood group and personal e-mail without approval; bank and statutory IDs always with approval and a document. | MS-3 (also MS-5, MS-7 photo, WO-7) | D-MS2 |
| SSE-2 | May employees change their legal name or date of birth themselves? | Never · with approval and a supporting document | Never (today). Recommended: only with approval and a linked document. | MS-3 | D-MS11 |
| SSE-3 | Should the system store the full date of marriage, or only day and month? | Day / month · full date | Day / month (today), unless a benefit rule needs the year. | MS-4 | D-MS8 |
| SSE-4 | May any self-service change to bank details or statutory identifiers (PAN, Aadhaar reference, UAN, PF / ESIC) apply without approval? | Always needs approval (whatever `profile.self_edit.sections` says) · follow the setting | Always needs approval (as built with MS-3, 2026-10-30); legal name / date of birth also always need a document. | MS-3 (built) | MS-3 report |

### 3. Payroll and statutory

Rows PAY-10 to PAY-14 are rule checks that only the owner, with legal or payroll advisers, can do.

| ID | Decision needed (one plain question) | Options | Recommended default (keeps today's behaviour unless noted) | Blocks packages | Source IDs |
|---|---|---|---|---|---|
| PAY-1 | How are mistakes in a finalized payroll corrected? | Never reopen: use an arrear, recovery or TDS override in an open month, a supplementary run or the settlement · allow HROne-style "unprocess" after release | Never reopen (today). Attendance cannot be unprocessed once a finalized payroll covers the month; later fixes become arrears. | PO-5, PO-12, PO-19 (wording); TX-3 | D-PO1, D-TX6 |
| PAY-2 | When do employees see their payslips? | At finalize, with an optional manual "Publish" step per tenant · always a separate publish step | At finalize (today). Manual publish is optional per tenant. | PO-7 | D-PO2 |
| PAY-3 | What happens to an unclaimed pay-on-claim balance at financial-year end? | Keep · lapse · pay it as taxable in the last month | Keep (today). | PO-9 | D-PO3 |
| PAY-4 | At challan time, is TDS deposited exactly as the finalized payroll deducted it? | Deposit what payroll deducted and fix differences by override next month · allow editing (breaks immutability) | Deposit what payroll deducted. | PO-13 | D-PO7 |
| PAY-5 | How should payslip PDFs be protected? | None · password from PAN / date of birth (HROne) · a secret per employee, or signed links | None (today; payslips are on screen only). Recommended: a secret per employee or signed links, not passwords from PAN or date of birth. Needs VEN-5 first. | BP-14, PO-7, PO-12 (slips), MS-9 | D-PO9; BP-14; setup 08 §10.8 #47 |
| PAY-6 | Should approved referral bonuses from the recruitment system (RMS) flow into payroll? | Keep in the RMS (N-A) · RMS sends them as a payroll input | Keep in the RMS. | None (§25.6.9 #5) | D-PO10 |
| PAY-7 | Who may manage perquisites? | Keep under `payroll.manage` / `payroll.view` · new right `payroll.perquisites` | Keep under the payroll rights (today). | None (§25.2.2 #25) | D-PO12 |
| PAY-8 | Must a second person approve each full and final settlement? | Setting off · on by default for new tenants | Off (today). | PO-12 | D-PO13 |
| PAY-9 | If an employee leaves before payroll pays an approved transfer allowance, is it added to the full and final settlement? | Not added · added as a settlement line | Not added (today). | PO-12 (settlement lines) | Report §8 (Workforce) |
| PAY-10 | Owner / legal: please verify the core statutory rule pack: minimum wages, PT and LWF for the remaining states (including Punjab's PT basis), LWF bands, PT month amounts and the 50% wage-share figure. Today 0 of 24 rule versions are verified. | Verify, with evidence · leave unverified | Unverified. Payroll keeps refusing or skipping anything without a verified rule. Tenants never type statutory values. | BP-15; SV-1 (Punjab PT); PO-3 (minimum-wage and ceiling checks); MS-9 (wage-code verdict); AN-25 (joiner forms); BP-7 (wage-share block mode) | BP-15; setup 08 §10.1 #10, §10.8 #9; setup 13 PY-17; Report §9 |
| PAY-11 | Owner / legal: please verify the values behind the statutory fixes: ESI cover to the end of the contribution period, EPS stopping at 58, senior and super-senior old-regime slabs, disability ESI ceiling and PT exemptions, and employer retirement contributions above the limit taxed as a perquisite. | Verify · leave as today | The engine works as it does today, and the rule versions stay DRAFT. | SV-1 | SV-1; setup 13 PY-5, PY-8, PY-12, PY-13, PY-16, PY-20, PY-26, PY-27 |
| PAY-12 | Owner / legal: please verify the BONUS rule (Payment of Bonus Act). | Verify the rule version · leave bonus unprocessed | Bonus stays unprocessed (today). | PO-22; BP-15 (PY-19); MS-10 bonus tab; bonus Form C / D | D-PO5; setup 08 §10.12 #7; matrix 21 "Also open" |
| PAY-13 | Owner / legal: please verify a TDS rule version for the s.10(10AA) leave-encashment exemption (limit, averaging months, divisor). | Verify · keep encashment fully taxable | Fully taxable (today). | BP-10; PO-12 (row 12); PO-20; MS-4 (previous-employer exemption) | D-PO6; BP-10 |
| PAY-14 | Owner / legal: which declaration of TDS / TCS on other income (Form 12BAA and its heads) applies under the Income-tax Act 2025 from 1 Apr 2026? | Verify, then build · skip | Skip until verified. | PO-11 (row 7) | D-PO11 |
| PAY-15 | May the GST state-code list (used to check GSTINs) stay in config as reference data, outside the verified statutory rules? | Config reference data · verified rule data | Config reference data (as built). It is a list of codes, not a rate. | None | Report §8 (Core HR) |
| PAY-16 | Please confirm that GST on consultants' pay stays out of payroll, with accounts payable handling it. | Out of scope · build it in payroll | Out of scope. | None | setup 08 §10.9 #14 |
| PAY-17 | Which right may download the pre-run readiness CSV (employees missing PAN / UAN / structure etc., never the values)? | `payroll.view` (matrix 25 §25.6.2 #17) · `payroll.manage` | `payroll.view` (as built, 2026-10-30), like the other payroll exports; the export is audited. | PO-3 (built) | PO-3 report |

### 4. Time office

| ID | Decision needed (one plain question) | Options | Recommended default (keeps today's behaviour unless noted) | Blocks packages | Source IDs |
|---|---|---|---|---|---|
| TIM-1 | May HR close (freeze) an attendance month before payroll, and must payroll wait for that close? | Off: days lock at payroll finalization · on: close per company and pay-cycle month | Off (today). If on, payroll warns about an open month but does not block. A month can be reopened until a finalized payroll covers it. | TX-3 | D-TX1 |
| TIM-2 | Where are paid-day overrides made? | Payroll loss-of-pay adjustment only · also attendance-side paid-day adjustments (HROne) | Payroll loss of pay only (today). Recommended: also allow attendance-side adjustments (quantities only), with a warning when both touch the same month. | TX-4, TX-13 | D-TX2 |
| TIM-3 | May HR assign, remove or replace a holiday for chosen employees, on top of the holiday calendar? | No · yes, as dated exceptions per employee | No (today). Recommended: yes, as dated exceptions. Days locked by payroll follow the lock rule. | TX-12; RQ-11 (team) | D-TX3 |
| TIM-4 | For check-ins outside every allowed location, should there be an "accept after approval" option? If so, who approves, and may approving also save the place? | Flag only · approve | Flag only (today). If added, the manager in scope or a workflow approves, and saving the place needs an explicit tick (forever or for N days). | TX-10 | D-TX4 |
| TIM-5 | May HR apply leave in bulk on absent days? | No · yes, on behalf of the employee and approved by HR | No (today). When built: HR applies on the employee's behalf with a reason. Balances and limits apply, and refused rows are listed, never forced. | TX-3 | D-TX7 |
| TIM-6 | Should overtime plans be built (overtime allowed only when planned in advance)? | Not built · policy switch "overtime needs a plan" | Not built until asked. If built, the switch is off by default. | TX-11, TX-13 | D-TX8 |
| TIM-7 | May managers upload data for their own team (time office, variable pay, performance)? | HR only · managers too, limited to their scope | HR only (`team.uploads` off). | TX-13; RQ-15 (team uploads); PO-6 (manager variable upload); GO-10 (team use) | D-TX9; matrix 20 §20.3.1 #17, R.4.6, RQ-15; matrix 27 reuse (§27.10 #13) |

### 5. Workforce lifecycle

| ID | Decision needed (one plain question) | Options | Recommended default (keeps today's behaviour unless noted) | Blocks packages | Source IDs |
|---|---|---|---|---|---|
| WRK-1 | How should HR record that an upcoming joiner is not joining? | New end state `not_joined` · cancel and archive the pre-employee record | There is no such outcome today. Recommended: new end state `not_joined`. The record is kept for retention and rehire checks, onboarding is cancelled, and nothing is deleted. | WO-4 | D-WO1 |
| WRK-2 | How should "Unpublish / Revoke" and "Re-generate & publish" work for issued letters and shared documents? | Revoke: hide and mark revoked, file kept · delete. Re-generate: supersede (new letter linked to the old one) · overwrite | Revoke hides the letter from the employee and marks it revoked, with a reason and an audit entry; the file is kept. Re-generate supersedes the old letter, which is marked revoked. Nothing is deleted. | WO-13, WO-14 | D-WO5, D-WO6; matrix 27 reuse (§27.6 #9) |
| WRK-3 | Does a resignation withdrawal take effect at once, or is it a request that needs approval? | Immediate · needs approval | Immediate (today). Organisations may opt in to approval. | WO-11 | D-WO8 |
| WRK-4 | If the resignation workflow rejects a resignation, is the resignation withdrawn? | Withdrawn · stays open for HR | Withdrawn (as built). | RQ-6, WO-11 | Report §8 (Workflow); matrix 20 RQ-6 |
| WRK-5 | On a transfer, should the employee's approved check-in locations end? | Off, but the approver may tick it · always | Off; the approver may tick it. | WO-10, SV-6 | D-WO9 |
| WRK-6 | At exit, who takes over the leaver's approver and admin roles and their reportees? | They move to the leaver's manager automatically · HR chooses on the exit case | HR chooses. Anything unresolved is listed before "Complete exit" as a warning, not a block. | WO-11 | D-WO10 |
| WRK-7 | How may joiners fill in their own details before day one? Which sections, and what consent text? | No access · signed one-time links per section · time-limited sign-in | No access (today). Recommended: signed one-time links per section, valid until the joining date, with consent captured first and no portal account. The owner names the sections and the consent text. | WO-4, WO-8 | D-WO11; gap WF-13 |

### 6. Expense and travel (including assets and helpdesk)

| ID | Decision needed (one plain question) | Options | Recommended default (keeps today's behaviour unless noted) | Blocks packages | Source IDs |
|---|---|---|---|---|---|
| EXP-1 | When one approver returns an expense report, are the other approvers' line decisions discarded? | Discarded · kept | Discarded (as built). | XO-3 | Report §8 (Expense); matrix 26 §26.4.3 #9 |
| EXP-2 | An over-limit claim comes without a reason. Should it be capped or refused? | Cap at what is left · refuse | Cap (as built). | None | Report §8; matrix 26 §26.4.3 #11 |
| EXP-3 | May an advance approved for payment through payroll be paid by bank before payroll runs? | Yes · payroll only | Yes (as built). | XO-5 | Report §8; matrix 26 §26.3.2 #10 |
| EXP-4 | Do expense reports still waiting for approval block the finance clearance at exit? | Ignore · block | Ignore (today). The matrix first proposed "block". | None (setting `expense.exit.pending_reports` exists) | Report §8; setup 11 §11.11 open question 1 |
| EXP-5 | Is the "Pay expenses" payment step on by default? | On · off | On (as built). | XO-4 | Report §8; matrix 26 §26.4.4 #22 |
| EXP-6 | Should the expense cover-letter variables (`expense.*`) appear in the Letter studio variables guide? | Listed as "typed at generation" · full catalogue entries | As built. | None | Report §8 (Expense) |
| EXP-7 | May finance choose a later open payroll month for an expense report? | Next payroll only · any open month not yet finalized | Next payroll when left blank (today). Recommended: allow any open month not yet finalized. | XO-4 | D-XO1 |
| EXP-8 | May HR set a recovery month and a partial amount for an advance, overriding the automatic recovery date? | No · yes | The automatic date applies (today). Recommended: yes; a scheduled recovery takes precedence for that advance. | XO-6 | D-XO2 |
| EXP-9 | May the travel desk change an approved trip's legs, times or places without sending it back for approval? | No · yes, within the approved dates and estimate | No (today). Recommended: yes, within the approved dates and estimate, with an audit entry and the employee told. A later end date or a higher estimate goes back for approval. | XO-7 | D-XO3 |
| EXP-10 | When the travel desk records a company booking, should it be filed as a company-paid expense on the trip? | No · optional "File as expense" | Off by default. When on, the booking counts against the per-trip limits. | XO-7 | D-XO4 |
| EXP-11 | When a ticket's priority changes, does the SLA due date run from when the ticket was created or from the change? | From creation · from the change | Priority cannot be changed today. Recommended: from creation, but never earlier than now plus the warning margin; a reason is required. | XO-12 | D-XO5 |
| EXP-12 | Should there be an asset status "Reserved", for an asset held for a named employee or request? | No · yes | Add it, unused by default. Only that employee, or that request's fulfilment, can receive the asset. | XO-8 | D-XO6 |
| EXP-13 | When an approver marks an expense line "Suspected", does that block the decision, or is the line held out and decided later? | Block · hold out (approved at 0 for now) | Setting off (today). Recommended: hold the line out with a note and clear it later with a "Clear suspicion" decision. | XO-3 | D-XO7 |
| EXP-14 | May finance pay out less than the approved advance, and what happens to the rest? | Full amount only · several payouts up to the approved amount | Full amount only (today). Recommended: allow several payouts. The settle-by date counts from the first, and the rest lapses when the advance is settled or cancelled. | XO-5 | D-XO8 |

### 7. Performance and engage

| ID | Decision needed (one plain question) | Options | Recommended default (keeps today's behaviour unless noted) | Blocks packages | Source IDs |
|---|---|---|---|---|---|
| PER-1 | May HR reopen a review that the reviewer has already submitted? | Off · on (reason required, before finalization) | Off. If on, the earlier submission stays in the audit and the reviewer is told. HR never writes a review on someone's behalf. | GO-1 | D-GO1 |
| PER-2 | May managers normalise and publish their own team's ratings? | Off · on | Off. If on: only their own reports, with a reason. HR sees the changes, and the distribution check still applies. | GO-19, GO-3 | D-GO2 |
| PER-3 | Should fundraiser pledges be deducted through payroll? | Not built · voluntary payroll deduction | Not built until asked. If built: off by default, consent for each pledge, and a finalized run is never touched. | GO-18 | D-GO3 |
| PER-4 | Do badges given by the organisation (for event attendance or by bulk upload) use up a giver's monthly quota? | Use the quota · no quota | Allowed for `recognition.manage` and recorded with their source. No giver quota is used; receiver limits still apply. | GO-11, GO-15 | D-GO4 |
| PER-5 | Who may see private organisation goals? | Public · private with a visible-to list | Public (today). Private goals are seen by the owner, the creator, the visible-to list and HR in scope. Owners of aligned goals see only the parent goal's title. | GO-4 | D-GO5 |
| PER-6 | May a manager or HR override the achievement the system calculates for a goal? | Off · on | Off. If on: the manager or HR with a reason, and the system value is kept beside it. | GO-4 | D-GO7 |
| PER-7 | May employees create polls? | Off · on, with approval | Off. If on, every poll an employee creates needs approval first. | GO-16, MS-15 | D-GO8 |
| PER-8 | Please confirm the privacy fix: peer reviewers no longer see colleagues' ratings. | Keep the fix · show ratings to peers | Keep the fix (as built). A peer sees only the review they write. | HP-8h / SV-12 (360 visibility) | Report §8 (Performance); matrix 27 reuse (§27.4.1 #4) |
| PER-9 | May the one-on-one host change a meeting after setting it up? | Host may update · locked | The host may update it (as built). | GO-7, MS-13 | Report §8 (Performance); matrix 27 reuse (GO-7) |
| PER-10 | May HR delete or reset a performance cycle's transactions (HROne "Delete transaction")? | No · governed purge: second-person approval, legal-hold check, tombstone audit | No (immutability). "Withdraw from cycle" (GO-2) is the safe alternative. | HP-8k, P17 | HP-8k; P17; matrix 27 reuse (§27.2.2 #9, §27.3.3 #5) |
| PER-11 | Should colleagues be able to endorse each other's skills? | Off · on without wall posts · on with wall posts | Off; a tenant switch. | MS-6, GO-15 | D-MS4; matrix 20 R.3.1 #5 |
| PER-12 | Should the seeded badge keep the name "Super Woman"? | Keep · rename | Keep (as seeded). | None | Report §8 (Engage) |
| PER-13 | Should handbook acknowledgements have a due date by default? | No due date (`kb.acknowledgement.due_days` = 0) · N days | No due date (today). | None | Report §8 (Engage) |

### 8. Analytics and AI

| ID | Decision needed (one plain question) | Options | Recommended default (keeps today's behaviour unless noted) | Blocks packages | Source IDs |
|---|---|---|---|---|---|
| ANA-1 | Should ranked or named lists be shown: top / bottom performers, top agents, top points holders outside HR, names of unhappy employees, "bad hires"? | No rankings · factual lists behind permissions · copy HROne | No rankings (today). If accepted, show factual lists only: "Highest final ratings" (names only with `performance.view`), "Early exits within 6 months of joining", identified mood check-ins only. | AN-4, AN-5, AN-7, AN-9, AN-13; matrix 27 §27.15; matrix 20 smart search | Matrix 21 owner decision (a), §21.1.3 #7, §21.1.6 #10, §21.1.8 #9–10, happiness #4, §21.2.2 Engage #6; matrix 27 reuse (§27.15); matrix 20 R.6 #7 |
| ANA-2 | May a tenant enter its own benchmark score for pulse questions? | No · tenant-entered, per question | No (today). | GO-14, AN-8 | Matrix 21 (c), §21.1.9 #3; matrix 27 reuse (§27.8.5 #9) |
| ANA-3 | May report results be sent to an AI provider for analysis? | No · aggregated, suppressed data only | No (today). If yes: only aggregated, suppressed results through the existing AI controls, never row-level personal data. | AN-19 | Matrix 21 (d) |
| ANA-4 | Should AI help people draft text (goals, agendas, review comments, announcements, feedback, wishes)? | Off · on, through the existing AI controls | Off. If on, only the user's own draft text is sent: never ratings, other people's goals or personal data. | GO-5 (AI part), CA-16 | D-GO6; CA-16 |
| ANA-5 | Please confirm that tenants cannot write their own SQL reports. | Excluded (by design) · allow | Excluded. | None | Matrix 21 (e) |
| ANA-6 | Should reports name the reviewers of anonymous appraisal reviews to HR (the appraisal's Reviews tab does)? | "Anonymous" in reports · named to HR as on screen | "Anonymous" (as built, 2026-10-30): a report travels as a file, so it is stricter than the screen. | AN-27 (built) | AN-27 report |
| ANA-7 | May readers with only `payroll.view` use the loan, reimbursement and variable-pay datasets? | `payroll.manage` only (the screens' right) · also `payroll.view` | `payroll.manage` only (as built, 2026-10-30), because those screens need it. | AN-26 (built) | AN-26 report |
| ANA-8 | Should a shared dashboard view's e-mail carry figures (a summary worked out for each recipient under their own access) or only the view's link? | Link only · per-recipient summary | Link only (as built, CA-10, 2026-10-30): nothing personal leaves the screen, and each person sees the numbers under their own access when they open it. | CA-10 (built) | D-CA10-1 |
| ANA-9 | Should a preset CXO view start shared with the person who adds it only, or with every role holding the module's rights? | Its author only · the module's roles | Its author only (as built, CA-10): the author then shares it with roles or named people. | CA-10 (built) | D-CA10-2 |
| ANA-10 | Should the happiness index view accept an organisation filter? | Whole organisation only · a filter, with each group still suppressed | Whole organisation only (as built, CA-10), as the happiness index dataset shows nothing to a reader with a narrowed scope. | CA-10 (built) | D-CA10-3 |

### 9. External providers

| ID | Decision needed (one plain question) | Options | Recommended default (keeps today's behaviour unless noted) | Blocks packages | Source IDs |
|---|---|---|---|---|---|
| VEN-1 | Which SMS provider, if any (mobile number checks, invitations)? | None · a vendor | None. E-mail checks need no provider. Decide together with VEN-2. | MS-8 (phone), WO-16 (SMS invites) | D-MS7; gap IP-07 |
| VEN-2 | Which WhatsApp provider, if any (invitations, notifications, employee bot)? | None · a vendor | None. WhatsApp stays a log-only channel. | P6 (WhatsApp switch), GO-12, WO-16 | D-WO7; Report §8 (Engage); gap IP-07; setup 02 §02.7 WhatsApp row |
| VEN-3 | Which voucher vendor, if any, for a reward store? | Payroll redemption only · a voucher vendor | Payroll redemption only until there is a vendor contract. | Reward store parts of HP-9d, MS-15, GO-17 | D-MS6; Report §8 (Engage); matrix 27 reuse (§27.8.7 #5, #8) |
| VEN-4 | Should salaries be paid through a payout provider or bank API? Should an OTP be asked for before release? | Bank files only · bank files plus OTP · payout provider | Bank files only (today). An OTP before release is available as a setting, off by default. | SV-21 (provider part), PO-7 | D-PO4; SV-21 (XC-11, XC-12) |
| VEN-5 | Which PDF and spreadsheet library should be used, including for password-protected files? | Choose a library (new composer dependency) · none | None: CSV and on-screen HTML only (today). | BP-14, PO-7, AN-17, AN-20, RQ-13, WO-17 (.xlsx), MS-9 / MS-17 downloads, P11 | D-PO9; BP-14; matrix 21 (b); setup 08 §10.8 #47; matrix 20 §20.4.1 #13, #15 |
| VEN-6 | Which map and geocoding provider (map picker, drawing polygons, route distance, team map)? | None · a provider | None. Coordinates and distances are typed. | CA-8, expense map distance, RQ-11 map, MS-16 trips map, TX-10 map, "Start trip" (with a mobile app) | CA-8; setup 01 §1.17; setup 11 §11.1 rows 11–12; matrix 20 (map rows, R.2.2 #6, R.2.4 #1); matrix 22 (map rows); matrix 24 (polygon rows); matrix 26 §26.4.3 #15 |
| VEN-7 | Which verification vendors: BGV, e-KYC, PAN (including landlord PAN and the PAN–Aadhaar link), bank penny-drop, EPFO employment history? | None (manual and format checks) · named vendors | Manual BGV and format checks only (today). | CA-15; BP-8 (PAN verifier); BP-11 (PAN–Aadhaar); RQ-7b (PAN check) | CA-15; setup 08 §10.8 #26, §10.9 #11, #33; matrix 20 §20.1.5 #9; matrix 23 §23.2.1 #9, §23.2.2 #13, §23.2.3 #19, §23.2.5 #13 |
| VEN-8 | May we add a SAML 2.0 library and outbound SCIM (pushing employees to Entra or Google)? | OIDC sign-on and inbound SCIM only · add SAML and outbound SCIM | OIDC sign-on and inbound SCIM only (today). Each tenant's identity-provider setup is external. | CA-14 | CA-14 (IP-08); setup 01 §6.8–6.9 |
| VEN-9 | Which ERP systems should journal vouchers post to directly? | File export only · named ERP connectors | File export only (first version). | SV-27 (connectors), CA-13 | SV-27 (XC-66); CA-13 |
| VEN-10 | Which e-signature provider (Aadhaar eSign, DSC, DocuSign …) for letters and asset acknowledgements? | None (printed signature image) · a provider | None (today). | SV-17, WO-13 (e-sign rows), P8, asset acknowledgement signature | SV-17 (FT-2); setup 02 §03.5; matrix 20 §20.2.2 #32; matrix 23 §23.7.1 #9; matrix 26 §26.5.5 #8 |
| VEN-11 | Which receipt-reading (OCR) provider, if any, for expense receipts and travel bookings? | None · a provider | Upload only (today), behind a `ReceiptParser` seam that does nothing. | RQ-7c (provider part), XO-7 (booking OCR) | Matrix 20 §20.1.12 #2, RQ-7c; matrix 26 §26.2.3 #18 |
| VEN-12 | Should expenses and tickets be created from inbound e-mail (email-to-expense, email-to-ticket)? | No · an inbound-mail provider | No (today). | Email-to-expense, email-to-ticket (HD-03) | setup 11 build packages; `00-build-status` blocked row |
| VEN-13 | Should one-on-one and meeting invites go straight to Google or Outlook calendars? | .ics attachment only · calendar integration | .ics attachment only. | MS-13, GO-7 (beyond .ics) | Matrix 22 §22.14.1 #6 |
| VEN-14 | For the biometric sync agent, do we build our own or rely on a partner? | Build · partner | Publish only the sync specification and an instruction sheet; no agent ships. | SV-16 | SV-16 (TO-1) |
| VEN-15 | Which reference source, if any, should be approved for districts and cities (with the metro class used for HRA)? | None · an approved list | None. District stays optional, with an "All districts" fallback for each state. | CA-7, SV-8 | CA-7; setup 13 CH-13; matrix 22 §22.2.2 #11 |

### 10. Platform / SaaS (including shared workflow and Approval Center rules)

| ID | Decision needed (one plain question) | Options | Recommended default (keeps today's behaviour unless noted) | Blocks packages | Source IDs |
|---|---|---|---|---|---|
| PLT-1 | Should "no hard deletes" stay, with HROne's "Delete" actions replaced by cancel, exclude, inactivate or a reversing entry? | Never delete · allow hard delete | Never delete. Tasks are cancelled with a reason; there is no "delete" import (masters get "inactivate"); punches are excluded with a reason; claims and balances are cancelled or reversed. | WO-1, WO-17, SV-9, TX-7, PO-9 | D-WO3, D-WO4, D-TX5, D-PO8; matrix 25 "kept out" |
| PLT-2 | When may tenants see billing (invoices, payments, self-serve "add users")? | Operator-only · after the SaaS.7 release decisions | Operator-only (today). A read-only plan-and-usage card is fine meanwhile. Waits for SaaS B-4 / B-7 / B-8 / B-9. | P15, SV-25 (XC-51) | P15; SV-25; setup 02 §04.9; `00-build-status` |
| PLT-3 | Should tenants see their billing contact (read-only)? | Operator-only · read-only in the tenant | Operator-only (today). | None | Report §8 (Core HR); setup 01 §1.1 contact row |
| PLT-4 | May tenants send mail through their own mail provider (their own SMTP or API)? | Platform mail only · a mail setup per tenant behind a platform flag | Platform mail only. Needs a decision on deliverability and support. | P16 | P16; setup 02 §02.1 |
| PLT-5 | Should people be able to sign in with an employee code (HROne "Identifier 1 / 2") on a login page specific to the tenant? Also, what do Identifier 1 / 2 do in your HROne use? | E-mail sign-in on the shared page · a login address per tenant | E-mail sign-in on the shared page (today). | None (setup 01 §1.1 login rows) | Report §8 (Core HR); setup 01 §1.1 |
| PLT-6 | When a request is sent back, should it stay "pending" with an open return, or get a new "returned" status? | Pending plus open return · new status | Pending plus open return (as built). | None | Report §8 (Approval Center) |
| PLT-7 | Should requesters take part in a request discussion only by replying? | Reply only · may also start a discussion | Reply only (as built). | None | Report §8 (Approval Center) |
| PLT-8 | Should automatic workflow decisions be recorded with no person (a system decision)? | System, no person · named to an owner | System decision (as built). | None | Report §8 (Workflow) |
| PLT-9 | Should delegations chain (A to B to C when B is also away)? | No chain · chain | No chain (as built). | None | Report §8 (Workflow) |
| PLT-10 | May a workflow decide automatically at any approval level, not only the last? | Last step only · any level | Last step only. An automatic decision in the middle of a chain skips a human check. | SV-22 (auto-decide part) | SV-22 (XC-20) |
| PLT-11 | Who besides Tenant HR Admin may read API keys, their call logs, usage and security events (`api_key.view`)? | Tenant HR Admin only · also the Auditor template | Tenant HR Admin only (as built, 2026-10-30). Changes still need `api_key.manage`. | CA-11 (built) | CA-11 report |
| PLT-12 | How long are API call logs kept (`retention.api_request_logs_days`)? | 90 days · another period | 90 days (as built; Setup → Governance). | CA-11 (built) | CA-11 report |
| PLT-13 | Should a webhook's signing secret be shown only once, like an API key, instead of being revealable on the edit form? | Revealable to `webhook.manage` (today) · shown once | Revealable (today). Rotation already shows the new secret once. | SV-26 (built) | SV-26 report |
| PLT-14 | Should calls made with an unknown or invalid API key be logged (a platform-level log of failed attempts)? | Not logged (no key or tenant to attach them to) · operator-level log | Not logged (as built). | CA-11 follow-up | CA-11 report |
| PLT-15 | Whom may an employee notify about their own request ("Notify others", once a request type is switched on in `requests.notify_others.types`)? | People they can find in search (manager and reports for a plain employee) · anyone in the organisation · named roles only | People they can find in search (as built, 2026-10-30); the feature stays off until request types are chosen. | RQ-2 (built) | RQ-2 report |
| PLT-16 | Should the requester see the names of the people their request is waiting with (HROne "Pending with")? | Names shown · roles only | Names shown (as built, 2026-10-30), as HROne does. | RQ-2 (built) | RQ-2 report |
| PLT-17 | Should organisations be able to switch off "Send reminder" on one's own pending request? | Always available (at most once per `requests.reminder.min_hours`, default 24) · a switch | Always available (as built, 2026-10-30). | RQ-2 (built) | RQ-2 report |
| PLT-18 | Who may mark system to-dos "Not relevant" in the inbox (`task.ignore`)? | Tenant HR Admin and HR Manager (through `task.*`) · also managers · named roles | Tenant HR Admin and HR Manager (as built with RQ-8, 2026-10-30); reason required, audited, reversible. | RQ-8 (built) | RQ-8 report |
| PLT-19 | Which to-dos count as "system tasks" that may be marked not relevant? | To-dos of runs started by an event trigger or by the system (never approvals, never hand-started runs) · wider | As built with RQ-8 (2026-10-30). | RQ-8 (built) | RQ-8 report |
| PLT-20 | Should the inbox Insight also count decisions made on a module's own screen (they have no due time)? | Workflow steps and Approval Center decisions only · all decisions | Workflow steps and Approval Center decisions only (as built with RQ-8, 2026-10-30). | RQ-8 (built) | RQ-8 report |

## Count by theme (after merging duplicates)

| Theme | Open decisions |
|---|---|
| 1. Data visibility and privacy | 15 |
| 2. Self-service editing | 4 |
| 3. Payroll and statutory (5 are owner / legal rule checks) | 17 |
| 4. Time office | 7 |
| 5. Workforce lifecycle | 7 |
| 6. Expense and travel (including assets and helpdesk) | 14 |
| 7. Performance and engage | 13 |
| 8. Analytics and AI | 10 |
| 9. External providers | 15 |
| 10. Platform / SaaS | 20 |
| **Total** | **122** |

Merged rows: PAY-1 (D-PO1 + D-TX6), PAY-5 / VEN-5 (D-PO9 split into method and library), WRK-2 (D-WO5 + D-WO6),
TIM-7 (D-TX9 + matrix 27 reuse), PER-10 (HP-8k + P17), PLT-1 (D-WO3 + D-WO4 + D-TX5 + D-PO8), ANA-1 (five ranking
rows in matrices 21 and 27), VEN-7 (BGV, e-KYC, PAN, bank and EPFO checks), and the matrix 26 / 27 "reused" items
that point back to Report §8.

## Answer sheet

Write "default", "recommended" or your own choice.

1. PRV-1 own IDs and bank numbers. Answer: ______
2. PRV-2 profile sharing outside the organisation. Answer: ______
3. PRV-3 separation reason on own profile. Answer: ______
4. PRV-4 password before pay pages. Answer: ______
5. PRV-5 upload file types. Answer: ______
6. PRV-6 managers and team tax declarations. Answer: ______
7. PRV-7 face-recognition data. Answer: ______
8. PRV-8 privacy notice and consent. Answer: ______
9. PRV-9 business partner sign-in. Answer: ______
10. SSE-1 editable profile sections. Answer: ______
11. SSE-2 name and date of birth changes. Answer: ______
12. SSE-3 date of marriage. Answer: ______
13. PAY-1 corrections to finalized payroll. Answer: ______
14. PAY-2 payslip publishing. Answer: ______
15. PAY-3 unclaimed balance at year end. Answer: ______
16. PAY-4 TDS at challan time. Answer: ______
17. PAY-5 payslip protection method. Answer: ______
18. PAY-6 referral bonus. Answer: ______
19. PAY-7 perquisite rights. Answer: ______
20. PAY-8 second approval of settlements. Answer: ______
21. PAY-9 transfer allowance at exit. Answer: ______
22. PAY-10 verify core statutory rule pack. Answer: ______
23. PAY-11 verify statutory fix values. Answer: ______
24. PAY-12 verify BONUS rule. Answer: ______
25. PAY-13 verify s.10(10AA) rule. Answer: ______
26. PAY-14 verify Form 12BAA heads. Answer: ______
27. PAY-15 GST state-code list in config. Answer: ______
28. PAY-16 GST on consultants' pay. Answer: ______
29. TIM-1 attendance month close. Answer: ______
30. TIM-2 paid-day overrides. Answer: ______
31. TIM-3 holidays for chosen employees. Answer: ______
32. TIM-4 out-of-location check-ins. Answer: ______
33. TIM-5 HR bulk apply leave. Answer: ______
34. TIM-6 overtime plans. Answer: ______
35. TIM-7 manager uploads. Answer: ______
36. WRK-1 not joining. Answer: ______
37. WRK-2 revoke and re-generate letters. Answer: ______
38. WRK-3 resignation withdrawal. Answer: ______
39. WRK-4 rejected resignation workflow. Answer: ______
40. WRK-5 check-in locations on transfer. Answer: ______
41. WRK-6 leaver's roles and reportees. Answer: ______
42. WRK-7 joiner access before day one. Answer: ______
43. EXP-1 returned report line decisions. Answer: ______
44. EXP-2 over-limit claim without reason. Answer: ______
45. EXP-3 bank payout before payroll. Answer: ______
46. EXP-4 pending reports at exit. Answer: ______
47. EXP-5 payment step default. Answer: ______
48. EXP-6 cover-letter variables. Answer: ______
49. EXP-7 later pay month. Answer: ______
50. EXP-8 manual advance recovery. Answer: ______
51. EXP-9 travel desk edits. Answer: ______
52. EXP-10 company bookings as expenses. Answer: ______
53. EXP-11 SLA on priority change. Answer: ______
54. EXP-12 Reserved asset status. Answer: ______
55. EXP-13 Suspected expense line. Answer: ______
56. EXP-14 partial advance payout. Answer: ______
57. PER-1 HR reopens a review. Answer: ______
58. PER-2 managers normalise ratings. Answer: ______
59. PER-3 fundraiser payroll deductions. Answer: ______
60. PER-4 organisation badges and quota. Answer: ______
61. PER-5 private goals. Answer: ______
62. PER-6 achievement override. Answer: ______
63. PER-7 employee polls. Answer: ______
64. PER-8 peer rating visibility. Answer: ______
65. PER-9 one-on-one host edits. Answer: ______
66. PER-10 performance reset / delete. Answer: ______
67. PER-11 skill endorsements. Answer: ______
68. PER-12 "Super Woman" badge name. Answer: ______
69. PER-13 handbook acknowledgement due date. Answer: ______
70. ANA-1 ranked and named lists. Answer: ______
71. ANA-2 tenant pulse benchmark. Answer: ______
72. ANA-3 AI on report data. Answer: ______
73. ANA-4 AI drafting help. Answer: ______
74. ANA-5 tenant SQL reports. Answer: ______
75. VEN-1 SMS provider. Answer: ______
76. VEN-2 WhatsApp provider. Answer: ______
77. VEN-3 voucher vendor. Answer: ______
78. VEN-4 payout provider and OTP. Answer: ______
79. VEN-5 PDF and spreadsheet library. Answer: ______
80. VEN-6 map provider. Answer: ______
81. VEN-7 verification vendors. Answer: ______
82. VEN-8 SAML and outbound SCIM. Answer: ______
83. VEN-9 ERP connectors. Answer: ______
84. VEN-10 e-signature provider. Answer: ______
85. VEN-11 receipt OCR provider. Answer: ______
86. VEN-12 inbound e-mail. Answer: ______
87. VEN-13 calendar integration. Answer: ______
88. VEN-14 biometric sync agent. Answer: ______
89. VEN-15 districts and cities source. Answer: ______
90. PLT-1 no hard deletes. Answer: ______
91. PLT-2 tenant billing view. Answer: ______
92. PLT-3 billing contact for tenants. Answer: ______
93. PLT-4 tenant mail provider. Answer: ______
94. PLT-5 employee-code sign-in. Answer: ______
95. PLT-6 send-back status. Answer: ______
96. PLT-7 requester replies in discussions. Answer: ______
97. PLT-8 automatic decisions with no person. Answer: ______
98. PLT-9 delegation chains. Answer: ______
99. PLT-10 auto-decide at any level. Answer: ______
100. PLT-11 who may read API keys and call logs. Answer: ______
101. PLT-12 API call log retention. Answer: ______
102. PLT-13 webhook secret shown once. Answer: ______
103. PLT-14 log calls with invalid keys. Answer: ______
104. ANA-6 anonymous reviewers in reports. Answer: ______
105. ANA-7 payroll datasets for payroll.view. Answer: ______
106. PAY-17 readiness CSV right. Answer: ______
107. PLT-15 whom an employee may notify. Answer: ______
108. PLT-16 approver names on the request trail. Answer: ______
109. PLT-17 switch for Send reminder. Answer: ______
110. SSE-4 bank and identifier changes always approved. Answer: ______
111. PRV-10 My space page access blocks the page. Answer: ______
112. PRV-11 Workforce data-access groups in every module. Answer: ______
113. PRV-12 Account Owner by rule. Answer: ______
114. PRV-13 rules beyond the author's scope. Answer: ______
115. PRV-14 geo check-ins visible to HR by default. Answer: ______
116. ANA-8 view e-mails: link only or figures. Answer: ______
117. ANA-9 preset views shared with their author first. Answer: ______
118. ANA-10 happiness index view: whole organisation only. Answer: ______
119. PRV-15 colleagues' inbox Insight for audit holders. Answer: ______
120. PLT-18 who may mark to-dos not relevant. Answer: ______
121. PLT-19 what counts as a system to-do. Answer: ______
122. PLT-20 Insight over module-screen decisions. Answer: ______
