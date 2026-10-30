# HROne → PeopleOS gap register

Prepared 2026-10-28. Source: a read-only walk through the live HROne portal (app.hrone.cloud) used by Markedge Technologies, covering the menu catalogue, setup screens, 230 reports, 111 imports, 68 HR-desk actions, ~140 workflow types and the workforce/payroll/expense settings. The full captured catalogue is in [hrone-feature-inventory.md](hrone-feature-inventory.md). Each item was checked against the PeopleOS code, not only the docs.

**Rules for closing gaps:** build inside the existing architecture (tenant scoping, audit, access scopes, data inventory, policies, workflow-event approvals, PeopleOS base pages). No existing screen, flow or feature is removed or redesigned; new capability is added beside it.

**Status key:** PARTIAL = something exists but the HROne capability is not fully covered. MISSING = nothing equivalent. EXT = needs an outside provider or an owner decision before it can go live (the seam can still be built).

**Out of scope:** Recruitment (job requisitions, candidates, interviews, offers, talent pool, referrals, IJP, candidate engagement, recruiter access). Markedge runs a separate recruitment system that will be integrated later through the existing RMS boundary (`POST /api/v1/pre-employees`, blueprint §465). Recruitment reports and imports are excluded with it.

## Summary

| Area | Strong in PeopleOS today | Main gaps | Build phase |
|---|---|---|---|
| Time Office | Shifts, rotating schedules, punches, processing, regularisation, leave engine, holidays | Short leave, comp-off claims, OT claims, shift change requests, multi-day OD, RH quota, late-mark penalties, geo-fencing, arrears, imports | HP-1 |
| Payroll | Salary structures, CTC changes, run pipeline, statutory engine, TDS, F&F, Form 16/24Q | Loans, reimbursement claims, FBP, LTA, variable pay, hold/release, investment proofs into TDS, overrides, bonus run, imports | HP-2, HP-3 |
| Expense & Travel | — | Whole module | HP-4 |
| Projects & timesheets | — | Whole module | HP-5 |
| Workforce | Profile, lifecycle, onboarding, BGV, exit, F&F, letters, code series | Letter lifecycle, resignation approval, transfers, contracts, confirmation, induction, statuses, insurance, imports | HP-6 |
| Inbox & platform | Approvals, workflows, audit, access scopes, API, SCIM, SSO | Delegation, bulk approval, discussion, archive, business partners, geo/calendar access, settings | HP-7 |
| Performance | Cycles, appraisals, calibration, goals/OKR, PIP, 1-on-1, feedback, TNI | Goal approval, deadline extension, normalisation, standalone 360, initiatives, letters, 9-box | HP-8 |
| Engage | Handbook, announcements, surveys, birthdays | Badges, rewards, wall of fame, social wall, polls, events, moodbot, core values | HP-9 |
| Asset & Helpdesk | Asset lifecycle, tickets, SLA | Asset requests, entitlement policy, sub-types, imports, ticket reports | HP-10 |
| Analytics & self-service | Report builder, dashboards, schedules, assistants | Missing datasets, to-dos, social profile, team analytics, ROI | HP-11 |

## Time Office (HP-1)

| ID | HROne capability | Status | PeopleOS today | Gap to close |
|---|---|---|---|---|
| TO-01 | Short leave (hourly permission) + cancellation, short-leave policy | MISSING | Hourly leave types are refused (`Leaves::request`) | Short-leave requests in minutes/hours with a policy (max per month, max duration, window), approval, cancellation, attendance effect |
| TO-02 | Comp-off request + verify comp-off, credit expiry | PARTIAL | CO type, `comp_off_credit` ledger, `holiday_work` exception; credit only by manual adjust | Employee claims for worked off-days, approval/verification, credit with expiry, lapse of expired credit |
| TO-03 | Overtime requests by employee, verify overtime | PARTIAL | OT computed from punches, approve/reject on the record | Employee OT claims, OT in Approval Center, policy rates applied |
| TO-04 | Shift change requests | MISSING | HR assigns schedules only | Employee/manager request for a different shift on given dates, approval, day-level override used by processing |
| TO-05 | Shift roster (day-wise) and bulk shift assignment | PARTIAL | Weekly/rotating patterns with effective dates | Day-level roster overrides and bulk assign |
| TO-06 | On duty requests (multi-day, half-day, hourly) + cancellation | PARTIAL | OD is a single-day regularisation type | OD requests over a date range or part-day |
| TO-07 | Cancel approved regularisation / OD | PARTIAL | Only pending requests can be withdrawn | Cancellation request on approved items with approval and reprocessing |
| TO-08 | Restricted holidays from the optional list with a quota + cancellation | PARTIAL | RH leave type not tied to optional holidays | Pick from the calendar's optional holidays, yearly quota, cancellation |
| TO-09 | Employee holiday calendar | PARTIAL | Calendars are admin-only | Read-only "My holidays" view |
| TO-10 | Leave encashment request + cancellation + payout | PARTIAL | HR-only, instant; paid only in F&F | Employee request with approval, cancellation, payout through monthly payroll |
| TO-11 | Leave donation | MISSING | — | Donate days to a colleague with approval, ledger entries both sides |
| TO-12 | Late-mark penalties / attendance deductions | MISSING | Policy fields exist, unused | Count late marks per month, apply warn / half day / LOP per policy, report |
| TO-13 | Change attendance status (HR override) | PARTIAL | Only via self-approved regularisation | Direct audited status override for a day |
| TO-14 | Override working hours | MISSING | — | Audited override of worked minutes for a day |
| TO-15 | Attendance arrears (LOP reversal after payroll lock) | MISSING | Manual money arrear only | Arrear-day records for corrections after a finalised period, paid in next payroll |
| TO-16 | Geo work locations + location requests | MISSING | Locations have no coordinates | Geofence (lat/long/radius) per location or employee, check on punch, location approval requests |
| TO-17 | Mobile check-in controls (device binding, block without location, offline) | MISSING | API punch carries lat/long only | Device registry per employee, policy checks on API punches (EXT: native app) |
| TO-18 | Attendance calendar grid | PARTIAL | Day table only | Month grid per employee |
| TO-19 | Paid days / paid days YTD before payroll | PARTIAL | Shown on payroll entries | Paid-days view from attendance output |
| TO-20 | Bulk attendance slip (PDF) | MISSING | CSV exports only | Printable monthly attendance slips |
| TO-21 | Time Office imports (leave opening/change balance, leave requests, comp-off and RH balance, shift, geo location, overtime, override hours, paid days, arrears) | MISSING | Punch and monthly sheet imports only | File imports on the existing bulk-upload pattern |
| TO-22 | Reports: short leave, leave donation, penalties, geo, mobile check-in, arrear days/calculation, time-office request reconciliation, day-wise roster, shift change history, monthly leave balance, Form 25 | MISSING/PARTIAL | — | Datasets + report library templates |
| TO-23 | Process attendance for a date range in the UI | PARTIAL | CLI only for ranges | Range action |

## Payroll (HP-2 money flows, HP-3 tax & statutory)

| ID | HROne capability | Status | PeopleOS today | Gap to close |
|---|---|---|---|---|
| PY-01 | Loans and salary advances (request, guarantor, approval, EMI schedule, payroll recovery, F&F recovery, ledger, adjust, tenure extension) | MISSING | Fixed `ADV` deduction component only | Loan module feeding payroll deductions and F&F — **policy completion built (HS BP-9): request days, requests a year, EMI share of pay, exceptions, approver edits, several guarantors with acceptance** |
| PY-02 | Reimbursements (pay on claim with bills, entitlement balances, cancellation, opening balance; pay by default) | PARTIAL | Manual `reimbursement` payroll adjustment | Claim module with balances, payout in payroll — **one open claim, never above bills, reminders to claim built (HS BP-9)** |
| PY-03 | Flexible benefit plan declaration and approval | MISSING | — | FBP declaration against CTC components |
| PY-04 | LTA claims and liability | MISSING | — | LTA block-year claims, liability report |
| PY-05 | Variable pay plans, requests, cancellation | PARTIAL → built (HP-2 requests; HS BP-9 plans, task day, schedule on My compensation) | Target stored for formulas | Variable payment requests/plans with approval, payout |
| PY-06 | Salary hold and release | MISSING | — | Hold per employee/period, excluded from bank file until released |
| PY-07 | Out-of-salary payments | MISSING | — | Payments recorded outside the run with register |
| PY-08 | Negative salary carry-forward/recovery | PARTIAL | Block or allow only | Carry the shortfall into the next period |
| PY-09 | Automatic arrears on back-dated revisions; PF arrear | PARTIAL | Manual arrear row | Arrear computation from revision deltas |
| PY-10 | Per-employee salary unprocess | PARTIAL | Whole-run reopen | Unprocess one employee in an open run |
| PY-11 | Leave encashment payout through payroll (10(10AA)) | PARTIAL | Days deducted, nothing paid | Approved encashment becomes a payroll earning |
| PY-12 | Investment proofs feed TDS (employee upload, proof window) | PARTIAL | TDS uses declared amounts | Proof submission, verification, verified amounts used after the proof window |
| PY-13 | Form 12BB, previous-employer 12B details, house property 24(b), other income, 80CCD(2), multiple landlords | MISSING/PARTIAL | Single landlord, two previous-employer numbers | Declaration heads and printable 12BB |
| PY-14 | Overrides of PF/ESI/PT/TDS with override audit | MISSING | — | Audited per-period overrides |
| PY-15 | VPF / employee-level PF settings | MISSING | Establishment-level only | Employee PF options |
| PY-16 | Employer NPS (80CCD(2)) | MISSING | — | NPS component and report |
| PY-17 | Perquisites | MISSING | — | Perquisite entries taxed in TDS |
| PY-18 | Income outside the system | MISSING | — | Entries counted in TDS projection |
| PY-19 | Bonus processing (calculation, payout, slab, Form C/D) | PARTIAL | Bonus register (provisional) | Bonus run with payout (BONUS rule verification still awaits the owner) |
| PY-20 | Gratuity monthly provisioning | PARTIAL | Accrued in register | Month-end liability report |
| PY-21 | Challan tracking | PARTIAL | Working sheets | Challan register with payment status |
| PY-22 | Pay groups | PARTIAL | Runs per company | Pay group entity used to split runs |
| PY-23 | Payslip / CTC password protection | MISSING | — | Password-protected PDFs |
| PY-24 | Salary bank configuration, bank formats | PARTIAL → built (HS BP-13: paying accounts, versioned bank file layouts, pay modes, cash and cheque register) | One generic CSV | Company debit banks and bank-specific layouts |
| PY-25 | Mid-year implementation (opening YTD) | MISSING | — | Opening YTD import used by TDS and registers |
| PY-26 | Minimum wage validation; 50% wage rule enforcement | MISSING/PARTIAL | Advisory warning | Validation on structures |
| PY-27 | EPF Form 11, ESI Form 1, Gratuity Form F | MISSING | — | Printable forms from profile data |
| PY-28 | Salary revision letter on revision | PARTIAL | Manual letters | Auto letter after approved revision (with WF-01) |
| PY-29 | Standalone increment calculator | PARTIAL | Inside cycles only | Calculator page |
| PY-30 | Payroll imports (statutory details, investments, HRA, 12BAA, overrides, perquisites, income outside, loans, reimbursements, bonus slab, pay group, settlement, mid-year, monthly variables, manual arrears) | MISSING | Salary and bank imports only | Imports per item |
| PY-31 | Payroll reports (TDS worksheet, loan ledger/request/summary, reimbursement audit/ledger/request/summary, hold, negative, out-of-salary, override audit, perquisite, NPS, LTA liability, variable plan/request, effective PF wages, investment not submitted, encashment liability, Form D) | MISSING/PARTIAL | — | Datasets + templates |
| PY-32 | Digital signature (DSC) on payroll documents | EXT | Image signature only | Provider decision needed |
| PY-33 | Settlement auto-trigger days, settlement import | MISSING | — | Trigger + import |

## Expense & Travel (HP-4)

| ID | HROne capability | Status | Gap to close |
|---|---|---|---|
| EX-01 | Expense policy: categories, limits by grade / location group, currency, tax | MISSING | Policy-driven categories and limits |
| EX-02 | Expense receipts by amount and mileage, attachments, GST, duplicate detection | MISSING | Receipt capture with checks |
| EX-03 | Expense reports (bundle receipts, submit, multi-level approval, exceptional expense) | MISSING | Report workflow on the workflow engine |
| EX-04 | Pay expenses (through payroll or payment register) | MISSING | Approved reports paid via the payroll reimbursement rail or marked paid |
| EX-05 | Expense advances (request, cancel, dispense, settle against reports, recover via payroll/F&F) + advance policy | MISSING | Advance ledger |
| EX-06 | Travel requests (multi-leg), bookings, cancellations | MISSING | Travel plan with approval and booking details |
| EX-07 | Expense settings (submission window, expiry, duplicate %, backdated travel, overlap) | MISSING | Tenant settings |
| EX-08 | Team views, expense dashboard | MISSING | Manager lens and analytics |
| EX-09 | 12 expense/travel reports | MISSING | Datasets + templates |

## Projects & timesheets (HP-5)

| ID | HROne capability | Status | Gap to close |
|---|---|---|---|
| PR-01 | Customers and vendors (+ imports) | MISSING | Masters |
| PR-02 | Projects (stages, members, form) + import | MISSING | Project master |
| PR-03 | Tasks | MISSING | Project tasks |
| PR-04 | Timesheets (submit, approve, cancel) | MISSING | Weekly timesheets with approval |
| PR-05 | Work plans and milestones with approval | MISSING | Plans and milestones |
| PR-06 | Project detail report, project-based expense | MISSING | Dataset; link EX to projects |

## Workforce (HP-6)

| ID | HROne capability | Status | PeopleOS today | Gap to close |
|---|---|---|---|---|
| WF-01 | Letter lifecycle: auto-generation from workflows, bulk generate/share, employee acceptance/acknowledgement with signature, extra letter types (resignation acceptance, termination, transfer intimation, contract, extension, performance, PIP, salary revision), acknowledgement and shared-letter reports | PARTIAL | Manual letters; workflow `document` node is a stub | Wire the node, bulk publish, acceptance |
| WF-02 | Resignation approval (accept/reject, last working day per approver, notice waiver/buyout, notice from exit policy, attachment, withdrawal control) | PARTIAL | Submit goes straight to notice | Approval step and policy-driven notice |
| WF-03 | Configurable clearance stages, manager and HR exit interviews, interview lead days, reassign reportees on exit | PARTIAL | Fixed stages, one interview | Configurable |
| WF-04 | Transfer requests with approval, pre/post-transfer checklists, allowances, date windows | MISSING | Direct HR transfer | Request flow and checklists |
| WF-05 | Contract workforce: end dates, renewal/extension, expiry alerts | MISSING | Contract employment type only | Contract records and renewals |
| WF-06 | Confirmation process: request at lead days, manager feedback, confirm/extend probation, letter | PARTIAL | Reminders + manual state change | Confirmation review |
| WF-07 | Induction plan and evaluation, welcome note, profile completion %, bulk invite | MISSING/PARTIAL | Onboarding tasks; single invites | Induction, welcome, completion |
| WF-08 | Status model: retirement age/date and auto-retire, other status (sabbatical, maternity, absconding) with dates/remarks, blacklist | PARTIAL | Lifecycle states | Status records |
| WF-09 | Field-level visibility/editability for standard fields; custom fields in reports/imports; more profile change requests | PARTIAL | Custom fields only | Field rules |
| WF-10 | Health insurance (policy, sum insured, covered dependants) | MISSING | — | Insurance records + report |
| WF-11 | Imports: org masters, family, nominees, qualifications, experience, previous employer, contracts, custom fields, skills, employee-code change, upcoming joiners | MISSING | Employee and bank imports | Imports |
| WF-12 | Masters: region › branch › sub-branch, district/city, dimensions, payment banks | PARTIAL (payment banks built, HS BP-13 / CA-5) | Flat locations, cost/profit centres | Masters |
| WF-13 | Pre-joiner self-service portal | EXT | Single admin panel | Owner decision on candidate access |

## Inbox & platform (HP-7)

| ID | HROne capability | Status | Gap to close |
|---|---|---|---|
| IP-01 | Delegation / out-of-office, reassign screen | MISSING | Delegate tasks for a period; reassign |
| IP-02 | Bulk approval | MISSING | Approve/reject many items at once |
| IP-03 | Discussion per task, archive, overdue/due filters, inbox insights | MISSING | Comments, archive, filters, on-time metrics |
| IP-04 | Business partners (external users) + report | PARTIAL | Partner type and report |
| IP-05 | Geo access, calendar access, widget access, role auto-mapping by org unit | MISSING/PARTIAL | Access rules |
| IP-06 | Workforce settings (PAN/Aadhaar mandatory, back-dated joining limit, LWD rules, resignation during probation, concurrent logins, hide approver comments …) | MISSING | Tenant settings read by the flows |
| IP-07 | SMS / WhatsApp / push channels, tenant sender settings | EXT | Provider adapters (channels log only today) |
| IP-08 | e-KYC, journal voucher export, SAML SSO | EXT/MISSING | JV export can be built; e-KYC and SAML need providers |

## Performance (HP-8)

| ID | HROne capability | Status | Gap to close |
|---|---|---|---|
| PF-01 | Goal plan approval, goal requests | PARTIAL | Manager approval step, request list |
| PF-02 | Extend goal / review / 360 deadlines | MISSING | Audited stage extension |
| PF-03 | Rating normalisation (bell curve) and bulk publish | PARTIAL | Distribution targets, bulk normalise, publish date — **publish date and bulk publish built (HS HP-8b, `performance.rating_publish`); bell curve open (HP-8g)** |
| PF-04 | Standalone 360 with nominations and approval | PARTIAL | 360 cycles, nominations |
| PF-05 | Initiatives (+ feedback) | Built (HP-8j, 2026-10-29) | Initiative records, feedback requests, My / team / HR screens, dataset |
| PF-06 | 1-on-1 acknowledgement | MISSING | Employee acknowledgement — **built (HS HP-8b): feedback card = acknowledgement (`performance.one_on_one.feedback_from`)** |
| PF-07 | Performance and PIP letters | PARTIAL | Letter types + merge fields |
| PF-08 | 9-box grid from ratings | Built (HP-8g, 2026-10-29) | `NineBoxGrid`; box names per tenant still config-only |
| PF-09 | Goal library / templates | PARTIAL | Pickable goals |
| PF-10 | OKR final achievement sign-off | PARTIAL | Final achievement |
| PF-11 | Separate review-rating and overall-rating acknowledgement | PARTIAL | Two acknowledgements |
| PF-12 | Performance imports | MISSING | Imports |
| PF-13 | Performance reports (goal status, goals not created, check-ins, 1-on-1, 360, feedback, TNI, review details by KPI / approver) | MISSING/PARTIAL | Datasets |
| PF-14 | Delete/reset reviews, bulk delete goals | PARTIAL | Admin actions |

## Engage (HP-9)

| ID | HROne capability | Status | Gap to close |
|---|---|---|---|
| EN-01 | Badges and custom badges with monthly quota, ledger | MISSING → built (HP-9c) | Recognition |
| EN-02 | Reward points policy, disbursal, redemption ledger | MISSING | Points wallet |
| EN-03 | Wall of fame | MISSING → built (HP-9c: monthly, Home card) | Weekly/monthly leaderboard |
| EN-04 | Social wall posts, likes, comments, restricted comment types | MISSING | Feed |
| EN-05 | Polls (+ approval) | MISSING | Quick polls |
| EN-06 | Events (+ nominations, attendance, pre/post feedback) | MISSING | Events |
| EN-07 | Happiness index / moodbot with reason tags | MISSING | Mood check-in + dashboard |
| EN-08 | Mission statement / core values | MISSING → built (HP-9b) | Values content |
| EN-09 | Work and wedding anniversary wishes | PARTIAL → built (HP-9a) | Celebrations beyond birthdays |
| EN-10 | Per-employee announcement read and handbook acknowledgement lists, handbook due days | PARTIAL (handbook part built, HP-9a; announcement read lists open) | Lists + due dates |
| EN-11 | Fundraisers, contests | MISSING | Low priority |
| EN-12 | Engage imports and reports | MISSING | Imports + datasets |

## Asset & Helpdesk (HP-10)

| ID | HROne capability | Status | Gap to close |
|---|---|---|---|
| AS-01 | Asset requests linked to inventory | MISSING | Request → approve → assign |
| AS-02 | Asset entitlement policy | MISSING | Eligibility by grade/designation/location |
| AS-03 | Asset sub-type level | PARTIAL | Third level |
| AS-04 | Asset imports | MISSING | Type, sub-type, product, inventory |
| AS-05 | Warranty-expiry, overdue-return, acknowledgement reminders | PARTIAL | Alerts |
| AS-06 | Asset acknowledgement and asset history reports | MISSING/PARTIAL | Datasets |
| HD-01 | Ticket reports by requestee / resolver | PARTIAL | Datasets with names and departments |
| HD-02 | Configurable ticket feedback | PARTIAL | Feedback questions |
| HD-03 | Email-to-ticket | EXT | Inbound mail provider |

## Analytics & self-service (HP-11)

| ID | HROne capability | Status | Gap to close |
|---|---|---|---|
| AN-01 | Personal to-do list | PARTIAL | User to-dos on Home |
| AN-02 | Home cards: yesterday's attendance, team planned leaves | PARTIAL | Cards |
| AN-03 | Social profile (about, hobbies, interests) | MISSING | Profile section |
| AN-04 | Favourite pages and actions | PARTIAL | Favourites |
| AN-05 | Team analytics and team reports for managers | PARTIAL | Manager page |
| AN-06 | ROI (processes automated) | MISSING | Automation coverage metric |
| AN-07 | Dashboards: 9-box, happiness, expense, payroll cost trend | MISSING/PARTIAL | Dashboards |
| AN-08 | Inbox insights | MISSING | With IP-03 |

## Configuration parity (Setup centre)

HROne's Setup was re-read in depth (176 setup screens, 303 configuration endpoints; [hrone-configuration-catalogue.md](hrone-configuration-catalogue.md)). PeopleOS answers it with three existing mechanisms, now gathered in one **Setup centre** (Configuration → Setup, `config/peopleos_setup.php`), so each client is configured without code:

1. **Typed tenant settings** per module (organisation-wide switches, windows and limits), validated and audited on save.
2. **Policy types** (Policy Engine): versioned rule sets assigned to employee groups by conditions — the equivalent of HROne's per-policy "applicable on company / grade / employee status …".
3. **Masters and configuration screens** of the module (shifts, calendars, components, templates …).

A setting appears in the Setup centre only when code reads it. Items below are closed when the option exists and drives behaviour.

| ID | HROne configuration | PeopleOS today | Gap to close | Phase |
|---|---|---|---|---|
| CF-01 | Setup hub by module (Core HR, Automation, Forms, Governance, Access, Integration, module setups) | Admin Centre (module directory), raw key/value Settings screen | **Done (HP-1):** Setup centre with typed settings, policy types and screens for 14 modules | HP-1 |
| CF-02 | Short leave policy: count per interval, max/min/total hours, backdated windows (employee / manager / admin), future-dated, multiple per day, after attendance processing, leave deduction beyond limit | — | **Done (HP-1):** `short_leave` policy type (enabled, min/max minutes, count and minutes per month, window, approval). Pending: manager/admin windows, deduction from leave beyond the limit | HP-1 |
| CF-03 | Check-in policy: request limits per week/month, backdated / future windows, allow shift change / weekly-off change requests, specific shifts; geo, device, sources | — | **Done (HP-1):** `checkin` policy type (sources, location required, geo location required + outside action, single device, first device auto-approval) and `time_office` policy type (on duty, shift change, overtime and comp-off claims, windows, cancellation approval). Pending: weekly/monthly request limits, weekly-off change requests | HP-1 |
| CF-04 | Shift policy: in-margin, half/full-day minimums, absent-hour rules for each half, grace in/out, employee may change shift, mark-attendance limits for manager/admin, break deduction rules | Shift master: grace, half/full-day minutes, break, overtime eligibility | Absent-hour rules per half, employee-may-change flag (via `time_office`), manager/admin mark-attendance limits | HP-1b |
| CF-05 | Shift rotation policy: interval, auto shift by punch, auto default shift, auto weekly off/holiday by shift | Rotating weekly schedules | Auto shift detection by punch time | HP-1b |
| CF-06 | Weekly-off policy per organisation unit; weekly-off limit per week | Weekly offs inside schedules | Weekly-off change requests and limit | HP-1b |
| CF-07 | Holiday calendar: restricted holidays applicable, yearly maximum, proration | Calendars by rules | **Done (HP-1):** optional holidays by choice, yearly quota. Pending: proration for joiners | HP-1 |
| CF-08 | General time-office settings: arrears and months back, punches based on, weekly-off limit, overtime hours editable, copy last shift, new-joiner arrear, request after extra benefits, absent-weekoff-absent rule, holiday year, leave donation, leave request on a present day, default working hours, directory visibility | Few settings | **Done (HP-1):** arrears after lock (switch), leave donation (types, yearly limit). Pending: sandwich rule, leave request on a present day, directory visibility, months-back limit for arrears | HP-1b |
| CF-09 | Leave policy: credit frequency (monthly … custom), joiner credit rules (rule of 15 by DOJ/DOC, include/exclude joining month, proportionate), sandwich (absent–week-off–absent), leave on present day, donation per type, encashment, visibility | Leave policy: accrual annual/monthly/quarterly, proration monthly/daily/none, carry forward, encashment, eligibility | Half-yearly / next-year credit, rule-of-15 joiner credit, sandwich rules | HP-1b |
| CF-10 | Payroll pay groups (components, templates for salary slip / tax / reimbursement / appointment / revision / contract / annexure, flexible plan, PF limit gross) | Structures per company | Pay group entity tying structures, templates and payroll settings | HP-2 |
| CF-11 | Pay component flags (part of CTC / gross / PF / ESI / PT, prorate, rounding, taxable, show on payslip, arrear applicable, perquisite, FBP min/max, mid-month increment …) | Salary component flags (taxable, PF, statutory classification, formula) | Remaining flags (rounding rule, ESI/PT/gross parts, arrear applicable, FBP limits, show-on-payslip) | HP-2 |
| CF-12 | Payroll general settings (105 switches: leave cash days, arrear with salary, overtime in gross, letters after revision, loans, bonus limit, HRA rules, CTC/slip passwords, investment proof rules, minimum wage, pay after challan, NPS, guarantor, settlement trigger …) | ~7 payroll settings | Each switch with its feature (loans, investments, passwords, bonus …) | HP-2/HP-3 |
| CF-13 | Separation / confirmation / transfer / induction policies; reasons masters (with employee/approver use, approver remark mandatory); checklist types; transfer allowances and change types | Exit policy (notice days, unused), fixed clearance stages | Policy types for separation, confirmation, transfer, induction; reasons masters; configurable checklists | HP-6 |
| CF-14 | Document settings per document (view/edit/mandatory for employee, candidate, joiner; expiry alert days; sample) | Document types with expiry | Per-audience edit/view/mandatory flags, sample files | HP-6 |
| CF-15 | Profile field visibility (employee / manager / others) and social profile access | Custom-field visibility only | Field rules for standard fields | HP-6 |
| CF-16 | Code series per module (not only employee codes), manual vs automatic per employee type | Employee code series per company | Code series for other documents (letters, requests, tickets) | HP-7 |
| CF-17 | Letter templates per module with digital-signature position, languages; letter acceptance (acceptance required, message) | Letter templates (HTML/DOCX), signatories | Acceptance settings, template languages | HP-6 |
| CF-18 | Feedback forms per module (exit interview, confirmation, induction, interview) with question bank and types | Forms engine; fixed exit questionnaire | Question bank + feedback templates bound to processes | HP-6 |
| CF-19 | Governance: password policy (expiry, strict, reuse, MFA), disable login by other status, password-protected reports, override records, audit trail collections, data export modules, import approval per import | Security policy page, audit, data export | Disable login by status, password-protected report files, import approval | HP-7 |
| CF-20 | Access: page access, data access, CXO access, widget access, calendar access, geo dashboard access | Roles + permissions, access scopes, dashboard visibility | Widget, calendar and geo access rules | HP-7 |
| CF-21 | Automation: workflows per request type (~60), task automation (task, due days, TAT, role mapping), inbox auto-archive, self-service lock (lock period, exempt employees, tasks), scheduled services, notification catalogue per process with email/WhatsApp switches | Workflow engine, notification rules/templates | Task due-day automation, ESS lock, inbox archive, per-process notification switches | HP-7 |
| CF-22 | Mail configuration (custom SMTP / provider API, sender), SSO (SAML, certificates), API keys, SCIM, journal voucher mapping | SSO (OIDC), API keys, SCIM | Tenant SMTP sender, SAML (EXT), journal voucher mapping | HP-7 |
| CF-23 | Expense: expense and travel form field visibility/mandatory, categories (amount/mileage), policies with effective dates, location groups, taxes, currencies, advance policy | — | With the expense module | HP-4 |
| CF-24 | Performance: appraisal type, start month, decimal ratings and rounding, goal edit window, OKR check-in schedulers (company/team/employee), auto-publish rating, 360 visibility, terminology (KRA/KPI/competency labels), units of measure, perspectives, rating scales (goal, competency), KPI rating formula, bell curve, 9-box, initiative types, 1-on-1 settings, goal sheet policy, performance policy, PIP policy and reasons | Templates, rating scales, competencies, KRA library, cycles | General settings, terminology, UoM, perspectives, bell curve, 9-box, initiative types, PIP reasons | HP-8 |
| CF-25 | Engage: badges per month and list, custom badges, reward points policy (cycle, points per cycle, limit, eligibility), redeem types, feed types with comment restrictions, celebrations (birthday / work / wedding anniversary, achievements), moodbot (frequency, reason tags), mission statement, handbook policy | Birthday settings | With the engage features | HP-9 |
| CF-26 | Asset: asset policy, types, sub-types, products, deployment type, nature of asset | Categories, models | With the asset gaps | HP-10 |
| CF-27 | Helpdesk: resolver rules, helpdesk policy per category | Ticket categories, services, SLA | Resolver rules by category/location | HP-10 |

## Progress

| Phase | Closed | Deferred (with reason) |
|---|---|---|
| HP-1 Time Office | TO-01 short leave · TO-02 comp-off claims, verification, expiry · TO-03 overtime claims · TO-04 shift change requests · TO-05 day roster + bulk roster · TO-06 multi-day / half / hourly on duty · TO-07 cancellation of approved requests · TO-08 restricted holidays with quota · TO-09 employee holiday view (attendance calendar) · TO-10 encashment request, cancellation (payout → PY-11) · TO-11 leave donation · TO-12 late-mark penalties into loss of pay · TO-13/14 HR day overrides · TO-15 attendance arrears (quantities; payment → HP-2) · TO-16 geo locations + location requests · TO-17 device binding, location and source rules (API) · TO-18 attendance calendar grid · TO-19 paid days on the calendar · TO-20 printable attendance slip · TO-21 uploads: leave balances, leave history, roster, geo locations, overtime, day overrides · TO-22 reports: time-office requests, reconciliation, short leave, on duty, comp-off, overtime claims, restricted holidays, shift changes, penalties, arrears, devices, leave donations · TO-23 process a date range · CF-01 Setup centre · CF-02 short-leave policy (windows, one per day, leave beyond the limit) · CF-03 time-office policy (per-type monthly limits) and check-in policy · CF-04 half day when late/early beyond N minutes · CF-07 restricted-holiday quota · CF-08 sandwich rule, leave on a present day · CF-09 half-yearly credit, joiner credit rules | Auto shift by punch (CF-05) and weekly-off swap requests (CF-06): need a shift-rotation design decision · RH proration for joiners (CF-07) · Factories Act Form 25 variant (compliance register) · native mobile app (EXT) · paid-days and comp-off/RH balance imports are covered by the monthly attendance and leave balance uploads |
| HP-2 Payroll money flows (part 1) | PY-01 loans and advances (policy, schedule, payout and recovery in payroll, prepay, defer, exit recovery) · PY-02 reimbursement claims paid on claim (accrual ledger, balance, bills, partial approval, payout) · PY-05 variable pay requests · PY-06 salary hold and release · PY-11 leave encashment payout · TO-15 attendance arrears payout · payroll input and settlement extension points | PY-03 FBP and PY-04 LTA move to HP-3 with their tax treatment · PY-07/PY-18 out-of-payroll payments with TDS (HP-3) · PY-08, PY-09, PY-10, PY-22–PY-25, PY-30 next |
| HP-3 Tax and statutory | PY-12 proofs feed TDS (proof submission, verification, verified amounts after the organisation's date, declaration and proof windows) · PY-13 home-loan interest and other income heads, previous-employer details, multiple landlords, landlord PAN rule, printable Form 12BB · PY-14 audited statutory overrides (`payroll.override`) · PY-15 VPF and employee PF wage basis · PY-16 NPS classifications (employer 80CCD(2), employee with 80C) · PY-17 perquisites · PY-07/PY-18/PY-25 income paid outside payroll (incl. opening months before go-live) · PY-30 uploads (perquisites, outside income, overrides, PF options) · PY-31 reports (perquisite register, out-of-salary payments, override audit, VPF register) · CF-12 tax settings | Home-loan interest is counted only once a TDS rule version carries its limits (owner: rule pack) · PY-03 FBP and PY-04 LTA · PY-19 bonus (BONUS rule awaits the owner) · PY-20, PY-21, PY-27 |
| HS Setup parity (in progress) | Research: every HROne Setup page's fields from its front end, gap matrices for all sections in `docs/hrone-parity/setup/` · Policy form engine (yes/no and option radios, times, lists, sections, follow-up questions, labelled view; refresh after draft/publish; policy-without-rules notice) · **Time office complete (TO-1 … TO-8)**: check-in policy (web/mobile controls, polygon and temporary geo locations, photos/WiFi/pings API, web check-in location and device), request rules (AR, on duty, shift and week-off change, short leave), attendance policy (punch mode, flexi, within shift, unpaid weekly off, entitlement, comp-off generation, absenteeism alerts, request lock), shift master (absence criteria, cut-off, per-shift requests, auto shift, shift allowance → payroll), holiday calendars (RH proration, FY basis, cancellation lock, shift requirement), leave-type rules (eligibility, limits, backdating per role, birthday window, clubbing, carry forward, credit cap/rounding, encashment), biometric sync servers and silence alerts | Time office PARTIAL leftovers listed in the matrix (late deduction from leave priority, OT formulas, encashable balances by quarter, credit by attendance, lapse alert). Next sections: Payroll, Workforce, Core HR, Automation & inbox, Governance, Access rights, Performance, Engage, Expense (new module), Asset, Helpdesk, Integration, Forms & templates — build packages in each matrix |

## How progress is tracked

Each phase records what was built, the tests that cover it and anything deferred in `docs/architecture/hrone-parity.md`. An item is closed only when it has code, a policy/permission entry, data-inventory classification, and Pest coverage.
