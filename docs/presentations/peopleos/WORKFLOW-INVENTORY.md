# PeopleOS training library — workflow inventory (Phase B)

**Prepared:** 21 October 2026, before screenshot capture · **Application:** branch `feature/oct_20_people_os_phase_1`, commit `72c96a73`
**For:** the training-library authors and reviewers. It maps each major workflow to the real screen and to the deck that teaches it.

This inventory was built from:

- the running application: every persona signed in on a disposable database, `hcm_training_showcase` (built with
  `UxShowcaseSeeder`; fictional people only), served locally at `http://127.0.0.1:8098`;
- the module inventory and status reports of 19 October (`docs/reports/feature-inventory.md`,
  `implementation-status.md`, `documentation-gaps.md`). Defects F25, F38 and F44 were re-checked in the code at
  `72c96a73` and are still present;
- the release gate (`docs/saas/SaaS-Final-Release-Gate.md`: **NO-GO — BLOCKED**, re-verified 13 October 2026).

"Plan" says how each workflow will be shown. **Execute** means the workflow is run end to end on the disposable
database, with a screenshot of each state. **Open** means the screen is opened (forms filled, then cancelled) but not
submitted, because submitting needs something the environment cannot provide. **Status only** means the screen is
shown in its current state (switched off, blocked or empty) and the prerequisite is documented. The final status of
each workflow is recorded in `FEATURE-TO-PRESENTATION-MATRIX.md`, after capture.

## Environment and access

| Item | Finding |
|---|---|
| Application | One Filament panel at `/admin` for tenant users and platform operators. Laravel 13, Filament 5, PHP 8.5, MySQL |
| Development instance | `http://localhost:8000` serves the development database `hcm`. It was **not** used, so no development data was changed |
| Training instance | `http://127.0.0.1:8098` serves the disposable database `hcm_training_showcase`, with the array cache (so sign-in throttling does not block automation) and mail written to the server log. No application file or setting was changed. The instance was started with environment variables only |
| Tenant users | The showcase personas Priya Nair (Employee), Rahul Sharma (Employee), Amit Verma (Manager), Neha Kapoor (HR Manager), Kavya Menon (Tenant HR Admin), Arjun Bose (Payroll Admin) and Meera Iyer (Executive) use the seeder's public fixture password, which works on `*_showcase` databases only. Demo Admin (Tenant Super Admin) uses a per-run seed password held outside the repository |
| Platform operator | `platform@markedge.local` (fictional). Operators must use MFA (`PEOPLEOS_PLATFORM_MFA_REQUIRED`, default on). The operator was enrolled through the real set-up screen; the key is stored outside the repository |
| Mail | The invitation and password-reset e-mails are written to the training server's log. Invitation links are opened from there, as a user would open them from e-mail |
| Switched-off capabilities | Public signup, live payments and tenant deletion are off by default. They stay off for this work |

## Roles available for capture

| Persona | System role(s) | Used for |
|---|---|---|
| Priya Nair | Employee | Deck 1 and the employee steps of multi-role workflows |
| Rahul Sharma | Employee | A second employee (resignation and exit) |
| Amit Verma | Manager (Platform team scope) + Employee | Deck 2 |
| Neha Kapoor | HR Manager + Employee | Deck 3: HR operations, the second HR person for separation-of-duties steps |
| Kavya Menon | Tenant HR Admin + Employee | Decks 3, 4, 8 and 12: configuration and administration |
| Arjun Bose | Payroll Admin + Employee | Deck 3: payroll and statutory |
| Meera Iyer | Executive + Employee | Decks 9 and 12: executive views |
| Demo Admin | Tenant Super Admin (no employee record) | Decks 4 and 8: role editing and data export |
| New tenant administrator | First administrator of a newly provisioned tenant (invited) | Deck 4: implementation from an empty tenant |
| Markedge Platform Admin | Platform operator | Decks 5, 6 and 8 |

## Workflows by role

### Employee (Deck 1)

| ID | Workflow | Real screen(s) | Pre-capture status | Plan |
|---|---|---|---|---|
| E1 | Sign in, Home, navigation | `/admin/login`, Home, sidebar, area bar | Implemented (handbook-verified) | Execute |
| E2 | Search and quick launch | Command center (Ctrl K), + New | Implemented | Execute |
| E3 | My profile (own Employee 360, read-only) | Avatar › My profile; Your record | Implemented | Execute |
| E4 | Letters and documents | My letters; own record › Documents | Implemented (employees cannot upload their own documents) | Execute (view) |
| E5 | Request leave → status → result | Home › Request leave; Leave requests; Balances | Implemented | Execute end to end with Deck 2 (manager approval) |
| E6 | Attendance check-in and correction | Home › Your day › Check in; Fix my attendance; Attendance records | Implemented | Execute end to end with Deck 2 (approval) |
| E7 | My work inbox and notifications | My work; Notifications | Implemented | Execute |
| E8 | Payslips and tax declaration | Payslips; Tax declarations | Payslips depend on a finalised run, which is blocked (statutory rules unverified). Tax declaration has no employee submit step (F13) | Status only (payslips); Open (declaration) |
| E9 | Goals and performance | Goals; check-in; Appraisals; Career passport | Implemented | Execute (goal check-in); Open (self review) |
| E10 | Learning | My learning; course; enrolment | Implemented | Execute |
| E11 | My compensation | Avatar › My compensation | Implemented (read only) | Execute |
| E12 | HR requests and knowledge | My HR › Ask HR; My requests; Knowledge base | Implemented | Execute (raise a request) |
| E13 | Surveys and feedback | My HR › Surveys; Give feedback | Give feedback fails on MySQL (F17) | Status only, with the defect stated |
| E14 | Announcements | Communication › Announcements | Implemented | Execute |
| E15 | Resignation (My exit) | My exit › Resign | Implemented | Execute (Rahul) with Deck 3 exit workflow |
| E16 | Account security and preferences | Account security; Preferences | Implemented | Execute (view) |
| E17 | Restricted access | An HR-only page as an employee | Implemented ("This page is not available to you") | Execute |

### Manager (Deck 2)

| ID | Workflow | Real screen(s) | Pre-capture status | Plan |
|---|---|---|---|---|
| M1 | Manager Home and team visibility | Home (Your team lens); My Team | Implemented | Execute |
| M2 | Direct reports and Employee 360 | Directory (team scope); team member's record | Implemented (relationship scope) | Execute |
| M3 | Approve leave | Approval Center › item › Approve | Implemented | Execute (with E5) |
| M4 | Reject with a reason | Approval Center › Reject (reason required) | Implemented | Execute: validation, then rejection |
| M5 | Attendance corrections and exceptions | Approvals; Regularisations; Exception centre | Implemented | Execute (with E6) |
| M6 | Leave calendar | Leave calendar | Implemented | Execute |
| M7 | Goals, check-ins, one-on-ones, feedback | Goals; One-on-ones; Feedback | Implemented | Execute (schedule a one-on-one, give feedback) |
| M8 | Team learning and career | Team learning; Team career | Implemented | Execute (view) |
| M9 | Team HR requests | Team HR requests | Implemented (status only, never sensitive details) | Execute (view) |
| M10 | Team insights | People analytics (team scope) | Implemented | Execute |
| M11 | Restricted access | A record outside the team; Employees register without Edit | Implemented | Execute |

### HR administrator (Deck 3)

| ID | Workflow | Real screen(s) | Pre-capture status | Plan |
|---|---|---|---|---|
| H1 | People Directory and register | People › Directory; Employees | Implemented | Execute |
| H2 | Employee 360 | Employee record (views, Records tabs, History) | Implemented (History tab may raise a lazy-loading error outside production, F26) | Execute |
| H3 | Hire an employee with duplicate check | Employees › Hire employee (Person → Employment → Position → Manager → Review) | Implemented | Execute (a fictional hire), including a duplicate warning |
| H4 | Change manager (reporting relationship) | 360 › Action › Change manager | Implemented | Execute |
| H5 | Transfer / promote and lifecycle state | 360 › Action | Implemented | Execute (transfer); Open (lifecycle) |
| H6 | Onboarding plan and tasks | Onboarding; Task inbox › Onboarding tasks | Implemented (plan starts automatically on hire) | Execute |
| H7 | Employee documents with verification | 360 › Records › Operations › Documents | Implemented (the uploader cannot verify) | Execute (upload by one HR person, verify by another) |
| H8 | Attendance administration | Attendance records; Manual punch; Process day; Exception centre | Implemented | Execute |
| H9 | Leave administration | Balances (Adjust); Leave types; Leave policy; Apply on behalf | Apply on behalf fails (F38); workaround from the 360 | Execute (adjust, workaround); Status only (F38) |
| H10 | Payroll run and controls | Payroll control room; Payroll runs; Breakdown; Payroll Auditor | Finalisation blocked: 0 of 24 statutory rules verified | Execute (calculate, view issues); Status only (finalise) |
| H11 | Statutory configuration and verification | Compliance control room; Rule verification; Statutory profiles | Verification needs two platform administrators with official evidence | Status only |
| H12 | Performance and goals administration | Cycles; Appraisals; Calibration | Implemented | Execute (view); Open (new cycle) |
| H13 | Learning administration | Courses; Assignments | Implemented | Execute (view) |
| H14 | Workforce planning and positions | Positions; Headcount plans; Org map | Implemented | Execute (draft position) |
| H15 | Compensation change (four-person chain) | Compensation changes | Implemented; four different people required | Execute where the personas allow; Status only otherwise |
| H16 | Assets, letters, HR requests | Asset register; Letters (generate → approve → issue); HR queue | Implemented (letters: the requester cannot approve) | Execute |
| H17 | Exit and full & final settlement | Exit cases; clearance; F&F tab | Implemented; gratuity not calculated | Execute (Rahul) |
| H18 | Reports and audit history | Change history; record History | Implemented | Execute |
| H19 | Employee import | Employee imports (Upload → Inspect → Map columns) | **Map columns fails (F25)** | Execute up to the failure; state the defect |

### Client onboarding and implementation (Deck 4)

| ID | Stage | Real screen(s) | Pre-capture status | Plan |
|---|---|---|---|---|
| C1 | Tenant provisioning (operator) | Platform › Tenants › New tenant | Implemented (operator only; first administrator invited by e-mail) | Execute: a fictional customer tenant |
| C2 | First administrator activation | Invitation link › Activate my account | Implemented | Execute |
| C3 | Admin Centre and configuration finder | Admin › All modules; What do you want to configure? | Implemented | Execute |
| C4 | Company, legal entity, establishment, location | Organisation screens | Implemented (locations cannot be linked to an establishment on screen, F10) | Execute |
| C5 | Organisation structure | Business units, departments, teams; Organisation Designer; Org map | Implemented | Execute |
| C6 | Job architecture | Designations, grades, levels, job families; Positions | Implemented | Execute |
| C7 | Employee data and person matching | Hire employee; Employee imports | Import blocked by F25 | Execute (hire); Execute up to the failure (import) |
| C8 | Roles, users and access scope | Roles; Users › New user (invite) | Implemented | Execute |
| C9 | Attendance and shifts | Shifts; Work schedules; Holiday calendars | Implemented (holiday type and half day ignored, F39) | Execute |
| C10 | Leave policies | Leave types; Policies | Implemented | Execute (view, edit) |
| C11 | Payroll components and structures | Salary components; Salary structures | Implemented | Execute (view); Open (new) |
| C12 | Statutory set-up | Statutory registrations; establishment statutory profiles; Compliance control room | Rules unverified (platform responsibility) | Execute (registration); Status only (verification) |
| C13 | Workflows and approvals | Workflows; builder; Policies | Implemented ("Generate document" step skipped, F33) | Execute (view) |
| C14 | Notifications and security policy | Notification rules; Security policy; SSO | Notification rules cannot be created (F18); SSO is OIDC only | Execute (view, require MFA) |
| C15 | Validation, readiness, Change Centre, audit | Reports; Change Centre; Change history; Platform readiness | Implemented | Execute (view) |
| C16 | UAT, go-live, hypercare, handover | Process stages, not screens | Manual (Markedge) | Checklists and diagrams, marked as process |

### Platform administrator (Deck 5)

| ID | Workflow | Real screen(s) | Pre-capture status | Plan |
|---|---|---|---|---|
| P1 | Operator sign-in with MFA | Sign in; forced set-up; challenge | Implemented | Execute |
| P2 | Tenant list and lifecycle | Tenants; edit; suspend and reactivate | Implemented | Execute on a fictional tenant |
| P3 | Signups | Platform › Signups | Public registration **off** | Status only |
| P4 | Plans, subscriptions, entitlements | Plans; Subscriptions; Entitlements (shadow mode) | Shadow mode; no plan shipped | Status only |
| P5 | Billing, tax, payments, invoices, approvals | Billing catalogue; Tax & invoicing; Payments; Invoices; Approvals | Live payments **off**; no prices shipped | Status only |
| P6 | Operations, queues, failed jobs | Platform › Operations | Implemented | Execute (view) |
| P7 | Platform audit trail | Platform audit trail | Implemented | Execute (view, verify) |
| P8 | Readiness | Platform readiness | Implemented | Execute (view) |
| P9 | Export, offboarding, retention, legal hold, deletion | Offboarding; Retention policies | Deletion **off**; no retention policy | Execute where safe (legal hold on a fictional tenant); Status only (deletion) |
| P10 | Statutory rule verification (two operators) | Compliance rules; Rule notices | Needs official evidence | Status only |

### Other decks

| Deck | Content | Source of screens |
|---|---|---|
| 6 Markedge internal operations | Markedge as its own employer: a fictional internal tenant set apart from SaaS operations | An internal tenant provisioned through the operator workflow, plus the tenant HR screens |
| 7 Troubleshooting | Real error, validation, restricted and blocked states | Captured while running the workflows above (sign-in failure, 403, F25, F38, F17, payroll blocked, suspended tenant) |
| 8 Security and compliance | Roles, scope, field protection, audit, MFA, separation of duties, export and deletion safeguards | Captured from the running controls |
| 9 Executive demo | Narrative over the verified screens | Screens captured for the other decks |
| 10 Architecture | Layers, tenancy, audit, workflows, integration boundaries | Conceptual diagrams, labelled as such, and a few linked screens |
| 11 UAT and go-live | Test cases with the observed results of this run | The executed workflows above |
| 12 Reporting and analytics | Dashboards, reports, analytics pages, export, audit | Insights screens; report builder defect F44 |

## Known defects and limits that must stay visible

| Ref | What | Where it shows |
|---|---|---|
| F25 | Employee and punch import: Map columns fails ("Undefined variable $svc"). Imports cannot progress from the screen | Decks 3, 4, 7, 11 |
| F38 | Leave requests › Apply on behalf: server error when the dialog opens. Workaround: Employee 360 › Records › Time › Leave › Apply for leave | Decks 3, 7 |
| F44 | Report builder: Filters, Group by, Chart and Sort field lists are empty | Decks 7, 12 |
| F17 | My HR › Give feedback: error on MySQL. The feedback is saved but HR is not notified | Decks 1, 7 |
| F13 | Tax declaration has no employee submit step | Deck 1 |
| Payroll | Finalisation and payslips blocked until the statutory rules are verified (0 of 24). Gratuity is not calculated | Decks 1, 3, 4, 7, 9, 11 |
| Release | Release gate NO-GO: 10 of 14 gates blocked (owner decisions, independent review, hosting, DR, statutory) | Every deck's status slide |
