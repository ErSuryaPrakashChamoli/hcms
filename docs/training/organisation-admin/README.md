# PeopleOS Organisation Administrator training

**For:** the trainers who deliver this course and the people who maintain it.
**Audience of the course:** the organisation administrator of a client company that uses PeopleOS, and the HR and
payroll leads who work with them. It covers only what that administrator can see and do inside their own
organisation. Functions run by the service provider (Markedge) for all customers are not in it.

## What is in this folder

| File | What it is |
|---|---|
| `PeopleOS-Organisation-Administrator-Complete-Training.pptx` | The course: 26 modules in 9 learning groups, built from real screens. Navigable: Contents (Home) on every slide, the current module highlighted in its colour, clickable contents, module and summary slides, Previous / Next. |
| `PeopleOS-Organisation-Administrator-Complete-Training.pdf` | The same course as a PDF (links work in PDF readers that support them). |
| `01-complete-application-inventory.md` | Every screen the administrator can open: path, tabs, sections, fields, actions, filters, columns, permissions, dependencies, status. |
| `02-navigation-and-permission-matrix.md` | The administrator's navigation, and which other roles of the example organisation can open each module. |
| `03-module-coverage-and-gap-register.md` | Coverage per module, and everything that could not be shown or confirmed. |
| `04-screenshot-register.md` | Every screenshot with its module, screen, path and callouts. |
| `05-training-validation-report.md` | Totals, the coverage table, the method and the link check. |
| `screenshots/` | The genuine screens (lists, pages, new / view / edit forms) and `screenshots/flows/` (step-by-step captures). |
| `tools/` | Everything used to produce the course (see below). |

## How the course was made

1. **What the administrator can open** was taken from the application itself: its navigation and its own access
   checks for a real organisation administrator account (`tools/discover.php`). Screens that only the provider's
   platform operators may open (tenants, plans, billing, subscriptions, sign-ups, platform readiness) are refused to
   this role by the application, so they are not in the course.
2. **Every screen was opened and photographed** as that administrator (`tools/capture/10-crawl.mjs`), on a
   disposable training database with a fictional organisation (Demo Group). Before each picture, values that look
   like PAN, Aadhaar/UAN, bank account numbers, mobile numbers or personal e-mail addresses are masked in the page.
   No picture is edited or generated.
3. **Each module was researched from the source code and the training data** (`tools/AGENT-BRIEF.md`,
   `tools/content/*.json`): purpose, fields, actions, permissions, dependencies, rules, calculations, limitations.
   Nothing is invented; what could not be confirmed is listed in the gap register.
4. **Callouts** (numbered boxes on the screens) were measured on the live screens (`tools/capture/20-annotate.mjs`)
   and are editable PowerPoint shapes.
5. **Step-by-step flows** (signing in, menus, the Employee 360, wizards, the payroll run) were driven in the browser
   (`tools/capture/30-flows.mjs`, `tools/capture/40-steps.mjs` with `tools/capture/steps*/`) and placed with captions
   (`tools/flows-placement.json`).
6. **The end of a payroll month** (approve, close, publish payslips, pay) cannot be reached in the example
   organisation, because PeopleOS refuses those steps while the statutory rule versions a run uses are unverified.
   Those pictures come from a copy of the training database (`tools/env-payroll.sh`) where that check was switched
   off; the course says so on the slide before them. Everything else on those screens is unchanged.
7. **Build and checks** (`tools/build/build.js`): the deck uses the navigable design system in
   `docs/presentations/peopleos/tools/build/` (`design.js`, `modular.js`, `lib.js`). Every internal link is re-read
   from the written file and checked (`check-nav.js`, report in `tools/nav-map/nav-check-OA.json`).

## Rebuilding

Requirements: the repository's PHP and Composer setup, MySQL, Node 22, `npm ci` in
`docs/presentations/peopleos/tools` (pptxgenjs, sharp), the repository's Playwright with `/snap/bin/chromium`, and the
snap LibreOffice for the PDF.

```bash
cd docs/training/organisation-admin/tools
source env.sh                    # pins every command to the training database (works with a cached config)
# 1. A disposable training database with the fictional Demo Group (never a real organisation)
mysql -e "CREATE DATABASE hcm_orgadmin_showcase"
(cd ../../../.. && php artisan migrate --force && PEOPLEOS_DEMO_PASSWORD=<12+ characters> php artisan db:seed --class='Database\Seeders\Demo\DemoOrganisationSeeder' --force)
#    keep that password in tools/.work/demo-password (gitignored)
# 2. Serve it (from public/) on its own port
(cd ../../../../public && php -S 127.0.0.1:8091 ../vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php)
# 3. Discover, assign, capture
php discover.php && node assign.js
node capture/10-crawl.mjs && node capture/20-annotate.mjs && node capture/30-flows.mjs && node capture/40-steps.mjs
# 4. Build, check and write the registers
node build/build.js && node build/registers.js
```

`tools/q.php` runs read-only SELECT queries against the training database only (used while researching).
`tools/clone-salaries.php` and `tools/env-payroll.sh` are only for the payroll copy.

## Before you deliver it

- The screens are those of the release running on 16 November 2026. Re-capture after a release that changes them.
- Some features are switched off or empty in the example organisation; the course describes them from the product
  and the gap register lists what could not be photographed.
- Statutory figures in the examples come from rule versions that PeopleOS has not yet verified; they are examples,
  not legal advice.
