# PeopleOS Organisation Administrator: module coverage and gap register

**Generated:** 2026-11-18 by `tools/build/registers.js`.
**For:** the people who maintain and review the PeopleOS Organisation Administrator training. How much of each module the training covers, and what could not be covered or confirmed.

| Module | Screens in the application | Screens with a screenshot | Screens explained | Callouts placed | Procedures | Calculations | Warnings | Troubleshooting rows | Status |
|---|---:|---:|---:|---:|---:|---:|---:|---:|---|
| M01 Sign in, Home and navigation | 1 | 1 | 1 | 7 | 13 | 2 | 5 | 10 | Covered |
| M02 My work and approvals | 7 | 7 | 7 | 33 | 8 | 1 | 4 | 8 | Covered |
| M03 Organisation structure | 36 | 36 | 36 | 201 | 4 | 0 | 5 | 10 | Covered |
| M04 Masters and configuration | 43 | 43 | 43 | 207 | 5 | 1 | 5 | 10 | Covered |
| M05 Users, access and integrations | 25 | 25 | 25 | 128 | 11 | 3 | 12 | 12 | Covered |
| M06 Workflows and policies | 7 | 7 | 7 | 54 | 8 | 3 | 7 | 9 | Covered |
| M07 Employees and the employee profile | 11 | 11 | 11 | 69 | 9 | 1 | 3 | 15 | Covered |
| M08 Joining, changes and letters | 18 | 18 | 18 | 87 | 11 | 2 | 4 | 16 | Covered |
| M09 Exit and alumni | 7 | 7 | 7 | 33 | 8 | 2 | 4 | 15 | Covered |
| M10 Attendance and time office | 34 | 34 | 34 | 175 | 10 | 7 | 7 | 12 | Covered |
| M11 Leave | 9 | 9 | 9 | 44 | 8 | 8 | 5 | 10 | Covered |
| M12 Compensation and salary structures | 17 | 17 | 17 | 75 | 11 | 6 | 9 | 20 | Covered |
| M13 Payroll | 20 | 20 | 20 | 92 | 11 | 10 | 6 | 15 | Covered |
| M14 Statutory compliance and tax | 22 | 22 | 22 | 56 | 13 | 7 | 8 | 16 | Covered |
| M15 Expense and travel | 19 | 19 | 19 | 103 | 12 | 6 | 7 | 15 | Covered |
| M16 Performance | 34 | 34 | 34 | 183 | 7 | 4 | 7 | 10 | Covered |
| M17 Learning, skills and talent | 35 | 35 | 35 | 151 | 7 | 2 | 6 | 8 | Covered |
| M18 Assets | 9 | 9 | 9 | 68 | 10 | 2 | 5 | 10 | Covered |
| M19 Workforce planning | 15 | 15 | 15 | 83 | 11 | 5 | 6 | 10 | Covered |
| M20 Helpdesk, grievances and knowledge | 19 | 18 | 19 | 113 | 13 | 5 | 6 | 13 | Partly covered (19/19 explained) |
| M21 Communication and engagement | 25 | 24 | 25 | 145 | 15 | 3 | 6 | 12 | Partly covered (25/25 explained) |
| M22 Reports and analytics | 12 | 12 | 12 | 78 | 9 | 5 | 4 | 11 | Covered |
| M23 Audit, change history and data import | 6 | 6 | 6 | 28 | 12 | 2 | 10 | 14 | Covered |
| M24 Daily, monthly and annual routines | 0 | 0 | 0 | 0 | 5 | 0 | 1 | 0 | Process module (no screens of its own) |
| M25 Troubleshooting | 0 | 0 | 0 | 0 | 1 | 0 | 1 | 0 | Process module (no screens of its own) |
| M26 Signing out and staying secure | 0 | 0 | 0 | 0 | 5 | 1 | 4 | 5 | Process module (no screens of its own) |

## Gaps, limitations and unverified items

- **M01** Sign-in, forgot/reset password, invitation, two-factor, expired-password, Account security and Preferences pages are not in the crawl; described from source code only.
- **M01** There is no separate Help menu in the workspace; help comes from page subheadings, the ? shortcuts list, Ask PeopleOS and Knowledge articles.
- **M01** Remember me duration and the server session lifetime are service settings not visible to the organisation (framework default for Remember me is about 400 days); confirm with PeopleOS support.
- **M01** When an idle timeout or an ended session signs a person out, the sign-in page does not show the reason (the message is stored but not displayed).
- **M01** The number of actions in the Actions panel for this role and the rail's Pinned people / Recent lists were not captured.
- **M01** The rail's Recent list fills only from items opened through Ctrl K search, not from every page visited.
- **M01** Approvals (Approval Center, /admin/approvals) is not listed in the rail for this role; it is reached from Home, G then A, or the phone bar.
- **M02** Out of office (delegation) is not on any assigned screen and was not captured; its menu position under Workflows is taken from source.
- **M02** The Approval Center page (Approvals) is reached from My work › Decisions › Approval Center; it is not an assigned screen here.
- **M02** The administrator in the example has no employee record, so My requests, Team HR requests and own-request types show empty.
- **M02** No inbox action (send back, bulk approval, discussion, delegate, archive) is switched on in the example, so those controls are described from source.
- **M02** The Preferences link on Notifications (channel preferences) was not followed.
- **M03** Company Time zone and Currency are stored on the company card; no calculation was found that uses them, so their effect is not confirmed.
- **M03** A location cannot be linked to an establishment on screen; employees fall back to the company's principal establishment unless assigned explicitly.
- **M03** The hire and change selectors list units by name without filtering out Inactive ones (from the source); not checked in the running screens.
- **M03** Department Cost centre is not confirmed to become the default cost centre on employee positions.
- **M03** The Org map content depends on reporting relationships kept on employee records (M07); it was captured with the example organisation's lines only.
- **M04** Minimum-wage skill category: recorded and shown on the employee's profile; its use in minimum-wage checks or the wages register was not confirmed.
- **M04** Feature flags organisation.designer and security.mfa, and settings locale.date_format, locale.time_format and branding.primary_colour, are not read anywhere: no effect today.
- **M04** There is no organisation-level date format, time format or financial year setting for the administrator; the payroll and tax year starting in April is set by PeopleOS.
- **M04** Settings and Feature flags are classed high risk but their screens save directly; approval of these is not possible today.
- **M04** Change Centre view of a pending change was captured only as a scheduled change; the Approve, Reject and Roll back states need extra screenshots.
- **M05** Wizard screens were captured on their first step only; fields of later steps (user Roles and Access scope, role Permissions, group data and members, security Passwords to Warehouse, SSO Provisioning, integration Inbound security) are described from source.
- **M05** Out of office (task delegation) is described in M02/M06; in the example organisation no request type is switched on for delegation.
- **M05** There is no dedicated access-review screen; the quarterly review uses the Users list, Roles by person and the Report library templates.
- **M05** Membership-rule condition fields (which units can be used) were not enumerated from the rule field catalogue.
- **M05** Webhook event list is long (about 70 events); only examples are given.
- **M05** The example organisation has no API keys, webhooks, SSO connections, integration systems, JV codes, exchange rates or product ideas, so screens are shown empty.
- **M05** Exact menu path of Out of office and of the user's own MFA set-up page (profile) were not confirmed from the crawl.
- **M06** The builder modal, the run view and the later wizard steps were not captured; their fields are described from source.
- **M06** The example organisation has no runs and no workflow on any request type, so no request lifecycle can be shown from real data.
- **M06** The two-level leave example (condition on days) is built from source; it was not run in the example organisation.
- **M06** Saving a draft policy with applicability already stores active rules; the effect on employees is described from source, not observed.
- **M06** The example Probation confirmation workflow stamps the confirmation date from {{ context.today }}, which a manual run does not supply (unknown placeholders render empty); whether that step sets the date or fails was not verified.
- **M06** The list of fields available in Condition nodes beyond employee fields, subject.* and data.* depends on each trigger and is not listed in full.
- **M07** Exact labels of the Employees register Report list were taken from report keys, not the screen.
- **M07** Employee upload (creating employees from CSV) and the register Export are switched off in the example organisation, so they were not captured.
- **M07** The Employee hired completion panel and the duplicate notices were described from code; they need captures.
- **M07** The PeopleOS Intelligence block on the 360 was seen in the crawl but its content depends on the AI policy and was not documented.
- **M07** Health insurance, Nominations and Passport & licence tabs are described only at tab level (their own forms not detailed).
- **M08** No background checks, confirmation reviews, transfer requests, contracts, schedules or shares exist in the example organisation, so those row actions were described from code.
- **M08** The More menu on a letter page was not expanded in the crawl; its exact items (e.g. Revoke) are from code.
- **M08** Approving a transfer request whose date overlaps the current position may show an error page rather than a message; not confirmed on screen.
- **M08** Close case on a background check may also affect In progress checks of other cases (seen in code, not confirmed on screen); verify before teaching Close case.
- **M08** The create-button label of the Checklist items tab was not captured.
- **M09** Vikram Singh has no sign-in, so the alumni portal could not be shown with real data.
- **M09** The Accept / reject resignation and withdrawal approval steps are switched off in the example organisation and were described from code.
- **M09** Clear, Block and Complete exit modals were not captured: no case is in Clearance in the example organisation.
- **M09** Gratuity amounts were not shown: the only settlement is not covered by the gratuity rule.
- **M09** The F&F column and Exit interview field show raw status words (e.g. approved, submitted) rather than labels.
- **M10** Half day on a holiday is stored and shown but not used in the calculation (day treated as a full holiday).
- **M10** Paid days screen and payroll differ for Incomplete days and unpaid weekly offs; a half-day leave with a worked other half may also read 0.5 on Paid days (not verified with data).
- **M10** Close month / Reopen month and Adjust paid days are switched off in the example organisation, so they were not captured.
- **M10** Create from holiday library is not shown: no published holiday lists in the example organisation.
- **M10** Device 'A sync agent reads the attendance server' option: availability of the agent not confirmed; Geo dashboard has no map; split shifts not available.
- **M11** Hourly leave requests are not available (types in hours are refused).
- **M11** Leave counting has no prefix/suffix sandwich rule; only count or skip weekly offs and holidays per leave policy.
- **M11** No encashments, donations or unpaid leave exist in the example organisation, so those examples are illustrative.
- **M11** Leave policy screens (Workflows › Policies) belong to M06 and were not described here.
- **M11** Leave calendar is a list, not a grid.
- **M12** PeopleOS does not calculate arrears for back-dated revisions: dates inside finalized payroll are refused and the difference must be entered as an arrear adjustment.
- **M12** Process bonus is blocked in the example organisation: the BONUS rule (maintained by PeopleOS) is not verified, so no bonus lines can be shown.
- **M12** Statutory rules in the example data are not verified: the Assign salary preview shows 'No verified rule: not estimated', and the PF figures used in the calculations come from rule versions under review.
- **M12** The example organisation has one structure, salaries for only four current employees, and no cycles, ranges, budgets, uploads or variable pay, so those screens were captured empty.
- **M12** Only step 01 of the component, builder and structure-edit wizards was captured; later steps, the Versions table rows, Preview, the Assign salary wizard and the Employee 360 propose form are described from source.
- **M12** Component default formulas are not versioned: an edit applies at once to every structure line without an override.
- **M12** Only the monthly pay frequency is supported.
- **M12** The exact field labels on the upload page's Map columns step and the cycle page (populate and line adjust forms) were taken from source, not from a capture.
- **M13** Close payroll, payslip publishing, bank file, journal voucher, Mark as paid and payslip view cannot be shown with the example data: the run cannot be validated while statutory rules are unverified.
- **M13** Payslip PDF download is not built yet; payslips are viewed on screen only (My payslips and People › Pay › Payslips).
- **M13** Approval is refused only to the person who started (opened) the run; the code does not also check who calculated it.
- **M13** The Adjustments form cannot record the earlier month an arrear or recovery is for (only the Salary arrear upload records it), and does not stop duplicate entries.
- **M13** Arrears from back-dated salary revisions are not worked out automatically; a revision inside a paid month is refused and the difference is paid as an arrear.
- **M13** The captured loan view (/admin/loans/1) is a pending request with no instalments yet; the Instalments table is described from source. /admin/loans/2 (approved, 12 instalments) is a better capture.
- **M13** Whether a joiner's days before joining raise an 'attendance incomplete' warning depends on how attendance records those days; not confirmed.
- **M13** The Issues column shows some exception types as raw codes (unverified_statutory_rule, statutory_change_pending) because they have no display label.
- **M14** No statutory return, challan, TDS deductor profile, ledger or Form 130 certificate exists in the example organisation; return view pages and the filing steps are described from source, not captured.
- **M14** No rule version is verified and 5 notices are open, so the November 2026 run cannot be validated and no month can be finalised; the end-to-end return flow cannot be shown with data.
- **M14** No employee in the example organisation has statutory details (PAN, UAN, ESIC number) recorded, so every entry warns 'PAN not on file' and an ECR would fail on missing UANs.
- **M14** Declare investments was captured with only the Employee and Financial year selectors (the administrator has no employee record); the wizard steps are from source.
- **M14** There is no screen to request a correction of an establishment statutory profile for months already approved or finalised; the error message mentions a correction but customers must ask PeopleOS support.
- **M14** Export layouts (0 of 5) and the Form 138 file schema are unverified, so under enforcement exports are refused and the Form 138 export is only a working schedule.
- **M14** Gratuity has no screen of its own in this module: it appears in the gratuity register (Compliance reports) and in full and final settlements (M09); no gratuity rule version is verified.
- **M14** Not modelled today: ESI coverage continuing to the end of a contribution period (warned, not applied), EPS eligibility, the EPF 10% option, ESI disability exemption, state LWF manager and supervisor exclusions.
- **M14** Income tax settings (declaration and proof windows, verified-proofs date) live on Admin › Setup › Payroll (M04); not captured in this module.
- **M14** The approval workflow that can verify or return submitted declarations (Workflows) was not checked on screen.
- **M15** The new and edit expense category screens were captured on step 1 only; the field list for the later steps comes from the form source.
- **M15** The example organisation has no advances, trips, location groups, mileage categories or expense advance policy, so those screens are empty; flows are described from source.
- **M15** The administrator's own Add expense, New expense report, Request an advance and Request travel buttons appear only for a user linked to an employee record; the demo administrator sees only the on-behalf actions.
- **M15** Distance from a map needs a maps provider that is not connected; places are typed in. A project field on expenses and trips is not available.
- **M15** The policy screens (Workflows › Policies) belong to M06; the exact button names there were not confirmed here.
- **M15** Approvals page and workflow set-up for expense triggers are covered in M02/M06; not verified on screen for this module.
- **M16** The example organisation has no goal sheet, performance, PIP or 360 feedback policy, no goal templates, KRAs, KPI formulas, PIPs, initiatives, check-ins, one-on-ones, calibration sessions or 360 rounds: those screens were captured empty.
- **M16** Review forms (Self review, Manager review, Approver review) and the items under the 'More' menus were not captured: the administrator sign-in is not linked to an employee and menus were not opened. Described from source.
- **M16** Modal and wizard forms (Normalise ratings, Distribution targets, Bands, Launch 360 feedback, Open plan, Extend window, Add employees) are described from source, not from the crawl.
- **M16** The cycle is at Self review, so the Calibration board's targets chart, finalize and publish flow and the 9-box placements could not be shown with real ratings; calculations of the appraisal rating are illustrative.
- **M16** The Performance Admin role template holds no performance permissions by default (only company, organisation and people set-up view); confirm with the customer which rights it should carry.
- **M16** Several performance policy answers are marked '(recorded)' and are not applied yet (goal cycle, manual goal cycle start roles, joiner goals, acknowledgement roles, rounding, editing system ratings).
- **M17** The example organisation has no providers, instructors, learning paths, programs, certificates, skill assessments, development plans, career tracks or profiles, role requirements, critical positions, succession plans, talent pools or reviews: those screens were captured empty.
- **M17** Modal forms (Publish version for paths, programs and career paths, Assign learning, Record external certificate, New assessment, New plan, Designate critical position, Add successor, New talent review) are described from source, not from the crawl.
- **M17** The items under 'More' on the enrolment view and the Attendees list on the session edit page were taken from source and the screenshot; the crawl did not list them.
- **M17** Self-service screens employees use (My learning, My career, Register me) are not visible to the administrator sign-in, which is not linked to an employee.
- **M17** Whether a succession plan or talent review is approved by a workflow depends on a set-up made by PeopleOS; without it, activating a plan and completing a review take effect at once.
- **M18** The In transit status exists in the status list, but no button or upload sets it; assets cannot be put in transit today.
- **M18** The asset type's 'IT asset (IT clearance at exit)' flag is stored but does not change the exit clearance: the Asset clearance lists every asset held, whatever the flag.
- **M18** Depreciation schedules, stock counts (asset audits) and barcode scanning are not available; book value is a simple straight-line figure.
- **M18** Assets, asset types, sub types and products have no delete action; only Inactive status (types, sub types, products) or Retire / Dispose (assets).
- **M18** The example organisation has no sub types, asset requests, repairs or asset policy, so those screens show empty states; dialogs and the Setup asset page were not captured.
- **M19** The example organisation has no positions, plans, scenarios or budgets, so these screens were captured empty; the position view page, Lifecycle menu, plan actions and forecast were not captured.
- **M19** The position view has two tabs both titled History (versions and audit trail); their exact on-screen order was not confirmed.
- **M19** Modal forms (New position, New plan, New budget, Add line) open as step wizards named Details / More details / Review; these were not captured.
- **M19** Lifecycle moves and which position changes need approval are set by PeopleOS configuration; ask PeopleOS support to change them.
- **M19** Workforce analytics' subtitle mentions joiners and leavers over time, but the page shows today's capacity only.
- **M19** If your organisation has a plan approval workflow, Start review, Approve, Reject and Return are not shown on the plan; the decision is made in the workflow task.
- **M20** Service analytics figures could not be shown: the example organisation has requests from fewer than 5 employees.
- **M20** The administrator login has no employee record, so Raise a grievance, My HR and the article acknowledgement button were not seen as an employee sees them.
- **M20** Access granted to a restricted request or a grievance cannot be withdrawn from the screen; ask PeopleOS support if it must be removed.
- **M20** Article review needs a second person with kb.review; the Review action was not captured.
- **M20** Employee-side raising (My HR, New request, Request a service) and the Helpdesk access policy belong to other modules and are only referred to here.
- **M20** Request, grievance and article notices go by in-app and email only (see M21: SMS, WhatsApp and Push are not connected); which events notify whom depends on your notification rules.
- **M20** Screen did not open for the administrator: Services › Knowledge › Articles (edit, HTTP 403)
- **M21** Notification rules and templates: no add or delete for administrators (missing create/delete permissions); confirm with PeopleOS whether this is intended.
- **M21** The Engage desk screenshot was captured mid fade-in; it needs a re-shoot.
- **M21** Announcement edit could not be shown with a draft: both example announcements are published.
- **M21** No audiences, campaigns, events, fundraisers, feedback or mission statements exist in the example organisation; those screens show empty states.
- **M21** Reward points, the mood bot and the social wall are off in the example organisation; their working screens could not be captured.
- **M21** Survey results, the Announcements feed and the social wall are reached outside the assigned screens; the employee views were not seen (the administrator has no employee record).
- **M21** The event and fundraiser view pages, survey results page and the Setup › Engage panel were not in the assigned screens; described from source only.
- **M21** Polls are not available in the product today.
- **M21** Screen did not open for the administrator: Communication › Messaging › Announcements (edit, HTTP 403)
- **M22** The crawl captured the Report library area headings and 231 rows but not each row's text; the catalogue was taken from the report library definitions in the source.
- **M22** Report builder steps 2–6, the Schedules form, the Use dialog and the dashboard Widgets step were not captured.
- **M22** No reports have been exported or scheduled in the example organisation, so Runs and Schedules are empty.
- **M22** People cost, cost per head and payroll widgets show "—" in the example organisation because no payroll is finalized.
- **M22** Exports are CSV only; there is no PDF or native Excel export.
- **M22** Critical skills are empty in the example organisation (no skills recorded).
- **M22** Change intelligence and the area detail pages linked from the overviews belong to other modules.
- **M23** The example organisation has no uploads, so Recent uploads (empty) and Data import steps 2–4 were described from source; captures needed.
- **M23** New hire, New hires (advance), Employee and Personal details are hidden in the example organisation (bulk employee import not switched on); described from source only.
- **M23** The per-area upload screens (Data uploads, Workforce, Time office and Payroll uploads) where checkers approve and run files are outside this module and were not captured; button names come from source.
- **M23** Each upload's exact columns are not listed here: the on-screen format guide of each of the 106 processes is the reference.
- **M23** The Employee statutory about-text on the page mentions LWF, but the upload does not take LWF (it follows the establishment's statutory profile).
- **M23** Change history has no export action on screen; whole-organisation exports belong to Data export (M05). Verify integrity results of the nightly PeopleOS check are not shown to the organisation.
- **M26** There is no 'sign out of all my devices' button for your own account; changing your password is the way.
- **M26** After an automatic sign-out (idle, ended session, suspended account) the sign-in page shows no reason.
- **M26** There is no per-account lock after repeated wrong passwords, only a short pause per network address.
- **M26** Without an idle timeout, how long an unused session lasts is a service setting; ask PeopleOS support for the value.
- **M26** No sign-out, Account menu or pay-password screens were captured; described from source code.
