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
| PRV-16 | When letters may be e-mailed (`letters.email.enabled`), may HR send copies to typed outside addresses, and who may send to an employee's personal e-mail? | Organisation people and typed addresses; personal e-mail only with `employee.sensitive.view` (as built) · organisation people only | As built with WO-13 (2026-10-30). The switch is off by default, so nothing is e-mailed until it is turned on. | WO-13 (built) | WO-13 report |
| PRV-17 | Should the free-text remarks of an exit (approver remark, rehire comment, withdrawal reason) be available in the exits analytics dataset? | Kept out (as built) · included for HR analysts | Kept out (as built with WO-11, 2026-10-30). They stay on the exit case for people who may see it. | WO-11 (built) | WO-11 report |
| PRV-18 | What may employees see of how their expense report was paid (with `expense.payment.show_to_employee` on)? | Mode and cheque details, never the paying account or the payment note (as built) · also the paying account · mode only | As built with XO-4 (2026-10-30). | XO-4 (built) | XO-4 report |
| PRV-19 | Who still sees the leave type of a leave marked private? | The employee, the people who decided or are deciding it, and anyone with `leave.view` / `leave.approve` in scope (as built) · only the employee, its approvers and HR leave administrators | As built with RQ-4 (2026-10-30). Note: the shipped Manager template holds `leave.view`, and a manager without scope rows sees the whole organisation, so today most people who can open the leave calendar see private leave in full. Recommended: narrow it to the employee, the request's approvers and HR leave administrators. | RQ-4 (built) | RQ-4 report |
| PRV-20 | Should every employee see who in their team is away (the "Who else is away" panel and team planned leaves), as in HROne? | Only people who can open the leave calendar (as built) · every employee, showing "Away" only | As built with RQ-4 (2026-10-30). | RQ-4 (built) | RQ-4 report |
| PRV-21 | Should notifications about a private leave hide the leave type from colleagues (notification rules can name it today)? | Unchanged (as built) · hide it from anyone but the employee, its approvers and HR | Unchanged (as built with RQ-4, 2026-10-30). Recommended: hide it. | RQ-4 (built) | RQ-4 report |
| PRV-22 | At hire, a not-eligible-for-rehire match that the HR user may not see still asks them to confirm, which tells them such a person exists (without naming them). Is that acceptable? | Yes, the minimum needed to enforce the rehire rule (as built) · only users who may see the match are asked; others are refused and told to ask HR | As built with WO-3 (2026-10-30). Aadhaar references shorter than 12 characters are never compared. | WO-3 (built) | WO-3 report |
| PRV-23 | Who may see check-in photos (and IP / Wi-Fi details) on a punch? | The same audience as check-in coordinates (`geo_dashboard.checkins_visible_to`: nobody until chosen; then HR in scope and managers for direct reports) (as built) · a separate audience for photos | As built with TX-7 (2026-10-30); photos are served through a 30-minute signed, audited link. | TX-7 (built) | TX-7 report; PRV-14 |

### 2. Self-service editing

| ID | Decision needed (one plain question) | Options | Recommended default (keeps today's behaviour unless noted) | Blocks packages | Source IDs |
|---|---|---|---|---|---|
| SSE-1 | Which profile sections may employees edit themselves, and which edits need approval? | Per section: not editable · editable with approval · editable without approval | Nothing editable (today). Suggested tenant start: contacts, addresses, emergency contacts, family, education, experience and skills with approval; blood group and personal e-mail without approval; bank and statutory IDs always with approval and a document. | MS-3 (also MS-5, MS-7 photo, WO-7) | D-MS2 |
| SSE-2 | May employees change their legal name or date of birth themselves? | Never · with approval and a supporting document | Never (today). Recommended: only with approval and a linked document. | MS-3 | D-MS11 |
| SSE-3 | Should the system store the full date of marriage, or only day and month? | Day / month · full date | Day / month (today), unless a benefit rule needs the year. | MS-4 | D-MS8 |
| SSE-4 | May any self-service change to bank details or statutory identifiers (PAN, Aadhaar reference, UAN, PF / ESIC) apply without approval? | Always needs approval (whatever `profile.self_edit.sections` says) · follow the setting | Always needs approval (as built with MS-3, 2026-10-30); legal name / date of birth also always need a document. | MS-3 (built) | MS-3 report |
| SSE-5 | Nomination rules: from what age is a nominee no longer a minor (a guardian is required below it), and which benefits can be nominated for? | 18, and PF, EPS, gratuity, group insurance, ESI, superannuation, other (as built) · another age or list, or a tenant setting | As built with MS-4 (2026-10-30): age in config (`nominee_majority_age` = 18), benefits in the option list `nomination_benefits` (tenants may edit the list). | MS-4 (built) | MS-4 report |
| SSE-6 | When employees may edit their nominations themselves (`profile.self_edit.sections` → nominations), should changes need approval by default? | Off until the tenant turns it on, then as the tenant's matrix says (as built) · always with approval, like bank details | As built with MS-4 (2026-10-30). Recommended: with approval, since nominations decide who is paid benefits. | MS-4 (built) | MS-4 report; SSE-1, SSE-4 |
| SSE-7 | When employees may edit their health insurance details themselves, must every change be approved (as SSE-4 requires for bank details and identifiers)? | Tenant's matrix decides, approval on by default (as built) · always approved | As built with MS-5 (2026-10-30). Recommended: always approved, since member ids are sensitive. | MS-5 (built) | MS-5 report; SSE-4 |
| SSE-8 | May employees upload their own insurance e-card? | HR only (as built) · employees too, with approval | HR only (as built with MS-5, 2026-10-30). | MS-5 (built) | MS-5 report |

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
| PAY-18 | Should a draft compensation change count as "pending" on the salary structure register, and block a second proposal for that employee? | Yes (as built) · only submitted changes | Yes (as built with PO-2, 2026-10-30): one open change per employee at a time. | PO-2 (built) | PO-2 report |
| PAY-19 | Which roles may download the salary structure register as CSV? | `compensation.export` (as built; today only Tenant HR Admin holds it) · also give it to the HR Manager and Payroll Admin templates | As built with PO-2 (2026-10-30). Recommended: add `compensation.export` to the Payroll Admin template. | PO-2 (built) | PO-2 report; matrix 25 §25.2.1 #3 |
| PAY-20 | The employee's tax regime is held in two places: the year's tax declaration (what TDS uses, shown on the salary structure register) and the statutory details field (edited in the Assign salary wizard, not read by payroll). How should they relate? | Keep both (as built) · the declaration is the source and the statutory field shows it read-only · the statutory field sets the default for a new year's declaration | Keep both (as built with PO-2, 2026-10-30). Recommended: the declaration is the source; the statutory field shows it read-only. | PO-2 (built) | PO-2 report |
| PAY-21 | The printed salary breakup (letters' salary annexure) works out PF on the wage ceiling, while the Assign salary preview uses the employee's own PF wage basis. Should the annexure follow the employee's basis? | Annexure on the ceiling (as built) · annexure follows the employee's PF wage basis | As built with PO-2 (2026-10-30). Recommended: follow the employee's basis (this changes letter output for people on actual wages). | PO-2 (built); WO-13 | PO-2 report |
| PAY-22 | Should health insurance premiums (employee share, parents' cover) be recovered through payroll, and how are they treated for tax (e.g. 80D)? | Not handled (as built) · a payroll deduction and tax treatment from verified rules | Not handled (as built with MS-5, 2026-10-30); needs the owner's and a statutory adviser's input before anything is built. | MS-5 (built); BP packages | MS-5 report |
| PAY-23 | When the automatic calculation day (`payroll.auto_calculate_day`) recalculates open runs, who should be told? | Nobody (as built) · the run's preparer · the payroll team | Nobody (as built with PO-4, 2026-10-31); the setting is 0 (off) by default. Recommended: the run's preparer. | PO-4 (built) | PO-4 report |
| PAY-24 | Should the automatic calculation also recalculate validated runs (which then drop back to calculated)? | Yes: draft, calculated and validated runs (as built) · draft and calculated only | As built with PO-4 (2026-10-31); approved, finalized and paid runs are never touched. Recommended: leave validated runs alone. | PO-4 (built) | PO-4 report; PAY-1 |
| PAY-25 | Who may tick a payroll entry as checked? | `payroll.calculate` or `payroll.approve` (as built) · approvers only | As built with PO-4 (2026-10-31); the tick never blocks finalizing and is cleared when the entry is recalculated. | PO-4 (built) | PO-4 report |

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
| TIM-8 | Should the Attendance Admin role template get `bulk.run`, so attendance admins can use the bulk actions on the attendance month desk (Mark present, Hold / Release arrears)? | HR Admin and HR Manager only (today) · also Attendance Admin | HR Admin and HR Manager only (as built with TX-3, 2026-10-30). An attendance admin can still close, reopen and process one month at a time. | TX-3 (built) | TX-3 report |
| TIM-9 | When month close is on, should "no pending requests" count the whole company, or only the people the closer can see? | Whole company (as built) · the closer's access scope | Whole company (as built with TX-3, 2026-10-30): a month is closed for everyone, so every pending request on its days stops the close. The refusal shows counts by kind, not names. | TX-3 (built) | TX-3 report |
| TIM-10 | If the pay cycle changes after a month was closed, should the closed month keep the dates it was closed with? | Keep the dates at close (as built) · follow the new cycle (reopen first) | Keep the dates at close (as built with TX-3, 2026-10-30). To use the new cycle's dates, HR reopens the month (refused once a finalized payroll covers it). | TX-3 (built) | TX-3 report |
| TIM-11 | Attendance's month summary counts a day with half-day leave as half a paid day whatever the other half (a worked day with half-day leave counts 0.5; a half day of unpaid leave −0.5). Fix the summary? | Keep (as built) · count the worked half and paid leave half in full | Keep (as built with TX-4, 2026-10-30): the Paid days register shows the summary's figure so both agree; fixing it changes the attendance calendar's paid days (payroll is not affected: it reads days, not the summary). | TX-4 (built) | TX-4 report |
| TIM-12 | With paid-day adjustments on, should payroll take the attendance adjustment as loss-of-pay days, or only show it next to its own loss-of-pay adjustment? | Take it (as built) · show only | Take it (as built with TX-4, 2026-10-30): payroll adds the change in paid days to loss of pay, records it on the entry and warns when its own loss-of-pay adjustment touches the same month. Off by default (TIM-2). | TX-4 (built) | TX-4 report |
| TIM-13 | When HR sets a month's counts ("Manually set paid days"), should late-mark penalty days still reduce the paid days? | Yes, penalties stay separate (as built) · the set counts are final | Penalties stay separate (as built with TX-4, 2026-10-30): HR waives a penalty on the penalties register if the set counts should be final. | TX-4 (built) | TX-4 report |
| TIM-14 | On a rostered day flagged for auto shift correction, which shifts may the correction choose from? | The schedule's correction list plus the rostered shift, which wins a tie (as built) · the schedule's list only | As built with TX-5 (2026-10-30). If the schedule lists no shifts, the flag does nothing. | TX-5 (built) | TX-5 report |
| TIM-15 | When an approved shift-change request lands on a rostered day flagged for auto correction, should the flag be cleared (and restored if the request is cancelled)? | Cleared and restored (as built) · kept | Cleared and restored (as built with TX-5, 2026-10-30): the employee's requested shift stands. | TX-5 (built) | TX-5 report |
| TIM-16 | How long a date range may HR change in one go with "Change attendance status"? | Up to 31 days (as built) · longer | Up to 31 days (as built with TX-6, 2026-10-30); 25 employees × 31 days run at once, larger selections go through a bulk run. | TX-6 (built) | TX-6 report |
| TIM-17 | When the two halves of a day have different statuses that both count (e.g. holiday + weekly off), which status does the day show? | The upload's precedence: present, work from home, on duty, holiday, weekly off (as built) · another order | As built with TX-6 (2026-10-30); paid-day categories still count each half separately. | TX-6 (built) | TX-6 report |
| TIM-18 | With `team.punch_edit` on, whose punches may a manager add, exclude or restore? | Direct reports of any reporting type (as built) · line reports only · all levels below | As built with TX-7 (2026-10-30); never their own. The switch is off by default. | TX-7 (built); RQ-11 | TX-7 report |
| TIM-19 | Should a punch HR excluded still count toward the employee's daily punch limit? | No, so the employee can punch again (as built) · yes | Not counted (as built with TX-7, 2026-10-30). Cancelling one day of a multi-day leave from the day drawer is not built; the whole request is cancelled, as on Leave requests. | TX-7 (built) | TX-7 report |

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
| WRK-8 | Should revoking an issued letter need its own right (HROne has a separate "Unpublish" right)? | Same right as issuing (`letter.issue`, as built) · a separate `letter.revoke` | Same right as issuing (as built with WO-13, 2026-10-30). Revoking asks for a reason, is audited and keeps the file. | WO-13 (built) | WO-13 report |
| WRK-9 | When an issued letter is generated again, when does the employee stop seeing the old one? | When the new letter is issued (as built) · at once | When the new letter is issued (as built with WO-13, 2026-10-30), so the employee always has one valid letter. A letter not yet issued is revoked at once. | WO-13 (built) | WO-13 report; WRK-2 |
| WRK-10 | Should clearance stages and exits ready to complete appear in My tasks (`exit.clearance.my_tasks`)? | Off (as built) · on | Off (as built with WO-11, 2026-10-30): owners find their stages on the exit case and the Clearances register. Recommended: on, as HROne lists clearance tasks in the inbox. | WO-11 (built) | WO-11 report; matrix 23 §23.11 #10–11 |
| WRK-11 | With resignation acceptance switched on, may the HR person who initiated a resignation on the employee's behalf also accept it? | Yes (as built) · someone else must accept | Yes (as built with WO-11, 2026-10-30). The employee can never decide their own resignation. | WO-11 (built) | WO-11 report |
| WRK-12 | When HR changes a leaver's last working day, should asset-return requests and the exit-interview due date move with it? | Not moved (as built) · moved | Not moved (as built with WO-11, 2026-10-30). Clearance due dates and the notice shortfall are recalculated. | WO-11 (built) | WO-11 report |
| WRK-13 | With resignation acceptance switched on, may HR complete an exit whose resignation was never accepted? | No (as built) · yes, with a warning | No (as built with WO-11, 2026-10-30). Accept or reject it first. | WO-11 (built) | WO-11 report |
| WRK-14 | When a team's manager change is dated in the future, should their open approvals move to the new manager on that date? | Not moved; ticking "move approvals" is refused for a future date (as built) · moved automatically on the effective date | Not moved (as built with WO-16, 2026-10-30): until that date today's manager is still the manager. | WO-16 (built) | WO-16 report |
| WRK-15 | May HR invite pre-joiners (not yet joined) to sign in, in bulk? | Yes, as the single-user Users → New flow allows (as built) · refused until the joining date | Yes (as built with WO-16, 2026-10-30); only leavers and suspended people are refused. Recommended: refuse until the joining date, in line with WRK-7 (no access before day one). | WO-16 (built); WO-4, WO-8 | WO-16 report; WRK-7 |
| WRK-16 | When a team moves to a new manager and "move approvals" is ticked, should HR business partner steps move as well? | Line and functional manager steps only (as built) · also HR business partner steps | Line and functional manager steps only (as built with WO-16, 2026-10-30). | WO-16 (built) | WO-16 report |
| WRK-17 | When HR saves an employee's insurance policy form, should a covered member left out of the form be removed from the policy? | Yes, the form is the full member list (as built) · no, members are removed only one at a time | As built with MS-5 (2026-10-30); uploads and self-service never remove anyone. Removals are audited. | MS-5 (built) | MS-5 report |
| WRK-18 | At hire, should a PAN or Aadhaar match with someone still employed here need a ticked confirmation (it is likely a duplicate)? | Shown, no confirmation (as built) · confirmation with a reason | Shown only (as built with WO-3, 2026-10-30). A match with someone marked not eligible for rehire already needs a confirmation and a reason. | WO-3 (built) | WO-3 report |
| WRK-19 | Should the hire wizard accept documents, so fields that need a supporting document (CA-4) can be filled at hire? | No: add them on the Employee 360 after uploading (as built) · upload in the wizard | As built with WO-3 (2026-10-30). | WO-3 (built); CA-4 | WO-3 report |
| WRK-20 | Should HR with a limited access scope see a future joiner in their area before the joining date? | Not until the planned start date, because scope reads positions in force today (as built, existing behaviour) · yes, count the planned position in scope | As built (unchanged by WO-4, 2026-10-31). Recommended: count the planned position, so HR can prepare the joiner. | WO-4 (built); CA-9 | WO-4 report |
| WRK-21 | May a person marked "not joining" be brought back if they accept a new offer? | No: `not_joined` is final; a new hire of the same person meets the duplicate-person check, which HR may override (as built) · a "reinstate offer" step back to preboarding | As built with WO-4 (2026-10-31). | WO-4 (built); WRK-1 | WO-4 report |
| WRK-22 | When HR changes an upcoming joiner's expected joining date, should the salary (compensation) effective date move too? | No (as built) · yes, when it starts on the old date | Not moved (as built with WO-4, 2026-10-31); onboarding, probation, position and reporting line move. Recommended: move it when it starts on the old date. | WO-4 (built) | WO-4 report |
| WRK-23 | When someone is marked "not joining", should open background-check cases be closed and people set to report to them be moved? | Left as they are, with a warning for reportees (as built) · closed and moved automatically | As built with WO-4 (2026-10-31). | WO-4 (built) | WO-4 report |

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
| EXP-15 | Once an expense report is paid (or carried by a payroll run or a final settlement), how is a line held as "Suspected" released? | It can only be rejected (as built) · approved and paid in a supplementary payment | Only rejected (as built with XO-3, 2026-10-30; kept by XO-4). A later release needs a second payment per report: a payments table, its own payroll input with the never-twice guards, the final-settlement source and advance settlement — a follow-up package if the owner wants it. | XO-3 (built); XO-4 | XO-3 report; EXP-13 |
| EXP-16 | Who may clear a line held as "Suspected"? | Anyone who may decide that part of the report (as built) · only the approver who held it | Anyone who may decide the part (as built with XO-3, 2026-10-30); never on one's own report; a note is required and audited. | XO-3 (built) | XO-3 report |
| EXP-17 | Does a line held as "Suspected" count against expense limits and frequencies? | Yes, like a reported line (as built) · not until cleared | Yes (as built with XO-3, 2026-10-30). | XO-3 (built) | XO-3 report |
| EXP-18 | Should lines held as "Suspected" appear as items in the Approval Center? | No; approvers find them with the register filter and on the report (as built) · yes, as their own item kind | No (as built with XO-3, 2026-10-30). | XO-3 (built); RQ packages | XO-3 report |
| EXP-19 | When an expense report's chosen payroll month is finalized without it, what happens? | It stays on that month, flagged, until finance moves it (as built) · it rolls to the next open month automatically | Stays and is flagged (as built with XO-4, 2026-10-30); no other month picks it up on its own. | XO-4 (built) | XO-4 report; EXP-7 |
| EXP-20 | May finance choose a payroll month whose run is already calculated or approved (it then needs reopening and recalculating)? | Yes, with a note in the month's label (as built) · only months with no run or a draft run | Yes, with the note (as built with XO-4, 2026-10-30). Finalized months are never offered. | XO-4 (built) | XO-4 report |
| EXP-21 | When finance records an outside payment, must the payment mode and (for bank transfer and cheque) the paying account be filled in? | Optional, as today (as built) · required | Optional (as built with XO-4, 2026-10-30); a reference is still required, even for cash. | XO-4 (built) | XO-4 report |
| EXP-22 | With partial dispense on, may the employee or finance cancel the unpaid rest of an advance at once, even where the policy needs approval to cancel an advance? | Yes, at once, audited (as built) · only with the policy's cancellation approval | As built with XO-5 (2026-10-30); the switch `expense.advance.partial_dispense` is off by default. | XO-5 (built) | XO-5 report; EXP-14 |
| EXP-23 | When HR files an advance or an expense report on an employee's behalf, may the same person approve it? | Yes, as for receipts and trips filed on behalf today (as built) · a different approver is required | As built with XO-5 (2026-10-30). Recommended: a different approver. | XO-5 (built) | XO-5 report |
| EXP-24 | Should the employee be told when the unpaid rest of a partly dispensed advance lapses? | Audit only (as built) · a notification | Audit only (as built with XO-5, 2026-10-30): the existing "advance cancelled" message would wrongly say the whole advance was cancelled. | XO-5 (built) | XO-5 report |
| EXP-25 | Should a recovery finance scheduled for a payroll month wait while the employee has an expense report waiting for a decision (as the automatic recovery does)? | No: finance chose the month and amount (as built) · yes, wait like the automatic recovery | As built with XO-6 (2026-10-30). | XO-6 (built) | XO-6 report; EXP-8 |
| EXP-26 | Should the employee be told when a recovery of their advance is scheduled? | Audit only (as built) · a notification with the month and amount | Audit only (as built with XO-6, 2026-10-30). Recommended: notify the employee. | XO-6 (built) | XO-6 report |
| EXP-27 | When a payroll month is finalized without a scheduled recovery, what happens? | It stays scheduled, flagged, and holds back the advance's automatic recovery until finance moves or cancels it (as built) · it falls back to the automatic recovery date | As built with XO-6 (2026-10-30), like EXP-19 for expense reports. | XO-6 (built) | XO-6 report; EXP-19 |
| EXP-28 | May finance schedule a payroll recovery for an advance whose policy says recovery `none` or `exit_only`? | Yes, HR overrides the policy (as built) · no, the policy's recovery rule applies | As built with XO-6 (2026-10-30). | XO-6 (built) | XO-6 report |

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
| PER-14 | May a person withdrawn from a performance cycle be added back to it? | No, a withdrawal is final (as built) · yes, reinstated with their history | No (as built with GO-2, 2026-10-30). Adding them again is refused with the reason; nothing is deleted. | GO-2 (built) | GO-2 report; PER-10 |
| PER-15 | When someone is withdrawn from a cycle, should 360 nominations and feedback forms already sent about them be cancelled? | Left as they are (as built) · cancelled with the withdrawal | Left as they are (as built with GO-2, 2026-10-30). Their appraisal is read-only and out of every rating view. | GO-2 (built) | GO-2 report |
| PER-16 | Should people be told when their goal-setting, review or 360 window is extended? | No notice; the new date shows in reminders, review requests and My tasks (as built) · a notification | No notice (as built with GO-2, 2026-10-30). | GO-2 (built) | GO-2 report |
| PER-17 | When the employee request lock counts overdue "performance" tasks, should it include the new task kinds (reviews, goal sheets, goal check-ins, PIP evaluations)? | Yes (as built) · one-on-one and 360 feedback only, as before | Yes (as built with GO-9, 2026-10-30). The lock itself stays off by default. | GO-9 (built) | GO-9 report |
| PER-18 | Should the existing per-row "Calibrate" action refuse HR's own appraisal, as the new normalisation does? | Allowed, as today · refused | Allowed (today; unchanged by GO-3, 2026-10-30). Recommended: refuse it, so nobody changes their own rating. The new normalisation grid already refuses one's own appraisal. | GO-3 (built) | GO-3 report |
| PER-19 | With manager normalisation on (`performance.normalise.by_managers`), may managers also normalise their team's periodic review ratings? | HR only (as built) · managers too, for their own reports | HR only (as built with GO-3, 2026-10-30); the manager setting covers performance ratings only. | GO-3 (built); GO-19 | GO-3 report; PER-2 |
| PER-20 | Who should get the scheduled check-in reminders for an organisation goal that has an owner person? | The goal's creator (as built) · the owner person · both | The creator (as built with GO-4, 2026-10-30). Recommended: the owner person. | GO-4 (built) | GO-4 report |
| PER-21 | Who can be put on a private goal's visible-to list? | Named employees only (as built) · also organisation units or rule-based audiences | Named employees (as built with GO-4, 2026-10-30). | GO-4 (built); PER-5 | GO-4 report |
| PER-22 | May HR with a limited scope see a private organisation goal that has no owner person (so it belongs to nobody in their scope)? | No, it fails closed (as built) · yes, every performance HR user sees it | Hidden (as built with GO-4, 2026-10-30); creators, the visible-to list and HR without a scope limit still see it. | GO-4 (built); PER-5 | GO-4 report |
| PER-23 | With achievement override on, should a parent goal's progress roll up from its children's overridden (final) achievement or their system achievement? | System achievement (as built) · final achievement | System (as built with GO-4, 2026-10-30). | GO-4 (built); PER-6 | GO-4 report |

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
| PLT-21 | When bulk invitations copy someone (CC), should they get one notice per invited person or one summary per run? | One per person (as built) · one summary per run | One per person (as built with WO-16, 2026-10-30). The copy never carries the sign-in or reset link. | WO-16 (built) | WO-16 report |

## Count by theme (after merging duplicates)

| Theme | Open decisions |
|---|---|
| 1. Data visibility and privacy | 23 |
| 2. Self-service editing | 8 |
| 3. Payroll and statutory (5 are owner / legal rule checks) | 25 |
| 4. Time office | 19 |
| 5. Workforce lifecycle | 23 |
| 6. Expense and travel (including assets and helpdesk) | 28 |
| 7. Performance and engage | 23 |
| 8. Analytics and AI | 10 |
| 9. External providers | 15 |
| 10. Platform / SaaS | 21 |
| **Total** | **195** |

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
123. TIM-8 bulk actions for Attendance Admin. Answer: ______
124. TIM-9 pending requests counted company-wide at month close. Answer: ______
125. TIM-10 closed month keeps its dates when the pay cycle changes. Answer: ______
126. PRV-16 who may receive e-mailed letters. Answer: ______
127. WRK-8 separate right to revoke letters. Answer: ______
128. WRK-9 when a re-generated letter replaces the old one. Answer: ______
129. PER-14 re-adding a withdrawn person. Answer: ______
130. PER-15 360 requests after a withdrawal. Answer: ______
131. PER-16 notice of a window extension. Answer: ______
132. PER-17 request lock counts the new performance tasks. Answer: ______
133. PRV-17 exit remarks in the exits dataset. Answer: ______
134. WRK-10 clearance tasks in My tasks. Answer: ______
135. WRK-11 initiator accepts the resignation. Answer: ______
136. WRK-12 last working day change moves asset and interview dates. Answer: ______
137. WRK-13 completing an unaccepted resignation. Answer: ______
138. TIM-11 half-day leave in the attendance month summary. Answer: ______
139. TIM-12 payroll takes attendance paid-day adjustments as loss of pay. Answer: ______
140. TIM-13 penalty days after set counts. Answer: ______
141. WRK-14 approvals with a future-dated manager change. Answer: ______
142. WRK-15 inviting pre-joiners. Answer: ______
143. WRK-16 HR business partner steps on a team move. Answer: ______
144. PLT-21 CC notices per person or per run. Answer: ______
145. PAY-18 drafts block a second proposal. Answer: ______
146. PAY-19 who may export the salary structure register. Answer: ______
147. PAY-20 one source for the tax regime. Answer: ______
148. PAY-21 printed breakup follows the PF wage basis. Answer: ______
149. EXP-15 releasing a held line after payment. Answer: ______
150. EXP-16 who clears a suspicion. Answer: ______
151. EXP-17 held lines and expense limits. Answer: ______
152. EXP-18 held lines in the Approval Center. Answer: ______
153. PER-18 calibrating one's own appraisal. Answer: ______
154. PER-19 managers normalise review ratings. Answer: ______
155. EXP-19 a chosen month finalized without the report. Answer: ______
156. EXP-20 choosing a month whose run is calculated or approved. Answer: ______
157. EXP-21 payment mode and account required. Answer: ______
158. PRV-18 what employees see of an expense payment. Answer: ______
159. TIM-14 auto correction shifts on a rostered day. Answer: ______
160. TIM-15 a shift change clears the roster's auto flag. Answer: ______
161. SSE-5 nominee majority age and benefit list. Answer: ______
162. SSE-6 approval for self-edited nominations. Answer: ______
163. TIM-16 longest range for a status change. Answer: ______
164. TIM-17 day status from two different halves. Answer: ______
165. SSE-7 approval for self-edited insurance. Answer: ______
166. SSE-8 employees upload their e-card. Answer: ______
167. WRK-17 HR form removes left-out insured members. Answer: ______
168. PAY-22 insurance premiums in payroll and tax. Answer: ______
169. PRV-19 who sees the type of a private leave. Answer: ______
170. PRV-20 team away for every employee. Answer: ______
171. PRV-21 private leave in notifications. Answer: ______
172. WRK-18 confirming a match with a current employee. Answer: ______
173. WRK-19 documents in the hire wizard. Answer: ______
174. PRV-22 hidden not-eligible match at hire. Answer: ______
175. EXP-22 cancelling the unpaid rest of an advance. Answer: ______
176. EXP-23 approving one's own on-behalf filing. Answer: ______
177. EXP-24 notice when an advance's rest lapses. Answer: ______
178. PRV-23 who sees check-in photos. Answer: ______
179. TIM-18 a manager's team for punch edits. Answer: ______
180. TIM-19 excluded punches and the daily limit. Answer: ______
181. EXP-25 scheduled recovery and pending reports. Answer: ______
182. EXP-26 notify the employee of a scheduled recovery. Answer: ______
183. EXP-27 a finalized month without the scheduled recovery. Answer: ______
184. EXP-28 scheduling against the advance policy. Answer: ______
185. PER-20 check-in reminders for owned goals. Answer: ______
186. PER-21 visible-to audiences for private goals. Answer: ______
187. PER-22 scoped HR and private goals with no owner. Answer: ______
188. PER-23 roll-ups from overridden achievement. Answer: ______
189. PAY-23 who hears about the automatic calculation. Answer: ______
190. PAY-24 automatic calculation of validated runs. Answer: ______
191. PAY-25 who may tick payroll entries. Answer: ______
192. WRK-20 scoped HR and future joiners. Answer: ______
193. WRK-21 bringing back a not-joined person. Answer: ______
194. WRK-22 joining date change moves salary dates. Answer: ______
195. WRK-23 background checks and reportees of a not-joiner. Answer: ______
