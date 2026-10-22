# PeopleOS training library: QA report

**Date:** 2026-10-21 · **Application:** commit `72c96a73` (no application file changed) ·
**For:** the reviewers who accept the training library.

## 1. Summary

| Item | Result |
|---|---|
| Presentations | 12 editable PPTX files (pptxgenjs; text, shapes, tables and callouts are native PowerPoint objects) |
| Slides | 684 in total |
| Genuine screenshots captured | 481 (all from the running application on the disposable training database) |
| Screenshots used in at least one deck | 438 |
| Workflows in the feature matrix | 86; every one except C16 (implementation process outside the application) has screenshot evidence in a deck |
| Speaker notes | every slide of every deck |
| Validator (pptx skill `validate.py`) | PASSED for all decks |
| Distorted pictures | 0 (aspect ratio of every placed picture equals its image) |
| Empty slides | 0 |
| Secrets in screenshots or documents | none found (operator QR code and key covered before capture; no token, link, password or real personal data) |

## 2. Per presentation

| Deck | Slides | Screenshots used | Screenshot placements | Notes | Conceptual-diagram labels | Size | Validator |
|---|---|---|---|---|---|---|---|
| 01-PeopleOS-Employee-User-Training.pptx | 68 | 70 | 71 | 68/68 | 3 | 6.2 MB | PASSED |
| 02-PeopleOS-Manager-Self-Service-Training.pptx | 38 | 34 | 34 | 38/38 | 2 | 3.5 MB | PASSED |
| 03-PeopleOS-HR-Administrator-Training.pptx | 130 | 153 | 154 | 130/130 | 4 | 14.9 MB | PASSED |
| 04-PeopleOS-Client-Onboarding-and-Implementation-Playbook.pptx | 129 | 97 | 99 | 129/129 | 3 | 8.8 MB | PASSED |
| 05-PeopleOS-Platform-Administrator-Training.pptx | 42 | 35 | 36 | 42/42 | 1 | 3.5 MB | PASSED |
| 06-Markedge-Internal-PeopleOS-Operations.pptx | 25 | 18 | 19 | 25/25 | 1 | 2.1 MB | PASSED |
| 07-PeopleOS-Troubleshooting-and-Support-Playbook.pptx | 51 | 29 | 30 | 51/51 | 1 | 3.1 MB | PASSED |
| 08-PeopleOS-Security-Governance-and-Compliance.pptx | 43 | 34 | 35 | 43/43 | 2 | 3.5 MB | PASSED |
| 09-PeopleOS-Executive-and-Client-Demo.pptx | 31 | 25 | 26 | 31/31 | 1 | 3.1 MB | PASSED |
| 10-PeopleOS-HCM-System-Architecture-and-Workflows.pptx | 43 | 10 | 11 | 43/43 | 9 | 1.4 MB | PASSED |
| 11-PeopleOS-Implementation-UAT-and-Go-Live-Checklist.pptx | 46 | 20 | 21 | 46/46 | 2 | 2.7 MB | PASSED |
| 12-PeopleOS-Reporting-and-Analytics-Training.pptx | 38 | 29 | 30 | 38/38 | 1 | 3.3 MB | PASSED |

## 3. Workflow coverage

Major workflows documented (feature matrix rows): **86**. Status after capture:

| Status | Count | Workflows |
|---|---|---|
| VERIFIED IN RUNNING APPLICATION | 66 | E1 Sign in, Home, navigation; E2 Search and quick launch; E3 Own record (read only); E4 Documents and letters; E5 Request leave → decision → balance; E6 Check in and correct attendance; E7 My work and notifications; E9 Goals, appraisal, career; E10 Learning; E11 My compensation; E12 HR requests and knowledge base; E14 Announcements; E16 Account security, preferences, phone, dark mode; E17 Restricted access (employee); M1 Manager Home and team; M2 Team member record; M3–M4 Approve and reject leave; M5 Attendance corrections and exceptions; M6 Leave calendar; M7 Goals, reviews, one-on-ones, feedback; M8–M9 Team learning, career, HR requests, tasks; M10 Team insights; M11 Restricted access (manager); H1–H2 Directory, register, Employee 360; H3 Hire with duplicate check; H4–H5 Change manager, transfer, lifecycle state; H6 Onboarding plans and tasks; H7 Documents with verification; H8 Attendance administration; H9a Leave administration (balances, types, policy, calendar); H10a Payroll calculate and check; H12–H13 Performance and learning administration; H14 Workforce planning and positions; H15 Compensation (four-person chain); H16a Letters (generate, approve, issue); H16b Assets; H16c HR queue; H17 Exit and full & final settlement; H18 Audit history; C1 Tenant provisioning (operator); C2 First administrator activation; C3 Admin Centre and configuration finder; C4 Company, legal entity, establishment, location; C5 Organisation structure and designer; C6 Job architecture and positions; C7 Hire and person matching; C8 Roles, users and scope; C9 Shifts, schedules, holiday calendars; C10 Leave types and policy; C11 Salary components and structures; C12 Statutory registrations and profiles; C13 Workflows and policies; C14b Security policy, settings, feature flags; C15 Change Centre, audit, readiness; P1 Operator sign-in with MFA; P2 Tenant lifecycle (suspend, reactivate, enter); P6 Operations; P7 Platform audit trail; P8 Readiness; P9a Legal hold; R1 Dashboards, People analytics, Workforce pulse; R2 Reports run and CSV export; R4 Area dashboards (pay, growth, workforce, exit, service); R5 Ask PeopleOS; S2 Markedge internal tenant; S3 Sign-in support (reset, invited, suspended) |
| DISABLED BY SAFETY OR COMMERCIAL GATES | 6 | E8a Payslips; H10b Payroll approve, finalise, payslips, bank file; P3 Signups; P4 Plans, subscriptions, entitlements; P5 Billing, tax, payments, invoices, approvals; P9d Tenant deletion |
| INCOMPLETE | 6 | E8b Tax declaration; E13b Give feedback; H9b Apply on behalf (Leave requests); H19 Employee import; C14a Notification rules; R3 Report builder |
| AVAILABLE ONLY AFTER CONFIGURATION | 3 | E13a Surveys; H11 Statutory configuration; P9c Retention policies |
| IMPLEMENTED BUT NOT VERIFIED END-TO-END | 3 | E15 Resignation; P9b Tenant export; S1 Integrations: API keys, webhooks, SSO, hub, inbound events |
| NOT IMPLEMENTED | 1 | C16 UAT, go-live, hypercare, handover |
| UNABLE TO VERIFY | 1 | P10 Statutory rule verification |

Workflows without screenshot evidence: **C16** (UAT, go-live, hypercare and handover are process stages outside the
application; Deck 04 and Deck 11 cover them with stage cards, checklists and the supporting workbooks).

Workflows executed end to end on the training database (each state shown happened): leave request → manager approval
and rejection → employee result; attendance check-in, correction, approval, manual punch and day processing; hire with
duplicate check; transfer and manager change; onboarding task; document upload and second-person verification; leave
balance adjustment; letters with approval; asset assignment; HR request handling; exit with clearance, full & final
settlement and alumni portal; four-person compensation change; payroll recalculation and refused validation; report
run, export and audit; tenant provisioning, activation and configuration from empty (Northwind Retail); a second
internal tenant (Markedge); tenant suspension and reactivation; legal hold; password reset; employee self-service
(HR request, goal check-in, self review, course start, acknowledgements); manager one-on-one and feedback.

## 4. Rendering and file-integrity checks

1. **Exist and open:** all 12 files open with python-pptx (`tools/build/check.py`) and with LibreOffice.
2. **Real content:** every deck has a cover, learning objectives, status slides, sections, screenshot slides, exercises,
   an answer key and a summary; no empty slide.
3. **Screenshots embedded:** 566 placements; JPEG copies (max 1920 px) of the PNG originals, cropped where
   noted ("Real screen (cropped)"); originals kept in `screenshots/`.
4. **Validator:** the pptx skill's `office/validate.py` (schema, relationships, content types, slide checks).
5. **Rendering:** every deck converted to PDF with LibreOffice and rasterised (`tools/.work/render.sh`); every slide
   inspected in two review passes (section 5).
6. **Editability:** titles use layout placeholders; text, callout badges and boxes, tables and diagrams are native
   shapes; screenshots are pictures; theme colours are written into the theme (PeopleOS palette).
7. **Notes:** every slide has speaker notes (what to say, what learners should see, evidence, what to avoid).
8. **Fictional data:** all people, companies and tenants are invented; e-mail domains are .local, .example or .test.

## 5. Visual review passes

**Pass 1** (five independent reviewers, every slide of the build at that time, before later consolidation to the
final 684 slides): findings included steps without markers and markers without
steps, compare slides without status pill or source line, captions describing parts of a page outside the crop,
step text overprinting on some slides, small guest-page cards, a slide captioned as success that showed a development
error page, two screenshots taken before the screen refreshed, a too-broad claim about small-group suppression, and
several badges covering labels. All High findings and the systematic Medium findings were fixed in the generator
(markers only for referenced steps, generated steps from recorded labels, outline-free comparisons with per-side pills
and source lines, content cropping of guest pages, conservative text fitting, connector-length limits) or in the deck
content; two screens were re-captured (`tools/capture/98-recapture.mjs`).

**Pass 2** (three reviewers, all decks re-rendered): confirmed the pass-1 fixes and reported remaining items (mostly
Medium: individual badge positions, captions naming rows outside a crop, two toasts too small). These were fixed and
the affected decks rebuilt; see section 6 for what remains.

## 6. Known limitations of the decks

- LibreOffice renders Calibri and Cambria with metric-compatible substitutes; PowerPoint should match, but check the
  densest slides (stage cards, tables) once in PowerPoint before a live session.
- Comparison slides show two screenshots at half width: overall layout is readable; small text inside them is
  described in the caption rather than read from the image.
- Callout badges are placed automatically; on a few dense screens a badge may still sit close to a label.
- Some screenshots intentionally show development-mode error pages (labelled as defects). In production the user sees a
  generic server-error dialog instead.
- The Give feedback error toast (F17) shows local database connection details from the disposable environment; no
  secret is shown.
- Screens were captured at 1440 × 900 in Chromium. Safari, Android Chrome and real devices were not used.

## 7. Unverified, incomplete, disabled or unimplemented features shown as such

See section 3 and `FEATURE-TO-PRESENTATION-MATRIX.md`. Highlights: payroll finalisation, payslips and statutory returns
(disabled until 24 statutory rules are verified; 0 verified); employee and punch imports (F25); Apply on behalf (F38);
report builder (F44); executive dataset permissions (F45); Give feedback (F17); notification rule creation (F18);
public signup, billing, payments and tenant deletion (disabled by gates); data export generation, integration round
trips and statutory rule verification (not exercised); gratuity, SAML, native mobile app, SMS/WhatsApp delivery (not
implemented). New findings during capture are in the accuracy register (D14–D30).

## 7a. Remaining blockers (product, not the training library)

The PeopleOS release gate is **NO-GO** (10 of 14 gates blocked; `docs/saas/SaaS-Final-Release-Gate.md`): credential
remediation, operator MFA in the target, independent security review, statutory verification and gratuity, backup and
DR, hosting and on-call, staging validation, owner decisions, deployment rehearsal. Every deck states this.
