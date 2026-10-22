# PeopleOS training library: feature-to-presentation matrix

**Generated:** 2026-10-21 by `tools/build/matrix.js`. **For:** reviewers checking coverage and status.
Each row is a workflow from `WORKFLOW-INVENTORY.md` with its status label after capture, the number of genuine
screenshots that evidence it, and the decks that actually embed those screenshots (from the build). Screenshot names
are in `SCREENSHOT-INVENTORY.md`; claims and sources are in `PEOPLEOS-PRESENTATION-ACCURACY-REGISTER.md`.

| Status | Workflows |
|---|---|
| VERIFIED IN RUNNING APPLICATION | 66 |
| IMPLEMENTED BUT NOT VERIFIED END-TO-END | 3 |
| AVAILABLE ONLY AFTER CONFIGURATION | 3 |
| DISABLED BY SAFETY OR COMMERCIAL GATES | 6 |
| INCOMPLETE | 6 |
| NOT IMPLEMENTED | 1 |
| UNABLE TO VERIFY | 1 |

| ID | Workflow | Status | Screenshots | Decks | Note |
|---|---|---|---|---|---|
| E1 | Sign in, Home, navigation | VERIFIED IN RUNNING APPLICATION | 6 | 1, 9 | Wrong-password message generic by design |
| E2 | Search and quick launch | VERIFIED IN RUNNING APPLICATION | 3 | 1 |  |
| E3 | Own record (read only) | VERIFIED IN RUNNING APPLICATION | 4 | 1 | Records › Employment errors (new finding) |
| E4 | Documents and letters | VERIFIED IN RUNNING APPLICATION | 2 | 1 | Employees cannot upload their own documents |
| E5 | Request leave → decision → balance | VERIFIED IN RUNNING APPLICATION | 15 | 1, 2, 6, 7, 9, 11 | Executed end to end |
| E6 | Check in and correct attendance | VERIFIED IN RUNNING APPLICATION | 10 | 1, 2, 7, 11 | Executed end to end |
| E7 | My work and notifications | VERIFIED IN RUNNING APPLICATION | 4 | 1 |  |
| E8a | Payslips | DISABLED BY SAFETY OR COMMERCIAL GATES | 1 | 1 | None can exist until payroll is finalised (statutory rules unverified) |
| E8b | Tax declaration | INCOMPLETE | 2 | 1 | Draft only; no employee submit (F13) |
| E9 | Goals, appraisal, career | VERIFIED IN RUNNING APPLICATION | 8 | 1 | Check-in and self review executed |
| E10 | Learning | VERIFIED IN RUNNING APPLICATION | 3 | 1 | Course started |
| E11 | My compensation | VERIFIED IN RUNNING APPLICATION | 1 | 1 |  |
| E12 | HR requests and knowledge base | VERIFIED IN RUNNING APPLICATION | 8 | 1, 9 | Request raised; policy acknowledged |
| E13a | Surveys | AVAILABLE ONLY AFTER CONFIGURATION | 1 | 1 | No survey open in the training data |
| E13b | Give feedback | INCOMPLETE | 2 | 1, 7 | F17: error shown, HR not notified |
| E14 | Announcements | VERIFIED IN RUNNING APPLICATION | 2 | 1 | Acknowledged |
| E15 | Resignation | IMPLEMENTED BUT NOT VERIFIED END-TO-END | 2 | 1 | Form opened and cancelled for the employee; full exit flow in H17 |
| E16 | Account security, preferences, phone, dark mode | VERIFIED IN RUNNING APPLICATION | 4 | 1, 9, 11 | MFA set-up not opened (secret) |
| E17 | Restricted access (employee) | VERIFIED IN RUNNING APPLICATION | 1 | 1, 7, 8 | 403 "not available" |
| M1 | Manager Home and team | VERIFIED IN RUNNING APPLICATION | 5 | 2 |  |
| M2 | Team member record | VERIFIED IN RUNNING APPLICATION | 3 | 2, 8 | Rewards and Operations not offered to managers |
| M3–M4 | Approve and reject leave | VERIFIED IN RUNNING APPLICATION | 6 | 1, 2, 6, 7, 9, 11 | Executed |
| M5 | Attendance corrections and exceptions | VERIFIED IN RUNNING APPLICATION | 4 | 2, 7, 11 | Executed |
| M6 | Leave calendar | VERIFIED IN RUNNING APPLICATION | 1 | 2 |  |
| M7 | Goals, reviews, one-on-ones, feedback | VERIFIED IN RUNNING APPLICATION | 9 | 2 | Manager review refused until the stage opens (F21) |
| M8–M9 | Team learning, career, HR requests, tasks | VERIFIED IN RUNNING APPLICATION | 6 | 2 |  |
| M10 | Team insights | VERIFIED IN RUNNING APPLICATION | 1 | 2 |  |
| M11 | Restricted access (manager) | VERIFIED IN RUNNING APPLICATION | 1 | 2, 7, 8, 9 | 404 "We could not find that" |
| H1–H2 | Directory, register, Employee 360 | VERIFIED IN RUNNING APPLICATION | 10 | 3, 7, 8, 9, 10 | History tab error outside production (F26) |
| H3 | Hire with duplicate check | VERIFIED IN RUNNING APPLICATION | 6 | 3, 6, 7, 9, 11 | Executed |
| H4–H5 | Change manager, transfer, lifecycle state | VERIFIED IN RUNNING APPLICATION | 10 | 3 | Lifecycle dialog opened and cancelled |
| H6 | Onboarding plans and tasks | VERIFIED IN RUNNING APPLICATION | 6 | 3, 6, 9 | Template items error (new finding) |
| H7 | Documents with verification | VERIFIED IN RUNNING APPLICATION | 5 | 3, 8 | SoD refusal shown as server error (new finding) |
| H8 | Attendance administration | VERIFIED IN RUNNING APPLICATION | 6 | 3, 6, 12 | Executed |
| H9a | Leave administration (balances, types, policy, calendar) | VERIFIED IN RUNNING APPLICATION | 13 | 3, 12 | Balances search error (new finding) |
| H9b | Apply on behalf (Leave requests) | INCOMPLETE | 1 | 3, 7 | F38; workaround via Employee 360 verified |
| H10a | Payroll calculate and check | VERIFIED IN RUNNING APPLICATION | 15 | 3, 9, 10, 11, 12 | Recalculate executed |
| H10b | Payroll approve, finalise, payslips, bank file | DISABLED BY SAFETY OR COMMERCIAL GATES | 1 | 3, 6, 7, 9, 10, 11 | Blocked by unverified statutory rules |
| H11 | Statutory configuration | AVAILABLE ONLY AFTER CONFIGURATION | 7 | 3 | Rule verification is a platform task: 0 of 24 verified |
| H12–H13 | Performance and learning administration | VERIFIED IN RUNNING APPLICATION | 7 | 3, 6 | Opened; cycle not changed |
| H14 | Workforce planning and positions | VERIFIED IN RUNNING APPLICATION | 6 | 3 | Draft position created |
| H15 | Compensation (four-person chain) | VERIFIED IN RUNNING APPLICATION | 11 | 3, 6, 8, 9, 11 | Executed |
| H16a | Letters (generate, approve, issue) | VERIFIED IN RUNNING APPLICATION | 6 | 3 | See Deck 3 |
| H16b | Assets | VERIFIED IN RUNNING APPLICATION | 3 | 3 |  |
| H16c | HR queue | VERIFIED IN RUNNING APPLICATION | 3 | 3, 6 |  |
| H17 | Exit and full & final settlement | VERIFIED IN RUNNING APPLICATION | 22 | 3, 9 | Gratuity not calculated |
| H18 | Audit history | VERIFIED IN RUNNING APPLICATION | 4 | 8, 9, 10, 12 |  |
| H19 | Employee import | INCOMPLETE | 10 | 3, 4, 7, 11 | F25 at Map columns |
| C1 | Tenant provisioning (operator) | VERIFIED IN RUNNING APPLICATION | 6 | 4 | Executed (Northwind Retail) |
| C2 | First administrator activation | VERIFIED IN RUNNING APPLICATION | 6 | 4, 7 | E-mail sender shows "Laravel" in development |
| C3 | Admin Centre and configuration finder | VERIFIED IN RUNNING APPLICATION | 4 | 4 |  |
| C4 | Company, legal entity, establishment, location | VERIFIED IN RUNNING APPLICATION | 9 | 4 | Company creates a primary legal entity and establishment |
| C5 | Organisation structure and designer | VERIFIED IN RUNNING APPLICATION | 9 | 4 |  |
| C6 | Job architecture and positions | VERIFIED IN RUNNING APPLICATION | 5 | 4 | FTE defect F8 |
| C7 | Hire and person matching | VERIFIED IN RUNNING APPLICATION | 9 | 4 |  |
| C8 | Roles, users and scope | VERIFIED IN RUNNING APPLICATION | 11 | 4, 7, 11 |  |
| C9 | Shifts, schedules, holiday calendars | VERIFIED IN RUNNING APPLICATION | 6 | 4 | Holiday type and half day ignored (F39) |
| C10 | Leave types and policy | VERIFIED IN RUNNING APPLICATION | 5 | 4 | Policy published |
| C11 | Salary components and structures | VERIFIED IN RUNNING APPLICATION | 3 | 4 |  |
| C12 | Statutory registrations and profiles | VERIFIED IN RUNNING APPLICATION | 5 | 4 | Verification blocked (platform) |
| C13 | Workflows and policies | VERIFIED IN RUNNING APPLICATION | 9 | 4, 9, 10 | Builder opened; publish not executed |
| C14a | Notification rules | INCOMPLETE | 4 | 4, 7 | No create/delete (F18) |
| C14b | Security policy, settings, feature flags | VERIFIED IN RUNNING APPLICATION | 3 | 4 | Viewed, not saved |
| C15 | Change Centre, audit, readiness | VERIFIED IN RUNNING APPLICATION | 3 | 4, 8, 9, 11 | Chain intact: 287 events |
| C16 | UAT, go-live, hypercare, handover | NOT IMPLEMENTED | 0 | — | Process stages outside the application (checklists provided) |
| P1 | Operator sign-in with MFA | VERIFIED IN RUNNING APPLICATION | 3 | 5, 8 | QR code and key covered |
| P2 | Tenant lifecycle (suspend, reactivate, enter) | VERIFIED IN RUNNING APPLICATION | 8 | 5, 6, 7, 8, 9 | Executed on Northwind |
| P3 | Signups | DISABLED BY SAFETY OR COMMERCIAL GATES | 1 | 5 | Public registration off |
| P4 | Plans, subscriptions, entitlements | DISABLED BY SAFETY OR COMMERCIAL GATES | 3 | 5 | Shadow mode |
| P5 | Billing, tax, payments, invoices, approvals | DISABLED BY SAFETY OR COMMERCIAL GATES | 7 | 5 | No prices; live payments refused |
| P6 | Operations | VERIFIED IN RUNNING APPLICATION | 1 | 4, 5, 7, 10 | No scheduler or worker locally |
| P7 | Platform audit trail | VERIFIED IN RUNNING APPLICATION | 2 | 5, 8, 10 | Chains not recently verified (no scheduler) |
| P8 | Readiness | VERIFIED IN RUNNING APPLICATION | 1 | 4, 5, 9 |  |
| P9a | Legal hold | VERIFIED IN RUNNING APPLICATION | 5 | 5, 7, 8 | Release needs a second operator (pending) |
| P9b | Tenant export | IMPLEMENTED BUT NOT VERIFIED END-TO-END | 2 | 5, 8 | Dialogs only; generation not run |
| P9c | Retention policies | AVAILABLE ONLY AFTER CONFIGURATION | 1 | 5, 8 | None in force (D-8) |
| P9d | Tenant deletion | DISABLED BY SAFETY OR COMMERCIAL GATES | 1 | 5, 8, 10 | Switched off |
| P10 | Statutory rule verification | UNABLE TO VERIFY | 3 | 5 | Needs official evidence and two operators |
| R1 | Dashboards, People analytics, Workforce pulse | VERIFIED IN RUNNING APPLICATION | 4 | 6, 9, 12 | Executive datasets missing (F45) |
| R2 | Reports run and CSV export | VERIFIED IN RUNNING APPLICATION | 3 | 4, 11, 12 | Export audited |
| R3 | Report builder | INCOMPLETE | 1 | 12 | F44 |
| R4 | Area dashboards (pay, growth, workforce, exit, service) | VERIFIED IN RUNNING APPLICATION | 7 | 12 | Small groups suppressed |
| R5 | Ask PeopleOS | VERIFIED IN RUNNING APPLICATION | 1 | 9, 12 | Language model off; rule-based answers |
| S1 | Integrations: API keys, webhooks, SSO, hub, inbound events | IMPLEMENTED BUT NOT VERIFIED END-TO-END | 5 | 8, 10 | Forms only; no round trip; F37 |
| S2 | Markedge internal tenant | VERIFIED IN RUNNING APPLICATION | 8 | 6, 8 | Provisioned and staffed with fictional employees |
| S3 | Sign-in support (reset, invited, suspended) | VERIFIED IN RUNNING APPLICATION | 8 | 7, 8 | Executed |

Rows with 0 screenshots or no deck are gaps by design (C16 is a process outside the application) or must be reviewed.
