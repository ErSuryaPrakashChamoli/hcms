# Markedge PeopleOS training library

**For:** Markedge trainers, implementation specialists and platform operators who deliver PeopleOS training, and
maintainers who rebuild the decks. Built on 21 October 2026 from the running application (branch
`feature/oct_20_people_os_phase_1`, commit `72c96a73`). No application code or configuration was changed.

PeopleOS is **not yet generally available**: the release gate is NO-GO (10 of 14 gates blocked, re-verified
13 October 2026; see `docs/saas/SaaS-Final-Release-Gate.md`). Every deck says so. None of the training work changes a gate.

## At a glance (21 October 2026)

| Deck | Slides | Screenshots used | Size |
|---|---|---|---|
| 01-PeopleOS-Employee-User-Training.pptx | 68 | 70 | 6.2 MB |
| 02-PeopleOS-Manager-Self-Service-Training.pptx | 38 | 34 | 3.5 MB |
| 03-PeopleOS-HR-Administrator-Training.pptx | 130 | 153 | 14.9 MB |
| 04-PeopleOS-Client-Onboarding-and-Implementation-Playbook.pptx | 129 | 97 | 8.8 MB |
| 05-PeopleOS-Platform-Administrator-Training.pptx | 42 | 35 | 3.5 MB |
| 06-Markedge-Internal-PeopleOS-Operations.pptx | 25 | 18 | 2.1 MB |
| 07-PeopleOS-Troubleshooting-and-Support-Playbook.pptx | 51 | 29 | 3.1 MB |
| 08-PeopleOS-Security-Governance-and-Compliance.pptx | 43 | 34 | 3.5 MB |
| 09-PeopleOS-Executive-and-Client-Demo.pptx | 31 | 25 | 3.1 MB |
| 10-PeopleOS-HCM-System-Architecture-and-Workflows.pptx | 43 | 10 | 1.4 MB |
| 11-PeopleOS-Implementation-UAT-and-Go-Live-Checklist.pptx | 46 | 20 | 2.7 MB |
| 12-PeopleOS-Reporting-and-Analytics-Training.pptx | 38 | 29 | 3.3 MB |
| 13-PeopleOS-Organisation-Administrator-Training.pptx (added 16 Nov 2026) | 227 | 220 (222 placements) | 22.4 MB |

- **684 slides**; every slide has speaker notes; all 12 files pass the pptx validator and open in PowerPoint-compatible tools.
- **481 genuine screenshots** in `screenshots/` (unannotated originals; a few earlier full-size originals are kept in
  `screenshots/_originals/`), each listed in `SCREENSHOT-INVENTORY.md` with its deck, role, workflow, action, result,
  status and prerequisite.
- **86 workflows** in `FEATURE-TO-PRESENTATION-MATRIX.md`; all but one process stage have screenshot evidence in a deck.
- **Two review passes** of every slide; results in `QA-REPORT.md`.

## The decks

| # | File | For | What it teaches |
|---|---|---|---|
| 01 | `01-PeopleOS-Employee-User-Training.pptx` | Every employee | Sign in, Home, profile, leave, attendance, requests, pay and tax, goals, learning, communication |
| 02 | `02-PeopleOS-Manager-Self-Service-Training.pptx` | Line managers | Team visibility, approvals, team attendance, goals and one-on-ones, team insights, access limits |
| 03 | `03-PeopleOS-HR-Administrator-Training.pptx` | HR administrators and HR managers | People records, hiring, lifecycle, documents, time and leave, payroll and statutory, growth, compensation, letters, requests, exit, imports, audit |
| 04 | `04-PeopleOS-Client-Onboarding-and-Implementation-Playbook.pptx` | Markedge implementation team, customer project team | 28 implementation stages from kickoff to handover, on a tenant provisioned and configured from empty |
| 05 | `05-PeopleOS-Platform-Administrator-Training.pptx` | Markedge platform operators | Operator sign-in with MFA, tenant lifecycle, commercial pages in shadow mode, operations, audit, readiness, offboarding |
| 06 | `06-Markedge-Internal-PeopleOS-Operations.pptx` | Markedge internal HR and operations | Markedge as its own employer on an ordinary tenant, separate from SaaS operations |
| 07 | `07-PeopleOS-Troubleshooting-and-Support-Playbook.pptx` | Support staff | Real error, validation, restricted and blocked states, with diagnosis and escalation |
| 08 | `08-PeopleOS-Security-Governance-and-Compliance.pptx` | Security, compliance and governance reviewers | Roles, scope, field protection, audit, MFA, separation of duties, export, retention, holds, deletion |
| 09 | `09-PeopleOS-Executive-and-Client-Demo.pptx` | Customer executives | Outcomes and the product on real screens, with live versus not-yet-available stated |
| 10 | `10-PeopleOS-HCM-System-Architecture-and-Workflows.pptx` | Architects and engineers | Layers, tenancy, access, effective dating, audit, workflows, payroll safeguards, integration boundaries |
| 11 | `11-PeopleOS-Implementation-UAT-and-Go-Live-Checklist.pptx` | Customer UAT lead, implementation team | Environment, data, configuration and role testing, UAT cases with reference runs, defects, go-live criteria |
| 12 | `12-PeopleOS-Reporting-and-Analytics-Training.pptx` | HR analysts, executives, auditors | Dashboards, analytics pages, reports and export, audit and operational reports, known gaps |
| 13 | `13-PeopleOS-Organisation-Administrator-Training.pptx` | The customer's organisation administrators (Account Owner, Tenant Super Admin, Tenant HR Admin) | Set-up and running of an organisation in 13 modules and five learning groups, with clickable navigation (see below) |

## Deck 13: the navigable design system

Deck 13 joins the set-up screens of deck 04 with the operations screens of deck 03 and presents them as a modular
course built inside PowerPoint. It reuses the 21 October 2026 captures; no new screenshots were taken.

- **Navigation bar** on every instructional slide: the PeopleOS mark, a **Contents** (Home) button, the current module
  highlighted in its colour, the current submodule, module progress (slide n of m and a bar), and Previous / Next.
  Module dividers use a simplified bar; the cover has none.
- **Clickable contents:** the main contents has one card per module in five learning groups. Each module has a
  divider, a submodule contents slide (rows link to each submodule's first slide), and a summary with Next module,
  Module contents, Main contents and related-module links. Five set-up and exit modules end with an administrator checklist.
- **Footer** on every slide with chrome: PeopleOS HCM · course name · module identifier · submodule progress · slide
  number (a live field) in the module colour.
- **Layouts** (in `tools/build/modular.js`, on top of the shared ones in `lib.js`): module introduction, submodule
  contents, navigation instructions (`navigate`), step-by-step procedure (`procedure`), annotated screenshot (`shot`,
  `wide`), form fields (`fields`), actions and buttons (`actions`), workflow diagram (`purpose`), process flow
  (`diagram`), calculation example (`calculation`), comparison (`table`, `compare`), warning (`warning`),
  troubleshooting (`troubleshoot`), administrator checklist (`checklist`) and module summary (`summary`).
- **One place for colours and geometry:** `tools/build/design.js`. Brand values come from the application
  (`resources/css/filament/admin/theme.css`, `public/favicon.svg`, the Heroicons the app renders and the rail's Home
  icon). Eight module colours are the app's `--pos-hue-*` wayfinding hues; five are deck-only extensions at the same
  depth. Every build fails if a module colour carries white text below 4.5:1.
- **Stable slide keys, not slide numbers:** links are written against keys such as `toc`, `M05`, `M05.contents`,
  `M05.5.2`, `M05.summary`, and resolved to slide numbers when the deck is built. Insert or move slides, rebuild, and
  every link follows; a link to an unknown key fails the build. The resolved map is written to `tools/nav-maps/13.json`.
- **Clickable areas** are transparent shapes laid over each button or card (named `Link · …`), so the whole area works
  in slideshow mode and no text carries an underlined run-level hyperlink.
- **Link check:** `node build/check-nav.js 13` re-reads the written .pptx and verifies every Home, Previous, Next,
  contents card, submodule row, summary and related-module link, plus chrome placement, slide bounds and footers.
  It writes `tools/nav-check-13.json` and exits non-zero on any failure. Run it after every rebuild.

## Supporting documents

| File | Purpose |
|---|---|
| `README.md` | This page |
| `WORKFLOW-INVENTORY.md` | Phase B: every workflow mapped to its real screen, before capture |
| `SCREENSHOT-INVENTORY.md` | Every screenshot: deck, user and role, workflow, screen, action, expected result, environment, status, prerequisite (generated) |
| `FEATURE-TO-PRESENTATION-MATRIX.md` | Each feature or workflow, its status label, its evidence and the decks that teach it |
| `PEOPLEOS-PRESENTATION-ACCURACY-REGISTER.md` | Material claims in the decks with their evidence source and status |
| `QA-REPORT.md` | Rendering, integrity and coverage checks of the final decks |
| `supporting/PeopleOS-Customer-Implementation-Checklist.xlsx` (+ `.md`) | One row per implementation stage, with status drop-downs |
| `supporting/PeopleOS-Go-Live-Handover-Checklist.xlsx` (+ `.md`) | Items to complete before support takes over |
| `supporting/PeopleOS-UAT-Test-Cases.xlsx` (+ `.md`) | UAT cases with the reference run observed in the training environment; Pass/Fail left for the customer |
| `screenshots/<role>/*.png` | Original, unannotated screenshots |

## Feature-status labels

Every screenshot slide shows one of these labels, and the matrix and register use the same seven:
VERIFIED IN RUNNING APPLICATION · IMPLEMENTED BUT NOT VERIFIED END-TO-END · AVAILABLE ONLY AFTER CONFIGURATION ·
DISABLED BY SAFETY OR COMMERCIAL GATES · INCOMPLETE · NOT IMPLEMENTED · UNABLE TO VERIFY.

## How the screenshots were produced

- **Environment:** a disposable MySQL database, `hcm_training_showcase`, created for this work, migrated and seeded with
  `UxShowcaseSeeder` (fictional people at the fictional Demo Group; the seeder refuses any database not named `*_showcase`
  and refuses production). It was served by local PHP servers on ports 8098–8101 with environment variables only. The
  development database `hcm` and the development server on port 8000 were not used.
- **Execution:** workflows were really executed on that database: leave requested, approved and rejected; attendance
  corrected and processed; people hired; managers changed; documents verified; letters issued; exits completed; and two
  fictional customer tenants provisioned by the platform operator and configured from empty. Each state shown happened.
- **Accounts:** the showcase personas use the seeder's public fixture password, which works on `*_showcase` databases
  only. The tenant super admin and the platform operator use a per-run password kept outside the repository. The operator
  is enrolled in MFA through the real set-up screen, and the authenticator key is kept outside the repository.
- **Nothing switched on:** public signup, live payments and tenant deletion stayed off; no security setting was weakened.
- **No secrets on screen:** the operator's QR code and key were covered before capture; recovery codes, tokens,
  invitation and reset links were never captured.
- **Annotations:** the PNG files are unannotated. Numbered callouts in the decks are editable PowerPoint shapes drawn
  from element boxes recorded at capture time (`tools/manifests/*.json`).

## Rebuild

Requirements: Node.js 22, the repository's own Playwright Chromium (`npx playwright install chromium` at the repository
root), Python 3 with Pillow (contact sheets), and LibreOffice (the snap build) for rendering checks.

```bash
cd docs/presentations/peopleos/tools
npm ci                                   # pptxgenjs, sharp, exceljs (separate from the application)

# 1. A disposable database (never a real tenant), then serve it on its own port
mysql -e "CREATE DATABASE hcm_training_showcase"
DB_DATABASE=hcm_training_showcase php artisan migrate --force
DB_DATABASE=hcm_training_showcase PEOPLEOS_SEED_PASSWORD=<private value> php artisan db:seed --class=UxShowcaseSeeder --force
# serve from public/ with: DB_DATABASE=hcm_training_showcase CACHE_STORE=array MAIL_MAILER=log LOG_CHANNEL=stderr php -S 127.0.0.1:8098 ../../vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php

# 2. Capture (scripts in order; 00 enrols the operator's authenticator)
export TRAINING_BASE_URL=http://127.0.0.1:8098 TRAINING_SEED_PASSWORD_FILE=<file holding the seed password>
node capture/00-operator-mfa.mjs && node capture/10-leave-flow.mjs && node capture/20-attendance-flow.mjs   # then 30-…99-, and 98-recapture last

# 3. Build
node build/build-all.js          # all decks (or: node build/build-all.js 04 07)
node build/check-nav.js          # deck 13 (also run by build-all): every internal link, chrome placement, footer
node build/supporting.js         # checklists and UAT workbook
node build/inventory.js          # SCREENSHOT-INVENTORY.md
node build/matrix.js             # FEATURE-TO-PRESENTATION-MATRIX.md
python3 build/check.py           # integrity check (python-pptx), then node build/qa-report.js
```

Capture scripts change data on purpose, so run them once, in order, on a fresh database. The scripts that executed
workflows in parallel (`30-…99-`) were written for four servers sharing one database; rerunning a single script on an
already-used database may find records in a different state.

## Maintenance

- When a defect named in the decks is fixed (F25 import, F38 apply on behalf, F44 report builder, F17 give feedback and
  others in `docs/reports/documentation-gaps.md`), re-capture the affected flow, change the slide's status label, and
  update the accuracy register and the matrix.
- When a release gate changes, update `tools/build/decks/common.js` (`statusDetailed`, `RELEASE_LINE` in `build/lib.js`)
  and rebuild every deck.
- Deck 13: change module colours, chrome geometry or brand values only in `tools/build/design.js`; add slides inside
  a module with `d.sub(id)` before the first slide of a submodule, and rebuild then run `build/check-nav.js 13`.
  Its screenshots are the 21 October 2026 captures and predate the November navigation redesign of the application;
  re-capture before using the deck with customers on the current release.
- Contact details, prices, hosting region and service levels are not stated anywhere in the repository; the decks say
  "to be confirmed with the SaaS provider" rather than inventing them.
