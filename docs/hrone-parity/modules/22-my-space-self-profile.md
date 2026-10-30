# HROne vs PeopleOS — 22 My space and the employee self profile (field by field)

Prepared 2026-10-29, analysis only (no code, no tests, no HROne access). Recruitment is out of scope (separate RMS):
such rows are N-A. Package prefix: **MS-**.

**Inputs.**

- `hrone-research/menu-permission-tree.md`, branch **"Profile [54]"** (the role page-access tree for My space) and the
  same nodes in `menu-catalogue.json` → `MenuAccessPermission/0` (`parentKey` 206), which also gives each node's grantable
  actions (most are "View"; Profile View / Create; Goals and initiatives Goal view / Initiatives create / complete / view;
  Performance scorecard Performance scorecard / Initiatives / 1 on 1 / 360 feedback / Continuous feedback / Training;
  Team announcement View / Create; Social profile "Download id card"; QR Code and Trips none).
- `hrone-research/pages.jsonl` (latest line per id): `rail_My_space` (+ `__favorite`, `__CTC`, `__HR_Handbook`,
  `__Leave_balance`, `__Payslip`, `__Profile`), `self_app_myprofile`, `self_app_myprofile_profile`,
  `self_app_myprofile_social_profile`, `self_section_*` (Favorites, Social profile, Attendance, Snapshot, Personal,
  Family, Professional, Health Insurance, Employees Other Details, Bank, Previous Employer Details, Documents, History),
  `menu_My_space__*` (Social profile, Attendance, CTC, Goals and initiatives + tabs Team / Company, HR Handbook, Holiday
  calendar, Leave balance, Letter, Payslip, Performance scorecard + tabs Completed initiatives / 1 on 1 / 360 feedback /
  Continuous feedback, Profile, View all); and, from the follow-up "View all" pass, the `myspace_*` pages (pass
  `myspace`): `myspace_view_all` (the "All Searches" dialog) and one page per item — 1_on_1, Asset_request, Attendance,
  Attendance_regularization, Attendance_ytd, Badges_and_rewards (+ tabs Reward points, Reward ledger), Change_shift,
  Clearance (+ tab Pending), Compensatory_off, Confirmation, Confirmed_investment_history, CTC, Exit_interview,
  Expense_advance, Expense_receipt, Expense_report, Fbp_approval_history, Geo_location, Goal_history,
  Goals_and_initiatives (+ tabs), Holiday_calendar, HR_Handbook, Leave, Leave_balance, Leave_encashment, Letter,
  Loan_history, Location_approval, My_assets, On_duty, Overtime, Payslip, Performance_scorecard (+ tabs), PIP,
  PIP_evaluation, Polls, Profile, Proposed_investment_history, Reimbursement_history, Restricted_holiday, Reward_store,
  Salary_ytd, Shift, Short_leave, Social_profile, Team_announcement, Ticket, Travel, Variable_history (`myspace_clear`,
  `myspace_Favorites` and repeated `myspace_view_all` lines ignored).
- `hrone-research/components.json`: `app-profile` (the 44 My space children), `app-my-profile-info` and the shared
  profile section forms `app-create-employee-{snapshot,personal,family,professional,health-insurance,other-details,
  bank-details,pre-employer-details,documents,history,signature,skills,contract,setting}`, `app-forms-document-upload`,
  `app-employee-comment-dialog`, `app-view-status-details`, `app-employment-history-dialog`, the approver / history
  views (`app-profile-home`, `app-profile-detail*`, `app-other-profile-detail`, `app-employee-document-profile`,
  `app-document-details`, `app-skills-detail`, `app-profile-history*`, `app-initiated-profile`, `app-upcoming-*`),
  `app-my-social-profile` → `app-social-profile`, `app-social-handlers`, `app-endorse-skill`, `app-select-contact-otp`,
  `app-send-otp*`, `app-share-qrcode-dialog`, `app-qr-code`, `app-my-calendar` → `app-emp-attendance`,
  `app-emp-punches-details-flyer`, `app-att-ytd-deduction`, `app-attendance-ytd-*`, `app-my-ctc`, `app-password-checker`,
  `app-update-salary-structure-step2`, `app-define-salary-structure`, `app-my-payslip`, `app-salary-ytd-*`,
  `app-employee-state-insurance`, `app-salary-voluntary-provident-fund`, `app-reimbursement-table`,
  `app-fbp-history-search`, `app-my-goals-and-initiatives` (+ `app-company-goal`, `app-team-goal`, `app-view-other-goal`,
  `app-initiative-details`, `app-check-in`, `app-goal-approval-view-form`), `app-my-performance` →
  `app-performance-view`, `app-my-policies`, `app-ack-hr-handbook`, `app-my-holiday`, `app-my-leave-balance` →
  `app-leave-balance-view`, `app-my-letters`, `app-acknowledge-letter*`, `app-my-assets`, `app-my-clearance`,
  `app-clearance-task-card`, `app-my-geo-location`, `app-approved-locations-flyer`, `app-my-wifi-details`,
  `app-my-one-on-one`, `app-schedule-one`, `app-my-polls`, `app-my-badges-and-rewards`, `app-points`,
  `app-rewards-credit-ledger`, `app-reward-store`, `app-redeem-history-drawer`, `app-employee-trips`,
  `app-my-profile-announcement`, `app-announcement`, `app-position-assigned-*`, `app-my-preferences`, and the per-type
  history lists `app-*-search` (confirmation, exit interview, PIP, PIP evaluation, goal, mark attendance, location
  approval, travel tickets).
- `hrone-research/routes.json` (`/app/myprofile/{coninvestment,proinvestment,fbphistory,loan,loanhistory,variable,salaryytd}/:pageType`).
- Page text and screenshots under the session scratchpad `hrone/deep/<id>/` for the 41 ids above and the `myspace_*`
  ids (structure only; no personal data copied).
- PeopleOS, checked in code: `app/Filament/Pages/{MyHr,MyDay,MyWork,MyCareer,MyCompensation,MyLearning,MyTeam,Home,SocialWall,DeclareInvestments,AttendanceCalendar,LeaveCalendar,Birthdays,Preferences,People,NotificationCenter,AnnouncementsFeed,AssistantPage}.php`,
  `app/Filament/Auth/EditProfile.php`, the avatar menu in `app/Providers/Filament/AdminPanelProvider.php` (My profile,
  My HR, My day, My career, My learning, My compensation, Preferences), the Employee 360 (`EmployeeResource`,
  `Pages/ViewEmployee.php` with the `employee.self` own-record path, `Schemas/EmployeeInfolist.php`, every relation
  manager and its `canViewForRecord`), the "Me" resources (`MyInvestmentProofResource`, `MyLeaveEncashmentResource`,
  `MyTaxCertificateResource`, `FeedbackRequestResource`) and the resources that switch to "Me" for non-HR viewers
  (Loans, Reimbursement claims, Variable pay requests / plans, Time-office requests, Location requests, Letters,
  Tickets, Expense advances / receipts / reports, Travel requests, Asset requests, Recognition, Reward points, Leave
  donations, Exit cases, Grievances, Articles), `PayslipResource`, `LeaveBalanceResource`, `LeaveTransactionResource`,
  `GoalResource`, `AppraisalResource`, `OneOnOneResource`, `FeedbackEntryResource`, `ImprovementPlanResource`,
  `AssetResource`, `AnnouncementResource`, `HolidayCalendarResource`; services `Employee360`, `PersonWorkspace`,
  `PersonPeek`, `PeopleVisibility`, `ExperiencePreferences`, `QuickActions`, `ExperienceNavigation`, `ModuleCatalogue`,
  `ProfileChangeGuard`, `ChangesProfileData`, `UpdatePersonAction`, the People / Employment change actions, the Service
  Desk profile domain actions (`app/Domain/ServiceDesk/DomainActions/*`), `DocumentRules`, `RewardPoints`,
  `LocationPing`; models `Person`, `PersonAddress`, `PersonFamilyMember`, `PersonEmergencyContact`,
  `PersonQualification`, `PersonExperience`, `PersonSkill`, `EmployeeStatutoryDetail`, `EmployeeBankAccount`,
  `EmployeeDocument`, `ExitClearance`, `OneOnOne`, `FeedbackEntry`, `CustomField`; `config/peopleos.php` (role
  templates, `servicedesk.domain_actions` / `service_defaults`, `experience.task_sources`, `people.*` vocabularies) and
  `config/peopleos_setup.php`; docs `docs/architecture/hrone-parity.md`, `docs/hrone-parity/setup/00-build-status.md`,
  `01-core-hr-access-integration.md` (§1.18–1.27, §5.4–5.6), `02-automation-forms-governance.md` (§03.4, workflow
  request types), `08-workforce-payroll.md`, `12-performance-engage.md`, `13-setup-reverification.md`, and
  `docs/hrone-parity/modules/20-requests-inbox-team.md` (request forms and request history lists, not repeated here).

**Progress.**

- Covered: all 54 nodes of the "Profile" branch (index in §22.0, every node has at least one counted row); the rail
  flyout, Favorites and the "View all" dialog (module chips, search, favourite hearts); the self profile with its ten sections and every field of their edit forms (from
  components), the change-reason, workflow, status, document-upload and PII-consent dialogs, the approver and history
  views of profile changes; the social profile (basic information, signature, about, hobbies, skills and endorsement,
  education, experience, badges, shared goals, planned leaves, work plan, social links, contact verification, QR code,
  ID card); attendance calendar and YTD; CTC; payslips; salary YTD with its statutory tabs; FBP history; goals and
  initiatives with its tabs and dialogs; performance scorecard with its seven tabs; HR handbook; holiday calendar; leave
  balance; letters; and the remaining My space items (1 on 1, assets, badges and rewards, clearance, confirmation, exit
  interview, geo location, Wi-Fi details, goal history, mark attendance, location approval, PIP, PIP evaluation, polls,
  reward store, team announcement, trips, position assigned, job requisition), plus My preferences (avatar menu).
- Pointed, not repeated: items that are request forms or request history lists already analysed field by field in
  matrix 20 (asset request, attendance regularization, change shift, compensatory off, expense advance / receipt /
  report, leave, leave encashment, loan, on duty, overtime, reimbursement, investment declarations, restricted holiday,
  short leave, travel, variable) keep one row here that names the matrix 20 section; the "Ticket" item (travel tickets
  booked by the company) points to the travel rows of matrix 20 and RQ-7a.
- Follow-up (same day): the "View all" pass captured every listed item except Job requisition, Mark attendance and
  Position assigned (listed in the dialog, not opened; rows from components). QR Code and Trips are not in the dialog at
  all (page-access tree only; rows from components). Rows first rated from components now carry the real URL and what the
  capture shows; new rows were added for the View all dialog (§22.1.1), the Salary YTD page (tabs, month grid, form
  download), the Badges and rewards tabs, the Reward store page, the Geo location card and the Attendance YTD filters. A
  correction: the My space item **"Ticket" is the list of travel tickets booked by the company**
  (`/app/myprofile/tickets`), not helpdesk tickets (§22.14.2 #14).
- Limits of the captures: the crawl account has no data for most history lists (requests, PIP, confirmation, exit
  interview, polls, announcements, assets, clearance), so those pages show only the title, the help line and the empty
  state; their columns and filters still come from components (and matrix 20 §20.1.19). Salary YTD stopped at its password
  gate and Attendance YTD and Shift at a loader (structure from the skeleton, the APIs and components). The account has no
  goals, reviews, initiatives, 1-on-1s, letters or handbook entries, so card contents there are taken from component text.
- Re-rated after HP-8j (initiatives) merged on 2026-10-29, checked in code (`InitiativeResource`, `Initiative`,
  `InitiativeFeedback`, `Initiatives` service, `InitiativeFeedbackTaskSource`, permission `performance.initiatives` in the
  employee template): §22.8 #11 MISSING → HAVE, §22.8 #12 MISSING → PARTIAL (no comments; GO-8), §22.9 #5 MISSING →
  PARTIAL (data exists; the scorecard page is MS-13); MS-13 and MS-14 no longer depend on HP-8j. Same corrections as
  matrix 27 §27.17.
- Result: 372 rows — HAVE 128, PARTIAL 136, MISSING 92, N-A 16 (see "Counts"; before the HP-8j re-rating 127 / 134 /
  95 / 16; the first version had 351 rows, 124 / 129 / 86 / 12); 17 build packages MS-1 … MS-17; 11
  owner decisions D-MS1 … D-MS11.

**Status key.** HAVE: the employee can see / do the same today. PARTIAL: something exists but does not cover the HROne
element (often: the data exists but the employee cannot see it, or HR can do it but the employee cannot). MISSING:
nothing equivalent. N-A: UI chrome, vendor marketing, HR-only parts of a shared component, or recruitment.
*(inferred)* marks a meaning taken from a control name because HROne's label or behaviour was not captured.

**Conventions used in the build notes** (same as matrix 20): additive changes whose defaults reproduce today's
behaviour; per-group options as Policy Engine type fields; organisation switches as typed settings in
`config/peopleos_setup.php`; new models tenant-scoped (`BelongsToTenant` + `Auditable`, `HasEffectiveDates` where history
matters), classified in `DataInventory`, guarded by a policy; sensitive values encrypted, audit-excluded where the audit
would copy them, and masked to anyone without the sensitive permission; every self-service write goes through the
owning domain's action (People / Employment change actions with `ProfileChangeGuard`, `ChangeOrigin`) and first calls
`SelfServiceLock::assertOpen`; approvals through a workflow trigger (P5 bridge) or a tagged `ApprovalSource`;
permissions declared in `config/peopleos.php` then `php artisan peopleos:sync-permissions`; statutory values never
hard-coded (rule data).

---

## 0. PeopleOS building blocks this matrix relies on

| Building block | Where | Relevance |
|---|---|---|
| Avatar ("You") menu: My profile (own Employee 360), My HR, My day, My career, My learning, My compensation, Preferences; "Me" navigation group (space "You" in `ModuleCatalogue`, section Home in `ExperienceNavigation`) | `AdminPanelProvider::userMenuItems`, `ModuleCatalogue`, `ExperienceNavigation` | HROne rail "My space" flyout and "View all" |
| Own Employee 360, read-only: a holder of `employee.self` opens only their own record (`ViewEmployee::needsResourceAccess`, `EmployeePolicy::isOwnRecord`); header, person workspace, "All details", relation tabs whose `canViewForRecord` passes (Timeline, positions, reporting, attendance, leave, performance, goals, learning, skills, payslips, addresses, family & nominees, emergency contacts, qualifications, experiences); bank accounts and statutory details need `employee.sensitive.view`; documents tab needs `document.view` | `app/Filament/Resources/Employees/*` | HROne Profile sections |
| Profile changes requested by the employee through HR services with domain actions: `profile.address`, `profile.bank_account` (approval), `profile.statutory_identity` (PAN / UAN, approval), `profile.emergency_contact`, `profile.family_member`; seeded as DRAFT services (Address change, Bank account change, PAN / UAN update, Emergency contact update) that HR completes and approves; values masked to the requester and purged after execution; executed by the People / Employment actions (`ProfileChangeGuard`, CA-4 linked documents) | `config/peopleos.php` → `servicedesk.domain_actions`, `service_defaults`; `app/Domain/ServiceDesk/DomainActions/*`; `QuickActions` "Update bank details" | HROne "EDIT" on a profile section |
| Employee self-edits that exist: celebrated birthday and wedding anniversary (day / month, shared or private) on Celebrations (`UpdatePersonAction`) | `Birthdays.php` | Precedent for MS-3 |
| My HR: requests, tasks, approvals, documents (own documents, missing mandatory ones, "Upload a document" for types employees may upload, sample, HR verifies), letters, policies (acknowledge), services, surveys, feedback, communications, notification preferences; "Your record" | `MyHr.php`, `DocumentRules` (CA-4) | HROne Documents, Letter, HR Handbook |
| My compensation (PS-3 switches), payslips (own, HTML view, viewed-at, audited), My tax certificates (Form 16), Declare investments wizard (incl. previous employer), My investment proofs | `MyCompensation.php`, `PayslipResource`, `MyTaxCertificateResource`, `DeclareInvestments.php`, `MyInvestmentProofResource` | HROne CTC, Payslip, investment history, Previous employer |
| Attendance calendar (own month: day codes, in / out, late, holiday, shift, trip mark; summary; holidays of the month; printable slip), My day (punch, balances, latest payslip, apply leave, regularise, resign), leave balances / transactions / donations (own) | `AttendanceCalendar.php`, `MyDay.php`, Leave resources | HROne Attendance, Holiday calendar, Leave balance |
| Performance self views: goals (own + organisation-level goals), appraisals (own, ratings after publication, HP-8d scorecard hidden fields), one-on-ones, continuous feedback, check-ins, improvement plans, feedback requests (exit interview, confirmation, probation, induction) | Performance resources, `FeedbackRequestResource` | HROne Goals and initiatives, Performance scorecard, PIP, Exit interview |
| Engage self views: recognition (received / given, certificate, LinkedIn share), reward points (own ledger, give, redeem through payroll), social wall, celebrations, mood check-in | `RecognitionResource`, `RewardPointEntryResource`, `SocialWall.php`, `Birthdays.php`, `Home.php` | HROne Badges and rewards, Reward store, Social profile |
| People directory and person peek (name, title, team, location, manager; link to the 360 when allowed) | `People.php`, `PersonPeek`, `PeopleVisibility` | HROne Social profile of a colleague |

---

## 22.0 Index of the 54 My space items (page-access tree "Profile")

Not counted in "Counts" (each item's counted rows are in the section named). Overall status = the weakest material row.

| # | HROne My space item | Rows | PeopleOS today (headline) | Overall |
|---|---|---|---|---|
| 1 | 1 on 1 | §22.14.1 | One-on-ones (own), schedule, feedback card | PARTIAL |
| 2 | Asset request | §22.14.2 → 20 §20.1.17, §20.1.19 #15 | Asset requests (own) | HAVE |
| 3 | Attendance | §22.4 | Attendance calendar | PARTIAL |
| 4 | Attendance regularization | §22.14.2 → 20 §20.1.8, §20.1.19 #3 | Regularisations (own) | PARTIAL |
| 5 | Attendance ytd | §22.14.3 | — (HR report "Paid day YTD") | MISSING |
| 6 | Badges and rewards | §22.14.4 | Recognition, reward points | PARTIAL |
| 7 | Change shift | §22.14.2 → 20 §20.1.18, §20.1.19 #8 | Time-office requests | PARTIAL |
| 8 | Clearance | §22.14.5 | Exit clearances (owner notified; acts on the exit case) | PARTIAL |
| 9 | Compensatory off | §22.14.2 → 20 §20.1.19 #9 | Time-office comp-off claims | PARTIAL |
| 10 | Confirmation | §22.14.6 | Confirmation reviews (HR); feedback requests | PARTIAL |
| 11 | Confirmed investment history | §22.7 → 20 §20.1.6, §20.1.19 #19 | Tax declarations, proofs | PARTIAL |
| 12 | CTC | §22.5 | My compensation | PARTIAL |
| 13 | Exit interview | §22.14.7 | Feedback requests (exit interview) | HAVE |
| 14 | Expense advance | §22.14.2 → 20 §20.1.11, §20.1.19 #12 | Expense advances (own) | HAVE |
| 15 | Expense receipt | §22.14.2 → 20 §20.1.12, §20.1.19 #20 | Expense receipts (own) | HAVE |
| 16 | Expense report | §22.14.2 → 20 (expense reports) | Expense reports (own) | HAVE |
| 17 | Fbp approval history | §22.7 | — (FBP not built, BP-7) | MISSING |
| 18 | Geo location | §22.14.8 | Location requests (own) | PARTIAL |
| 19 | Goal history | §22.14.9 | Goals list (own, cycle / status) | HAVE |
| 20 | Goals and initiatives | §22.8 | Goals; My initiatives (HP-8j) | PARTIAL |
| 21 | Holiday calendar | §22.11 | Attendance calendar holidays (month) | PARTIAL |
| 22 | HR Handbook | §22.10 | HR articles / My HR policies, acknowledge | HAVE |
| 23 | Job requisition | §22.14.10 | — | N-A (recruitment) |
| 24 | Leave | §22.14.2 → 20 §20.1.18, §20.1.19 #4 | Leave requests (own) | PARTIAL |
| 25 | Leave balance | §22.12 | Leave balances, ledger, donations | PARTIAL |
| 26 | Leave encashment | §22.14.2 → 20 §20.1.18, §20.1.19 #11 | My encashments | HAVE |
| 27 | Letter | §22.13 | Own issued letters | PARTIAL |
| 28 | Loan history | §22.7 → 20 §20.1.3, §20.1.19 #17 | Loans (own) | HAVE |
| 29 | Location approval | §22.14.11 | Location requests (own) | HAVE |
| 30 | Mark attendance | §22.14.12 | Web punch; HR overrides | PARTIAL |
| 31 | My assets | §22.14.13 | Assets (custodian) | HAVE |
| 32 | On duty | §22.14.2 → 20 §20.1.18, §20.1.19 #5 | Time-office requests | PARTIAL |
| 33 | Overtime | §22.14.2 → 20 §20.1.19 #10 | Time-office overtime claims | PARTIAL |
| 34 | Payslip | §22.6 | Payslips (own, HTML) | PARTIAL |
| 35 | Performance scorecard | §22.9 | Appraisals (own, after publication) | PARTIAL |
| 36 | PIP | §22.14.14 | Improvement plans (own) | HAVE |
| 37 | PIP evaluation | §22.14.14 | Improvement plan outcome (HP-8f) | HAVE |
| 38 | Polls | §22.14.15 | — (surveys only) | MISSING |
| 39 | Position assigned | §22.14.16 | — | N-A (recruitment) |
| 40 | Profile | §22.2 | Own Employee 360 (read-only) + HR services | PARTIAL |
| 41 | Proposed investment history | §22.7 → 20 §20.1.5, §20.1.19 #19 | Tax declarations | PARTIAL |
| 42 | QR Code | §22.3 #23 | — | MISSING |
| 43 | Reimbursement history | §22.7 → 20 §20.1.4, §20.1.19 #18 | Reimbursement claims (own) | PARTIAL |
| 44 | Restricted holiday | §22.14.2 → 20 §20.1.7, §20.1.19 #6 | Time-office requests | PARTIAL |
| 45 | Reward store | §22.14.4a | Payroll redemption only (vendor catalogue EXT) | MISSING |
| 46 | Salary ytd | §22.7.1 | — (HR report template) | MISSING |
| 47 | Shift | §22.14.17 | Shift code per day on the calendar | PARTIAL |
| 48 | Short leave | §22.14.2 → 20 §20.1.9, §20.1.19 #7 | Time-office requests | PARTIAL |
| 49 | Social profile | §22.3 | Directory peek | PARTIAL |
| 50 | Team announcement | §22.14.18 | Announcements (HR only) | PARTIAL |
| 51 | Ticket (travel tickets booked by the company) | §22.14.2 #14, #16 | Travel bookings on the trip (EX-F) | PARTIAL |
| 52 | Travel | §22.14.2 → 20 §20.1.16, §20.1.19 #14 | Travel requests (own) | PARTIAL |
| 53 | Trips | §22.14.19 | Location pings stored, no view | PARTIAL |
| 54 | Variable history | §22.7 → 20 §20.1.15, §20.1.19 #16 | Variable pay requests (own) | HAVE |

---

## 22.1 Rail "My space" flyout, Favorites, "View all" and My preferences

Pages `rail_My_space` (+ `__favorite`), `menu_My_space__View_all`, `self_app_myprofile`; flyout items Favorites, Social
profile, Attendance, CTC, Goals and initiatives, HR Handbook, Holiday calendar, Leave balance, Letter, Payslip,
Performance scorecard, Profile, VIEW ALL. My preferences from `app-my-preferences` (avatar menu).

| # | HROne element (tab / field / column / action) | What it does (inferred where unclear, mark *(inferred)*) | PeopleOS today (file or screen) | Status | Build note |
|---|---|---|---|---|---|
| 1 | Rail "My space" flyout (12 items + VIEW ALL) | One launcher for the person's own pages | Avatar menu (My profile, My HR, My day, My career, My learning, My compensation, Preferences) and the "Me" group (space "You"); the rest (loans, claims, time office, letters, expense, travel, assets, recognition, points…) appear under "Me" only for viewers without the module's HR permission | PARTIAL → built (MS-1: "My space" page in the Me group and the avatar menu; 68 items in six groups, each under its page's own `canAccess()`) | MS-1: "My space" page listing every self destination the viewer can open (registry `peopleos.myspace.items`: key, label, icon, group, destination, `canAccess` of the target page / resource), grouped (Profile, Time, Pay, Performance, Engage, Requests) |
| 2 | "Favorites" group at the top of the flyout (the items hearted in "View all"; 11 in the capture) | Frequent self pages first | `ExperiencePreferences` keeps pinned people, favourite reports and recent items; no favourite pages | MISSING → built (MS-1: hearted items, at most 12, first on My space and at the top of the avatar menu) | MS-1: `favourite_pages` in `ExperiencePreferences` (cap 12, same as pinned people), shown first in the avatar menu and on My space |
| 3 | "VIEW ALL" button | Opens the "All Searches" dialog (§22.1.1) | Admin Centre "All modules" (`ModuleCatalogue`) lists modules, not self items | PARTIAL → built (MS-1: the My space page; "My space" in the avatar menu) | MS-1 page = "View all" |
| 4 | Flyout search ("Search", "Recent Searches") | Find a self page by name from the flyout | Command Center finds Quick actions and navigation; recent items | PARTIAL → built (MS-1: every available item is a Command Center navigation result, found by its label, group or HROne's name; recent items as before) | MS-1: register each My space item as a navigation result in `CommandSearch` |
| 5 | Page access per My space item (54 "View" grants; Profile View / Create; Goals Goal view / Initiatives create / complete / view; Scorecard tab grants; Team announcement View / Create; Social profile "Download id card") | The role decides which self pages exist for the person | Self pages follow domain permissions in the role (`employee.self`, `payroll.payslip`, `compensation.self` + `compensation.self_service`, `document.own`, `performance.*`, `recognition.*`, `exit.resign` …); no per-item switch | PARTIAL → built (MS-1: in part — `myspace.hidden_items` in Setup → Core HR → My space; CA-9: page access per My space item on each role, `roles.myspace_hidden_items`, roles adding up; the pages themselves follow the role's permission keys, D-CA9-1) | CA-9 (access rights, pending rebuild) owns page access; MS-1 adds `myspace.hidden_items` (multiselect of registry keys, default `[]` = all shown; hiding never grants) |
| 6 | My preferences: "Product Updates — Receive alerts for new features and tips" | Vendor product news opt-in | — | N-A | Vendor marketing |
| 7 | My preferences: "App View — Switch between light and power app" | HROne's two UI modes | One UI; density comfortable / compact and the home lens on Preferences | N-A | Different product model (density is the nearest) |
| 8 | My preferences: "Access Rights — Refresh Access" | Reload the person's permissions after a change | Permissions are read live on each request; nothing to refresh | HAVE | — |
| 9 | My preferences: "Password — Reset Password" | Change the login password | Edit profile (current + new password under the security policy) and password reset | HAVE | — |
| 10 | My preferences: "HROne commune" | Vendor community | — | N-A | Vendor |
| 11 | My preferences: "Support Assistant — Get instant AI help for system queries" | AI help | Employee assistant (`AssistantPage`, `ai.use`) | HAVE | — |
| 12 | Notification settings (My preferences header "notifications") | Per-person notification choices | My HR → Preferences (in-app / e-mail per communication category; mandatory types locked); Notification center snooze | HAVE | — |

### 22.1.1 "View all" dialog (`myspace_view_all`; "All Searches")

| # | HROne element (tab / field / column / action) | What it does (inferred where unclear, mark *(inferred)*) | PeopleOS today (file or screen) | Status | Build note |
|---|---|---|---|---|---|
| 1 | Dialog "All Searches" with close (`clear`; closing returns to the social profile) | Container | — | N-A | UI chrome |
| 2 | Module chips: All · Performance · Asset · Time Office · Engage · Workforce · Payroll · Expense · Recruitment | Filter the items by module | Self pages are not grouped by module anywhere | MISSING → built (MS-1: chips All · Profile · Time · Pay · Performance · Engage · Requests, only for groups with items) | MS-1: registry `group` = module; chips for the groups that have items (Recruitment N-A) |
| 3 | "Search..." | Filter the items by name | Command Center navigation results | PARTIAL → built (MS-1: search over label, description, group and keywords incl. HROne's names) | MS-1: search box on the page |
| 4 | Item list, alphabetical (52 items: the tree's 54 less QR Code and Trips, which have no page) | Everything the role grants in one list | Self pages spread over the avatar menu and the "Me" group; several appear only when the viewer lacks the HR permission | PARTIAL → built (MS-1: every self page the person may open, alphabetical within its group; HROne items without a PeopleOS page are listed by their owning package when built) | MS-1 |
| 5 | Heart ("favorite") on each item; hearted items appear under "Favorites" in the flyout | Add / remove a favourite | Pinned people and favourite reports only | MISSING → built (MS-1: heart on each item) | MS-1: `favourite_pages` toggle on each item |
| 6 | Recruitment items in the list (Job requisition, Position assigned) and the "Recruitment" chip | Recruiter pages | — | N-A | Recruitment (separate RMS) |

---

## 22.2 Profile (`/app/myprofile/profile`; `app-my-profile-info` → `app-create-employee-*`)

Pages `menu_My_space__Profile`, `rail_My_space__Profile`, `self_app_myprofile_profile`, `self_section_*`, `myspace_Profile`
(same content). The self profile: header, ten sections (Snapshot, Personal, Family, Professional, Health Insurance, Employees Other
Details, Bank, Previous Employer Details, Documents, History), each with "EDIT". The section forms are the same
components HR uses on the employee profile; the employee's edits go through a per-section workflow
(`ValidateWorkflow/{employee}/{process}`: 49 snapshot, 74 personal, 75 family, 76 professional, 77 health insurance,
73 other details, 81 bank, 78 previous employer) and show a pending status (`EmployeeRequestStatus/{employee}/{section}`).
PeopleOS today: the employee opens their own Employee 360 read-only ("My profile") and asks HR for changes through
services (§0).

### 22.2.0 Header and shared chrome

| # | HROne element (tab / field / column / action) | What it does (inferred where unclear, mark *(inferred)*) | PeopleOS today (file or screen) | Status | Build note |
|---|---|---|---|---|---|
| 1 | Header: avatar, name, company, business unit, employee code | Whose profile | Own Employee 360 header (name, code, designation, department, location, manager); company and BU in "All details" | HAVE | — |
| 2 | "Employee photo" — "Upload pic", "Choose" ("No file chosen") | The employee sets their own photo | `people.photo_path` exists (listed in `DataInventory`) but nothing uploads or shows it; avatars are initials | MISSING | MS-7 |
| 3 | "Signature — Edit" (`app-create-employee-signature`: "You can draw your signature in below drawing area", clear; "You can upload your signature from below", "Drop files here to upload or Browse Files"; "Enter signature" (typed)) | The employee keeps a personal signature (drawn, uploaded or typed) for e-sign and acknowledgements *(inferred)* | Letter signatories have signature images (HR); no employee signature | MISSING | MS-7 |
| 4 | Section tabs Snapshot · Personal · Family · Professional · Health Insurance · Employees Other Details · Bank · Previous Employer Details · Documents · History | One page per area of the employee's own record | Own 360: workspace, "All details", relation groups Employment, Time, Growth, Rewards, Operations, Personal (addresses, family & nominees, emergency contacts, qualifications, experiences), Timeline; bank, statutory and documents hidden from the employee; no health insurance; previous employer lives on the tax declaration; other details = custom-field section | PARTIAL → built (MS-2) | MS-2: a "My profile" page arranged by these sections (reads only, through the owning domains) |
| 5 | "EDIT" on a section | The employee changes their own data | The employee cannot edit their record (satellite create / edit need `employee.create` / `employee.update`); changes go through HR services with domain actions (address, bank account, PAN / UAN, emergency contact, family member), which start as DRAFT services HR must approve; only birthday / wedding-anniversary day and sharing are self-edited | PARTIAL → built (MS-3) | MS-3: section edit → governed change request executed by the existing actions |
| 6 | Change-reason dialog (`app-employee-comment-dialog`: "Comments — Please enter your reason for making changes to profile", Comment, VIEW DOCUMENT) | A reason travels with every self change | The service request has its own description; the executed change records "Service request TKT-…" as the audit reason | PARTIAL → built (MS-3) | MS-3: required comment (≤ 500) stored on the change request and used as the audit reason |
| 7 | Workflow per section (`ValidateWorkflow`, form loader "Workflow") | Each section may route to approval or apply at once | Per service `approval_required` and the service-desk workflow bridge; only for the five profile services | PARTIAL → built (MS-3) | MS-3: workflow trigger `profile.change_requested` with section in the context (P5 first-match); else per-section setting "needs approval" |
| 8 | Pending status per section (`EmployeeRequestStatus`; `app-view-status-details`: Details, VIEW DOCUMENT, OKAY) | Shows that a change is waiting and what was asked | My HR → Requests shows the ticket and its status (values masked to the requester) | PARTIAL → built (MS-3) | MS-3: "Change pending since … with …" on the section; one open change per section |
| 9 | Static page layout per section (`StaticPageLayoutDetails/{n}`: renamed labels, hidden fields, section names) | Tenant-shaped forms | — | MISSING | Owned by matrix 02 §03.4 (`form_layouts`, WF-09); MS-2 / MS-3 read it once built |
| 10 | PII consent (`app-my-profile-info`: "Personal identifiable information ( PII ) consent", "I , hereby give / consent the authority to process my personal data…", "I accept the data privacy addendum and Privacy Policy of the organization", Submit, Go Back; `IsDataPrivacyRequiredForEmployee`) | The employee consents before using the profile | Consent only for BGV and alumni | MISSING | Owned by SV-14 (`privacy.pii_consent_required`, `privacy_consents`; owner / legal text) |
| 11 | Data-privacy masking (`pii/isprivilegeuser`, `Company/DataPrivacy`) | Sensitive values hidden unless privileged | Sensitive values masked without `employee.sensitive.view`; sensitive views audited (`SensitiveAccessAuditor`) | HAVE | — |
| 12 | BGV setting read on each section (`BGV/Setting`) | Fields under background verification are locked or re-verified *(inferred)* | BGV cases exist; no link to profile edits | MISSING → built (MS-3) | MS-3: setting `profile.self_edit.lock_during_bgv` (default off) refuses a self change while a BGV case of the employee is open |
| 13 | "Initiate separation formalities", legal-entity change warning, "Do you want to enable this employee's login?" (inside the shared snapshot form) | HR actions on the HR copy of the form | HR side (exit, transfer, user accounts) | N-A | HR-only; not shown in My space |

### 22.2.1 Snapshot (`app-create-employee-snapshot`; page `self_section_Snapshot`)

| # | HROne element (tab / field / column / action) | What it does (inferred where unclear, mark *(inferred)*) | PeopleOS today (file or screen) | Status | Build note |
|---|---|---|---|---|---|
| 1 | Prefix · `salutation` (Mr., Ms., Dr. …) | Title used on letters and lists | No salutation; letters derive pronouns | MISSING | MS-4: `people.salutation` (option list `salutations`, tenant-extendable through `option_lists`), letter variable `{{ employee.salutation }}` |
| 2 | First name · `firstName`; Middle name · `middleName`; Last name · `lastName` | Legal name | Person first / middle / last (+ preferred name) shown on the 360 | HAVE | Self change (legal identity) only with approval and a document (MS-3) → built (MS-3: `profile.self_edit.legal_identity`, off by default; D-MS11 open) |
| 3 | Employee Code · `employeeCode` | Code | Shown | HAVE | — |
| 4 | Date of birth · `dateOfBirth` | DOB | "All details" | HAVE | Self change only with approval (MS-3) → built (MS-3: with legal name, approval and a document; off by default) |
| 5 | Gender · `genderId` | Gender | "All details" | HAVE | — |
| 6 | Blood group · `bloodGroupCode` | Blood group | Stored (`people.blood_group`, HR edit form); not shown on the 360 | PARTIAL → built (MS-2; edit side → built (MS-3)) | MS-2 shows it; MS-3 lets the employee change it (no approval by default) — built: section `blood_group`, approval per the tenant's matrix (D-MS2 open) |
| 7 | Nationality · `nationality` | Nationality | Stored; not shown on the 360 | PARTIAL → built (MS-2) | MS-2 |
| 8 | Work email · `officialEmail` | Work e-mail | Shown | HAVE | — |
| 9 | ISDcode · `internationalCode` + Mobile number · `mobileNo` | Mobile with country code | `work_phone` / `personal_phone` free text; no country code | PARTIAL | MS-4: phone fields stored in E.164 with a country-code select (existing values kept as typed) |
| 10 | Biometric Id · `biometricId` | Device user id that maps punches to the employee | Punches are matched by employee code; no separate device id | MISSING | MS-4: `employees.device_user_id` (unique per tenant, nullable); `PunchIngestion` tries it before the employee code (null = today's behaviour) |
| 11 | Skill Type · `skillTypeId` (Skilled …) | Wage skill category of the employee | Designation `wage_skill_category` (CA-2); not per employee, not shown | PARTIAL → built (MS-2) | MS-2 shows the designation's category; per-employee override stays HR (BP-11) |
| 12 | Date of joining · `dateOfJoining` | DOJ | Shown | HAVE | — |
| 13 | Retirement date · `dateOfRetirement` | Expected retirement | Separation policy retirement age + "retire on" (WF) and the `RetirementDue` job; no date shown | PARTIAL → built (MS-2) | MS-2: computed date from the employee's exit policy (read-only) |
| 14 | Employment type · `employeeTypeId` | Permanent / contract … | On the current position; "All details" shows category, level, grade but not employment type | PARTIAL → built (MS-2) | MS-2 |
| 15 | Employment status · `employeeStatusId` (Probation / Confirmed) | Status | Lifecycle state badge | HAVE | — |
| 16 | Date of Confirmation · `dateOfConfirmation` | Confirmation date | Shown | HAVE | — |
| 17 | Employee Other Status · `otherStatusId`, Other Status Date · `otherStatusDate`, Other Status Remarks · `otherStatusRemarks` (Absconding, Maternity, Sabbatical, Suspended, Retired …) | Secondary status | — | MISSING | Owned by WF-08 ("other status" records, matrix 02 login settings); MS-2 shows it once built |
| 18 | Date of resignation · `dateOfResignation`; Date of leaving · `dateOfLeaving`; Date of settlement · `dateOfSettlement` (shown on the social-profile basic information) | Exit dates | Exit case (own, "Me" → Exit): resignation date, last working day, settlement status | PARTIAL → built (MS-2) | MS-2: show on the profile while an exit case exists |
| 19 | Employee / Organization / Settlement separation reason · `employeeSeparationReasonId`, `organizationSeparationReasonId`, `settlementSeparationReasonId` | Reasons recorded at exit | Exit case `workforce_reason_id` (WF-2 / CA-3), HR side | PARTIAL | Owner decision D-MS5: show only the employee's own reason; never the organisation's |
| 20 | "Considered for future opening" · `alumniStatus`; Blacklist reason · `blacklistReasonId`; Blacklist comments · `blacklistComments` | Rehire eligibility | Exit case `is_rehire_eligible`, `rehire_block_reason_id` (HR) | PARTIAL | D-MS5: keep HR-only (recommended); no build |
| 21 | Company · `companyCode` | Legal entity | Shown | HAVE | — |
| 22 | Business Unit · `businessUnitCode` | BU | Shown | HAVE | — |
| 23 | Department · `departmentCode`; Sub department · `subDepartmentCode` | Department and its parent | Department on the header; parent department (CA-2) | HAVE | — |
| 24 | Designation · `designationCode` | Designation | Header | HAVE | — |
| 25 | Region · `regionCode` | Branch grouping | — | MISSING | Owned by matrix 01 §1.10 (`geo_groupings`) |
| 26 | Branch · `branchCode` | Work location | Location on the header | HAVE | — |
| 27 | Sub branch · `subBranchCode` | Location below a branch | One location level | PARTIAL | Owned by matrix 01 §1.17 |
| 28 | Grade · `gradeCode`; Level · `levelCode` | Grade / level | "All details" | HAVE | — |
| 29 | Reporting manager · `reportingManagerId` | Line manager | Header; Reporting tab | HAVE | — |
| 30 | Functional manager · `functionalManagerId` | Second (functional) manager | Reporting relationship type "Functional manager" (Reporting tab) | HAVE | — |
| 31 | Unique position code · `workforcePlanningRequestId`; Original unique position code · `workforceOriginalPlanningRequestId` | The seat the employee occupies | Position (seat) on the employee position; Positions tab | HAVE | — |

### 22.2.2 Personal (`app-create-employee-personal`; page `self_section_Personal`)

| # | HROne element (tab / field / column / action) | What it does (inferred where unclear, mark *(inferred)*) | PeopleOS today (file or screen) | Status | Build note |
|---|---|---|---|---|---|
| 1 | Identification details: PAN no. · `panNumber` + "Attach document — Upload" | PAN with proof | `employee_statutory_details.pan`; the employee cannot see it (statutory view needs `employee.sensitive.view`); change through "PAN / UAN update" (approval) with the CA-4 linked PAN document | PARTIAL → built (MS-2; edit side → built (MS-3)) | MS-2: own identifiers masked (last 4) with an audited reveal (D-MS1); MS-3 section edit — built: section `identifiers` (always with approval; CA-4 linked PAN document) |
| 2 | Aadhar no. · `uniqueIdentificationNumber` + attach | Aadhaar with proof | `aadhaar_reference` (a reference / last digits by design) | PARTIAL → built (MS-2; edit side → built (MS-3)) | MS-2 / MS-3 (store the reference only, as today) |
| 3 | Passport no. · `passportNo` + attach; Passport Expiry Date · `passportExpiryDate` | Passport | Passport number only per international trip (encrypted); documents may carry an expiry | MISSING | MS-4: `person_identity_documents` (kind passport / driving licence, number encrypted, issued / expiry dates); CA-4 linked field `passport` |
| 4 | Driving license no. · `drivingLicenseNo`; Driving license expiry date · `drivingLicenseExpiryDate` | Driving licence | — | MISSING | MS-4 (same table) |
| 5 | Contact details: Personal email · `personalEmail` | Private e-mail | Stored (`people.personal_email`); not shown to the employee; no self change service | PARTIAL → built (MS-2; edit side → built (MS-3)) | MS-2 / MS-3; verification MS-8 — built: section `contact` (personal e-mail and phone) |
| 6 | Emergency contact name · `emergencyContactName` | Who to call | Emergency contacts tab (own, visible); "Emergency contact update" service | HAVE | — |
| 7 | ISD Code Emergency Contact · `emergencyInternationalSubscriberDialingCode` | Country code | Phone free text | PARTIAL | MS-4 (E.164) |
| 8 | Emergency contact no. · `emergencyContactNo` | Number | Phone (+ alternate phone, e-mail, priority) | HAVE | — |
| 9 | Relation · `contactRelationshipId` | Relationship | Relation | HAVE | — |
| 10 | Present residence: Current Address · `addressCurrent` + attach document | Current address with proof | Address type current (lines); "Address change" service; CA-4 linked address document | HAVE | — |
| 11 | Current country · `countryCodeCurrent`; state · `stateCodeCurrent`; city · `cityCodeCurrent`; pin · `zipCodeCurrent` | Address parts | Country code, state code, city (text), postal code | HAVE | City master waits for CA-7 (approved source) |
| 12 | Permanent residence: "Same as present" | Copy the current address | — | MISSING → built (MS-3) | MS-3 form convenience |
| 13 | Permanent Address · `addressPermanent`; country; state; city; pin | Permanent address | Address type permanent | HAVE | — |
| 14 | Upload dialog (`app-forms-document-upload`): "Attach supporting document", Select document · `documentId`, Comment · `documentComment`, Expiry date · `documentExpiryDate` ("You can only enter the future dates"), drag and drop / "Click to upload", "Max file size is 10 MB. Supported file types are pdf, doc, docx, xls, xlsx, jpeg, jpg, png, bmp, zip and rar", Done | Proof attached to the field change | My HR "Upload a document" (types the employee may upload; pdf, jpeg, png, doc, docx; size `documents.max_kb`; issued / expiry date; sample); not tied to a field change; no comment | PARTIAL → built (MS-3) | MS-3: the change form carries the CA-4 linked document (same `DocumentRules` upload, status pending); allowlist unchanged (D-MS9: no zip / rar / xls) |

### 22.2.3 Family (`app-create-employee-family`; page `self_section_Family`)

Groups ParentalInformation, MaritalStatusAndChildren, OtherDependents, NomineeDetails; lookups `ParentsMediclaim`,
`MaritalStatus`, `NominationType`, `RelationshipWithoutSelf`.

| # | HROne element (tab / field / column / action) | What it does (inferred where unclear, mark *(inferred)*) | PeopleOS today (file or screen) | Status | Build note |
|---|---|---|---|---|---|
| 1 | Father name · `fatherName`; Father date of birth · `fathersDateOfBirth` | Parent details | Family member with relation Father (name, DOB) on "Family & nominees" (own, visible) | HAVE | — |
| 2 | Mother name · `motherName`; Mother Date of Birth · `mothersDateOfBirth` | Parent details | Family member with relation Mother | HAVE | — |
| 3 | Parents' mediclaim (`ParentsMediclaim` lookup) | Whether parents are covered by the health policy *(inferred)* | — | MISSING | MS-5 (insured members) |
| 4 | Marital status · `maritalId` | Marital status | Stored (`people.marital_status`); not shown to the employee | PARTIAL → built (MS-2; edit side → built (MS-3)) | MS-2 / MS-3 — built: section `family` (marital status and family members) |
| 5 | Spouse name · `spouseName`; spouse DOB · `spouseDob` | Spouse | Family member relation Spouse | HAVE | — |
| 6 | Date of marriage · `dateOfMarriage` | Marriage date | Wedding anniversary day and month only (HP-9a, privacy choice), shared or private | PARTIAL | D-MS8: keep day / month unless a benefit needs the year |
| 7 | "Add children": Child name · `childName`, DOB · `childDateOfBirth`, Gender · `genderId`, Dependent · `isDependent` | Children | Family members Son / Daughter with DOB, gender, dependent | HAVE | — |
| 8 | Child "School" · `isSchool`, "Hostel" · `isHostel` | Children's education / hostel allowance eligibility *(inferred)* | — | MISSING | MS-4: `person_family_members.in_school`, `in_hostel` (booleans, default false); a future claim rule may read them (no statutory amount coded) |
| 9 | "Add other": Dependent name · `dependentName`, relationship · `relationshipId`, DOB · `dependentDob`, gender · `otherGenderId`, remarks · `otherRemarks` | Other dependents | Family member with any relation (tenant-extendable list), DOB, gender, dependent; no remarks | PARTIAL | MS-4: `remarks` (text 255) |
| 10 | "Add nominee": Nominee for · `nomineeFor` (`NominationType`) | Which benefit the nomination is for (PF, gratuity, insurance …) *(inferred)* | One "nominee" flag + share per family member | MISSING | MS-4: `person_nominations` (family member, benefit from option list `nomination_benefits`, share %), shares per benefit must total 100 |
| 11 | Nominee relationship · `relationship`, name · `nomineeName`, DOB · `nomineeDateOfBirth`, gender · `nomineeGenderId` | Nominee identity | The nominee is a family member (name, relation, DOB, gender) | HAVE | — |
| 12 | Percentage · `percentage` | Share | `nominee_share` | HAVE | MS-4 moves it per benefit |
| 13 | Nominee address · `nomineeAddress`; remarks · `nomineeRemarks`; contact no. · `nomineeContactNo` | Nominee contact | Phone on the family member; no address / remarks | PARTIAL | MS-4: `address`, `remarks` on the nomination |
| 14 | Minor · `minor`; Guardian name · `nomineeGuardianName`; Guardian relationship · `nomineeGuardianRelationship` | Guardian for a minor nominee | — | MISSING | MS-4: derived `minor` from DOB; guardian name / relation required when minor |

### 22.2.4 Professional (`app-create-employee-professional`, `app-employment-history-dialog`; page `self_section_Professional`)

| # | HROne element (tab / field / column / action) | What it does (inferred where unclear, mark *(inferred)*) | PeopleOS today (file or screen) | Status | Build note |
|---|---|---|---|---|---|
| 1 | "I am a fresher" · `isFresher` | No earlier employment | No flag (an empty experience list) | MISSING | MS-4: `people.is_fresher` (boolean, default false); refuses experience rows while set |
| 2 | Work experience — "Add experience": Company name · `companyName`, Designation · `designation`, From · `fromDate`, To · `toDate`, Number of months · `numberOfMonths`, Job description · `jobDescription`; add / remove row | Earlier jobs | Experiences tab (own, visible): employer, designation, from, to, location, responsibilities, reason for leaving | HAVE | Months derived from the dates in MS-2 |
| 3 | "View employment history" (`app-employment-history-dialog`): verification type UAN / Mobile no. / PAN, "Enter the 12 digit Universal Account Number", "Find UAN" from PAN or mobile, "User provided details" vs "EPFO records" (ID, Joining, Leaving), "Restart verification" | Checks declared experience against EPFO service history | — | MISSING | External (EPFO / UAN verification vendor; matrix 01 CA-15 seam); no build here |
| 4 | Education — "Add education": highest qualification · `isHighestQualification` ("This is the highest qualification") | Marks the top qualification | — | MISSING | MS-4: `person_qualifications.is_highest` (one per person) |
| 5 | Qualification · `qualificationCode`; Specialisation · `specializationId` / `specializationName` | Degree and stream | Qualification and specialisation masters (CA-3) with free-text fallback | HAVE | — |
| 6 | Mode · `qualificationMode` | Full time / part time / distance *(inferred)* | — | MISSING | MS-4: `mode` (option list `qualification_modes`) |
| 7 | Year of starting · `yearOfStartingDate`; Year of passing · `yearOfPassingDate` | Study period | Year of completion only | PARTIAL | MS-4: `year_started` |
| 8 | Board / University · `boardUniversityName`; Institute · `instituteName`; Grade · `grade` | Institution and result | Board or university, institution, grade or score | HAVE | — |
| 9 | Validation texts ("a-z, A-Z, 0-9, +, - and . characters are allowed", "You cannot enter future date") | Input rules | Date and text validation on the forms | HAVE | — |

### 22.2.5 Health insurance (`app-create-employee-health-insurance`; page `self_section_Health_Insurance`)

Form array `InsuranceList` (one row per insured person).

| # | HROne element (tab / field / column / action) | What it does (inferred where unclear, mark *(inferred)*) | PeopleOS today (file or screen) | Status | Build note |
|---|---|---|---|---|---|
| 1 | Insuree Name · `nomineeName` | Covered person | — | MISSING | MS-5 |
| 2 | Relationship · `relationshipId` | Relation to the employee | — | MISSING | MS-5 (from the family member) |
| 3 | Insuree Date of Birth · `nomineeDateOfBirth`; Insuree Gender · `genderId` | Insured person's details | — | MISSING | MS-5 |
| 4 | Insurance code · `insuranceCode` | Policy / member number | — | MISSING | MS-5 (encrypted) |
| 5 | Assured sum · `sumAssuredAmount` | Cover amount | — | MISSING | MS-5 |
| 6 | Insurance company · `insuranceCompanyName`; Company code · `insuranceCompanyCode` | Insurer | — | MISSING | MS-5 |
| 7 | Issue date · `insuranceIssueDate`; Valid upto · `insuranceValidityPeriod` | Policy period | — | MISSING | MS-5 (expiry reminder through the document-expiry pattern) |
| 8 | "EDIT" (insurance change through the section workflow 77) | The employee updates insured members | — | MISSING → partly built (MS-3: the section mechanism; section `health_insurance` joins with MS-5) | MS-5 + MS-3 (section `health_insurance`) |

### 22.2.6 Employees Other Details (dynamic form `DynamicPageLayoutDetails/2`, `app-create-employee-other-details`; page `self_section_Employees_Other_Details`)

| # | HROne element (tab / field / column / action) | What it does (inferred where unclear, mark *(inferred)*) | PeopleOS today (file or screen) | Status | Build note |
|---|---|---|---|---|---|
| 1 | Tenant-defined fields ("NO EMPLOYEE OTHER DETAILS" when none) | Extra employee fields | Employee custom fields with `visible_to_employee` / manager / HR (custom-fields section on the 360) | HAVE | — |
| 2 | "EDIT" of other details (workflow 73) | The employee fills tenant fields | No employee edit of custom fields | MISSING → built (MS-3) | MS-3: custom field flag `editable_by_employee` (default false); section `other_details` |

### 22.2.7 Bank (`app-create-employee-bank-details`; page `self_section_Bank`)

| # | HROne element (tab / field / column / action) | What it does (inferred where unclear, mark *(inferred)*) | PeopleOS today (file or screen) | Status | Build note |
|---|---|---|---|---|---|
| 1 | Salary Pay Mode · `salaryPayModeCode` (Online transfer / cash / cheque, `PayMode`) | How salary is paid | `employee_payment_modes` (BP-13), HR-set; not shown to the employee | PARTIAL → built (MS-2) | MS-2 shows it; changing it stays HR |
| 2 | Salary Bank Name · `salaryBankName` (bank list `SalaryBankDetail`), Salary Branch Name · `salaryBranchName`, Salary IFSC Code · `salaryFinancialSystemCode`, Salary Account Number · `salaryAccountNumber` | Salary account | `employee_bank_accounts` (holder, bank, branch, IFSC, number, type, primary); visible only with `employee.sensitive.view`, so not to the employee; change through "Bank account change" (approval) / Quick action "Update bank details" | PARTIAL → built (MS-2; edit side → built (MS-3)) | MS-2: own accounts masked (last 4), reveal audited (D-MS1); MS-3 section edit (approval on by default for bank) — built: always with approval |
| 3 | Confirm Salary Account Number · `confirmSalaryAccountNumber`; "Account numbers do not match." | Typo guard | No confirmation field on the bank service | MISSING → built (MS-3) | MS-3: confirm field (not stored) |
| 4 | "Attach document — Upload" (bank proof) | Proof of account | CA-4 linked field `bank_account` requires a current document when an account is added / its number changes | HAVE | — |
| 5 | "Reimbursement — Same as salary" | Reuse the salary account for claims | BP-13: reimbursement mode / account null = as salary | HAVE | — |
| 6 | Reimbursement Pay Mode · `reimbursementPayModeCode`; Bank · `reimbursementBankName`; Branch · `reimbursementBranchName`; IFSC · `reimbursementFinancialSystemCode`; Account · `reimbursementAccountNumber`; Confirm · `confirmReimbursementAccountNumber` | Separate claims account | BP-13 reimbursement mode and paying account (HR) | PARTIAL | MS-3: the bank change lets the employee pick "use for reimbursements" (executed by `PaymentModes::set`, approval as bank) — not built by MS-3: `PaymentModes::set` keeps pay modes and the company's paying accounts; PeopleOS has no separate employee reimbursement account (claims go to the salary account), so this needs a payroll package (bank file) first |
| 7 | Bank account verification ("We'll verify the details below against bank records", "Verifying bank account details…", "Verification Failed — Common Reasons: Name mismatch with bank records / wrong or incomplete account number / IFSC doesn't match the branch / account closed", "Verification Successful" with holder name, bank, branch, account no., IFSC; Verify later, Try again, Continue, Close) | Penny-drop / name-match check | `employee_bank_accounts.verified_at` exists; no verification service | MISSING | External (bank-verification vendor, matrix 01 CA-15 seam) |

### 22.2.8 Previous Employer Details (`app-create-employee-pre-employer-details`; page `self_section_Previous_Employer_Details`)

| # | HROne element (tab / field / column / action) | What it does (inferred where unclear, mark *(inferred)*) | PeopleOS today (file or screen) | Status | Build note |
|---|---|---|---|---|---|
| 1 | Taxable salary · `taxableSalary` | Income from the earlier employer this year | Declare investments → "Previous job": taxable salary (per declaration / FY) | HAVE | — |
| 2 | Tax deducted by previous employer · `taxDeducted` | TDS already paid | Same step: TDS they deducted | HAVE | — |
| 3 | Exempted gratuity · `exemptedGratuity` | Gratuity exemption already used | — | MISSING | MS-4: `previous.gratuity_exempt` on the declaration; `TaxComputer` reduces the remaining exemption only through rule data (BP-10, 10(10) verification) |
| 4 | Exempted encashed leave · `exemptedLeaveEncashment` | Leave-encashment exemption already used | — | MISSING | MS-4: `previous.leave_encashment_exempt`, same rule-data path (BP-10 10(10AA) awaits owner verification) |
| 5 | Exempted professional tax · `exemptedProfessionalTax` | PT paid there | PT deducted there | HAVE | — |
| 6 | Exempted provident fund · `exemptedProvidentFund` | PF paid there | PF deducted there | HAVE | — |
| 7 | "Attachment — Please upload document" | Form 16 / 12B proof | Proofs requested for the previous employer (Form 16, Form 12B or payslips) on My investment proofs | HAVE | — |

### 22.2.9 Documents (`app-create-employee-documents`, `WebDocument/PROFILE`; page `self_section_Documents`)

| # | HROne element (tab / field / column / action) | What it does (inferred where unclear, mark *(inferred)*) | PeopleOS today (file or screen) | Status | Build note |
|---|---|---|---|---|---|
| 1 | "Please click on edit to upload documents" / EDIT → "Click to upload" per document type | Self upload | My HR → Documents "Upload a document" for types with `employee_can_upload` (status pending, HR verifies) | HAVE | — |
| 2 | "Download All Documents" | One download of everything | One document at a time (short-lived private link, audited) | MISSING → built (MS-2) | MS-2: ZIP of the employee's visible current documents built on the fly, one audit entry per file |
| 3 | Grouping by category (Education, Employee …) | Documents by group | Category column, not grouped | PARTIAL → built (MS-2) | MS-2 groups by category |
| 4 | Columns Sr. No., Document name, File name, Last updated | Document facts | Document, category, status, version, expiry; original file name stored, not shown | PARTIAL → built (MS-2) | MS-2 adds file name and last updated |
| 5 | Column "comments" | Uploader / reviewer comment | HR review note stored (`review_note`), not shown; no uploader comment | PARTIAL → built (MS-2; uploader comment → built (MS-3)) | MS-2 shows the review note; MS-3 adds an uploader comment |
| 6 | Column "Date of expiry" | Expiry | Expires column | HAVE | — |
| 7 | Column "Download file" | Download | Download link | HAVE | — |
| 8 | Column "Sample document" | Sample of what to upload | CA-4 sample link in the upload dialog only | PARTIAL → built (MS-2) | MS-2 shows the sample per type in the list |
| 9 | Every type the employee may see is listed, uploaded or not | What is still missing | "Still needed from you" lists missing mandatory types only | PARTIAL → built (MS-2) | MS-2 lists all viewable / uploadable types with "not uploaded" |
| 10 | Consent message before upload (`ConsentMessage`) | Data-use consent *(inferred)* | — | MISSING | SV-14 |

### 22.2.10 History (`app-create-employee-history`; page `self_section_History`)

| # | HROne element (tab / field / column / action) | What it does (inferred where unclear, mark *(inferred)*) | PeopleOS today (file or screen) | Status | Build note |
|---|---|---|---|---|---|
| 1 | Timeline (Probation <date>, Confirmed <date>; "Suspended on", "Confirmed on", By) | Lifecycle milestones | Timeline tab and Journey block on the own 360 | HAVE | — |
| 2 | History "Type" filter (All updates / per area) | Filter changes | Timeline filters by category and kind | HAVE | — |
| 3 | Change card: field, "Earlier", "New" | What changed | Timeline entries carry no values by design (`ChangesProfileData`); values only in the audit history (`audit.view`) | PARTIAL → built (MS-2) | MS-2: "My changes" view of the employee's own audited non-sensitive fields with earlier / new; sensitive fields show "changed" only |
| 4 | "Requested on … By …", "Approved on … By …" | Who asked and who approved | Timeline "By" (actor); request and approval only on the ticket | PARTIAL → built (MS-3) | MS-3 links each executed change to its request (requested by / on, approved by / on) |
| 5 | SHOW MORE | Paging | Paginated | HAVE | — |

### 22.2.11 Skills (`app-create-employee-skills`, `app-skills-detail`) and the profile forms shown elsewhere

| # | HROne element (tab / field / column / action) | What it does (inferred where unclear, mark *(inferred)*) | PeopleOS today (file or screen) | Status | Build note |
|---|---|---|---|---|---|
| 1 | Skills rows `employeePersonalSkillsDetails`: Skill · `skillId` (master), Proficiency · `proficiencyId` (master); add / remove row | The employee lists skills with a level | Skill profile on My learning; self-assessment (`skills.self`) finalised by manager / HR; profile skills `person_skills` have no edit screen | PARTIAL → built (MS-3) | MS-3 section `skills` (adds / changes a profile skill through approval when the tenant wants it) |
| 2 | Approver view "Employee Requested Skills Updates" (Skill, Proficiency, Documents, Remarks, approver remarks · `approverRemarks`) | Approve self-declared skills | Self assessments are reviewed in Skill assessments | PARTIAL → built (MS-3) | MS-3 (generic approver diff) |
| 3 | Skill endorsement flyer (`app-profile-skill-endorsement-flyer`) | Who endorsed which skill | — | MISSING | MS-6 |

### 22.2.12 Approver side and history of profile changes

Components `app-profile-home`, `app-profile-detail`, `app-profile-detail-view-flyer`, `app-other-profile-detail`,
`app-employee-other-profile`, `app-employee-document-profile`, `app-document-details`, `app-profile-history`,
`app-profile-history-card`, `app-profile-history-flyer`, `app-initiated-profile`, `app-upcoming-*`.

| # | HROne element (tab / field / column / action) | What it does (inferred where unclear, mark *(inferred)*) | PeopleOS today (file or screen) | Status | Build note |
|---|---|---|---|---|---|
| 1 | Approver card "Employee Requested <section> Changes": per field "Earlier" / "New", Remarks, Comments, Document, "No Changes", "No photo uploaded!" | Decide on a self change seeing what changes | The approver sees the service request's submitted fields (sensitive ones only with the service's view permission); no earlier / new comparison | PARTIAL → built (MS-3) | MS-3: diff panel (earlier read through the owning model at decision time, new from the request; sensitive values masked unless the approver holds the sensitive permission) |
| 2 | Inbox chrome on these cards (OVERDUE, DUE TODAY / TOMORROW, missed to respond) | Due labels | Approval Center groups by urgency; relative labels → RQ-8 | PARTIAL → built (RQ-8: relative due labels and "Missed to respond" in the unified inbox) | RQ-8 (matrix 20) |
| 3 | Document change approval: "Employee Requested Document Changes", Earlier / New, "Document date of expiry" | Verify a self-uploaded document | HR verifies pending documents (`document.verify`) | HAVE | — |
| 4 | "Send back" dialog ("This will send back the request to … Once … takes action, it will come back to you for approval"; "Select send back to") | Return to the requester for correction | Service request "waiting for employee" and P3 send back on workflow tasks | PARTIAL → built (MS-3) | MS-3 change requests implement `SupportsSendBack` |
| 5 | Profile history list (`app-profile-history-card`): Last approved date, Rejected / Approved / Pending, On, Pending With, Profile type | The employee's past and open profile changes | My HR → Requests (status; no "pending with", no section facet) | PARTIAL → built (MS-3) | MS-3 list on My profile; also an RQ-1 request type |
| 6 | History flyer (`app-profile-history-flyer`): Earlier / New per field, Remarks, Profile history | Details of one past change | — | MISSING → built (MS-3) | MS-3 (values kept on the request until executed, then only field names and masked values — the purge rule of Phase 12 stays) |
| 7 | Initiated profile (`app-initiated-profile` + `app-request-workflow`: responded by / on, TAT, Send Reminder, Add approver, Discussion, Move to archive) | The requester follows the approval | Ticket status; workflow run viewer for HR | PARTIAL → partly built (RQ-2: the requester's request page for a profile change raised as a service request — its trail, "Send reminder" while a workflow decides it, the discussion; "Add approver" stays RQ-9) | RQ-2 requester timeline (matrix 20 §20.1.1 rows 8–12) |
| 8 | Upcoming-joiner review flyers (`app-upcoming-{bank,family,health-insurance,skills,emp-document}`, "Review all the steps for upcoming joiner profile", Under Review / Verified / Invalid, "Send For Profile Completion", profile completion mailer) | HR reviews a pre-joiner's self-filled profile | Pre-joiner portal not built (WF-13 EXT) | N-A | Onboarding / HR desk (WF-13, SV-13); not My space |

### 22.2.13 HR-only parts of the shared profile component

| # | HROne element (tab / field / column / action) | What it does (inferred where unclear, mark *(inferred)*) | PeopleOS today (file or screen) | Status | Build note |
|---|---|---|---|---|---|
| 1 | Contract (`app-create-employee-contract`: contract name, description, code, start / end, period, payroll by, vendor, lapse leave …) | HR maintains contracts | Contracts (BP-16) pending | N-A | HR desk; not shown in My space |
| 2 | Settings (`app-create-employee-setting`: login, time zone, multi-device, licences, 2FA, facial biometric, Wi-Fi attendance devices, SSO, SCIM) | HR account settings | Users, security policy, devices (HR) | N-A | HR desk |
| 3 | "Do you want to make this employee profile private on the directory?" · `isProfilePrivate`; "hide this employee from the wishes section" · `isHiddenWishesSection` | Directory and celebration privacy for the person | The person can hide their birthday / anniversary (`birthday_shared`, `wedding_anniversary_shared`); no directory-private flag | PARTIAL | MS-6: `people.directory_private` (HR-set, default false: the person appears only to HR and their managers) |

---

## 22.3 Social profile (`/app/myprofile/social-profile`; `app-my-social-profile` → `app-social-profile`)

Pages `menu_My_space__Social_profile`, `self_app_myprofile_social_profile`, `self_section_Social_profile`,
`rail_My_space` (the My space landing), `myspace_Social_profile`. APIs `SocialProfileAccess` (+ `HideMenuFromProfile`, `SocialNetwork/Details`),
`SocialProfile/{AboutMe,Education,Experience,Hobbies/Skills}`, `LastPunchWorkStatus`, `Leave/Planned/Publicaly`,
`Engage/Badge/Feed`, `okr/employee/goal/share/KPI`. The same component opens for a colleague (`app-sw-profile-flyer`,
`app-overall-profile-search`).

| # | HROne element (tab / field / column / action) | What it does (inferred where unclear, mark *(inferred)*) | PeopleOS today (file or screen) | Status | Build note |
|---|---|---|---|---|---|
| 1 | The social profile page (own, and a colleague's from search / wall) | A people-facing profile beyond HR data | Directory cards and person peek (name, title, team, location, manager, link to the 360 when allowed) | PARTIAL | MS-6: person profile page (People → a person) with the sections below, each gated by `directory.sections_visible` (matrix 01 §1.22, rebuilt with CA-6) and the person's own hidden sections |
| 2 | Header: avatar, name, company, business unit, employee code | Identity | Peek / 360 header | HAVE | — |
| 3 | "Last Check In : <date, time>" | Last punch time shown on the profile | Punches exist; not on any profile | MISSING | MS-6 (section `last_checkin`, off by default per 01 §1.22) |
| 4 | Banner "Pending — Your contact details verification are still pending. Verify your contact to start receiving updates and notifications." + "Verify Contact Details" | Prompt to verify e-mail / mobile | — | MISSING | MS-8 |
| 5 | Verify contact dialogs (`app-select-contact-otp`: "Select the contact type you would like to verify", Email / phone, Proceed, Verified; `app-send-otp-phone`: "Please enter the code to verify", "Didn't get a code? Resend code in …", Resend; captcha) | One-time-code verification | — | MISSING | MS-8 (e-mail by signed link; phone waits for an SMS provider, IP-07) |
| 6 | "Add social network" / "Edit Social Links" (`app-social-handlers`: "Add social network and weblinks", URL list, "Please enter the correct URL", Cancel, Submit) | Personal LinkedIn / other links | Company social links only (CA-2) | MISSING | MS-6: `social_profiles.links` (LinkedIn, X, Facebook, Instagram, GitHub, website; https only, max 6) |
| 7 | "Edit Profile Picture" | Change photo | — | MISSING | MS-7 |
| 8 | "Basic Information" block (prefix, names, code, DOB, gender, blood group, nationality, work email, mobile, DOJ, employment type / status, company, BU, department, sub department, designation, region, branch, sub branch, managers, skill type, other status, dates of leaving / confirmation / resignation / settlement, biometric id, separation reasons, considered for future opening, blacklist reason / comments) | The person's basic HR facts on the profile | Same fields as §22.2.1 | PARTIAL → built (MS-2) | See §22.2.1 rows 1–31 (MS-2; D-MS5 for exit / rehire fields); colleagues see only the directory fields |
| 9 | "Signature — edit" ("Draw, type or upload your signature") | Own signature | — | MISSING | MS-7 (§22.2.0 row 3) |
| 10 | About (text, Save / cancel, emoji picker; "No Data") | A short self-introduction | — | MISSING | MS-6: `social_profiles.about` (≤ 1000 characters, plain text) |
| 11 | Hobbies ("ADD HOBBY", "Enter your hobby", add, Clear, "See all Hobbies", "No Hobbies") | Interests | — | MISSING | MS-6: `social_profiles.hobbies` (tags, max 15, 40 characters each) |
| 12 | Skills ("Skills", "Edit", "View Skills", "See skills & endorsements") | Skills on the profile | Skill profile on My learning (own); not shown to colleagues | PARTIAL | MS-6 shows the skill profile (names and levels the tenant shares) |
| 13 | Endorse skill (`app-endorse-skill`: Give to · `giveTo`, skills · `skills` "You can not select more than 3 skills", "Do not post on social wall" · `activeStatus`) | Colleagues vouch for skills | No endorsements (HP-9e wall has no endorsement posts) | MISSING | MS-6: `skill_endorsements` (endorser, endorsee, skill; ≤ 3 per action; once per pair and skill), optional wall post kind `endorsement`; setting `engage.endorsements.enabled` (default false, D-MS4) |
| 14 | Education ("See all education") | Education on the profile | Qualifications tab on the own 360; not for colleagues | PARTIAL | MS-6 (section `education`) |
| 15 | Work experience ("Total Experience :", "See all work experience") | Experience and its total | Experiences tab (own); no total | PARTIAL | MS-6 (section `experience`, total = earlier experience + tenure) |
| 16 | Badges ("Badges", "See all badges"; `app-badges-and-rewards` with Badges / Period filters; badge card: Download Attachment, Download Certificate, Reward, Internal Note) | Recognition received | Recognition list with "Received by me", certificate, LinkedIn share; not on a profile | PARTIAL | MS-6 (section `badges`, from Recognition; internal notes never shown) |
| 17 | Shared goals ("Shared Goals", "Goals for", "See All Shared Goals") | Goals the person shares | Organisation-level goals visible to everyone; personal goals to owner and manager only | PARTIAL | MS-6: `goals.shared` (owner's choice, default false) + section `goals` |
| 18 | Planned leaves (`Leave/Planned/Publicaly`) | When the person will be away | Leave calendar (scope rules; reasons never shown) | PARTIAL | MS-6 (section `planned_leaves`: approved future leave dates as "Away", no type; private leave from RQ-4 honoured) |
| 19 | Workplan "Work from Office" / "Work from Home" | This week's work location plan | — | MISSING | Owned by SV-18 (hybrid work); MS-6 renders it once built |
| 20 | "Other Details" | Tenant fields on the profile | Custom fields (`visible_to_*`) on the 360 only | PARTIAL | MS-6: custom-field flag `on_social_profile` (default false) |
| 21 | Social profile access (`SocialProfileAccess`, `HideMenuFromProfile`): which sections show, which the employee may hide | Tenant and personal control of the profile | — | MISSING | Owned by matrix 01 §1.22 (`directory.sections_visible`, `directory.sections_employee_may_hide`, `people.directory_hidden` on Preferences) |
| 22 | Performance label names on the profile (`performance/setting/LabelName`) | Tenant terminology | `PerformanceLabels` | HAVE | — |
| 23 | QR code share (`app-share-qrcode-dialog`: "Copy link to QR code", visibility, Facebook, X, Instagram, Naukri, Whatsapp, Linkedin; `app-qr-code`) | Share a link to the profile as a QR code | — | MISSING | MS-6: QR of the internal profile URL (sign-in required); external sharing off unless D-MS3 allows a public card |
| 24 | "Download id card" (page-access action on Social profile) | Virtual employee ID card | — | MISSING | MS-17 (with SV-13 ID-card template) |

---

## 22.4 Attendance (`/app/myprofile/calendar`; `app-my-calendar` → `app-emp-attendance`)

Pages `menu_My_space__Attendance`, `self_section_Attendance`, `myspace_Attendance` (same content). APIs `attendance/v1/Calendar`, `Calendar/PaidDays`,
`v1/Sequences`, `setting/shift/Details`, `attendancepolicy/Calendar/Visibility`.

| # | HROne element (tab / field / column / action) | What it does (inferred where unclear, mark *(inferred)*) | PeopleOS today (file or screen) | Status | Build note |
|---|---|---|---|---|---|
| 1 | Header "<name> (#code)", company, BU, "Attendance view" | Whose calendar | Attendance calendar shows the person (own; others with `attendance.view` in scope) | HAVE | — |
| 2 | Month and year selects ("Choose month") | Navigate months | Previous / next month | HAVE | — |
| 3 | Calendar grid Sun–Sat with day codes (P, HO, WO …) | Day results | Day codes and colours per status, in / out, late minutes, holiday name, shift code, trip mark | HAVE | — |
| 4 | Legend Present, Absent, Week off, Leave, Holiday, Home, Office | Meaning of codes | Status labels (present, absent, half day, leave, holiday, weekly off, on duty, WFH, field duty) | HAVE | — |
| 5 | Punch-source legend: Excel Import, Manual Punch from UI, AR Request, OD Request, Short leave request, Mark Attendance, Web Checkin, Biometric, Location Approval, Timesheet, API | Where each day's punches came from | Punches carry their source; the calendar does not show it | PARTIAL | MS-11: source dot per day and in the day drawer |
| 6 | "Attendance regularization" button | Raise AR from the calendar | My day "Regularise attendance" (one date) | PARTIAL | RQ-3 (matrix 20: calendar picking) |
| 7 | "Your pending requests" (`app-view-pending-request`) | Open requests of the time-office family | Per-module lists | PARTIAL → built (RQ-1: "Your open requests" panel inside the leave, regularisation and time-office forms) | RQ-1 panel (matrix 20 §20.1.1 row 7) |
| 8 | "You have to complete following task types to enable requests", "ESS is locked", "Go to Inbox" | Request lock until tasks are done | `SelfServiceLock` (P4) refuses requests while locked | HAVE | — |
| 9 | Summary: Total paid days, Current month OT, Previous month OT, Overtime, Arrears, Deduction (Paid days), Deduction (Leave), Unpaid Leave, Holidays, Weekly off, Paid Leave, Working Hours | Month totals | Present, half days, absent, leave, on duty / WFH, paid days, late minutes, penalty days | PARTIAL | MS-11: add OT hours (this and last month), arrear days, holidays, weekly offs, paid / unpaid leave split, penalty split (paid days vs leave), working hours — all from `AttendanceOutput` / `AttendanceArrear` |
| 10 | "WEEKLY HOURS BREAKUP": Week, Total shift hours, Actual working hours, Deducted Hours, Average, Actual, Expected; "Your expected working hours", "Organisation expected hours" | Hours per week vs expected | — | MISSING | MS-11 (from attendance records and the shift / flexi rules) |
| 11 | "Latecome/Earlygo deduction details": Attendance Date, Deduction value, Leave name, Days of late deduction | Why a penalty was applied | Penalty days total; attendance penalties list for HR | PARTIAL | MS-11: own penalty rows (date, value, from leave type or paid days, rule) |
| 12 | Day drawer — punches: Punch date, Punch time, Punch Type In / Out, Machine No., Location, Remarks, Excluded, "( Manual attendance )", Clocking times, Total hours, Breakup / "View breakup" | One day's detail | Calendar cell only (first in, last out) | PARTIAL | RQ-3 day drawer (matrix 20) with machine / location / excluded marks from MS-11 |
| 13 | Day drawer — "Add punch" (Comments, "Max 500 characters") | Ask for a missed punch | Regularisation (missed punch type) | PARTIAL | RQ-3 |
| 14 | Day drawer — Leave type, "Cancel leave" | Cancel a leave from the day | Leave cancel / cancellation request on the leave list | PARTIAL | RQ-3 |
| 15 | Day drawer — Shift Details, Working hours, "No Shift Available" | Shift of the day | Shift code on the cell | PARTIAL | RQ-3 / MS-11 |
| 16 | Day drawer — Overtime, Comp off generation, Comp off expiry, Deduction | Day effects | OT and comp-off claims, ledger (separate lists) | PARTIAL | MS-11 |
| 17 | Day drawer — Birthday Wishes, Badges, Expense (receipts), Timesheet, Workplan (Planned / Actual), Mood | Everything about the day | Wishes, recognition, receipts and mood exist in their own pages; no timesheets / work plans | PARTIAL | MS-11 (links to the day's items); timesheets wait for projects (matrix 21 PM-*), work plan for SV-18 |
| 18 | Day drawer — "( Updated By System )", "Reprocess attendance" | Reprocess the day | HR reprocessing (attendance processor) | N-A | HR action |
| 19 | Odd punches list (`app-odd-punches-flyer`: Date, No. of punches, Odd Punches) | Days with an odd punch count | Missed-punch report template (HR) | PARTIAL | MS-11: "Odd punches this month" on the calendar |
| 20 | Calendar visibility policy ("Attendance visibility settings have hidden this section in the detailed calendar") | Hide parts of the calendar | Calendar visibility (matrix 09) | HAVE | — |
| 21 | Request flyers from the calendar: leave, AR, OD, short leave, RH | Start a request on a date | Separate actions | PARTIAL | RQ-3 |
| 22 | Timesheet request (`app-timesheet-req`) | Fill a timesheet from the calendar | — | MISSING | Projects / timesheets (matrix 21 PM-*) |

---

## 22.5 CTC (`/app/myprofile/my-ctc`; `app-my-ctc`, `app-password-checker`, `app-update-salary-structure-step2`, `app-salary-insights`)

Pages `menu_My_space__CTC`, `rail_My_space__CTC`, `myspace_CTC` (same content). API `V3/Salary/Structure/View`.

| # | HROne element (tab / field / column / action) | What it does (inferred where unclear, mark *(inferred)*) | PeopleOS today (file or screen) | Status | Build note |
|---|---|---|---|---|---|
| 1 | Password gate: "Enter Password to open CTC", "Password for CTC is login password of System", show / hide, GO TO HOME, SUBMIT | Re-authenticate before showing pay | Opens directly; each open is an audited sensitive view | MISSING | MS-9: setting `compensation.self_service.confirm_password` (default false) → Filament password confirmation (15 minutes) before My compensation, payslips and salary YTD (D-MS10) |
| 2 | Tabs "Salary structure" / "Salary insights" ("You can view your salary structure") | Structure and its analysis | My compensation: in force today, scheduled, history (on by default); wage split and insight behind PS-3 switches | PARTIAL | MS-9 tabs on the existing page |
| 3 | CTC · `costToCompany` | Annual CTC | Annual and monthly CTC | HAVE | — |
| 4 | Gross (B) | Gross | Gross | HAVE | — |
| 5 | "CTC Details" (component breakup) | Components | Component values (input components excluded, BP-2) | HAVE | — |
| 6 | "Statutory details" tab (PF / ESI / PT / LWF applicability) | Statutory options on the salary | Statutory applicability on the employee (HR); not on My compensation | PARTIAL | MS-9 read-only block |
| 7 | "Perquisites Details" | Perquisites | Perquisites this FY (PS-3 switch, off by default) | HAVE | — |
| 8 | Salary effective date · `salaryEffectiveDate` | Since when | Effective from / to per row | HAVE | — |
| 9 | Arrear effective date · `arrearEffectiveDate` | Arrears from | Arrears computed by payroll; not shown | PARTIAL | MS-9: show the arrear-from date of a revision when it differs |
| 10 | Pay group · `payGroup` | Payroll unit | Pay groups pending (BP-12) | MISSING | Owned by BP-12 |
| 11 | Currency · `currencyCode` | Currency | Currency | HAVE | — |
| 12 | Revision mode · `revisionMode`, Revised CTC · `revisedCtcAmount`, Increment % · `incrementPercentage` | How the revision was made | Change type per row and % change (insight switch) | HAVE | — |
| 13 | Remarks · `salaryStructureRemarks` | Revision note | Reasons and internal notes are deliberately never shown | N-A | By design (Phase 11 invariant) |
| 14 | Salary payment mode | Online / cash / cheque | BP-13 payment mode (HR) | PARTIAL | MS-9 read-only |
| 15 | "Employer contribution to NPS": Amount / Percentage, "Do you want to prorate the amount as per paid days?", "Minimum contribution", "PRAN number" | NPS on the salary | NPS only as an 80CCD declaration item; no employer NPS configuration or PRAN | MISSING | Owned by BP-11 (`pran`, statutory options); MS-9 shows it once built |
| 16 | Salary insights: "Compliant as per the New Code of Wages", "Wages are greater than exclusions", "Exclusions are greater than wages. You may want to review the salary structure break up.", "Wages are less than defined minimum wage or minimum wages slab doesn't exist" | Wage-code checks on the structure | Wage split (wages, exclusions, share) behind a switch; no verdict text; no minimum-wage check | PARTIAL | MS-9: verdict from the wage share against the rule-data threshold (no hard-coded 50 %); minimum-wage check only when verified state rule versions exist |

---

## 22.6 Payslip (`/app/myprofile/payslip`; `app-my-payslip`)

Pages `menu_My_space__Payslip`, `rail_My_space__Payslip`, `myspace_Payslip` (same content: Search, Select Language, Salary / Tax cards per month). APIs `payroll/FinancialYear`, `Setting/General`,
`EmployeeSlipTemplateLanguage`, `EmployeeSlip`.

| # | HROne element (tab / field / column / action) | What it does (inferred where unclear, mark *(inferred)*) | PeopleOS today (file or screen) | Status | Build note |
|---|---|---|---|---|---|
| 1 | Help text on the PDF password (last 4 of PAN + month-year of joining; without PAN, month-year only) | Protected PDF payslips | No PDF payslip | MISSING | Owned by BP-14 (password-protected PDF waits for the PDF-library decision); MS-9 shows the tenant's text |
| 2 | Select Language | Payslip language | — | MISSING | Owned by BP-14 (payslip language) |
| 3 | Select Year (financial year) | Payslips of a year | Payslip list (all own payslips, search by number) | PARTIAL | MS-9: "My payslips" page with FY select |
| 4 | Search… | Find a month | Search by number | HAVE | — |
| 5 | Card "Salary <Mon, YYYY>" per month | Salary slip of the month | Own payslip per period (view; viewed-at; audited) | HAVE | — |
| 6 | Card "Tax <Mon, YYYY>" per month | Monthly income-tax computation slip | Collapsed "Tax computation" section on the payslip (key: value lines from the snapshot) | PARTIAL | MS-9: readable tax slip from the payslip's tax snapshot (income, exemptions, deductions, regime, tax, TDS so far / this month) |
| 7 | Document viewer: preview, Download, Download file | View / download the PDF | HTML view only | PARTIAL | BP-14 PDF; MS-9 download button once the PDF exists |
| 8 | "Download form from here" | Form 16 / statutory form | My tax certificates (Form 16 self-service, PS-2) | HAVE | — |
| 9 | "NO PAYSLIP", SHOW MORE | Empty state / paging | Empty state, paging | HAVE | — |

---

## 22.7 Payroll history pages (`/app/myprofile/{salaryytd,loanhistory,variable,proinvestment,coninvestment,fbphistory,reimbursementhistory}/…`)

Captured in the "View all" pass (`myspace_Salary_ytd`, `_Loan_history`, `_Variable_history`,
`_Proposed_investment_history`, `_Confirmed_investment_history`, `_Reimbursement_history`, `_Fbp_approval_history`);
panels that need data come from components.

### 22.7.1 Salary ytd (`/app/myprofile/salaryytd/search`; page `myspace_Salary_ytd`; `app-salary-ytd-process-search` → `app-salary-ytd-process-detail` and its tabs)

API `V3/Salary/Employee/YearToDateSalary/List`. The capture stopped at the password gate; the grid header is visible
behind it. The per-component panels below come from components (they open from a component row *(inferred)*).

| # | HROne element (tab / field / column / action) | What it does (inferred where unclear, mark *(inferred)*) | PeopleOS today (file or screen) | Status | Build note |
|---|---|---|---|---|---|
| 1 | Header: employee name (#code), company, business unit | Whose year | — | MISSING | MS-10 |
| 2 | "Financial year" select (the component also has FILTERS, Apply, CLEAR ALL, SHOW MORE) | Pick the FY | — (self); HR template "Year-to-date salary summary" (gross, deductions, net, TDS per employee) | MISSING | MS-10: "My salary YTD" page (FY select) read through a payroll read contract `PaidPayReader::yearToDate($employee, $fy)` over finalized / paid runs only |
| 3 | Password gate "Enter Password to open Salary YTD — Password for Salary YTD is login password of System", Password (show / hide), GO TO HOME, SUBMIT (`app-password-checker`) | Re-authenticate before pay data | — | MISSING | MS-9 password confirmation |
| 4 | Tabs "Salary" / "Reimbursement" | Salary components vs reimbursements | — | MISSING | MS-10 |
| 5 | Grid: Component, Apr, May, Jun, Jul, Aug, Sep, Oct, Nov, Dec, Jan, Feb, Mar, Total | Each component paid per month and the year total | Payslips per month only | MISSING | MS-10: component × month grid from paid runs, months in the tenant's financial-year order |
| 6 | "DOWNLOAD FORM 124" | Download a statutory income-tax form for the year *(inferred: a form number under the new income-tax rules; which form it is must be verified)* | My tax certificates (Form 16 self-service) and Form 12BB visibility (PS-2) | PARTIAL | Statutory verification first (form identity and template from rule data, BP-15 / PS-2); MS-10 links the download once the form is known |
| 7 | States "SALARY NOT PROCESSED" / "REIMBURSEMENT NOT PROCESSED" | Nothing paid yet | — | MISSING | MS-10 empty states |
| 8 | Component panel (`app-salary-ytd-common-component-information`): Total Paid, Arrear (arrear amount paid, arrear days, arrear month), Dividing Factor, Paid days, Formula, Tax Configuration, Part of Arrear, Prorate as per DOJ/DOL, Prorate as per paid days, Component Type, Total Amount; Configuration / Calculation / Close | How a component was computed this year | Component flags (PS-1) and formulas on salary components (HR) | MISSING | MS-10: "How it is calculated" panel from the component's settings (method, formula text, prorate rules, arrear and tax flags) with the arrear rows |
| 9 | Provident Fund panel (`app-salary-ytd-provident-fund`): Configuration — PF Limit, Prorate PF as per paid days, Prorate employee's PF contribution limit on paid days, Employee's PF Percentage, Process PF on Arrears; Calculation — PF to be calculated on, PF amount on salary, Total PF Amount on arrear; Arrear — Arrear Month, Arrear Days, PF amount on arrear; Total | PF year to date with its basis | PF lines on payslips; EPF settings (HR) | MISSING | MS-10: PF wages and contributions per month (employee, employer EPF / EPS), arrear rows; configuration read from the employee's PF options and the verified rule version (no constants) |
| 10 | Voluntary PF panel (`app-salary-voluntary-provident-fund`): VPF to be deducted on, VPF Wages, VPF Deducted, arrears, VPF to be calculated on, Total | VPF year to date | VPF lines on payslips | MISSING | MS-10 |
| 11 | ESI panel (`app-employee-state-insurance`): Wage ceiling of ESI Contribution, Process ESI on ESI limit, Process ESI on arrear, Process ESI on Overtime, Employees ESI percentage, ESI to be calculated on, ESI Amount on salary, Total ESI Amount on arrear, arrear rows, Employee ESI, Total | ESI year to date | ESI lines on payslips | MISSING | MS-10 (contribution-period view; ceiling from rule data) |
| 12 | Professional tax panel (`app-salary-ytd-professional-tax`): separate PT for arrear?, PT calculated on, Professional Tax Amount, Branch, Total, arrear rows | PT year to date | PT lines on payslips | MISSING | MS-10 (state from the PT registration of the month) |
| 13 | LWF panel (`app-salary-ytd-labour-welfare-fund`): Process LWF on the basis of, Process LWF on arrear, LWF amount, Branch, Total amount deducted, arrear rows, Employee LWF | LWF year to date | LWF lines on payslips | MISSING | MS-10 |
| 14 | Overtime panel (`app-salary-ytd-overtime`): Total paid, Increment Overtime, Overtime hours, Month and year, Increment overtime amount, Previous Month Overtime, Previous month overtime amount, Overtime for month, Overtime amount, Formula | OT paid this year | OT lines on payslips; OT claims | MISSING | MS-10 |
| 15 | Bonus panel (`app-salary-ytd-bonus`): Bonus Percentage, Taxable, Bonus Pay Days, Bonus pay month, Total Paid, Minimum wages, Bonus Amount, Branch Name, Nature of Industry, Skill Type | Statutory bonus year to date | Bonus register builder (compliance reports; BONUS rule awaits owner) | MISSING | MS-10 after the bonus rule is verified |
| 16 | Reimbursement tab table (`app-reimbursement-table`): Total Claimed, Total Paid, Total Bill, Taxable | Claims this year | Claims list per claim; no FY totals | PARTIAL | MS-10 (with RQ-5 FY totals on claims) |

### 22.7.2 Loan, variable, investment, reimbursement and FBP histories

All captured with the account's data or empty state; columns and filters of the empty lists come from components and
matrix 20 §20.1.19.

| # | HROne element (tab / field / column / action) | What it does (inferred where unclear, mark *(inferred)*) | PeopleOS today (file or screen) | Status | Build note |
|---|---|---|---|---|---|
| 1 | Loan history (`/app/myprofile/loanhistory/history`; `EmployeesForLoan/History`; empty state "No results found — Try adjusting the filters or come back later!") | Own loans | Loans (own, "Me") — matrix 20 §20.1.3 and §20.1.19 #17 | HAVE | See matrix 20 (RQ-1 date filters, RQ-5) |
| 2 | Variable history (`/app/myprofile/variable/search`; `EmployeeWiseVariable/History`): cards with the employee header (avatar, name, code, company, BU), "Requested by", "Requested on", "Pay month", status chip (Approved …); a card may show a manager as requester; no filters on the page | Own variable pay requests, including ones raised for the employee | Variable pay requests (own; requester shown; manager / HR on-behalf) — matrix 20 §20.1.15, §20.1.19 #16 | HAVE | See matrix 20 |
| 3 | Proposed investment history (`/app/myprofile/proinvestment/history`; financial-year list; `EmployeesForInvestment/History`) | Own proposed declarations | Tax declarations (own), Declare investments — matrix 20 §20.1.5, §20.1.19 #19 | PARTIAL | See matrix 20 (RQ-7b) |
| 4 | Confirmed investment history (`/app/myprofile/coninvestment/history`; financial-year list; city lookup for rent) | Own proof submissions | My investment proofs — matrix 20 §20.1.6, §20.1.19 #19 | PARTIAL | See matrix 20 (RQ-7b) |
| 5 | Reimbursement history (`/app/myprofile/reimbursementhistory/history`; filters financial year and reimbursement component; `EmployeesForReimbursement/History`) | Own claims | Reimbursement claims (own; status and component filters) — matrix 20 §20.1.4, §20.1.19 #18 | PARTIAL → built (RQ-1: in part: claims in My requests with claim / requested / decided date ranges; the component filter and FY totals stay RQ-5) | See matrix 20 (RQ-1, RQ-5) |
| 6 | Fbp approval history (`/app/myprofile/fbphistory/search`; `EmployeesForSalaryStructure/FBP/History`; component `app-fbp-history-search`: Salary Structure, Request date, Approved date, Status filter, workflow; `app-define-salary-structure` with salary insights and remarks) | Own flexible-benefit-plan choices and their approval | FBP not built | MISSING | Owned by BP-7 (FBP, PY-03); MS-1 lists it once built |

---

## 22.8 Goals and initiatives (`/app/myprofile/my-goals-and-initiatives`; `app-my-goals-and-initiatives`)

Pages `menu_My_space__Goals_and_initiatives` (+ `__tab_01_Team`, `__tab_02_Company`), `myspace_Goals_and_initiatives` (+ the same tabs; same content). APIs `pms/initiative/Request/Search`,
`initiative/Goals/PeriodDetails`, `setting/initiative/Type/Details`, `Team/Direct/Indirect`. Page-access actions: Goal
view, Initiatives create, Initiatives complete, Initiatives view.

| # | HROne element (tab / field / column / action) | What it does (inferred where unclear, mark *(inferred)*) | PeopleOS today (file or screen) | Status | Build note |
|---|---|---|---|---|---|
| 1 | Page "Goals and initiatives assigned to you are visible here" | Own goals in one place | Goals list (Performance group): own goals, reports' goals for managers, organisation-level goals for everyone | PARTIAL | MS-14: "My goals" page (Me) over `GoalResource` data |
| 2 | Period · Performance cycle select | Cycle | Cycle filter | HAVE | — |
| 3 | Tabs Individual / Team / Company | Goal level | Goal levels employee / team / department / business / company; filter, not tabs | PARTIAL | MS-14 tabs (Team = team / department goals of the employee's units) |
| 4 | "KRA (n) – x%" / "Competency (n) – x%" | Count and weight of KRAs and competencies | Goal types KRA / KPI with weights; competencies in the review template | PARTIAL | MS-14 summary chips |
| 5 | "View Other Goals" (`app-view-other-goal`: Company / Team / Other goals, "Search team member", Details) | Browse others' goals | Organisation goals visible; others' personal goals only to their managers | PARTIAL | MS-14 (other people's goals only when shared, MS-6 `goals.shared`) |
| 6 | "Edit" → change goal (`app-edit-goal`, "No approved goal available") | Revise the approved goal sheet | Change goal (matrix 20 §20.1.14) | HAVE | — |
| 7 | Goal view form (`app-goal-approval-view-form`): KRA name · `keyResultAreaName`, % weightage · `keyResultWeightage`, group · `groupId`, KPI name · `keyPerformanceIndicatorName`, weight, target · `keyPerformanceTarget`, UOM · `unitOfMeasurementId`, target due date · `targetDueDate`, competency name / weight, Attachment, Period | Goal sheet detail | Goal sheet (HP-8d): KRAs, KPIs, weights, units, due dates (HP-8a), competencies | HAVE | — |
| 8 | Feedback template mapping on a goal ("Please select the feedback template you want to map", `app-inbox-feedback-template`) | Feedback form per goal | Feedback templates exist (P13); not mapped to goals | PARTIAL | Owned by the Performance matrix (feedback-template mapping, matrix 20 "not packaged") |
| 9 | Check-in (`app-check-in`: "From here you can update the progress on KPI", Progress, Comment, Status, Start date, End date) | Update KPI progress | Key-result progress and check-ins (Phase 7) | HAVE | — |
| 10 | Company / team goal table (`app-company-goal`, `app-team-goal`): S.no, Employee name, Linked, KPI details, Check-In, Details, Achievement, Status, Comment, Activity, "System calculated achievement", Progress, "Employee unlinked cannot be linked again" | Who linked their KPIs to a shared goal and the roll-up | Goal cascade (`parent_id`); no linked-people table or roll-up view on the parent | PARTIAL | MS-14: "Linked goals" on a company / team goal (children, owner, progress; achievement roll-up read-only) |
| 11 | Initiatives: "Initiatives: Planned, Overdue", Assigned to, Created by, "NO INITIATIVES YET" | Action items under goals | My initiatives (HP-8j, Me; `InitiativeResource`): tabs All / Mine / My team / Asked for my feedback; columns assigned to, initiative (type), goal, due date (sortable), completion %, status, assigned by, feedback count; filters status (planned / in progress / completed / cancelled) and type; empty state "No initiatives yet" | HAVE | MS-14 shows them as a tab of "My goals" (overdue = open and past due date as a quick filter) |
| 12 | Initiative details (`app-initiative-details`, `app-assign-initiative`): create (initiative · `name`, Due date · `dueDate`, assign to · `employee`, KPI · `kpi`, type · `initiativeTypeId`; Save / Save and new), Completion %, "Are you sure, you want to complete?", Completed on, comments, activity log, "Do you want to request feedback for this initiative?" (choose an employee), VIEW FEEDBACK | Run an initiative to completion with feedback | My initiatives: "Create an initiative" (assign to self or a report, type, title, description, linked goal instead of a KPI, start / due date), Start, Update completion, Complete, Cancel (reason), Request feedback (choose a colleague), Give feedback (manager), Answer / Ignore a request (also a My tasks item); every change audited; no comment thread | PARTIAL | Comments owned by matrix 27 GO-8 (append-only initiative comments) |

---

## 22.9 Performance scorecard (`/app/myprofile/my-performance`; `app-my-performance` → `app-performance-view`)

Pages `menu_My_space__Performance_scorecard` (+ tabs Completed initiatives, 1 on 1, 360 feedback, Continuous feedback), `myspace_Performance_scorecard` (+ the same tabs; APIs add `pms/promotion/training`, `employees/feedback`, `threesixty/feedback/PeriodWise`, `review/FinalRating`).
Page-access actions: Performance scorecard, Initiatives, 1 on 1, 360 feedback, Continuous feedback, Training.

| # | HROne element (tab / field / column / action) | What it does (inferred where unclear, mark *(inferred)*) | PeopleOS today (file or screen) | Status | Build note |
|---|---|---|---|---|---|
| 1 | Page "Scorecard for completed performance reviews can be viewed here"; "Performance year" select (Apr–Mar) | All performance outcomes of a year | Appraisals list (own; ratings shown only once published, AppraisalVisibility); cycle filter | PARTIAL | MS-13: "My scorecard" page per performance year |
| 2 | Tab "Review (n)": "Review period" select, "Review rating (n)", Overall Rating, Average rating, Rating (stars) | Published review results | Appraisal: goal score, competency score, computed / final rating and label, manager summary (after publication); HP-8d hides chosen parts from the employee | HAVE | — |
| 3 | "View calculation" (`app-rating-calculation`, `app-review-formula-flyer`) | How the overall rating was computed | Computed rating shown; the formula is not | PARTIAL | MS-13: calculation panel (weights × scores per section, KPI rating formula per KPI from HP-8c) |
| 4 | Tabs "KRA ()" / "Competency ()" with per-item ratings | Item-level ratings | Per-item ratings live in the review form | PARTIAL | MS-13 lists them (hidden when HP-8d `scorecard_hidden` says so) |
| 5 | Tab "Completed initiatives (n)": Initiative, Initiative Type, Due date, Completed on, Completion percentage, Feedback, Comments, Check In History (Target, UOM, Achievement, Status, Comments) | Initiatives finished in the year | Completed initiatives exist with type, due date, completed on, completion %, outcome and feedback (My initiatives, status filter); not on a scorecard; no comments or check-in history | PARTIAL | MS-13 tab over `Initiative` (completed in the performance year); comments GO-8 |
| 6 | Tab "1 on 1 (n)": Agenda, Date, Time, (Initiator) & (Participant), Progress, Plans, Challenges, Any other feedback, Time zone, Meeting type, Attachments, Meeting room, Meeting URL | Held one-on-ones of the year | One-on-ones (own): scheduled / held at, agenda, notes, action items, status; feedback card (HP-8b); no meeting type / room / URL / attachments | PARTIAL | MS-13 tab; meeting fields in §22.14.1 |
| 7 | Tab "360 feedback (n)": groups Peers, Subordinate, Client, Reporting manager, Functional manager; "Average rating -", "Chose not to participate.", IGNORED; answers (`app-inbox-dynamic-form`: rating, subjective answer, attachment) | Multi-rater results | Peer rating on the appraisal and peer reviews; `performance.profile.show_360` (default off); full 360 not built | PARTIAL | HP-8h / SV-12; MS-13 tab honours `performance.profile.show_360` |
| 8 | Tab "Continuous feedback (n)": Feedback from, Feedback type, Feedback on | Feedback received in the year | Continuous feedback entries (types, visibility, anonymous) visible to the employee by the feedback rules | HAVE | MS-13 lists them per year |
| 9 | "Trainings (n)": Training Name, Recommended on, Recommended by | Trainings recommended after review | Learning enrolments and recommendations (My learning); development needs | PARTIAL | MS-13 tab (enrolments and development items linked to the cycle) |
| 10 | Variable Details (S.no., Pay component, Pay variable, Attachment), Increment Details, Promotion Details, Promotion Recommendation, Designation | Outcomes of the review | Appraisal "promotion recommended", "improvement plan recommended" flags; compensation changes separate | PARTIAL | MS-13: outcomes block behind setting `performance.scorecard.show_outcomes` (default false); amounts only through `CompensationOutput` |
| 11 | "Not mapped", "Excluded", "View Details", "View Feedback", "NO DATA AVAILABLE" | Item states | Equivalent states on the appraisal | HAVE | — |

---

## 22.10 HR Handbook (`/app/myprofile/policies`; `app-my-policies`, `app-ack-hr-handbook`)

Pages `menu_My_space__HR_Handbook`, `rail_My_space__HR_Handbook`, `myspace_HR_Handbook` (same content).

| # | HROne element (tab / field / column / action) | What it does (inferred where unclear, mark *(inferred)*) | PeopleOS today (file or screen) | Status | Build note |
|---|---|---|---|---|---|
| 1 | "HR HANDBOOK" list of policies (card: title, "Published on :") | Policies for the employee | My HR → Policies and HR articles ("Me" group for readers), audience-filtered | HAVE | — |
| 2 | "Search keyword" | Search | Title search | HAVE | — |
| 3 | Filter "Published On" · `publishedFrom`, CLEAR ALL, "Select filter to quickly sort and view specific HR policies", "NO RESULTS FOUND" | Filter by date | "Published" date-range filter on articles | HAVE | — |
| 4 | Acknowledge handbook (`app-ack-hr-handbook`) | Read-and-accept | Acknowledge (web / IP recorded), due days and reminders (HP-9a) | HAVE | — |
| 5 | Policy view (`app-policy-view`, `app-policy-view-flyer`) | Read a policy | Article view (versions) | HAVE | — |
| 6 | "NO HR HANDBOOK" | Empty state | Empty state | HAVE | — |

---

## 22.11 Holiday calendar (`/app/myprofile/my-holiday`; `app-my-holiday`)

Pages `menu_My_space__Holiday_calendar`, `myspace_Holiday_calendar` (same content). API `setting/holiday/Calendar/Employee/{year}/{employee}`.

| # | HROne element (tab / field / column / action) | What it does (inferred where unclear, mark *(inferred)*) | PeopleOS today (file or screen) | Status | Build note |
|---|---|---|---|---|---|
| 1 | "Holidays for year, <year>" + year select ("You can see list of holidays in calendar year.") | The employee's holiday list for a year | Holidays of the month under the attendance calendar; holiday calendars are HR-only | PARTIAL | MS-12: "My holidays" page (year select) from `HolidayResolver::calendarFor` |
| 2 | Tabs "Fixed holidays (n)" / "Restricted holiday (n)" | Fixed vs optional | Kinds shown per month ("Optional · chosen / not chosen") | PARTIAL | MS-12 tabs with counts |
| 3 | Columns Holiday name, Day, Date, Type (e.g. National holiday) | Holiday facts | Name, date, kind (month list) | PARTIAL | MS-12 adds weekday and the year view |
| 4 | "Note :" (calendar note) | Remarks of the calendar | — | MISSING | MS-12: `holiday_calendars.employee_note` (text, optional) |
| 5 | "Holiday Calendar" (calendar view) | Calendar layout | Attendance calendar marks holidays | HAVE | — |
| 6 | Choose a restricted holiday from the list | Apply RH | Time-office request type restricted holiday (matrix 20 §20.1.7) | PARTIAL | MS-12 "Choose" link pre-fills the RH request (RQ-7a helper) |

---

## 22.12 Leave balance (`/app/myprofile/myleave-balance`; `app-my-leave-balance` → `app-leave-balance-view`, `app-leave-balance-card`)

Pages `menu_My_space__Leave_balance`, `rail_My_space__Leave_balance`, `myspace_Leave_balance` (same content). API `request/employee/leave/Type/{employee}/false/{year}`.

| # | HROne element (tab / field / column / action) | What it does (inferred where unclear, mark *(inferred)*) | PeopleOS today (file or screen) | Status | Build note |
|---|---|---|---|---|---|
| 1 | Header "<name> (#code)", company, BU | Whose balance | Own balances | HAVE | — |
| 2 | Year select | Leave year | Year filter on balances and transactions | HAVE | — |
| 3 | Balance card per leave type (`app-leave-balance-card`) | Opening, credited, used, available | Leave balances (own): opening, accrued, adjusted, used, pending, encashed, lapsed, closing, available; My day balances | HAVE | — |
| 4 | Table "Leave month name", "Balance added", "Balance subtracted" | Month-by-month movement | Ledger rows per movement; no month pivot | PARTIAL | MS-12: month × added / subtracted per leave type from `LeaveTransactions` |
| 5 | Transaction list: Transaction date, Action type, Leave count, Reason | Ledger | Leave transactions (own): entry date, movement, days, note | HAVE | — |
| 6 | "Leave Donate": "To whom do you want to donate?", "How many leaves you want to donate?", Remarks (max 200 characters), Cancel | Donate leave | Leave donations (own, "Me"; approval) | HAVE | — |
| 7 | Apply leave from the page (`app-leave-request-flyer`) | Start a leave | Apply leave on My day / Home | HAVE | — |
| 8 | "NO LEAVE BALANCE", "Note:" | Empty state / note | Empty state | HAVE | — |

---

## 22.13 Letter (`/app/myprofile/letters`; `app-my-letters`, `app-acknowledge-letter`, `app-acknowledge-letter-details`, `app-letter-acceptance`)

Pages `menu_My_space__Letter`, `myspace_Letter` (same content).

| # | HROne element (tab / field / column / action) | What it does (inferred where unclear, mark *(inferred)*) | PeopleOS today (file or screen) | Status | Build note |
|---|---|---|---|---|---|
| 1 | "Letter — Here, you can find all the letters issued by the company." (cards, SHOW MORE, "NO LETTER") | Own letters | Letters (own issued letters, "Me"), My HR → Documents and letters | HAVE | — |
| 2 | Document viewer: preview, Download | Read / download | Open and Download (PDF / Word as generated) | HAVE | — |
| 3 | Acknowledge / accept a letter (`app-acknowledge-letter-details`, `app-letter-acceptance`) | The employee accepts the letter | No letter acknowledgement | MISSING | RQ-10 (matrix 20: template flag "needs acknowledgement" and a task) |

---

## 22.14 The other My space items ("View all")

Reached from "VIEW ALL" (§22.1.1) and the global search (`app-profile` children). Captured as `myspace_*` pages (URLs
below) except Job requisition, Mark attendance and Position assigned (listed but not opened) and Trips (no page).

### 22.14.1 1 on 1 (`/app/myprofile/one-on-one`; page `myspace_1_on_1`; `app-my-one-on-one`, `app-schedule-one`, `app-recurrence-dialog`)

| # | HROne element (tab / field / column / action) | What it does (inferred where unclear, mark *(inferred)*) | PeopleOS today (file or screen) | Status | Build note |
|---|---|---|---|---|---|
| 1 | Page "1-on-1": cards ("You", "Status :", "On :", SHOW MORE); empty state "NO ONE ON ONE SCHEDULED" | Own one-on-ones | One-on-ones (own as employee or host) | HAVE | — |
| 2 | Unpublish / cancel ("You want to unpublish the one on one. This action is irreversible and the data will be permanently lost. Are you sure?") | Cancel a scheduled meeting | Cancel status on the one-on-one (data kept, audited) | HAVE | Deleting data is not copied (audit invariant) |
| 3 | "Schedule 1-on-1": 1 on 1 with · `employee`, Agenda · `agenda` (emoji picker), Select date · `date`, Start time / End time, Save, "Save and new" | Book a meeting | Schedule a one-on-one (scope team / organisation, HP-8b): person, date-time, agenda | HAVE | — |
| 4 | Meeting type · `meetingType`, Time zone · `timeZoneId`, Meeting address / room / URL · `meetingAddress` | Where and how to meet | — | MISSING | MS-13: `one_on_ones.meeting_type` (in person / video / call), `location_or_url` (https only for URLs), time zone from the host |
| 5 | Frequency · `intervalId` + recurrence dialog (Start date, End date "up to 3 months from the current date", Repeat every, weekdays, "On day" / "On the …") | Recurring one-on-ones | One meeting per record | MISSING | MS-13: series (`one_on_one_series`: rule weekly / monthly, until ≤ 3 months) creating individual meetings; editing one occurrence leaves the rest |
| 6 | "You can also connect your Google/Teams account to send invites on other's calendars directly" | Calendar invites | — | MISSING | External (calendar integration; owner decision on providers) — an .ics attachment on the invitation e-mail is the in-house step (MS-13) |

### 22.14.2 Items that are request forms or request history lists (analysed in matrix 20)

Each opens its own page under `/app/myprofile/…` (captured empty for this account: title, help line where shown, empty
state); columns and filters come from the components analysed in matrix 20 §20.1.19.

| # | HROne element (tab / field / column / action) | What it does (inferred where unclear, mark *(inferred)*) | PeopleOS today (file or screen) | Status | Build note |
|---|---|---|---|---|---|
| 1 | Asset request (`/app/myprofile/assetrequest/search`; "No results found — Try adjusting the filters or come back later!") | Own asset requests | Asset requests (own, "Me") — matrix 20 §20.1.17, §20.1.19 #15 | HAVE | — |
| 2 | Attendance regularization (`/app/myprofile/attendanceregularization/search`) | Own AR history | Regularisations (own) — matrix 20 §20.1.8, §20.1.19 #3 | PARTIAL → built (RQ-1: its own type in My requests with AR date / requested / decided ranges; calendar picking stays RQ-3) | RQ-1, RQ-3 |
| 3 | Change shift (`/app/myprofile/changeShiftSearch`; "View all your past and upcoming Change shift requests here"; shift and weekly-off change requests) | Own shift / weekly-off change history | Time-office requests — matrix 20 §20.1.18, §20.1.19 #8 | PARTIAL → built (RQ-1: change shift and week-off change as their own types in My requests; per-date rows stay RQ-3) | RQ-1, RQ-3 |
| 4 | Compensatory off (`/app/myprofile/compoff/search`) | Own comp-off claims | Time-office comp-off claims — matrix 20 §20.1.19 #9 | PARTIAL → built (RQ-1: comp-off as its own type in My requests with its date ranges) | RQ-1 |
| 5 | Expense advance (`/app/myprofile/expenseadvance/search`) | Own advances | Expense advances (own) — matrix 20 §20.1.11, §20.1.19 #12 | HAVE | — |
| 6 | Expense receipt (`/app/myprofile/expensereceiptglobal/search`; card / project lookups) | Own receipts | Expense receipts (own) — matrix 20 §20.1.12, §20.1.19 #20 | HAVE | Project fields blocked (projects) |
| 7 | Expense report (`/app/myprofile/expensereceipt/search`; "Filters" button; project lookup) | Own expense reports | Expense reports (own, payment details when `expense.payment.show_to_employee`) — matrix 20 (EX-C / EX-G rows) | HAVE | — |
| 8 | Leave (`/app/myprofile/leave/search`; empty state "No Leave") | Own leave history | Leave requests (own) — matrix 20 §20.1.18, §20.1.19 #4 | PARTIAL → built (RQ-1: leave in My requests with its date ranges; form additions stay RQ-4) | RQ-1, RQ-4 |
| 9 | Leave encashment (`/app/myprofile/leaveEncashmentSearch`; "View all your past and upcoming Leave Encashment requests here"; "No Leave Encashment") | Own encashments | My encashments — matrix 20 §20.1.19 #11 | HAVE | — |
| 10 | On duty (`/app/myprofile/onduty/search`; project lookup) | Own on-duty requests | Time-office requests — matrix 20 §20.1.18, §20.1.19 #5 | PARTIAL → built (RQ-1: on duty as its own type in My requests; per-date slots stay RQ-3) | RQ-1, RQ-3 |
| 11 | Overtime (`/app/myprofile/overtime/search`) | Own OT claims | Time-office overtime claims — matrix 20 §20.1.19 #10 | PARTIAL → built (RQ-1: overtime as its own type in My requests with its date ranges) | RQ-1 |
| 12 | Restricted holiday (`/app/myprofile/restrictedholiday/search`) | Own RH | Time-office requests — matrix 20 §20.1.7, §20.1.19 #6 | PARTIAL → built (RQ-1: restricted holiday as its own type in My requests; the quota helper stays RQ-7a) | RQ-1, RQ-7a |
| 13 | Short leave (`/app/myprofile/shortleave/search`) | Own short leave | Time-office requests — matrix 20 §20.1.9, §20.1.19 #7 | PARTIAL → built (RQ-1: short leave as its own type in My requests; calendar picking stays RQ-3) | RQ-1, RQ-3 |
| 14 | Ticket (`/app/myprofile/tickets`; `expense/travel/booking/Details`): "Tickets — You can find all the tickets booked by the company.", "Status" select (default Booked), "NO TICKETS" | Travel tickets the company booked for the employee (not helpdesk tickets) | Travel bookings are raised for the travel desk when a trip is approved (`TravelBookings`; trip status booking → booked; EX-F) and seen on the trip; no own-bookings list | PARTIAL | RQ-7a: "My bookings" list over `TravelBookings` with a status filter (booked / cancelled …) |
| 15 | Travel (`/app/myprofile/travel/search`; state / city lookups, tax settings) | Own trips | Travel requests (own) — matrix 20 §20.1.16, §20.1.19 #14 | PARTIAL | Project fields blocked (projects) |
| 16 | Ticket filters and card (component `app-travel-tickets`: "You can use 1 or more filters", From, To, From date, To date, Type, Project, category, "Filters Applied", CLEAR ALL, APPLY FILTER; ticket documents in the viewer) | Narrow the booked tickets; open the ticket file | Booking details on the trip; no filters | PARTIAL | RQ-7a (same list); project filter blocked (projects) |
| 17 | Page chrome of these lists: title, help line, empty state "No results found — Try adjusting the filters or come back later!" | Consistent list pages | Filament lists: title, empty state, filters | HAVE | — |

### 22.14.3 Attendance ytd (`/app/myprofile/attendanceytd/user`; page `myspace_Attendance_ytd`; `app-attendance-ytd-*`, `app-att-ytd-deduction`)

The capture stopped at the loader: a filter strip and a table skeleton (avatar, name and four figure columns). The APIs
show the HR attendance-YTD search reused in "user" mode *(inferred)*.

| # | HROne element (tab / field / column / action) | What it does (inferred where unclear, mark *(inferred)*) | PeopleOS today (file or screen) | Status | Build note |
|---|---|---|---|---|---|
| 1 | YTD table (avatar, name, figure columns; month cards in the component, SHOW MORE) | The year's attendance month by month | — (self); HR report "Paid day YTD" | MISSING | MS-11: "My attendance this year" (per month: present, absent, leave paid / unpaid, holidays, weekly offs, on duty / WFH, paid days, penalty days, arrear days, OT hours) from `AttendanceOutput::summary` per month |
| 2 | Filters loaded with the page: financial year (`FinancialYearList`), month (`MonthYear`), pay group, company, business unit, department, sub department, designation, grade, level, branch, region, sub branch, employee status / type / other status | Pick the period (organisation filters belong to the HR version) | HR report filters | MISSING | MS-11: financial year and month only; organisation filters stay HR-only |
| 3 | Month details (select, counts) | One month's figures | Attendance calendar summary for a month | PARTIAL | MS-11 (drill to the calendar) |
| 4 | Deduction table: Attendance Month, Arrear Days, Attendance Date, Value, Reason, Leave Code, Leave Name, "Change" | Penalties and arrears per day | Attendance penalties and attendance arrears (HR lists) | PARTIAL | MS-11 (own rows, read-only; "Change" = raise AR through RQ-3) |

### 22.14.4 Badges and rewards (`/app/myprofile/my-badges-and-rewards`; pages `myspace_Badges_and_rewards` + tabs; `app-my-badges-and-rewards`, `app-points`, `app-rewards-credit-ledger`)

| # | HROne element (tab / field / column / action) | What it does (inferred where unclear, mark *(inferred)*) | PeopleOS today (file or screen) | Status | Build note |
|---|---|---|---|---|---|
| 1 | Page "Badges and reward points — It is a token of appreciation given or received to the employees to motivate them to work."; tabs Badges / Reward points / Reward ledger | Own recognition in one place | Recognition (filters "Received by me" / "Given by me") and Reward points (own ledger) are two lists | PARTIAL | MS-15: one "My badges and points" page with the three tabs over the existing reads |
| 2 | Badges tab: "Badges" select (Received / Given *(inferred)*), "Period" select (preset "3 months"), "NO BADGES" | Badges received or given in a period | Received / given filters; no period presets | PARTIAL | MS-15: period presets (3 / 6 / 12 months, this year) |
| 3 | Badge cards: badge, from, message, Download Attachment, Download Certificate, Reward, Internal Note | Badges received | Recognition rows: badge, to, from, reason, attachment, certificate, LinkedIn share, withdrawn state | HAVE | Internal notes never shown to the receiver |
| 4 | Reward points tab: "Reward points" select (Received / Given *(inferred)*), "Period", "Filter by" (All …), "NO REWARD POINTS" (`RewardPoints/PointsFeedDetails`, `RewardEligibilityList`) | Points received / given | Reward points ledger (own) with an entry-kind filter | HAVE | Period presets with row 2 |
| 5 | Balance chip "<n> pts = <currency> <amount>" (`RewardRedeem/PointDetails`) | Points and their money value | Points to give and points received in the subheading; the value per point is used only when redeeming | PARTIAL | MS-15: show the value of the received balance (value per point × points) when payroll redemption is on |
| 6 | Points panel (component): "HROne wallet points are the reward points received that still have not been redeemed or en-cashed", Claim, Comments, "Not transferred", On | Wallet explanation and entries | Ledger entries with kind and message | HAVE | — |
| 7 | Reward ledger tab: Received / Redeemed, Period (date range), columns Date, Reward points, Received/Redeemed, Remarks; "Current balance :"; "No transactions yet — Check back later for updates" (`RewardPoints/EmployeeLedger`) | The points ledger | Reward points ledger (date, entry, from / to, message, amount; kind filter) | HAVE | — |
| 8 | "Redeem" / "Transfer reward points" (Reward points to be transferred, Conversion rate, "Insufficient available credits. Please contact HROne support") | Turn points into money or vouchers | Redeem own points through payroll (`RewardPointActions::redeemOwn`, value per point, base currency) | HAVE | Voucher transfer → §22.14.4a |
| 9 | "Reward Store" button (opens the store) | Go to the voucher store | — | MISSING | §22.14.4a (D-MS6) |

### 22.14.4a Reward store (`/app/myprofile/reward-store`; page `myspace_Reward_store`; `app-reward-store`, `app-category-header`, `app-category-sidebar`, `app-reward-view`, `app-redeem-history-drawer`)

APIs `integration/rewards/categories`, `integration/rewards/products`, `RewardPoints/TotalRewardPoints/{employee}/LT`: a
third-party voucher catalogue.

| # | HROne element (tab / field / column / action) | What it does (inferred where unclear, mark *(inferred)*) | PeopleOS today (file or screen) | Status | Build note |
|---|---|---|---|---|---|
| 1 | Header "Reward Store", lifetime points ("No points available"), "Redeem History", "Cart" | Shop vouchers with points | — (HP-9d: vendor vouchers EXT) | MISSING | External (voucher vendor, D-MS6); no build until chosen |
| 2 | "Category" sidebar (Accessories, Charity, Co-Working Marketplace, Digital wallet, Dining and Experience, E-commerce, Electronics, Entertainment, Fashion and Lifestyle, Fuel, Furnishing, Grocery Dept. Stores, Health and Beauty, Home and Furnishings, Hotels, Jewellery, Online subscription, Travel and Booking, Verification) | Browse the vendor catalogue | — | MISSING | External (D-MS6) |
| 3 | "Hot Deals and Best Sellers": brand e-gift card tiles with "View" | Pick a voucher | — | MISSING | External (D-MS6) |
| 4 | Redeem history drawer: Redeemed on, Redeemed, Denomination, Quantity, Delivery option, Receiver's name / mobile / email, Coupon History | Past voucher orders | — | MISSING | External (D-MS6) |
| 5 | "Credit ledger" / "View credit ledger" in the store header | Points ledger from the store | Reward points ledger | HAVE | — |
| 6 | "FOR SUPPORT / ENQUIRIES:" vendor phone and e-mail | Vendor support | — | N-A | Vendor content |

### 22.14.5 Clearance (`/app/myprofile/clearance`; pages `myspace_Clearance` + tab Pending; `app-my-clearance`, `app-clearance-task-card`)

| # | HROne element (tab / field / column / action) | What it does (inferred where unclear, mark *(inferred)*) | PeopleOS today (file or screen) | Status | Build note |
|---|---|---|---|---|---|
| 1 | "Clearance Tasks" with tabs Completed (n) / Pending (n); "NO DATA FOUND" (`EmployeeRequest/EmployeeClearanceStatus/{employee}`) | The clearance tasks of the employee's own exit and those assigned to them as an owner *(inferred)* | The own exit case shows its clearances (read); owners (user or role) are notified and clear them on the exit case with `exit.clear` (managers hold it, employees do not); no "my clearance tasks" list | PARTIAL | MS-16: `ExitClearanceTaskSource` in `experience.task_sources` (owner user or role members, open clearances) + "Clearance tasks" list with Pending / Completed; acting needs only ownership of that clearance; the exiting employee sees the status of each clearance of their case |
| 2 | Card: Recovery Amount, Comments, Sub-status, Completed / Pending, DONE | Clear with a recovery amount | `recoverable_amount`, `remarks`, items, status on `exit_clearances` | HAVE | — |
| 3 | Card: Related link, Video Link (video player), Attachment | Guidance attached to the clearance item | Checklist items are text | PARTIAL | MS-16: clearance checklist items may carry a link (https) and an attachment |

### 22.14.6 Confirmation (`/app/myprofile/confirmation/search`; page `myspace_Confirmation`; `app-confirmation-process-search` → card, view form `app-confirmation-task`, workflow)

| # | HROne element (tab / field / column / action) | What it does (inferred where unclear, mark *(inferred)*) | PeopleOS today (file or screen) | Status | Build note |
|---|---|---|---|---|---|
| 1 | Confirmation list (`globalSearch/Confirmation`; cards, view form; empty state "No results found") | Confirmations the person takes part in (own, or as manager) | Confirmation reviews for HR (`employee.lifecycle`); managers answer the confirmation feedback request (P13); the employee sees only the confirmation date | PARTIAL | MS-16: own confirmation status (due date, extended to, outcome, letter) on My profile when `workforce.confirmation.visible_to_employee` (default false) |
| 2 | Workflow tracker on a confirmation | Approval route | Workflow run for HR | PARTIAL | RQ-2 |

### 22.14.7 Exit interview (`/app/myprofile/exitinterview/search`; page `myspace_Exit_interview`; `app-exit-interview-process-search` → card "Relieving date :", view form "Feedback")

| # | HROne element (tab / field / column / action) | What it does (inferred where unclear, mark *(inferred)*) | PeopleOS today (file or screen) | Status | Build note |
|---|---|---|---|---|---|
| 1 | Exit interview list (`globalSearch/ExitInterview`; card with relieving date; feedback form; "No results found — Try adjusting the filters or come back later!") | Answer / view the exit interview | Feedback requests (Me → Feedback): exit interview forms from templates, also in My tasks; manager and HR exit interviews (WF-3) | HAVE | — |

### 22.14.8 Geo location (`/app/myprofile/geo-location`; page `myspace_Geo_location`; `app-my-geo-location`, `app-approved-locations-flyer`, `app-polygon-preview-map`) and Wi-Fi details (`app-my-wifi-details`)

APIs `Policy/EmployeeGeoFencingLocationType/{employee}`, `mobile/checkin/Employee/ApprovedLocation/Details`. The self page
lists approved locations only; adding or deleting is in the approved-locations flyer (manager / HR view *(inferred)*).

| # | HROne element (tab / field / column / action) | What it does (inferred where unclear, mark *(inferred)*) | PeopleOS today (file or screen) | Status | Build note |
|---|---|---|---|---|---|
| 1 | "Radius based geo location(s)": a card per approved location — address, map pin, "Requested on", "Approved on", "Source" (Approved location …), "Co-ordinates" | Places the employee may check in from (radius type per the geo-fencing policy) | Location requests (own): place, coordinates, needed from / until, reason, status; approved ones become geo work locations; geo fences (HR) | HAVE | — |
| 2 | "You want to get location approval for" — "Forever" (or a period) | How long the location stays valid | Valid from / until on the location request | HAVE | — |
| 3 | Map preview (pin, polygon preview) | See the place on a map | No maps | MISSING | Map provider (matrix 01 CA-8, blocked) |
| 4 | "Add location" (flyer: Location name · `geoLocation`, Latitude, Longitude, range checks −90…90 / −180…180, help "Get the latitude and longitude of a place: Google Maps …") | Ask for / add a location | Location request (place, latitude, longitude, reason) for the employee; HR adds geo fences | HAVE | — |
| 5 | "Delete location — Please fill the reason in comment" (flyer) | Remove an approved location | HR edits / deactivates geo work locations | N-A | Manager / HR action (not on the self page) |
| 6 | Wi-Fi details (component only; no My space page): "Registered Devices — Authorized router MACs approved for access to this employee's profile", "Date Added", "Registered Wifi — Enable seamless office check-ins using secure Wi-Fi and device-based attendance tracking", SOURCE, "No registered WiFi" | The employee's Wi-Fi and device registrations | Check-in devices (HR list, TO-2 Wi-Fi rules) | PARTIAL | MS-16: read-only "My check-in devices and Wi-Fi" from `EmployeeDevice` and the check-in policy |

### 22.14.9 Goal history (`/app/myprofile/goal/history`; page `myspace_Goal_history`; `app-goal-search` → `app-goal-cards`)

| # | HROne element (tab / field / column / action) | What it does (inferred where unclear, mark *(inferred)*) | PeopleOS today (file or screen) | Status | Build note |
|---|---|---|---|---|---|
| 1 | Goal-sheet history (`goal/requestFlow/Search/History`, period list): cards "Goal period :", "Version :", Status, EDIT; filters Goal period, Status; "No results found — Try adjusting the filters or come back later!" | Past and current goal sheets and their versions | Goals list (own) with cycle and status filters; goal-sheet revisions through change goal | HAVE | — |

### 22.14.10 Job requisition (`app-my-job-opening`; listed in "View all", not opened by the crawl)

| # | HROne element (tab / field / column / action) | What it does (inferred where unclear, mark *(inferred)*) | PeopleOS today (file or screen) | Status | Build note |
|---|---|---|---|---|---|
| 1 | Own job requisitions / openings | Hiring requests | — | N-A | Recruitment (separate RMS) |

### 22.14.11 Location approval (`/app/myprofile/locationapproval/search`; page `myspace_Location_approval`; `app-location-approval-search` → `app-mark-attendance-card`)

| # | HROne element (tab / field / column / action) | What it does (inferred where unclear, mark *(inferred)*) | PeopleOS today (file or screen) | Status | Build note |
|---|---|---|---|---|---|
| 1 | Cards: employee header (avatar, name, code, company, BU), "Location status :" (Active / Inactive), "Requested Date :", status chip (Approved / Pending / Undo / Rejected); filters Status, Location status, request / approval date (component) | Own location requests and whether each is still active | Location requests (own; status filter; valid until) | HAVE | — |
| 2 | "Inactivate all" with "Employees selected" ("In order to do bulk inactive location, apply location status filter") | HR bulk-inactivates locations | — | N-A | HR action |

### 22.14.12 Mark attendance (`app-mark-attendance-global-search` → `app-mark-attendance-card`; listed in "View all", not opened by the crawl)

| # | HROne element (tab / field / column / action) | What it does (inferred where unclear, mark *(inferred)*) | PeopleOS today (file or screen) | Status | Build note |
|---|---|---|---|---|---|
| 1 | Mark-attendance request history: Request date, Approval date, Status (Pending / Undo / Rejected / Approved), Location status | Attendance marked by the employee that needs approval *(inferred)* | Web punch (with location rules, TO-2); HR overrides; no "mark attendance" request for approval | PARTIAL | Owned by RQ-11 (mark attendance with optional approval, matrix 20 §20.3.2 #26) |

### 22.14.13 My assets (`/app/myprofile/asset`; page `myspace_My_assets`; `app-my-assets` → `app-emp-assets`)

| # | HROne element (tab / field / column / action) | What it does (inferred where unclear, mark *(inferred)*) | PeopleOS today (file or screen) | Status | Build note |
|---|---|---|---|---|---|
| 1 | "My assets — Here, you can view all your assigned assets in a go."; tab "Assigned(n)"; cards, SHOW MORE; "NO ASSETS FOUND" (`Asset/EmployeeAsset`) | Assets held | Assets (custodian = me): tag, name, category, serial, status, location, condition; acknowledgement task (AS-A) | HAVE | — |

### 22.14.14 PIP (`/app/myprofile/pip/search`) and PIP evaluation (`/app/myprofile/pipevaluation/search`); pages `myspace_PIP`, `myspace_PIP_evaluation`; `app-pip-cards`, `app-pip-eval-card`

| # | HROne element (tab / field / column / action) | What it does (inferred where unclear, mark *(inferred)*) | PeopleOS today (file or screen) | Status | Build note |
|---|---|---|---|---|---|
| 1 | PIP list ("No results found — Try adjusting the filters or come back later!"); cards (component): PIP period, Pip start / end date, Pip request date, Pip approval date, Approved by; filters Status, request / approval / end date, search | Own improvement plans | Improvement plans (own as employee or manager): period, status, checkpoints, approval (HP-8f) | HAVE | Date-range filters with RQ-1 |
| 2 | PIP evaluation list: same + "Evaluation Status", "PIP EVALUATION" | Outcome of a plan | Plan outcome / evaluation awaiting approval (HP-8f) | HAVE | — |

### 22.14.15 Polls (`/app/myprofile/polls`; page `myspace_Polls`; `app-my-polls`, `app-polls-management`, `app-polls-result-card`, `app-poll-bar-chart`)

| # | HROne element (tab / field / column / action) | What it does (inferred where unclear, mark *(inferred)*) | PeopleOS today (file or screen) | Status | Build note |
|---|---|---|---|---|---|
| 1 | Page "Polls" with "Filter" (Published date · `publishedFrom`, Type of poll · `pollType`, Status · `pollStatus`, Apply, CLEAR ALL) and "NO POLLS"; cards "Published on", "Attendees", "Responses", "Status" (`engage/profile/poll/Details`; a feature-licence check runs first) | Quick polls for employees | Surveys (pulse / engagement, approval, anonymity rules) — no one-question poll | MISSING | MS-15: survey kind `poll` (one question, options, optional comment), published to an audience, results shown to voters when the poll says so |
| 2 | Vote ("Voted Successfully."), "Voting for this poll is closed now. You can checkout the results below." | Take part | Survey response | PARTIAL | MS-15 |
| 3 | Results: Total attendees, Total Responses, Question, bar chart; "USER RESPONSE"; "The responses you're trying to view are anonymous." | Results | Survey results (released, minimum group size, anonymity) | PARTIAL | MS-15 (same anonymity and minimum-group rules) |
| 4 | Comments & suggestions ("No comments & suggestion") | Free text with the vote | Survey comments (`engagement.comments`) | PARTIAL | MS-15 |
| 5 | Export Result | Download | Survey results page (released results, minimum group); no export file | MISSING | MS-15: CSV of the aggregated result (never individual answers of an anonymous poll) |
| 6 | Unpublish ("You want to unpublish the poll", Reason, "This action is irreversible…") | Withdraw a poll | Close a survey | PARTIAL | MS-15 (close with reason; data kept) |
| 7 | Create / edit poll (`app-polls-management`: details, applicability, review) | Author a poll | Survey builder (HR, `engagement.manage`) | PARTIAL | MS-15 |

### 22.14.16 Position assigned (`app-position-assigned-search` → `app-position-assigned-card`; listed in "View all", not opened by the crawl)

| # | HROne element (tab / field / column / action) | What it does (inferred where unclear, mark *(inferred)*) | PeopleOS today (file or screen) | Status | Build note |
|---|---|---|---|---|---|
| 1 | Positions assigned to a recruiter: Candidates, Screening, Interview, Offer, Hire, Number of openings, recruiter workload, Generate offer, Hiring Manager | Recruiter pipeline | — | N-A | Recruitment (separate RMS) |

### 22.14.17 Shift (`/app/myprofile/shift/user`; page `myspace_Shift`; `app-shift-process-search` → `app-shift-process-edit`, `app-shift-process-grid`)

| # | HROne element (tab / field / column / action) | What it does (inferred where unclear, mark *(inferred)*) | PeopleOS today (file or screen) | Status | Build note |
|---|---|---|---|---|---|
| 1 | Page "Shift": a list of cards (title, two lines, a right-hand label; still loading in the capture) and, in the component, a grid of people with "Details" opening the calendar | The person's shift assignments *(inferred)* | Attendance calendar shows the shift code per day (own); no roster view | PARTIAL | MS-11: "My roster" (shift and weekly off per day for a month) |
| 2 | Edit: From · `fromDate`, To · `toDate`, Shift, Weekly off, "Select all", "Do you want to allow auto shift correction ?" | Assign shift / weekly off for a range | Shift overrides and day roster (HR); employees ask through change shift | PARTIAL | RQ-11 (team shift assignment, matrix 20) |

### 22.14.18 Team announcement (`/app/myprofile/announcement`; page `myspace_Team_announcement`; `app-my-profile-announcement`, `app-announcement`)

| # | HROne element (tab / field / column / action) | What it does (inferred where unclear, mark *(inferred)*) | PeopleOS today (file or screen) | Status | Build note |
|---|---|---|---|---|---|
| 1 | "Team Announcement — Stay updated with all the announcements created for your team!"; "Filter" (Publish date, Publish Status, Apply, CLEAR ALL); "Create announcement"; "NO ANNOUNCEMENT DATA"; cards "On:", "Frequency:", View, Clone (`Announcement/Profile/Search`) | Announcements for the person's team | Announcements feed (reader); authoring needs `communication.manage` (HR) | PARTIAL | MS-15: managers author team announcements |
| 2 | "Create announcement" wizard: Details, Frequency, Applicability, Advance Applicability, Review; Preview, Previous, Next | Author with audience and repeat | Announcement form (HR): type, audience, schedule, acknowledgement, approval | PARTIAL | MS-15: permission `communication.team` (manager template; audience fixed to the author's direct / indirect reports; approval per setting `communication.team.requires_approval`, default true) |
| 3 | Frequency (repeat) | Recurring announcement | One publication per announcement | MISSING | MS-15: `announcements.repeat` (none / weekly / monthly, until) |
| 4 | "No of people shared with", "No of people responded" | Reach | Reads and acknowledgements per announcement | HAVE | — |
| 5 | Unpublish, Inactivate, Edit | Manage | Archive / cancel, edit draft | HAVE | — |
| 6 | "You do not have access" (`app-view-permission-denied`) | Page access | Permission check | HAVE | — |

### 22.14.19 Trips (`app-employee-trips`, `app-trip-details-drawer`; not in "View all" — page-access tree and the calendar day drawer only)

| # | HROne element (tab / field / column / action) | What it does (inferred where unclear, mark *(inferred)*) | PeopleOS today (file or screen) | Status | Build note |
|---|---|---|---|---|---|
| 1 | "Trips" for a day: Start Point, End Point, Address, Coordinate, TRIP DETAILS | Field trips reconstructed from live location tracking | Location pings from the mobile app stored (`attendance_location_pings`, continuous tracking policy); no trip view | PARTIAL | MS-16: "My trips" (pings of a day grouped into start / end with coordinates; addresses only with a geocoding provider) |
| 2 | "View on map", "We are unable to pin any location at the moment", "No check-in found" | Map | — | MISSING | Map provider (CA-8, blocked) |
| 3 | "Attendance visibility settings have hidden this section in the detailed calendar." | Visibility | Calendar visibility | HAVE | — |

---

## Counts

Rows of the tables in §22.1–§22.14 (the §22.0 index is not counted; each index item's rows are in the section it names).

| Area | Rows | HAVE | PARTIAL | MISSING | N-A |
|---|---:|---:|---:|---:|---:|
| 22.1 My space flyout, favourites, My preferences; 22.1.1 "View all" dialog | 18 | 4 | 6 | 3 | 5 |
| 22.2 Profile (chrome, ten sections, skills, approver / history views, HR-only parts) | 134 | 50 | 43 | 37 | 4 |
| 22.3 Social profile | 24 | 2 | 9 | 13 | 0 |
| 22.4 Attendance calendar | 22 | 6 | 13 | 2 | 1 |
| 22.5 CTC | 16 | 7 | 5 | 3 | 1 |
| 22.6 Payslip | 9 | 4 | 3 | 2 | 0 |
| 22.7 Salary YTD and payroll histories | 22 | 2 | 5 | 15 | 0 |
| 22.8 Goals and initiatives | 12 | 5 | 7 | 0 | 0 |
| 22.9 Performance scorecard | 11 | 3 | 8 | 0 | 0 |
| 22.10 HR Handbook | 6 | 6 | 0 | 0 | 0 |
| 22.11 Holiday calendar | 6 | 1 | 4 | 1 | 0 |
| 22.12 Leave balance | 8 | 7 | 1 | 0 | 0 |
| 22.13 Letter | 3 | 2 | 0 | 1 | 0 |
| 22.14 Other My space items (1 on 1, matrix-20 pointers incl. travel tickets, attendance YTD, badges and rewards, reward store, clearance, confirmation, exit interview, geo / Wi-Fi, goal history, job requisition, location approval, mark attendance, assets, PIP, polls, position assigned, shift, team announcement, trips) | 81 | 29 | 32 | 15 | 5 |
| **Total** | **372** | **128** | **136** | **92** | **16** |

By profile section (HAVE / PARTIAL / MISSING / N-A): chrome 2 / 5 / 5 / 1, Snapshot 17 / 10 / 4 / 0, Personal
6 / 5 / 3 / 0, Family 6 / 4 / 4 / 0, Professional 4 / 1 / 4 / 0, Health insurance 0 / 0 / 8 / 0, Other details
1 / 0 / 1 / 0, Bank 2 / 3 / 2 / 0, Previous employer 5 / 0 / 2 / 0, Documents 3 / 5 / 2 / 0, History 3 / 2 / 0 / 0,
Skills 0 / 2 / 1 / 0, approver / history views 1 / 5 / 1 / 1, HR-only parts 0 / 1 / 0 / 2.

Most PARTIAL rows are of two kinds: the data exists but the employee cannot see it on their own record (blood group,
nationality, marital status, personal e-mail, employment type, statutory identifiers, bank accounts, pay mode, exit
dates), or HR can change it but the employee can only ask through an HR service. Most MISSING rows are new person data
(health insurance, identity documents, nominee details), the social profile, and salary YTD.

## Biggest gaps

1. **The employee cannot edit any part of their profile.** HROne has "EDIT" on every section with a required reason,
   a supporting document, a per-section workflow, a pending badge, an earlier / new comparison for the approver and a
   profile-change history. PeopleOS offers five HR services (address, bank account, PAN / UAN, emergency contact, family
   member) that start as drafts HR must approve, plus self-edit of the celebrated birthday and wedding anniversary
   (§22.2.0 rows 5–8, §22.2.12).
2. **The employee sees little of their own record.** Blood group, nationality, marital status, personal e-mail,
   employment type, retirement date, exit dates, statutory identifiers, bank accounts and pay mode are stored but hidden
   from the employee; documents are not grouped, have no "download all" and do not list the types still to upload
   (§22.2.1, §22.2.2, §22.2.7, §22.2.9).
3. **No social profile.** About, hobbies, personal social links, skill endorsements, colleague-facing education /
   experience / badges / shared goals / planned leaves, last check-in, QR code and the virtual ID card are all absent;
   colleagues see a directory peek only (§22.3).
4. **No photo and no personal signature** anywhere in the product (`people.photo_path` exists but nothing writes it)
   (§22.2.0 rows 2–3).
5. **No salary YTD for the employee** — HROne's password-gated page shows a component × month grid for the financial
   year with Salary / Reimbursement tabs and a statutory form download, and per component the arrears and a "how it is
   calculated" panel, with PF, VPF, ESI, PT, LWF, overtime and bonus detail (§22.7.1).
6. **Thin pay self-service.** No payslip year view, no monthly tax slip, no PDF / download / password / language (BP-14);
   My compensation has no statutory block, pay mode, arrear date, wage-code verdict or re-authentication gate (§22.5,
   §22.6).
7. **Person data PeopleOS does not hold:** salutation, passport and driving licence, biometric device id, phone country
   codes, nominations per benefit with guardian for minors, children's school / hostel flags, highest qualification,
   study mode and start year, fresher flag, previous employer's exempted gratuity and leave encashment (§22.2.1–§22.2.4,
   §22.2.8).
8. **No health insurance records** (insured members, policy number, cover, insurer, validity, parents' cover)
   (§22.2.5).
9. **Attendance depth for the employee:** no attendance YTD, weekly hours breakup, OT / arrears / holiday / weekly-off
   counts, late-early deduction detail, punch sources, odd punches or own roster (§22.4, §22.14.3, §22.14.17).
10. **Performance self views:** no yearly scorecard (rating calculation, item ratings, 1-on-1, 360, continuous feedback,
    trainings and outcome tabs), no "My goals" with Individual / Team / Company tabs and linked goals (initiatives exist
    since HP-8j as My initiatives, without comments), and one-on-ones without meeting type, place / URL or recurrence
    (§22.8, §22.9, §22.14.1).

Also notable: the "View all" dialog's module chips and favourite hearts (§22.1.1); the "Ticket" item is the list of
travel tickets booked by the company, which PeopleOS shows only on each trip (§22.14.2 #14, RQ-7a); polls (§22.14.15); managers cannot publish team announcements (§22.14.18); clearance owners have no "my
clearance tasks" list (§22.14.5); contact verification (§22.3 rows 4–5); a single "My space" list with favourites
(§22.1); a year holiday list (§22.11); month-wise leave movement (§22.12); letter acknowledgement (§22.13, RQ-10);
giving up an approved check-in location and seeing one's Wi-Fi / device registrations (§22.14.8); trips from location
pings (§22.14.19).

## Owner decisions needed

| # | Topic | Options | Recommendation |
|---|---|---|---|
| D-MS1 | May employees see their own statutory identifiers (PAN, Aadhaar reference, UAN) and bank account numbers? | Hidden (today) · masked (last 4) · masked with an audited "reveal" | Masked with an audited reveal (new permission `employee.self_sensitive` in the employee template); HR keeps `employee.sensitive.view` |
| D-MS2 | Which profile sections may an employee edit, and which need approval? | Per-section matrix (editable / needs approval) | Default nothing editable (today). Suggested tenant start: contacts, addresses, emergency contacts, family, education, experience, skills editable with approval; blood group and personal e-mail without approval; bank and statutory identifiers always with approval and a document |
| D-MS3 | QR code and profile sharing outside the organisation (Facebook, X, Instagram, Naukri, WhatsApp, LinkedIn) | Internal link only · public profile card | Internal link only (a public card exposes employee data outside the tenant) |
| D-MS4 | Skill endorsements | Off · on without wall posts · on with wall posts | Off by default; tenant switch |
| D-MS5 | Separation reasons and rehire status on the employee's own profile (HROne shows them in "Basic information") | Show all · show the employee's own reason only · hide | Show only the employee's own stated reason; never the organisation / settlement reason or rehire block |
| D-MS6 | Reward store (voucher marketplace) | No store (payroll redemption only) · a voucher vendor | Payroll redemption until a vendor contract exists |
| D-MS7 | SMS provider for mobile verification (and other SMS) | None · vendor | Decide with IP-07 (WhatsApp / SMS); e-mail verification needs no provider |
| D-MS8 | Date of marriage: full date or day / month | Day / month (HP-9a) · full date | Keep day / month unless a benefit rule needs the year |
| D-MS9 | Upload file types (HROne also allows xls, xlsx, bmp, zip, rar) | Keep the document allowlist · widen | Keep (archives and spreadsheets can carry active content) |
| D-MS10 | Password re-entry before pay pages (CTC, payslips, salary YTD) | Off · on | Tenant setting, default off |
| D-MS11 | Legal identity fields (name, date of birth) by self-service | Never · with approval and a document | With approval and a linked document only |

---

## Build packages (ordered)

| # | Package | Scope (rows it closes) | Files likely touched | Depends on | Size (S/M/L) |
|---|---|---|---|---|---|
| 1 | **MS-1 My space hub and favourites → built (2026-10-30; `docs/architecture/hrone-parity.md` § "MS-1 My space hub and favourites").** Tagged contract `MySpaceItem` (`peopleos.myspace_items`: key, label, icon, group Profile / Time / Pay / Performance / Engage / Requests, destination URL, `available($user)` = the target page or resource's own `canAccess`) with one entry per existing self page; "My space" page (Me) grouped with search; favourites (`ExperiencePreferences::favourite_pages`, cap 12) shown first there and in the avatar menu; each item a navigation result in `CommandSearch`; module chips (registry `group`) and a heart per item as in HROne's "All Searches" dialog; setting `myspace.hidden_items` (multiselect, default `[]`; hiding never grants). Defaults: every page as today | 22.1 #1–5; 22.1.1 #2–5 | `app/Domain/Experience/Contracts/MySpaceItem.php` (new), `app/Domain/Experience/Services/MySpace.php` (new), `ParityServiceProvider`, `app/Filament/Pages/MySpace.php` + view (new), `ExperiencePreferences.php`, `CommandSearch.php`, `AdminPanelProvider.php` (avatar menu), `config/peopleos_setup.php` | — (RQ-1 adds "My requests" as an item) | M |
| 2 | **MS-2 My profile (self read by section) → built (2026-10-29; `docs/architecture/hrone-parity.md` § "Core HR → Profile access, Social profile access (CA-6) and My profile (MS-2)"; health insurance waits for MS-5, "other status" for WF-08).** "My profile" page (avatar menu "My profile" points here; the 360 stays for HR and managers) with Snapshot, Personal, Family, Professional, Health insurance (after MS-5), Other details, Bank, Previous employer (from the current declaration), Documents, History — each read through the owning domain and hidden by the CA-6 section-visibility matrix when built. Shows the stored-but-hidden fields (blood group, nationality, marital status, personal e-mail / phone, employment type, retirement date from the exit policy, exit dates while an exit case exists, designation's skill category, pay mode); own identifiers and bank numbers masked with an audited reveal (permission `employee.self_sensitive`, D-MS1); documents grouped by category with file name, last updated, review note, sample per type, "not uploaded" types and "Download all" (ZIP built on the fly, one audit entry per file); "My changes" with earlier / new for own non-sensitive audited fields | 22.2.0 #4; 22.2.1 #6, #7, #11, #13, #14, #18; 22.2.2 #1, #2, #5; 22.2.3 #4; 22.2.7 #1, #2; 22.2.9 #2–5, #8, #9; 22.2.10 #3; 22.3 #8 | `app/Filament/Pages/MyProfile.php` + `resources/views/filament/pages/my-profile.blade.php` (new), `app/Domain/Experience/Services/MyProfileSections.php` (new read model), `SensitiveAccessAuditor`, `Documents` (zip), `config/peopleos.php` (permission → `peopleos:sync-permissions`), `AdminPanelProvider.php` | CA-6 (section visibility, optional), D-MS1, D-MS5 | L |
| 3 | **MS-3 Self-service profile changes → built (2026-10-30; `docs/architecture/hrone-parity.md` § "MS-3 Self-service profile changes"; built on the Phase 12 profile-change flow — same actions, guard, ChangeOrigin, purge — not on service-desk tickets, which keep working; the reimbursement account (22.2.7 #6) is not built (no employee reimbursement account exists to pay into) and `health_insurance` joins with MS-5; the workflow bridge lives in `app/Domain/People/Listeners/` like the other P5 bridges).** `profile_change_requests` (employee, section, new values encrypted, earlier values masked at submit, required comment ≤ 500, linked document ids, status, decided by / at, executed record refs; payload purged after execution as in Phase 12); `ProfileChanges::submit` (`SelfServiceLock::assertOpen`, one open request per section, CA-4 linked documents through `DocumentRules`, BGV lock setting) → workflow trigger `profile.change_requested` (subject "Profile change", P5 bridge) or Approval Center source `ProfileChangeApprovalSource` (implements `SupportsSendBack`) when the section needs approval, else applied at once; execution only through the existing actions (`UpdatePersonAction` limited fields, address, emergency contact, family member, statutory identity, bank account, `PaymentModes::set` for the reimbursement account) plus new `ChangeQualificationAction`, `ChangeExperienceAction`, `ChangeProfileSkillAction`, employee-editable custom fields (`custom_fields.editable_by_employee`, default false); approver diff panel; "pending since … with …" on each section; history list and flyer; "Same as present" address and confirm-account-number on the forms; setting `profile.self_edit.sections` (matrix sections × editable / needs approval; default all off = today). The existing HR services keep working | 22.2.0 #5–8, #12; 22.2.2 #12, #14; 22.2.5 #8; 22.2.6 #2; 22.2.7 #3, #6; 22.2.9 #5; 22.2.10 #4; 22.2.11 #1, #2; 22.2.12 #1, #4–6; the edit side of 22.2.1 #6; 22.2.2 #1, #2, #5; 22.2.3 #4; 22.2.7 #2 | migration, `app/Domain/People/Models/ProfileChangeRequest.php`, `app/Domain/People/Services/ProfileChanges.php`, `app/Domain/People/Actions/Change{Qualification,Experience,ProfileSkill}Action.php`, `app/Domain/Experience/Sources/ProfileChangeApprovalSource.php`, `app/Domain/Workflow/Bridges/ProfileChangeWorkflowBridge.php`, `ParityServiceProvider`, `MyProfile.php` (edit actions), `ChangeOrigin` (self service), `config/peopleos.php` (event, subject, approval source), `config/peopleos_setup.php`, `DataInventory` | MS-2; D-MS2, D-MS11 | L |
| 4 | **MS-4 Person data additions.** `people.salutation` (option list `salutations`; letter variable), `people.is_fresher`; `person_identity_documents` (passport / driving licence: number encrypted, issued, expiry; CA-4 linked field `passport`, expiry reminders); `employees.device_user_id` (unique per tenant; `PunchIngestion` tries it before the employee code); phone country code (E.164) on work / personal / emergency phones; `person_family_members.in_school`, `in_hostel`, `remarks`; `person_nominations` (family member, benefit from option list `nomination_benefits`, share %, address, remarks; guardian name / relation required for a minor; shares per benefit total 100); `person_qualifications.is_highest`, `mode`, `year_started`; declaration fields `previous.gratuity_exempt`, `previous.leave_encashment_exempt` read by `TaxComputer` only through rule data. HR forms, imports and datasets gain the same fields | 22.2.1 #1, #9, #10; 22.2.2 #3, #4, #7; 22.2.3 #8–10, #12–14; 22.2.4 #1, #4, #6, #7; 22.2.8 #3, #4 | migrations, `Person`, `PersonFamilyMember`, `PersonQualification`, `app/Domain/People/Models/{PersonIdentityDocument,PersonNomination}.php` (new), `OptionLists` seeds, `PunchIngestion.php`, `LetterVariables.php`, `DocumentRules` (`peopleos.documents.linked_fields`), `DeclareInvestments.php`, `TaxComputer.php`, `EmployeeForm.php`, relation managers, `EmployeeImport*`, `EmployeesDataset`, `DataInventory` | BP-10 (exemption rule data) | M |
| 5 | **MS-7 Photo and signature.** Photo upload (PNG / JPEG ≤ 2 MB, square crop) to `tenants/{id}/people/photos`, served by `ParityFileController` type `person-photo` to anyone who may see the person (`PeopleVisibility`); avatars everywhere use it; settings `profile.photo.self_upload` (default false) and `profile.photo.needs_approval` (default true → MS-3 section `photo`). Personal signature (`person_signatures`: drawn / typed / uploaded image, active flag) served only to the owner and HR, for future acknowledgements and e-sign; setting `profile.signature.enabled` (default false) | 22.2.0 #2, #3; 22.3 #7, #9 | migration, `app/Domain/People/Services/{ProfilePhotos,PersonSignatures}.php` (new), `ParityFileController`, avatar Blade component, `MyProfile.php`, `config/peopleos_setup.php`, `DataInventory` (prefixes) | MS-3 (photo approval) | M |
| 6 | **MS-9 My pay pages.** "My payslips" (FY select, salary and monthly tax cards; tax slip rendered from the payslip's tax snapshot; download once BP-14 delivers the PDF; tenant help text for the password); My compensation tabs Structure / Insights with a statutory block, pay mode, arrear-from date and the wage-code verdict (threshold from rule data, minimum-wage check only with verified state rule versions); setting `compensation.self_service.confirm_password` (default false) → password confirmation before My compensation, My payslips and My salary YTD | 22.5 #1, #2, #6, #9, #14, #16; 22.6 #3, #6, #7; 22.7.1 #3 | `MyCompensation.php` + view, `app/Filament/Pages/MyPayslips.php` (new), `PayslipResource` (tax slip view), password-confirm middleware, `config/peopleos_setup.php` | BP-14 (PDF, password, language), BP-11 (NPS / PRAN), BP-12 (pay group) | M |
| 7 | **MS-10 Salary YTD.** `PaidPayReader::yearToDate($employee, $fy)` over finalized / paid runs only; "My salary YTD" page (employee header, FY select, Salary / Reimbursement tabs, component × Apr–Mar grid with totals, the year's statutory form download once its identity is verified): arrear rows and a "How it is calculated" panel from the component settings; statutory tabs PF, VPF, ESI, PT, LWF (wages, contributions, arrears; configuration read from the employee's statutory options and the verified rule version), overtime, bonus (after the bonus rule is verified), reimbursements (claimed, paid, bills, taxable) | 22.7.1 #1, #2, #4–16 | `app/Domain/Payroll/Contracts/PaidPayReader.php`, `PaidPay.php`, `app/Filament/Pages/MySalaryYtd.php` + view (new), `ParityServiceProvider` | MS-9 (password gate), RQ-5 (claim totals), bonus rule verification | M |
| 8 | **MS-11 Attendance depth for the employee.** Calendar: punch source per day, summary extras (OT this / last month, arrear days, holidays, weekly offs, paid / unpaid leave, penalty split, working hours), weekly hours breakup (expected vs actual vs shift), late / early deduction rows, odd punches, day effects (OT, comp-off, deductions, links to wishes / badges / receipts / mood); "My attendance this year" (month cards and deduction / arrear rows); "My roster" (shift and weekly off per day) | 22.4 #5, #9–11, #16, #17, #19 (and the source / shift marks of #12, #15 with RQ-3); 22.14.3 #1–4; 22.14.17 #1 | `AttendanceCalendar.php` + `attendance-calendar.blade.php`, `AttendanceOutput`, `ShiftResolver`, `app/Filament/Pages/MyAttendanceYear.php` (new) | RQ-3 (day drawer) | M |
| 9 | **MS-12 My holidays and leave month view.** "My holidays" (year select; Fixed / Restricted tabs with counts; name, weekday, date, type, chosen state; calendar note `holiday_calendars.employee_note`; "Choose" pre-fills the RH request); leave balance month table (added / subtracted per leave type from the ledger) | 22.11 #1–4, #6; 22.12 #4 | `app/Filament/Pages/MyHolidays.php` (new), migration, `HolidayCalendarResource` (note), `HolidayResolver`, Leave balances self view | RQ-7a (RH helper) | S |
| 10 | **MS-5 Health insurance.** `employee_insurance_policies` (insurer name / code, policy number, sum assured, issue date, valid until, e-card document) and `insured_members` (self or family member, member id encrypted, relation, DOB, gender, parents' cover flag); HR relation manager and import; My profile section; MS-3 section `health_insurance`; expiry reminders; dataset `insurance_members` | 22.2.3 #3; 22.2.5 #1–7 | migrations, `app/Domain/People/Models/{InsurancePolicy,InsuredMember}.php`, `InsuranceRecords` service, `Employees/RelationManagers/InsuranceRelationManager.php`, `ImportTypes`, `LifecycleReminders`, dataset, `DataInventory` | MS-3 (self changes) | M |
| 11 | **MS-16 Other self items.** `ExitClearanceTaskSource` (owner user or role members see open clearances in My tasks and a "Clearance tasks" list with Pending / Completed; acting needs only ownership); clearance checklist items with a link and an attachment; the exiting employee sees each clearance's status; own confirmation status when `workforce.confirmation.visible_to_employee` (default false); read-only "My check-in devices and Wi-Fi"; "My trips" from location pings (map waits for CA-8) | 22.14.5 #1, #3; 22.14.6 #1; 22.14.8 #6; 22.14.19 #1 | `app/Domain/Exit/Support/ExitClearanceTaskSource.php` (new), `config/peopleos.php` (`experience.task_sources`), `Exits`, `ClearanceChecklist`, `ConfirmationReviews`, `LocationRequests`, `EmployeeDeviceResource` (self view), `app/Filament/Pages/MyTrips.php` (new), `config/peopleos_setup.php` | CA-8 (maps, not blocking) | M |
| 12 | **MS-15 Engage self-service.** Polls as survey kind `poll` (one question, options, optional comment; audience, publish date, status; vote; results with the survey anonymity and minimum-group rules; export; close with reason); team announcements by managers (permission `communication.team` in the manager template, audience fixed to the author's reports, approval per `communication.team.requires_approval`, default true) and `announcements.repeat` (none / weekly / monthly, until); "My badges and points" page (Badges / Reward points / Ledger tabs, period presets, the money value of the received balance when payroll redemption is on) over the existing reads | 22.14.4 #1, #2, #5; 22.14.15 #1–7; 22.14.18 #1–3 | Engagement survey models / builder, Home poll card, `AnnouncementResource`, `Communications`, `config/peopleos.php` (permission → sync), `config/peopleos_setup.php`, `app/Filament/Pages/MyRecognition.php` (new) | — | M |
| 13 | **MS-13 Performance scorecard and one-on-one details.** "My scorecard" per performance year: Review (rating, calculation panel from weights and HP-8c formulas, KRA / competency item ratings within HP-8d hidden fields), Completed initiatives (from HP-8j records), 1 on 1, 360 (after HP-8h / SV-12, honouring `performance.profile.show_360`), Continuous feedback, Trainings, outcomes block behind `performance.scorecard.show_outcomes` (default false). One-on-ones: `meeting_type`, `location_or_url`, series (weekly / monthly, ≤ 3 months), .ics on the invitation | 22.9 #1, #3–10; 22.14.1 #4–6 | `app/Filament/Pages/MyScorecard.php` + view (new), `AppraisalVisibility`, rating calculation read, `Initiatives` (read), migration (one-on-one fields, `one_on_one_series`), `OneOnOnes`, notification template, `config/peopleos_setup.php` | HP-8h / SV-12 | M |
| 14 | **MS-14 My goals.** "My goals" page: cycle select, Individual / Team / Company tabs, KRA / competency count and weight chips, other people's shared goals, "Linked goals" on a company / team goal (children, owner, progress, read-only roll-up), initiatives tab over the HP-8j records (open / overdue quick filters) | 22.8 #1, #3–5, #10; the goals-page view of #11 | `app/Filament/Pages/MyGoals.php` + view (new), `GoalResource` (linked goals), `InitiativeResource` (embedded list), `goals.shared` (MS-6) | MS-6 (shared flag) | M |
| 15 | **MS-6 Social profile.** `social_profiles` (about ≤ 1000, hobbies ≤ 15 tags, links ≤ 6 https), `skill_endorsements` (≤ 3 skills per action, once per pair and skill, optional wall post; `engage.endorsements.enabled`, default false), `goals.shared` (default false), `people.directory_private` (HR, default false), custom field `on_social_profile` (default false); person profile page (People → a person) with About, Hobbies, Skills and endorsements, Education, Experience with total, Badges, Shared goals, Planned leaves ("Away", no type), Last check-in, Other details — each section behind `directory.sections_visible` and the person's hidden sections (01 §1.22); QR of the internal profile URL (D-MS3) | 22.2.11 #3; 22.2.13 #3; 22.3 #1, #3, #6, #10–18, #20, #23 | migrations, `app/Domain/People/Models/{SocialProfile,SkillEndorsement}.php`, `app/Filament/Pages/PersonProfile.php` + view (new), `PeopleVisibility`, `PersonPeek`, `SocialWall` (post kind), `Preferences.php`, `config/peopleos_setup.php`, `DataInventory` | CA-6 / 01 §1.22 settings, MS-7, RQ-4 (private leave); D-MS3, D-MS4 | L |
| 16 | **MS-8 Contact verification.** `people.personal_email_verified_at`, `personal_phone_verified_at`; `ContactVerification` (signed e-mail link; 6-digit code through an `SmsGateway` contract with a null implementation; 3 attempts per hour); banner on Home / My profile when `profile.contact_verification.required` (default false); a changed e-mail / phone resets its verification | 22.3 #4, #5; verification part of 22.2.2 #5 | migration, `app/Domain/People/Services/ContactVerification.php`, `app/Domain/Notifications/Contracts/SmsGateway.php` (new, null), `Home.php`, `MyProfile.php`, `config/peopleos_setup.php` | SMS provider (IP-07) for phones; D-MS7 | S |
| 17 | **MS-17 Virtual ID card.** "Download ID card" on My profile and the person profile: on-demand letter of type `id_card` (SV-13 template) with photo and the profile QR; never stored as an issued letter unless HR issues one | 22.3 #24 | `Letters::generate` (on-demand path), letter type config, `MyProfile.php` | SV-13, MS-7, BP-14 (PDF) | S |

Not packaged here (owned elsewhere or blocked): page access per My space item (built with CA-9); static form layouts (matrix 02
§03.4, WF-09); PII consent (SV-14); other statuses (WF-08); region and sub-branch (matrix 01 §1.10, §1.17); EPFO
employment-history and bank-account verification (external vendors, CA-15); FBP history (BP-7); NPS / PRAN (BP-11); pay
groups (BP-12); payslip PDF, password and language (BP-14); 360 feedback (HP-8h / SV-12); initiative comments and related person (matrix 27 GO-8); work plan
(SV-18); maps (CA-8); timesheets (matrix 21 PM-*); voucher store (D-MS6); calendar integrations for one-on-ones
(external); request history lists and request forms (matrix 20: RQ-1, RQ-2, RQ-3, RQ-4, RQ-5, RQ-7a, RQ-7b, RQ-8, RQ-10,
RQ-11); recruitment rows (N-A).
