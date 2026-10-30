# HROne Setup re-verification against the live capture (13)

Prepared 2026-10-29, analysis only (no code, no tests, no HROne access). Prefix for new build packages: **SV-**.

> **Built (2026-10-29):** PF-1 … PF-11 closed by HP-8h (360 policy, rounds, nominations, client links, anonymity) and HP-8i
> (performance policy); answers HROne's form has but PeopleOS does not read yet are recorded on the policy (see
> `docs/architecture/hrone-parity.md`, HP-8h / HP-8i).
>
> **Also (2026-10-29):** the section "Permission-tree cross-check" at the end checks all 240 nodes of HROne's Setup
> page-access tree against the matrices (none missing; 11 partly covered → rows XC-1 … XC-71, packages SV-20 … SV-27).
>
> **Progress (2026-10-29).** Covered: every Setup capture in `pages.jsonl` (259 `route_app_core_*` records, 193 of
> them `__tab_` captures; 66 routes, 7 of which redirect to the home page), their page text and
> API lists, and the `components.json` dialogs behind them, compared with matrices 01, 02, 08, 09, 11 and 12 and
> with the PeopleOS code in the working tree. Dialogs were screened automatically (form-control names not found in any
> matrix, for components reachable from matrix sections or named after Setup forms) and triaged by hand; a control a
> matrix describes in prose counts as covered. All 14 in-scope groups are
> written below; recruitment is N-A. Limits: Asset and Helpdesk have no live Setup page (their routes are lazy
> children that the crawl did not open), so for them only `components.json` was re-checked. Policy editors still show
> only their list cards in the live capture, so field-level findings come from `components.json` dialogs that the
> earlier matrices did not have (they were built from earlier extractions: `spec/*.md` and `components2.json`).

## Inputs

- `/home/administrator/Documents/hrms/hrone-research/pages.jsonl`: fields, table headers, buttons and API calls per
  page and tab. Global navigation buttons (Inbox, Request, Team, module rail, search) were removed before comparison.
- The screenshots and `text.txt` of each capture (scratchpad `hrone/deep/`), read for card contents and side menus.
  No names, codes, e-mails, phone numbers or amounts were copied.
- `components.json` (2,126 components), `routes.json` (66 `/app/core` routes, all of them in the capture).
- Matrices `01-core-hr-access-integration.md`, `02-automation-forms-governance.md`, `08-workforce-payroll.md`,
  `09-time-office.md`, `11-expense-asset-helpdesk.md`, `12-performance-engage.md`; `00-build-status.md`.
- PeopleOS code: `config/peopleos.php`, `config/peopleos_setup.php`, `database/data/compliance/in.php`,
  `app/Domain/*`, `app/Filament/*`, migrations.

## How to read this file

Only rows that the six matrices **missed** or that the live capture shows **differently** are listed. Each HROne
element starts with a tag:

- **[missed §x]**: present in the live capture or a `components.json` dialog, and no row of matrix section §x covers it.
- **[changed §x]**: a matrix row exists, but the live capture shows a different shape, scope or more controls; the
  status here replaces the matrix status for that row.
- **[no section]**: a live Setup page that no matrix has a section for.

Status = HAVE / PARTIAL / MISSING / N-A (reason). *(inferred)* marks a meaning taken from control names because
HROne's label or behaviour was not visible. Build notes follow the PeopleOS conventions used in the matrices:
additive; defaults reproduce today's behaviour; statutory values only in verified rule versions
(`database/data/compliance/in.php`), never in settings; per-group options as Policy Engine types; organisation
switches as Setup-centre settings (`config/peopleos.php` → `settings` + `config/peopleos_setup.php`); money through
`PayrollInputSource` / `SettlementSource`; new permissions declared in `config/peopleos.php` then
`php artisan peopleos:sync-permissions`.

## Setup navigation seen live (side menus from page text)

| Group | Menu entries in the live capture | Matrix sections |
|---|---|---|
| Core HR | Organization, Location, Employee, Payment bank, Customer, Project, Vendor | 01 §1.1–1.37 |
| Automation | Role, Workflow, Task automation, Inbox, Services, Notification, Communication | 02 §02.1–02.11 |
| Forms & templates | Code series, Forms, Letter, Letter acceptance, Checklist, Feedback | 02 §03.1–03.6 |
| Governance | Audit trail (Data import, Data export, Security, Subscriptions not opened) | 02 §04.1–04.9 |
| Access rights | Page access, Data access, CXO access, Widget access, Calendar access, Geo access | 01 §5.1–5.6 |
| Integration | API access, BGV verification, Employee offers, SCIM, Single sign on, e-KYC, API key management, Journal voucher, One AI | 01 §6.1–6.11 |
| Recruitment | Recruitment settings | N-A (separate RMS) |
| Workforce | Induction, Confirmation, Transfer, Separation (+ Onboarding route, redirected) | 08 §08.1–08.7 |
| Time office | Shift, Weekly off, Holiday, Check in, Attendance, Leave, Short leave (+ Work from home routes, redirected) | 09 |
| Payroll | Statutory template, Statutory, Challan, Slabs, Pay group, Settings | 08 §10.1–10.12 |
| Expense | Expense management, Expense advance | 11 §11.1–11.11 |
| Performance | Goal, Review, Initiative, 360 Feedback, PIP, 1-on-1 | 12 §12.0–12.17 |
| Engage | Badges, Happiness index, Reward points, Mission statement, Social wall, Celebration, HR Handbook | 12 §13.0–13.8 |
| Asset, Helpdesk | (no live page) | 11 §14, §15 |

---

## 1. Core HR (matrix 01 §1.1–1.37)

Live pages: Organization structure (Enterprise + Company, Business Unit, Department, Sub department, Designation,
Grade, Level, Dimension), Location (Global Region, Country, Business Region, State, District, City, Region, Branch,
Sub branch), Customer, Vendor (+ Vendor form). Employee, Payment bank and Project are in the menu but were not opened.

### /app/core/organization/organizationstructure/enterprise

| # | HROne element (tab / field / column / action) | What it does | PeopleOS today (file or screen) | Status | Build note |
|---|---|---|---|---|---|
| CH-1 | **[missed §1.1]** "Subscription": Total users · Active users · Remaining users; the component also has Total / Active / Remaining **Project** users and **ESS** users (`/api/SubscribeModule/License`, `/api/UserLicense/info`) | Shows the tenant admin how much of the licence is used, per licence type | Plan limits are evaluated operator-side only (`App\Domain\Entitlements\Support\PlanValues`, `Capability` limits, `PlatformPlansPage`); no tenant screen shows usage | PARTIAL | Read-only "Plan and usage" card on Setup → Core HR (or `AdminCentre`): active employees and active users against the plan's limit capabilities, through the entitlement service. No prices or invoices, so it stays outside the blocked billing view (P15). |
| CH-2 | **[missed §1.1]** Contact details with an extra partner tab "JUSTFIN" (`/api/core/Enterprise/ContactDetail/fundingfolks`); the Enterprise tab strip also lists it | Contacts for an HROne partner product (salary-advance lender) | — | N-A | HROne partner integration; no PeopleOS equivalent intended. |

### /app/core/organization/organizationstructure/company

| # | HROne element | What it does | PeopleOS today | Status | Build note |
|---|---|---|---|---|---|
| CH-3 | **[missed §1.2]** "Signatory name" — "You can only map employees who have uploaded their signature" | Default signatory of the company's letters, chosen from employees with a stored signature | Letter signatories per company with a default and a signature image (`app/Domain/Letters/Models/LetterSignatory.php`: `company_id`, `is_default`, `signature_path`; `LetterSignatories` resource); a signatory is a name, not an employee | PARTIAL | Optional `letter_signatories.employee_id` (nullable); when that employee exits, flag the signatory on `LetterSignatories` and the Setup checklist. Default unchanged. |
| CH-4 | **[missed §1.2]** "Do you want to take PII consent from employees?" | Employees must consent to personal-data processing before using the system | Consent is captured only for BGV and alumni (`app/Domain/Bgv`, `AlumniProfile`); the privacy-policy acknowledgement of §1.2 is still open | MISSING | Setting `privacy.pii_consent_required` (bool, default false) + table `privacy_consents` (employee, notice article version, consented_at, withdrawn_at; DataInventory); `EnforceAccountSecurity` asks once per notice version. Notice text is a versioned HR article, never hard-coded (DPDP notice wording is an owner/legal input). |

### /app/core/organization/organizationstructure/businessunit, department, designation

| # | HROne element | What it does | PeopleOS today | Status | Build note |
|---|---|---|---|---|---|
| CH-5 | **[changed §1.3]** Business unit: "Select OU type for business unit — Common / Specific", "Specify OU", "Update OU Type", step "APPLICABLE ON — Select whichever is applicable, SELECT ALL" (`app-business-unit-applicability`; the page loads Department, Designation, Grade and Level lists). Page help: "It is mandatory to have one sub department in order map a business unit." | A business unit is either common (any OU) or valid only for chosen departments / sub-departments / designations / grades / levels; employee forms offer only matching BUs | `business_units`: company, name, code, description, status, effective dates (`BusinessUnitForm.php`). Matrix §1.3 rated it HAVE from name + company | MISSING | `business_units.applicability` (json dimension → ids; empty = common = today). The position form and `AssignPositionAction` filter or warn on a BU outside its applicability (warn only for history rows). |
| CH-6 | **[missed §1.3]** Business unit "Upload attachment — Max 5MB, jpeg, jpg, png, bmp", "Remove image" | Image / logo per business unit shown on its card | — | MISSING | `business_units.logo_path` (optional, image ≤ 5 MB on the documents disk); low priority. |
| CH-7 | **[missed §1.4]** Department: "Select Designation for department — Common / Specific, Select All" | Which designations are valid inside a department | One department per designation (`designations.department_id`, `DesignationForm.php`) | PARTIAL | Pivot `department_designation` (empty = all designations = today); position form filters the designation list by department; `designations.department_id` stays the primary home. |
| CH-8 | **[missed §1.6]** Designation "Create JD by AI": "You have not subscribed this feature · Explore Marketplace" (`/api/customer/FeatureLicense/Details`, `/api/OneAI/features`) | AI job description is a separately licensed add-on | AI is gated by the `ai` capability / `ai.llm` feature (`Capability::Ai`); JD drafting itself waits for CA-16 | HAVE | Gate the JD action on the same entitlement when CA-16 lands (no new switch). |

### /app/core/organization/location/* (all nine tabs)

| # | HROne element | What it does | PeopleOS today | Status | Build note |
|---|---|---|---|---|---|
| CH-9 | **[changed §1.10]** Region is its own page (`/location/region`, "Region is used to group a set of branches", ADD REGION, `/api/core/Region/Search`); dialog `app-region`: `regionName` + optional `globalRegionCode`, `countryCode`, `businessRegionCode`, `stateCode`, `districtCode`, `cityCode`; card shows the parent chain, branch count and employee count | A region can be pinned at any geographic level (e.g. a state or a city) and groups branches | Matrix §1.10 assumed the Region tab "captured the same screen" as Global region and gave region no parents; no regions exist (`geo_groupings` planned in CA-7) | MISSING | Extend the planned `geo_groupings` kind `region` with nullable `country_code`, `state_code`, `district`, `city` and `parent_id` (business / global region); `establishments.region_id` as planned. |
| CH-10 | **[missed §1.4–1.17]** List cards carry counts: employees on every master; states and employees per country; cities and employees per state; sub-departments and employees per department; branches and employees per region; sub-branches and employees per branch | Shows at a glance how each master is used before it is edited or inactivated | Master tables show no counts (`DepartmentsTable`, `DesignationsTable`, `GradesTable`, `LevelsTable`, `LocationsTable`, establishments, business units) | MISSING | Count columns (`->counts()` for children; active employees through current effective positions, access-scoped); S. |
| CH-11 | **[missed §1.3–1.17, §1.29]** "Use import" (`app-use-import`): 1 Download Excel sheet → 2 Upload edited sheet → save; "Excel errors found — download the commented file". Present on Business unit, Department, Sub department, Designation, Grade, Level, Region, State, District, City, Branch, Sub branch and Customer; the Business Region tab calls `/api/workforce/import/ImportList` | Bulk create / update of organisation masters from a spreadsheet, with an annotated error file | Staged row imports exist for leave, attendance and payroll (`App\Domain\Employment\Imports\RowImportTypes`, `RowImports`, Recent uploads); none for organisation masters | MISSING | Row import types for departments, designations, grades, levels, business units, establishments and locations in `RowImportTypes` (area `core`, modes new / update, governed by `imports.approval_mode`); the results download is the error file. |
| CH-12 | **[missed §1.3–1.17]** List filters "SELECT FILTERS / APPLY FILTERS / CLEAR ALL" with Status (Active / Inactive) and parent filters; "SHOW MORE" paging | Narrow long master lists | Status filters on departments, designations, grades, levels; parent-department filter (`DepartmentsTable`) | HAVE | — |
| CH-13 | **[changed §1.14]** District tab in the live tenant holds only one placeholder per state ("‹State› all District", under its business region and global region); no real districts | HROne ships no district list; districts are optional | `establishments.district` free text; CA-7 marked "needs an approved districts / cities source" | PARTIAL | CA-7 need not wait for a district source: keep district optional, with an "All districts" fallback per state; load a reference list only when the owner approves one. |
| CH-14 | **[missed §1.10–1.11]** Seeded defaults: Global region "World"; Business region spanning India | Ready-made top of the geography tree | No geo groupings | MISSING | Seed both on first use of `geo_groupings` (editable, never forced). |
| CH-15 | **[missed §1.17]** Sub branch: "Manage wifi bank" next to "Manage polygon bank"; inline help "Get the latitude and longitude of a place — Step 1 … Step 3 (Google Maps)" | Shortcuts to the reusable Wi-Fi and polygon banks and guidance for typing coordinates | Geo fences typed in a repeater (`GeoFenceResource`); Wi-Fi bank planned (§1.17) | PARTIAL | Helper text on latitude / longitude fields explaining how to read coordinates from any map (no provider dependency); bank links follow the §1.17 Wi-Fi bank. |

### /app/core/organization/customer and /vendor

| # | HROne element | What it does | PeopleOS today | Status | Build note |
|---|---|---|---|---|---|
| CH-16 | **[missed §1.29]** Customer list: "Clone" card action; Filters Tags, Project, Status (`app-filter`); "Use import"; help "Customers created here are used to track expenses and time-sheets at customer level" | Copy a customer, find customers by tag or project, bulk load | No customers (PR-01, HP-5) | MISSING | With PR-01: `customers.tags` (json), a Replicate action, filters by tag / status / project, and a customer row-import type (CH-11). |
| CH-17 | **[missed §1.33]** Vendor list: "Clone" card action, Filters (Tags, Project, Status), tabs Vendor · Vendor form | Same for vendors | No vendors (PR-01) | MISSING | With PR-01, same pattern as CH-16. |
| CH-18 | **[no section]** Project template (`/app/core/project-management/project-template` and `/app/core/projects/project-template`, both redirected; `app-template-description`: template name, description) | Reusable project set-ups | No projects (HP-5, PR-02…05) | MISSING | With HP-5: `project_templates` (name, description, default stages, members, budget fields) and "Create from template". |
| CH-19 | **[missed §1.30–1.35]** Timesheet policy list (`app-timesheet-policy`: Add Policy, Clone, Status filter, "APPLICABLE ON", reminders step) | Timesheet rules per employee group | No timesheets (HP-5); §1.35 covers only the organisation-level timesheet settings | MISSING | With HP-5: policy type `timesheet` (applicability by rules) instead of organisation-only `timesheet.*` settings. |
| CH-20 | **[missed §1.30–1.35]** Task form (`app-task-fields-setting`: per field Rename Label, Enable, Mandatory, Edited By Owner, Edited By Team) and task alerts (`app-task-alerts`: alert when due exceeds, before / after N days, once / daily / alternate days, send to) | Project-task form layout and overdue reminders | No project tasks (HP-5) | MISSING | With HP-5: task fields through the custom-field engine (entity `project_task`) and reminder rules on the notification engine. |

---

## 2. Automation (matrix 02 §02.1–02.11)

Live pages: Role (`/app/core/organization/roles`), Communication → Email (`/app/core/general/emailsmsconfiguration/email`).

### /app/core/organization/roles

| # | HROne element | What it does | PeopleOS today | Status | Build note |
|---|---|---|---|---|---|
| AU-1 | **[changed §02.8]** Add role, step 2 (`app-add-roles-step-two`): "Select role organization wise" — company, business unit, department, sub department, designation, grade, level, region, branch, sub branch (Select All) — or "Select role for specific employees" (+ Add bulk employees); `employees_under_group_member` | Role holders are defined by an organisation rule, so membership follows people as they join, move and leave; the matrix read this step as plain "Assign rights" and rated it HAVE | Roles are granted per user by hand (`MembersRelationManager`, `RoleMembers`, `UserForm` roles; ceiling `RoleGrants`) | PARTIAL → built (SV-7: `role_membership_rules` on the Role page, `RoleMembershipSync` grants / removes only `source = rule` rows, audited, ceiling for authors, locked roles never) | `role_membership_rules` (role_id, conditions via `RuleConditionsSchema`, status) + `RoleMembershipSync` on `employee.position_changed` / joined / exited and nightly: grants and revokes `role_user` rows tagged `source = rule`; manual grants untouched; `RoleGrants` ceiling and audit apply. Default: no rules = today. |
| AU-2 | **[changed §02.8]** Live page: "VIEW: Role view" select, "SYSTEM DEFINED ROLES", "ADD ROLE"; role cards (HR, Admin, CEO, IT, Finance) with member initials and counts; `/api/core/RoleWise/RelieverEmployeeDetails` loads on open | Confirms role reliever and role / employee views | Built in HS-P14 (`RolesByPerson`, role status, reliever) | HAVE | — |

### /app/core/general/emailsmsconfiguration/email

| # | HROne element | What it does | PeopleOS today | Status | Build note |
|---|---|---|---|---|---|
| AU-3 | **[changed §02.1]** Live page has a single tab "Email" (no SMS) and shows only "Display name" + UPDATE; service provider, API path and key (`app-email-configuration`) are not shown to this tenant | Only the sender display name is self-service; a custom mail transport is enabled by HROne | `mail.from_name` / `mail.reply_to` not built (P6–P8 pending); tenant mail transport P16 blocked | MISSING | Build `mail.from_name` as planned (S). The live page lowers P16's priority: HROne itself does not offer a self-service transport. |

---

## 3. Forms & templates (matrix 02 §03.1–03.6)

Live page: Forms (`/app/core/general/forms`; Type list Static, Bank, Candidate Profile, Documents, Family, Health
Insurance, Personal, Previous Employer Details, Professional, Skills, Snapshot: all already in §03.4).

| # | HROne element | What it does | PeopleOS today | Status | Build note |
|---|---|---|---|---|---|
| FT-1 | **[missed §03.4]** Dynamic-form field types and their properties (`app-text`, `app-textarea`, `app-integer`, `app-decimal`, `app-email`, `app-phone`, `app-url`, `app-datetime`, `app-picklist`, `app-multiselect-picklist`): label `labelFC`, length `lengthFC`, decimal places `decimalFC`, mandatory `mandatoryFC`, picklist values `ControlValueFC`, **duplicity check** `isDuplicityCheck` | Typed custom fields with length and precision limits, date-time values, and a "no two records may hold the same value" check | Custom field types text, textarea, number, date, boolean, dropdown, multiselect, email, phone, url (`config('peopleos.custom_fields.types')`); no length, no decimal places, no date-time, no unique-value check | PARTIAL | Validation keys `max_length`, `decimals` and `unique` on `custom_fields.validation`, a `datetime` type; uniqueness checked per tenant and entity when values are saved (indexed value hash). Defaults = today. |
| FT-2 | **[missed §03.5]** Digital signature settings (`app-digital-signature`): "Do you want to enable digital signature?"; configuration "HROne Config / Other Config" (API key, Application ID); "Configuration letters" grid per Letter type · Module · Sign type; "Explore Market Place" | Turns on e-signing of letters, with HROne's or the client's own e-sign account, per letter type | Printed signature image only; e-sign MISSING (EXT) in §03.5 | MISSING | Same e-sign adapter as §03.5 (EXT, owner decision on provider); settings `letters.esign.enabled` (default false), provider credentials encrypted and audit-excluded, `letter_templates.esign_type` (none / aadhaar / dsc …). |

---

## 4. Governance (matrix 02 §04.1–04.9)

Live page: Audit trail (`/app/core/audittrail`; page help and an error dialog only, nothing new).

| # | HROne element | What it does | PeopleOS today | Status | Build note |
|---|---|---|---|---|---|
| GV-1 | **[missed §04.2 / §04.3]** Data import wizard (`app-do-imports-step-1`, `app-new-do-import-step-2`): "Please select the mode — Insert record / Update record / **Delete record**", employees organisation-wise or specific, per-type filters (pay group, components, cycles, contract end dates, punch mode …) | Bulk deletion through an upload | Import modes new / update / both (`ImportMode`); records are end-dated or inactivated, never deleted by upload (audit hash chain, effective dating) | N-A | Deletion by upload conflicts with the audit and finalized-payroll invariants. Optional "inactivate" mode for masters (sets `status` inactive and `effective_to`) inside SV-2. |

---

## 5. Access rights (matrix 01 §5.1–5.6)

Live pages: Page access, Data access, CXO access, Widget access (Calendar access and Geo access are in the menu only).

### /app/core/accessrights/pageaccess, dataaccess, analyticsaccess, widgetaccess

| # | HROne element | What it does | PeopleOS today | Status | Build note |
|---|---|---|---|---|---|
| AR-1 | **[missed §5.1]** Default page-access groups USER, ADMIN, **EXTERNAL**, MANAGER (each ACTIVE, member avatars and count) | HROne ships a separate group for external (non-employee) users | Role templates in `config('peopleos.roles')` (employee, manager, tenant HR admin, auditor, alumni …); no role for business partners / consultants | PARTIAL → built (SV-7: system role `external` with `task.view` / `task.act`, held by nobody until PRV-9 / IP-04) | Role template `external` with a minimal permission set, seeded as `is_system`, once business-partner users exist (IP-04). |
| AR-2 | **[missed §5.1]** Assign rights note "Granting access to view candidate details will allow authorized personnel to see the entire talent pool" | Warning on recruitment pages | — | N-A | Recruitment, separate RMS. |
| AR-3 | **[changed §5.2]** Data access → Assign rights "Provide data access organization wise": company, business unit, department, **sub department**, **designation**, **grade**, **level**, **region**, branch, sub-branch (Select All) — or "for specific employees" (+ Add bulk employees) | The data a group may see, by any organisation unit or by named employees | `UserAccessScope::DIMENSIONS` = company, location, business_unit, division, department, team, establishment (`app/Domain/Identity/Models/UserAccessScope.php`); no designation, grade, level or region; sub-department only as a department node | PARTIAL → built (SV-7 / CA-9: designation, grade, level in `UserAccessScope::DIMENSIONS` and groups, sub-departments on groups; region waits for CA-7) | Add `designation`, `grade`, `level` (and `region` after CA-7) to `UserAccessScope::DIMENSIONS` and `AccessScopes::constrainEmployees`; a department scope may include child departments (flag). Fail-closed tests for each dimension; part of CA-9. |
| AR-4 | **[missed §5.2]** `employees_under_group_member` (data step) | Group members also see the employees who report to them *(inferred)* | Managers see their team through the manager lens and team permissions, not through an access-scope option | PARTIAL → built (SV-7 / CA-9: data-access group option "reportees" direct / all levels) | Scope dimension `reportees` (direct / all levels) usable in access-scope groups (CA-9). |
| AR-5 | **[changed §5.6]** Widget access shows only "Widget Type" (All) and the columns **Widget name · Visibility** for 20 widgets; no order or sequence control | Widgets on / off for everyone; HROne does not expose ordering in the UI | Matrix row "Widget order · `sequenceNo` — MISSING" | N-A (CA-9 keeps `home.block_order` optional; `home.hidden_blocks` built) | The order is not an HROne admin setting; drop `home.block_order` from CA-10 or keep it optional. The on / off row (`home.hidden_blocks`) stands. |

---

## 6. Integration (matrix 01 §6.1–6.11)

Live pages: API access (`/app/core/integaration/developer`), Single sign on (`/app/core/workforce/single-sign-on`).
Biometric server sits in this URL space but in the Time office menu (see §8).

| # | HROne element | What it does | PeopleOS today | Status | Build note |
|---|---|---|---|---|---|
| IN-1 | **[missed §6.2]** Webhook form (`app-webhook-form-step-basic`): webhook name, target URL, description; Advance options: max retries, event throttling per second, "Require authentication" with API key (header name, value, prefix), Basic auth, Bearer token, mTLS (client certificate, private key, CA bundle, passphrase); custom headers | Outbound event delivery to a client system that requires its own authentication and rate | `webhook_endpoints`: name, url, `secret` (HMAC signature), events, status, failure_count (`app/Domain/Enterprise/Models/WebhookEndpoint.php`, `webhook.manage`) | PARTIAL | `webhook_endpoints.auth` (type none / api_key / basic / bearer; secrets encrypted, audit-excluded, never re-displayed), `headers` (json), `max_retries`, `max_per_second`; mTLS later (certificate storage is EXT). Defaults = today's signed delivery. |
| IN-2 | **[changed §6.9]** SSO page: "Please connect with HROne customer support for more information"; identity providers Office 365, G-Suite, Okta, **Mini Orange**; "Reference Document: Download"; route sits under `/workforce/` | SSO is enabled with vendor help; a setup guide is downloadable | Self-service OIDC connections with presets Entra / Google / Okta / generic (`SsoConnectionResource`, `peopleos.enterprise.sso_presets`) | HAVE | Optional: a miniOrange preset and a short setup guide linked from `SsoConnectionResource` (generic OIDC already works). SAML stays open as in §6.9. |

---

## 7. Workforce (matrix 08 §08.1–08.7)

Live pages: Separation policy, Transfer policy (tabs Transfer policy · Reason · Transfer allowances form), Induction
policy; Onboarding (`/app/core/workforce/onboarding`) redirects to the home page for this tenant. The transfer-policy
page loads letter types (`/api/workforce/LetterType/1/10`), checklist types and items 4 / 5
(`/api/workforce/Clearance/ChecklistItems/4`, `/5`), change types, OUs and pay components: these feed its
**Advance setting** step, now readable in `app-advance-setting` (matrix §08.4 #3 said "controls not captured";
matrix §12.10 read the same shared component as the PIP form).

### /app/core/workforce/transferpolicy/policy

| # | HROne element | What it does | PeopleOS today | Status | Build note |
|---|---|---|---|---|---|
| WF-1 | **[changed §08.4 #3]** Advance setting "Would you like to send letter?" `isTransferLetterRequired` → letter type `letterTypeId` + template `letterTemplateId` | A transfer letter is generated when the transfer is approved | Transfer policy fields: `changeable_dimensions`, `notice_days`, `backdate_days`, `reason_required` (`config/peopleos.php` → `policies.types.transfer`); letter type `transfer` exists but nothing generates it | PARTIAL | Fields `letter_on_approval` (yes_no, default no) and `letter_template_id` (`options_from` letter_templates, `letter_types` ['transfer']); the transfer approval calls `Letters::generate` (draft or issued per the template's `requires_approval`). |
| WF-2 | **[changed §08.4 #3]** "Would you like to map pre transfer checklist?" `isPreChecklistMapped`, `preChecklistId` (runtime: "Pre-requisite task" with Delegate / Done, related link, video link) | Tasks that must be done before the transfer takes effect (handover, access changes) | `clearance_checklists` exist for exits only (`items` [{stage, item, due_days, mandatory}]); transfers have no tasks | MISSING | `clearance_checklists.kind` (exit / pre_transfer / post_transfer, default exit) + policy field `pre_checklist_id`; tasks created on approval, due before the effective date; mandatory open tasks hold the change (warning to HR, never silent). |
| WF-3 | **[changed §08.4 #3]** "Would you like to map post transfer checklist?" `isPostChecklistMapped`, `postChecklistId` | Joining tasks at the new unit after the effective date | — | MISSING | Policy field `post_checklist_id`; tasks created on the effective date (same tables as WF-2). |
| WF-4 | **[changed §08.4 #3]** "Would you like to map allowance?" `isAllowanceMapped` → allowance `allowanceId`, amount, variable `allowanceVariableId` (add / remove rows) | Default transfer allowances for everyone under the policy | Allowances are typed per request (`transfer_requests.allowances`, `TransferAllowanceInput`, components flagged `transfer_allowance`) | PARTIAL | Policy repeater `default_allowances` (component, amount) pre-filled on new requests; payment path unchanged. |

### Transfer request and approval (runtime dialogs behind the policy)

| # | HROne element | What it does | PeopleOS today | Status | Build note |
|---|---|---|---|---|---|
| WF-5 | **[changed §08.4 #5]** Request `app-transfer-request`: requested transfer date `suggestedTransferDate`, reason, remarks, "What do you want to change?" (position, BU, department, sub-department, designation, grade, level, region, branch, sub-branch, reporting manager, **functional manager**), **Upload attachment**. Approval `app-do-transfer-details`: effective date `finalTransferDate` (may differ from requested), "Do you want to change goals?", "Do you want to revise salary structure?", "Do you want to delete all approved geo-locations for this employee transfer?", "Do you want to do transfer for other OUs mapped in policy?" `isMapOtherOU`, approver comment, attachment | The approver fixes the effective date and decides the side effects of the move | Built (BP-6): `transfer_requests` with `effective_date`, `workforce_reason_id`, `changes`, `new_manager_id`, `allowances`, `note`, `review_note` (`app/Domain/Employment/Models/TransferRequest.php`); no requested date, attachment, functional manager, goal / salary / geo flags | PARTIAL | Columns `requested_date`, `attachments` (private disk), `functional_manager_id`, `goals_changed`, `salary_revised` (+ `compensation_change_id`: opens a draft `CompensationChange` of type transfer), `remove_geo_fences` (deactivates the employee's own geo fences on the effective date, audited). Defaults reproduce today's direct change. |

### /app/core/workforce/onboarding (redirected) — **[no section]** in any matrix

Read from `app-opf-review`, `app-opf-advance-setting`, `app-opf-shortcuts`, `app-welcome-employee`. HROne's
onboarding policy is separate from the induction policy (§08.2) and the preboarding checklist (§03.1).

| # | HROne element | What it does | PeopleOS today | Status | Build note |
|---|---|---|---|---|---|
| WF-6 | **[no section]** Policy name, description; Applicability (shared OU steps) | Which joiners get which onboarding set-up | Onboarding templates with `conditions`, `priority`, `kind` (`OnboardingTemplateResource`, `Onboarding::templateFor`) | HAVE | — |
| WF-7 | **[no section]** "Do you want to enable employee to self download id cards?" `isEnableSelfDownloadIdCard` + "Select ID Card template" `letterTemplateId` | Employees download their own ID card from a template | No ID cards (letter type list has none) | MISSING | Letter type `id_card`; template field on the onboarding template; "Download ID card" on My HR generated without approval (photo from the profile). |
| WF-8 | **[no section]** "Do you want to allow employees to share social network links and web links with all?" `isEnableSocialNetwork` | Employees may publish their social / web links on their profile | Company social links only (`companies.social_links`) | MISSING | Optional `people.social_links` (json, URL-validated, self-service, visibility "all") behind setting `profile.social_links_enabled` (default false). Low. |
| WF-9 | **[no section]** Advance settings: checklist mapping preboarding `preboardChecklistId` / onboarding `onboardChecklistId` | Which checklists run before and after joining | Onboarding template items by phase (preboarding, joined, onboarding) | HAVE | — |
| WF-10 | **[no section]** "Share introduction with team members" + due day `dueDayToShareIntro`; "Manager shares welcome message" + due day `dueDayToShareWelcomeMessage` (welcome message, things to know / avoid, comments) | The joiner introduces themself to the team; the manager writes a welcome note | Item types task, document, form, acknowledgement, `profile_section`, `feedback` (`peopleos.onboarding.item_types`); Home shows a fixed welcome card | PARTIAL | Item types `introduction` (posted to the team / social wall when the wall is on) and `welcome_message` (manager writes, the joiner sees it on Home), each with `due_offset_days`. |
| WF-11 | **[no section]** "Send 'Complete Your Profile' task after onboarding?" + due day; "Select sections to be displayed for profile completion"; task description | A profile-completion task listing chosen sections | Item type `profile_section` (complete a profile section, checked on completion) | HAVE | One item per section reproduces it; optional multi-section item later. |
| WF-12 | **[no section]** "Assign buddy" `managerAssignsBuddy` + "Suggested Buddy Task" list (task name, due day) | The manager names a buddy who gets suggested tasks | Buddy is an owner type and a reporting relationship (`owner_types.buddy`, `Onboarding.php` resolves the buddy) | HAVE | — |
| WF-13 | **[no section]** Shortcuts: "Do you want to share shortcut links automatically?" (else sent with the manager's welcome message); shortcut name, URL, Non-editable, SSO Authentication | Useful links pushed to the joiner's shortcut tab | — | MISSING | `onboarding_templates.shortcuts` (json: name, URL, editable) shown on a Home "Shortcuts" card; the SSO-token option is N-A (HROne passes its own token). |

---

## 8. Time office (matrix 09)

Live pages: Weekly off, Attendance, Leave (policy cards + Leave Type cards), Holiday (year picker), Short leave, Check
in (tabs Checkin Policy · Biometric server), Shift rotation (tabs Shift policy · Shift rotation policy · Shift group
policy). The policy editors themselves were not opened; the step components (`app-to-asf-*`, `app-ts-*`,
`app-leave-type-*`, `app-to-ccs-*`, `app-to-hsf-*`, `app-to-sr-*`, `app-sls-step-one`) were compared row by row with
matrix 09, which summarises them in prose. Work from home routes redirect to the home page (no section anywhere).

### /app/core/timeoffice/checkIn → Biometric server (`/app/core/integaration/biometric`)

| # | HROne element | What it does | PeopleOS today | Status | Build note |
|---|---|---|---|---|---|
| TO-1 | **[changed §09 Biometric server]** "Download instructions" button; database-type list (`/api/Integration/Biometric/DatabaseType`); server-column mapping list; sync-failure banner (`SyncStatus/IsFailure/Exists`) | HROne ships an installable sync service and its set-up instructions; the page warns when a sync failed | Server type, table, connection reference, frequency and column map are stored on the device (`AttendanceDeviceResource`, TO-8 "BUILT"), but PeopleOS ships no sync agent and no instructions; silent-device alerts exist | PARTIAL | Publish a sync-agent specification and a downloadable instruction sheet from the device page (push endpoint, payload, column map, frequency); the agent binary is EXT (owner decision: build or partner). Show the last failed sync on the device list. |

### /app/core/timeoffice/attendance (attendance policy steps)

| # | HROne element | What it does | PeopleOS today | Status | Build note |
|---|---|---|---|---|---|
| TO-2 | **[changed §09 Attendance → Comp off]** `app-to-asf-compensatoryoff`: "credit extra half day comp off for every N hours completed after full day"; "advance comp off expiry rule" with expiry at Month end / Quarter end / Year end and "restrict users to redeem comp off only till the expiry period"; "disable comp off generation if not present for N days before the week off" / "… before the holiday"; "restrict employees from applying comp off leave before the date it was generated" | Finer comp-off generation and expiry rules | `comp_off_full_day_minutes`, `comp_off_half_day_minutes`, `comp_off_validity_days`, `comp_off_auto`, `comp_off_auto_approve`, `comp_off_max_per_month` (matrix rated the row BUILT) | PARTIAL | Time-office policy fields `comp_off_extra_half_day_after_minutes` (0 = off), `comp_off_expiry_mode` (days / month_end / quarter_end / year_end, default days), `comp_off_absent_before_off_days` (0 = off), `comp_off_apply_before_generation` (default = today's behaviour). |
| TO-3 | **[changed §09 Attendance → Alerts]** "Do you want to auto change the other status of employee to Absconding?" + "For how many days continuously if employee is absent then change the other status … to Absconding" | Long unexplained absence flags the employee as absconding | `absence_alert_days` → `attendance.absence_alert` only; exit type `absconding` exists (`exit.types`) | MISSING | Field `absconding_after_days` (0 = off, default); the daily absence job raises an HR task "possible absconding" and the event `attendance.absconding_suspected`; HR confirms before `Exits::initiate(type: absconding)` (no automatic exit). |
| TO-4 | **[changed §09 Attendance → Alerts]** "Do you want to send an alert if total working hours are less than the expected working hours?" + based on (daily / weekly / monthly) + trigger day | Periodic short-hours alert | Exception type `short_hours` in the attendance exception centre; no scheduled alert | PARTIAL | Notification event `attendance.short_hours_digest` sent by period from the exception data; options on the attendance policy (`short_hours_alert` period and day). |
| TO-5 | **[changed §09 Attendance → Calculation]** "Mark user absent for the day if there is no punch in the defined window before or after the short leave / AR / on duty", with minutes per request type | A request must be backed by a punch close to it, or the day is absent | Not in the attendance policy fields (`punch_mode`, `timing`, `count_within_shift`, `weekly_off_paid`) | MISSING | Fields `punch_near_request_minutes` per type (short_leave / regularisation / on_duty; 0 = off, default) read by the day evaluator. |
| TO-6 | **[changed §09 Shift → Absence criteria]** "Restrict managers / HR from marking backdate attendance status", "… future attendance status", "How many days after the calendar date can the user update attendance status?", "N hours before shift end time, HR / Manager can update employee attendance status" | Windows within which managers and HR may set a day's status | HR day overrides exist (HP-1), with no role windows (matrix rated the row BUILT) | PARTIAL | Shift (or attendance policy) fields `manager_mark_backdated_days`, `hr_mark_backdated_days`, `manager_mark_future`, `hr_mark_future`, `mark_hours_before_shift_end` (blank = unlimited = today), enforced where overrides are saved. |
| TO-7 | **[missed §09]** Custom formula master (`app-custom-formula-setting-home`: module, process, formula name, description, "select master data for the custom formula", formula calculator, INACTIVATE / EDIT / PROCESS); referenced as `formulaId` by comp-off, overtime and leave-credit rules | Reusable formulas for time-office calculations | Formula engine for pay components only (`FormulaEngine`); OT / comp-off formulas open (matrix PARTIAL) | PARTIAL | Table `custom_formulas` (module, process, name, expression, status) evaluated by `FormulaEngine` with process-specific variables; OT, comp-off and leave-credit fields may reference one. |

### /app/core/timeoffice/leave (Leave policy + Leave Type list)

| # | HROne element | What it does | PeopleOS today | Status | Build note |
|---|---|---|---|---|---|
| TO-8 | **[changed §09 Leave type]** Leave Type cards show each type's own cycle: most types "Apr - Mar", comp off "Jan - Dec"; plus Paid / Unpaid and Detail | Per-type leave years are used in practice | One tenant-wide leave year (`leave.year_start_month`); matrix row PARTIAL | PARTIAL | `leave_types.cycle_start_month` (nullable = tenant setting) honoured by accrual, carry-forward and balance year. Priority raised by the live data. |
| TO-9 | **[missed §09 Leave type → Penalty]** "In case of a complete week's leave, do you want to mark the week off after leave also as leave?"; "In case of leave from week off to holiday, mark week off and holiday before and after the leave also as leave?" | Sandwich rules for full-week leave and for leave bridging a week off and a holiday | `count_weekly_offs`, `count_holidays` on the leave policy | PARTIAL | Leave type fields `full_week_counts_following_off` and `bridge_off_and_holiday` (default false = today). |
| TO-10 | **[missed §09 Leave type / Shift]** "Please do not use reserved code P, -, A, WO, HO, LOP" on leave codes and shift codes (`/api/timeoffice/setting/shift/ReserveCode`) | Leave and shift codes cannot clash with attendance status codes in registers | Leave and shift codes are free text | MISSING | Validation rule on `LeaveTypeResource` / `ShiftResource` code against the attendance status codes used by registers and calendars. S. |

### /app/core/timeoffice/shiftrotation → Shift group policy

| # | HROne element | What it does | PeopleOS today | Status | Build note |
|---|---|---|---|---|---|
| TO-11 | **[changed §09 Shift group]** "SHIFT GROUP POLICY IS NOT ENABLED" until an organisation switch turns it on | Shift groups are opt-in | Shift-change limits live on the time-office policy (`shift_change_shifts`); nothing to enable | N-A | No switch needed. |

### /app/core/work-from-home/office-master and /policy (redirected) — **[no section]** in any matrix

| # | HROne element | What it does | PeopleOS today | Status | Build note |
|---|---|---|---|---|---|
| TO-12 | **[no section]** Office master (`app-office-master-form`): office name, address, city, workspace, sub-branch, "Sitting capacity of the office", "Can user plan more than capacity?" + "How many more employees can be planned than the sitting capacity for the day?", office image | Offices with seat capacity for hybrid-work planning | Work locations without capacity (`locations`); work modes master (`WorkModeResource`) | MISSING | `locations.seating_capacity`, `locations.overbook_allowance` (nullable = no limit), optional image. |
| TO-13 | **[no section]** Work from home policy (`app-policy-step-1/2`): name, description; work mode; "While sharing plan, … option to change shift?"; frequency to share plan; "Can employee update backdated work plan?" + days; maximum WFH days and maximum WFO days per interval (Any / Specific); "Can employee request more WFH / WFO than the limit?"; inputs remote city, office, work flexibility for the day | Employees share a periodic work plan (home / office / city), within limits, with optional shift change | Work mode is a position attribute (`work_modes`, rule field); no work plans, no WFH limits | MISSING | Policy type `work_plan` (fields above, defaults off) + `work_plans` (employee, date, mode, location_id, remote_city, shift_id) with approval through the Approval Center; a WFH day counts as present with source `work_plan`; office capacity from TO-12. L. |

---

## 9. Payroll (matrix 08 §10.1–10.12)

Live pages: Statutory (FY picker 2026 - 2027, "SWITCH TO 2025 - 2026 FINANCIAL YEAR", eight cards Tax setting ·
Provident Fund · Employee state insurance · Labour welfare fund · Bonus · Gratuity · Professional tax · TDS deductor
information, each "Reviewed · DETAILS"), Challan (four cards with "N sub-branches not mapped"), Slabs, Settings
(Increment type · New join salary declaration · Salary Bank · Statutory Skills). Matrix §10.12 rated the PF, ESI,
LWF and PT DETAILS HAVE and the Tax setting "controls not captured"; `components.json` now holds every dialog behind
those DETAILS buttons (`app-provident-fund-step1…4`, `app-esi-step1/2`, `app-professional-tax`, `app-lwf-sidebar`,
`app-tax-setting` → `app-exceptions` / `app-other` / `app-tax-setting-review`, `app-deduction-info-step1…4`, and the
challan groups `app-add-pf/esi/pt/lwf-group`). Statutory rates, ceilings and limits stay in rule versions (N-A as
settings); employer processing choices become establishment statutory-profile settings, which are already
effective-dated (`establishment_statutory_profiles.settings`, `compliance.profile_settings`).

### /app/core/payroll/statutory — common

| # | HROne element | What it does | PeopleOS today | Status | Build note |
|---|---|---|---|---|---|
| PY-1 | **[changed §10.10 #6 / §10.12]** Statutory settings are per financial year (FY picker, "SWITCH TO … FINANCIAL YEAR") and each card carries a review status ("Reviewed"); salary processing waits until all are reviewed | Year-scoped statutory set-up with an explicit admin review | Rule versions and statutory profiles are effective-dated; `RuleVerifications`, `ComplianceReadiness` and blocking exceptions (`ComplianceRules::enforced`) | HAVE | — |
| PY-2 | **[missed §10.12]** Every statutory dialog asks "From which month you want these settings to be effective?" (`app-finantial-year-dialog`) | Employer statutory choices change from a chosen month | Statutory profiles have `effective_from` / `effective_to`; tenant payroll settings are not effective-dated | PARTIAL | Put every new employer choice below on `establishment_statutory_profiles.settings` (effective-dated), never on a plain tenant setting. |

### Provident Fund DETAILS (`app-provident-fund-step1…4`, `app-provident-fund-review`)

| # | HROne element | What it does | PeopleOS today | Status | Build note |
|---|---|---|---|---|---|
| PY-3 | **[changed §10.12 #4]** "On what basis do you want to process employee's PF on? — Actual earning for all employees / ceiling"; "On what do you want to process employer's PF on? — Actual earnings / Specify contribution limit at employee level"; "Employers contribution limit" `employerMaxLimit`, employee limit `employeeMaxLimit` | Separate employee and employer PF wage bases | EPF profile `restrict_to_ceiling` + employee `employee_pf_options.wage_basis` for both shares | PARTIAL | `employer_wage_basis` as already planned in §10.11 #7, plus the organisation default on the EPF profile. |
| PY-4 | **[changed §10.12 #4]** Rates and charges: employee rate (A), employer pension rate (B), pension wage limit, A/C 2 PF admin rate + minimum, A/C 21 EDLI %, A/C 22 EDLI admin rate + minimum; "On what basis … PF admin charges? (AC2) — Employee / Employer / Higher of both"; "… EDLI admin charges? (AC21) — PF / Pension / EDLI wages" | Statutory EPF, EPS, EDLI and admin-charge parameters | EPF rule params `employee_rate`, `employer_rate`, `eps_rate`, `eps_wage_ceiling`, `edli_rate`, `edli_wage_ceiling`, `admin_rate`, `admin_minimum` (DRAFT, not confirmed) | N-A | Statutory values: rule data only. Add `admin_basis` / `edli_basis` as rule params only if the notification itself allows a choice. |
| PY-5 | **[missed §10.12]** "Do you want to calculate pension eligibility based on the last day of the salary month?"; "Pension contribution should not be greater than provident fund contribution" | EPS stops at age 58, judged at month end; EPS never exceeds the employer share | EPS is computed for every member (`StatutoryEngine` EPF block; §10.11 #12 `eps_applicable` MISSING) | MISSING | EPF rule params `eps_max_age` and `eps_age_basis` (month_end / date) read by `StatutoryEngine` (statutory, verified); employee override `eps_applicable` as planned. |
| PY-6 | **[missed §10.12]** "Process PF on arrears"; "Show employees whose PF for the month is zero on challan"; "Prorate PF as per paid days" | Processing choices for arrears and the ECR | PF follows the arrear component's flags (no organisation switch); ECR rows for zero PF not offered; PF on paid wages (HAVE) | PARTIAL | EPF profile settings `on_arrears` (default = component flags, today) and `ecr_include_zero_rows` (default false). |
| PY-7 | **[missed §10.12]** "Is voluntary provident fund applicable?" (organisation); "Calculate VPF on — PF gross / PF earning" | Allows VPF and fixes its base | Per-employee VPF (`employee_pf_options`: percent of PF wages or amount) | PARTIAL | EPF profile settings `vpf_allowed` (default true = today) and `vpf_basis` (earned PF wages default / PF gross rate). |
| PY-8 | **[missed §10.12]** "Employer PF Perquisite — PF Perquisite Rate, PF Perquisite exemption limit" (`app-provident-fund-step4`) | Employer contributions to PF / NPS / superannuation above the aggregate limit are a taxable perquisite (with accretion) | `TaxComputer` has no such perquisite | MISSING | TDS rule params for the aggregate limit and accretion method (statutory, verified); `TaxComputer` adds the excess as a perquisite and shows it on the tax sheet. |
| PY-9 | **[missed §10.12]** "When generating the PF challan, would you like to include 'Gross Rate' or 'Gross Earnings' in the Gross Wages?" | ECR gross-wages column basis | ECR from `EpfReturns` with one basis | PARTIAL | ECR layout option `gross_wages_basis` (earned default) on the versioned export layout. |
| PY-10 | **[missed §10.12]** "Do you want to provision employer PF in CTC?", "… PF admin charges in Employee CTC?" (the ESI dialog asks the same for employer ESI, the LWF dialog for LWF and employer LWF, PY-19) | Whether employer statutory costs sit inside the CTC | CTC composition comes from the salary structure (employer-contribution lines, CTC balancing component); admin charges are never in CTC | PARTIAL | Profile setting `in_ctc` per statute (PF, PF admin, ESI, LWF), read by the CTC balancing in `StructureFormulas`; defaults reproduce today's structures. |

### Employee state insurance DETAILS (`app-esi-step1/2`, `app-esi-review`)

| # | HROne element | What it does | PeopleOS today | Status | Build note |
|---|---|---|---|---|---|
| PY-11 | **[changed §10.12 #5]** Employee / employer contribution %, ESI limit, wage ceiling, round-off of employee and employer ESI (None / Nearest / Higher) | Statutory ESI values and rounding | ESI rule `employee_rate`, `employer_rate`, `wage_ceiling`, `round: ceil` | N-A | Statutory: rule data. |
| PY-12 | **[missed §10.12]** "Do you want to process ESI on ESI limit?" | Once covered at the start of a contribution period, ESI continues to its end even if wages cross the ceiling | ESI rule has `contribution_periods`, used only by `EsiReturns`; `StatutoryEngine` re-tests the ceiling every month | MISSING | Statutory continuation in the `StatutoryEngine` ESI block (covered in the period's first month → covered to period end); rule data already exists; needs owner verification, no setting. |
| PY-13 | **[missed §10.12]** "Do you want to apply ESI limit in case of physical disability?" + its limit `contributionLimitPhysicalDisabled` | Higher ESI coverage ceiling for employees with a disability | No disability flag on the person; no rule param | MISSING | `people.disability` (bool + type; sensitive, DataInventory restricted) and ESI rule param `wage_ceiling_disabled` (verified). Also used by PY-16. |
| PY-14 | **[missed §10.12]** "Do you want to enable the impact of negative arrear on ESI?"; "Process ESI on overtime"; "Process ESI on arrear"; "Whether the pay in this component should be considered to check ESI applicability limit" | ESI treatment of arrears, overtime and eligibility-only components | Component flags `esi_applicable` and ESI eligibility wages (PS-1); arrears and OT follow their component flags; no negative-arrear handling | PARTIAL | ESI profile setting `negative_arrear_reduces` (default false = today); arrear and OT stay component-driven (document in the formula guide). |

### Professional tax DETAILS (`app-professional-tax`)

| # | HROne element | What it does | PeopleOS today | Status | Build note |
|---|---|---|---|---|---|
| PY-15 | **[changed §10.12 #18]** "Do you want to apply professional tax settings?", "Professional tax exemption limit" | Statutory PT slabs and exemption | PT rules per state (`slabs`, February / March amounts, `female_exempt_upto`) | N-A | Statutory: rule data. |
| PY-16 | **[missed §10.12]** "Do you want to enable state PT exemption for senior citizens?" (state + minimum age list); "Do you want to process PT exemption for physically disabled employees?" | State-specific PT exemptions by age and disability | No age or disability exemption in the PT block | MISSING | PT rule params `exempt_from_age` and `exempt_disabled` per state (verified); `StatutoryEngine` reads date of birth and the PY-13 flag. |
| PY-17 | **[missed §10.12]** "In case of Punjab, how would you like to process PT? — System Defined PT Wages / Taxable Salary" | Punjab's levy may be computed on taxable income | No Punjab PT rule (§10.1 #16 lists missing states) | MISSING | Punjab rule version with a `basis` parameter when the state is added (data + owner verification). |
| PY-18 | **[missed §10.12]** "Do you want to calculate professional tax separately for arrear?"; "Do you want to process PT on earning or rate?"; "If employee paid days are zero, do you still want to process PT?" | Employer processing choices for PT | PT on the month's earned PT wages; arrears inside the month's gross when flagged; zero earnings give no PT | PARTIAL | PT profile settings `basis` (earned default / rate), `arrear_separately` (default false), `on_zero_paid_days` (default false); all defaults = today. |

### Labour welfare fund DETAILS (`app-lwf-sidebar`)

| # | HROne element | What it does | PeopleOS today | Status | Build note |
|---|---|---|---|---|---|
| PY-19 | **[changed §10.12 #6]** "Is LWF applicable?"; "Process LWF on basis of Rate / Earnings"; "Process LWF on settlement"; "Process LWF on arrear"; "Process LWF separately for arrear"; "In case of Haryana employees, do you want LWF not to be deducted if employee last working date is middle of the month?"; "Provision LWF / employer LWF contribution in CTC" (see PY-10) | Employer processing choices for LWF | LWF by rule months and LWF wages per establishment (`StatutoryEngine` LWF block); no settlement, arrear or mid-month-exit switches | PARTIAL | LWF profile settings `basis` (earned default / rate), `on_settlement`, `on_arrear`, `arrear_separately` (defaults = today); the Haryana mid-month exit rule as a rule parameter after verification. |

### Tax setting DETAILS (`app-tax-setting`: Exemption · TDS · Review)

| # | HROne element | What it does | PeopleOS today | Status | Build note |
|---|---|---|---|---|---|
| PY-20 | **[changed §10.12 #3]** Exemption step values: children for education allowance (count), tuition-fee and hostel exemption per child, 87A rebate income limit and amount (old / new), standard deduction (old / new), superannuation exemption limit, annual rent above which the landlord's PAN is required, loss-from-house-property limit, housing-loan interest limit | Statutory exemption limits used in TDS | TDS rule versions carry slabs, standard deduction, rebate income limit and amount, cess, surcharge and Chapter VI-A limits (`database/data/compliance/in.php`); children allowances, superannuation limit, landlord-PAN threshold, house-property loss and 24(b) limits are not parameters (`TaxComputer` notes 24(b) "when the rule carries its limits") | PARTIAL | Add the missing values as verified TDS rule parameters and read them in `TaxComputer`; `payroll.tax.landlord_pan_required` stays the switch, the threshold moves to the rule. No tenant settings. |
| PY-21 | **[missed §10.12]** "Maximum number of propose investment declaration requests in an year"; "Maximum number of confirm investment declaration requests in an year" | Caps how often employees resubmit declarations and proofs | Windows only (`payroll.tax.declaration_from/to`, `proof_from/to`, day window, `regime_locked_after_submit`) | MISSING | Settings `payroll.tax.max_declaration_submissions`, `payroll.tax.max_proof_submissions` (0 = unlimited = today), checked by `InvestmentDeclarations` / `InvestmentProofs`. |
| PY-22 | **[missed §10.12]** "Do you want to process salary of resigned employees as per confirmed investments?" | Leavers' TDS uses verified proofs, not declarations | One month switch for everyone (`payroll.tax.verified_proofs_from`) | PARTIAL | Setting `payroll.tax.leavers_use_verified_proofs` (default false). |
| PY-23 | **[missed §10.12]** "HRA exemption calculation — Prorate with DOJ, projected DOL / Actual rent paid amount" | How the s.10(13A) HRA exemption is computed for part-year employees | One annualised method (`TaxComputer`: HRA received vs rent − 10 % of basic vs 40 / 50 % of basic) | PARTIAL | Setting `payroll.tax.hra_method` (default = today's), the alternative prorates by months of service and rent months. |
| PY-24 | **[missed §10.12]** Review: "From which month tax should be processed considering the confirmed investment" `confirmMonth`; "From which month should pay by default reimbursements be processed as per bills submitted" `reimbursementConfirmMonth` | Switch-over months for proofs and for reimbursement bills | `payroll.tax.verified_proofs_from` (HAVE); no month for pay-by-default reimbursements | PARTIAL | Setting `payroll.reimbursement.bills_from_month` (null = today). |
| PY-25 | **[missed §10.12]** TDS step: "Deduct TDS in higher slab if PAN not available / if Aadhaar not available / if Aadhaar not linked with PAN"; education cess % | Statutory higher-rate TDS and cess | Missing PAN handled; cess in rule; PAN–Aadhaar link missing (§10.11 #3) | PARTIAL | No new row of work: closes with §10.11 #3 (`pan_aadhaar_linked`). |
| PY-26 | **[missed §10.12]** "Senior citizen age for income tax", "Super senior citizen age for income tax" | Old-regime basic exemption depends on age | Old-regime slabs are not age-based | MISSING | TDS rule params `age_slabs` (old regime) with the two ages; `TaxComputer` picks by date of birth at year end. Correctness gap for older employees in the old regime. |
| PY-27 | **[missed §10.12]** "Current LTA block period (start year / end year)" | LTA exemption counts journeys per block of years | No LTA block in the rule; LTA claim rules on the component (planned leave) | PARTIAL | TDS rule param `lta_block`; claims check journeys per block. |
| PY-28 | **[missed §10.12]** "Deposit income tax of employees whose salary is withheld?"; "Show employees whose Income Tax is Zero?"; "Add the tax deducted on perquisites with tax challan?"; "Round off net taxable salary on 5 / 10 rupees"; "Tax on the arrear part of proportionately taxed components — Proportionate / Pay month" | TDS processing and reporting choices *(inferred for the challan and arrear items)* | TDS follows the payment date (`deduction_trigger`); held salaries are not paid; rounding follows the rule; arrear tax on payment | PARTIAL | Settings `payroll.tax.deduct_on_held_salary`, `payroll.tax.report_zero_tax`, `payroll.tax.perquisite_tax_in_challan`, `payroll.tax.arrear_tax_method` (defaults = today); taxable-income rounding stays statutory (confirm the rule rounds to the nearest ₹10). |

### TDS deductor information and challan groups

| # | HROne element | What it does | PeopleOS today | Status | Build note |
|---|---|---|---|---|---|
| PY-29 | **[changed §10.12 #19]** TDS deductor information, five steps (`app-deduction-info-step1…4`): group name, PAN, TAN, GSTIN; deductor name, branch, flat / building / street / area / PIN, STD code, phone, fax, e-mail, Account Office Identification Number; responsible person name, owner, **father's name**, structured address, STD, phone, fax, mobile, PAN, e-mail, "Transfer deductor details to responsible person"; applicability to companies, branches, sub-branches | Data printed on Form 24Q / Form 16 and the FVU file | `tds_profiles`: company, legal entity, TAN / PAN registrations, deductor category, responsible person name / designation / PAN, one `address`, e-mail, phone (matrix rated HAVE) | PARTIAL | Columns on `tds_profiles`: structured deductor and responsible-person addresses, STD code, mobile, responsible person's father's name (Form 16 verification), AIN (government deductors), copy-address action; applicability through establishments. |
| PY-30 | **[missed §10.1]** ESI group (`app-add-esi-group`): group name, owner, responsible person, address, establishment ESI number, local office, sub-branches, employees | Several sub-branches under one ESI code, with a named owner | ESIC employer code and regional office registrations per establishment (`compliance.registration_types`) | PARTIAL | Link one registration from several establishments (as §10.1 #3 for PF) and add `responsible_person` / owner users on the registration. |
| PY-31 | **[missed §10.1]** PT group (`app-add-pt-group`): certificate number, **circle number**, responsible person, owner; LWF group (`app-add-lwf-group`): LWF registration number, responsible person, owner | Registration data for PT and LWF returns | PT certificate and LWF registration types exist; no circle number, no responsible person | PARTIAL | `statutory_registrations.metadata.circle` for PT and the same owner / responsible-person fields as PY-30. |
| PY-32 | **[missed §10.1]** PF group (`app-add-pf-group`): "Do you want to exclude EDLI charges for any of the sub-branches?" + excluded sub-branches | EDLI-exempted establishments | Registration type `edli_exemption` per establishment | HAVE | — |

---

## 10. Expense (matrix 11 §11.1–11.11)

Live pages: Expense advance policy (empty), Expense policy (policy card with category count and "Applicable On";
"Expense categories · 1 ITEMS" grid with the category's type AMOUNT), Location group (Tier help, State and City
counts), Tax (UTGST, IGST, SGST, CGST), Currency (empty), Expense form (10 columns, 16 rows), Travel plan form
(Visible / Mandatory / Rename Label / Action; 16 rows). Every tab, column and row is already in matrix 11
(§11.3–11.9 were written from the same page text). The only new input is the advance-policy dialog.

| # | HROne element | What it does | PeopleOS today | Status | Build note |
|---|---|---|---|---|---|
| EX-1 | **[missed §11.2]** Advance policy step "Name and description" (`app-advance-policy-step1`): "Advance amount limit for a request — Any / Specific"; "Do you want to define advance credit limit for the employee — Outstanding advance credit limit"; validation "Outstanding advance credit limit cannot be less than the expense advance" | Per-request cap and total outstanding cap on advances | Policy type `expense_advance`: `max_amount`, `max_open`, `max_outstanding_total` (`config/peopleos.php`), enforced by `ExpenseAdvances` (EX-D) | HAVE | Add the cross-field check (outstanding limit ≥ per-request limit when both are set) to the policy form. |

---

## 11. Performance (matrix 12 §12.0–12.17)

Live page: 360 policy (`/app/core/performance/policy360`, "NO 360 POLICY"; it loads feedback templates, core-value
policies, roles and organisation priorities). Matrix §12.2 said the 360 policy controls "are not in the compiled
templates" and inferred them; matrix §12.12 described the performance policy from catalogue names. Both forms are
now in `components.json` (`app-policy360-step-one`; `app-goal-policy-step1`, `-goal-review`, `-competency-review`,
`-appraisal`). HP-8h (360) and HP-8i (performance policy) are pending, so these rows replace their planned field
lists before the rebuild.

### /app/core/performance/policy360

| # | HROne element | What it does | PeopleOS today | Status | Build note |
|---|---|---|---|---|---|
| PF-1 | **[changed §12.2]** "Frequency & Duration": Frequency Yearly / Half yearly / Quarterly / Monthly `threeSixtyCycleBasedOn`; window start and end days; "Window to complete 360 feedback (days)" | 360 rounds recur on their own cycle with a response window | 360 = extra reviewers inside one appraisal (`Appraisals::addPeerReview`); planned design had only `due_days` | MISSING | Policy type `feedback_360` fields `frequency`, `window_start_days`, `window_days`; rounds created by a scheduled job per period. |
| PF-2 | **[changed §12.2]** Manager feedback: "Collect feedback from manager", "… from functional manager", "Anonymous feedback", template `managerFeedbackId` | Manager and functional-manager input, each with its own form and anonymity | Review types peer, upward, skip_level, external (`peopleos.performance.review_types`); anonymity per reviewer | PARTIAL | Repeater `relations` [{relation: manager / functional_manager / peer / subordinate / client, enabled, anonymous, feedback_template_id, max}] replacing the planned `relations` multiselect + single `feedback_form`. |
| PF-3 | **[changed §12.2]** Peer and subordinate feedback: "Peers are employees having [criterion 1] [criterion 2] [criterion 3] as the assessed employee", "Maximum number of peers"; the same for subordinates; anonymous; template per relation | Who counts as a peer or subordinate is defined by up to three matching organisation attributes (e.g. same department and grade) | Reviewers are picked by hand | MISSING | Fields `peer_match` and `subordinate_match` (up to three of `EmployeePosition::DIMENSIONS`), used to suggest and validate nominations; `max` per relation (PF-2). |
| PF-4 | **[changed §12.2]** Client feedback: "Collect feedback from clients", maximum number of clients, anonymous, template; nominations hold client name, designation, e-mail (`app-policy360-nomination-details`) | External reviewers by e-mail | Review type `external` exists; no external invitation flow | PARTIAL | External reviewer records (name, designation, e-mail) with a signed, expiring one-time link; responses stored like other 360 responses; personal data classified in DataInventory. |
| PF-5 | **[changed §12.2]** Nomination settings: "To whom do you want to send nomination requests? — Across the Organization / Direct Reportees"; nominating role `authorityRoleId` ("Same role will nominate for peers, subordinates and clients feedback") | Who nominates reviewers and from which pool | Planned `nominated_by` (employee / manager / hr) and `nomination_approval` | PARTIAL | Replace with `nominator_role` (role or manager) and `nomination_scope` (organisation / direct reportees); approval as planned. |

### Performance → Performance policy (`app-goal-policy-*`)

| # | HROne element | What it does | PeopleOS today | Status | Build note |
|---|---|---|---|---|---|
| PF-6 | **[changed §12.12]** Goal step: goal cycle Yearly / Half yearly / Quarterly / Monthly; "when you want to start the goal cycle window — Before / After — N days"; "How many days should the goal cycle run?"; "Manually initiate goal cycle" + initiating roles; "Set goals for new joins who join after goal cycle assignment" + "If an employee joins N days before the end of goal cycle then assign goal sheet"; "Performance cycle start month"; "map the feedback template on the performance review?" | Periodic goal cycles with windows, manual or automatic start, joiner rules | One goal-setting stage per cycle; `performance.year_start_month`; goal-sheet policy (HP-8d) | MISSING | Policy type `performance` fields `goal_cycle`, `goal_window_offset_days` (signed), `goal_window_days`, `goal_manual_start` + `goal_start_roles`, `joiner_goal_cutoff_days`, `start_month`, `feedback_template_id`. |
| PF-7 | **[changed §12.12]** Review step: review cycle (not Yearly when the goal cycle is half-yearly), window, manual initiation; "How do you want to calculate review rating? — Weighted average / Sum of all goals"; "calculate / show rating while doing performance review?"; "edit the system calculated KPI rating?"; "enter a rating more than the max rating"; "change the system-calculated review rating?"; "send an acknowledgement of the review rating" + review in which to take it; "restrict the user to edit system achievement?"; "copy last level inputs to the next level?"; "round off review-wise rating?" + formula | Rating rules for each periodic review | Organisation settings `performance.review.show_formula_rating`, `formula_rating_mode`, `copy_previous_level`, `performance.rating.rounding`, `performance.rating.final_decimals`; appraisal `acknowledged_at` | PARTIAL | Same keys as policy fields (blank = organisation setting = today), plus `review_cycle`, `review_window_*`, `rating_method` (weighted / sum), `allow_above_max`, `lock_system_achievement`, `ack_review_rating`. |
| PF-8 | **[changed §12.12]** Visibility: "Ratings and comments are visible to — All / Preceding level / Last level / Reviewer / None"; "Final Rating is visible to — All except employee …"; "Final comments is visible to" | Who in the review chain sees which ratings and comments | Publish setting `performance.rating_publish`; reviewers see the review form; no per-level visibility | MISSING | Fields `ratings_visible_to`, `final_rating_visible_to`, `final_comments_visible_to`, enforced in the appraisal view policy (fail-closed). |
| PF-9 | **[changed §12.12]** Advance: "enable TNI recommendation?", "promotion recommendation?", "salary increment recommendations?" (Amount / Percentage), "variable recommendations?" | Reviewers recommend training, promotion, increment and variable pay | Appraisal `promotion_recommended`, `pip_recommended`; development needs (`DevelopmentNeeds`) | PARTIAL | Fields `recommend_training`, `recommend_promotion`, `recommend_increment` (amount / percent), `recommend_variable`; increment and variable recommendations feed `CompensationCycles` as proposals (never pay directly). |
| PF-10 | **[changed §12.12]** Competency review: "same cycle as review", copy last level, rating above max, visibility, "Competency weightage : Goal weightage (%)" | Competency rating rules and the goal / competency split | Cycle weights (`peopleos.performance.default_weights`, 70 / 30) | PARTIAL | Fields `competency_with_review`, `competency_weight`, `goal_weight` (defaults = cycle weights). |
| PF-11 | **[changed §12.12]** Appraisal: "Enable appraisal eligibility criteria — If an employee joins after N days from start of performance cycle … not eligible"; acknowledgement of overall rating (role); "round off final PMS rating" + formula; "Calculate appraisal rating based on — Average of all reviews / Last review / Custom"; "Generate performance letter" + template; "Final Rating is visible to"; "Include appraisal with — Appraisal weightage" | How the year-end appraisal is built from periodic reviews and released | Cycle `eligibility` rules; rating rounding; letter type `performance` (HP-8f); one appraisal per cycle | PARTIAL | Fields `joiner_cutoff_days`, `ack_overall_rating`, `appraisal_basis` (average / last / custom weights), `letter_template_id`, `appraisal_weight`; rounding reuses `performance.rating.*`. |

---

## 12. Engage (matrix 12 §13.0–13.8)

Live pages: Badges (tabs Badges · Custom Badges; "Badge quota · Badge per month", nine "Badge allowed" toggles,
FINISH; Type filter on custom badges), Reward points policy (card "Maximum Disbursal · ACTIVE · Cycle Monthly"),
Mission statement (empty). All visible elements are in §13.1, §13.2, §13.6 and §13.7.

| # | HROne element | What it does | PeopleOS today | Status | Build note |
|---|---|---|---|---|---|
| EN-1 | **[missed §13.2]** Custom badge step 2: `numberOfCustomBadges` per `badgesFrequencyTypeId` (how many of this badge one person may give per period), next to the receive limit `employeeFrequencyTypeId` | A giving quota per custom badge | Organisation-wide giving quota `engage.badges.per_month`; per-badge receive limit (`badges.receive_limit`, `receive_period`) | PARTIAL | `badges.give_limit` + `give_period` (0 = only the organisation quota = today), checked in `Recognitions::give()` under the existing row lock. |
| EN-2 | **[missed §13.4]** HR handbook create form (`app-create-policy-step1`): Subject; "Add HR handbook link to inbox/request — Yes / No" + request type `inboxRequestTypeId`; Handbook sections (add / rename / delete, rich content per section); URL `mediaLink`; attachment (≤ 5 MB: pdf, doc, xls, images, zip) | A handbook policy made of sections, with a video / web link and a file, and shown inside a chosen request form | Articles: title, summary, category, Markdown body, tags, audience, acknowledgement, versions (`app/Domain/Knowledge/Models/Article.php`); no attachment, no media link, no link to request types | PARTIAL | `articles.attachments` (documents disk, audited download), `articles.media_url`, and `articles.service_definition_ids` so the article appears on matching helpdesk / request forms; sections stay Markdown headings (table of contents rendered from them). |
| EN-3 | **[changed §13.1]** Badge settings page loads certificate letters (`/api/workforce/LetterList/20/31`) and frequency types on open | Confirms certificate mapping and limits are part of badge set-up | Built in HP-9c / HP-9d (certificate template, limits) | HAVE | — |

---

## 13. Asset (matrix 11 §14.1–14.6)

No Asset Setup page is in the live capture. Re-checking `components.json` found no Setup control outside matrix §14;
the hardware-inventory dialogs (`app-hi-form-step-one/two/three`: asset details, GST, invoice, cost, warranty, audit and
end-of-life dates, assignment) are the runtime asset record, not Setup (N-A here).

## 14. Helpdesk (matrix 11 §15.1–15.4)

No Helpdesk Setup page is in the live capture. The resolver-rule dialogs in `components.json` show a two-stage SLA
that matrix §15.3 rows 9–11 read as "due days + escalation role".

| # | HROne element | What it does | PeopleOS today | Status | Build note |
|---|---|---|---|---|---|
| HD-1 | **[changed §15.3 #9–#11]** Define SLA per priority Low / Medium / High (`app-hd-sla-form`): "Due days"; "On due day, intimate to" — role `intimationAuthorityRoleId_*` **and named employees** `intimationOwnerIds_*`; "TAT (Days)" (must be ≥ due days); "On TAT, **reassign to**" — role `escalatedAuthorityRoleId_*` and named employees `escalatedOwnerIds_*` | At the due date chosen people are told; at the turnaround limit the ticket is moved to another resolver | SLA targets per priority in hours; warning to the ticket's workers at `warn_percent`; on breach the escalation role is notified, by level (`ServiceDeskProcessor`); the ticket is never reassigned; no named people | PARTIAL | SLA policy per priority: `intimate_role_id`, `intimate_user_ids` (at due), `tat_hours`, `reassign_role_id`, `reassign_user_ids`; at TAT `CaseAssignment::assign` to the named user or the least-loaded eligible member of the role (audited, event `servicedesk.ticket.reassigned_on_tat`). Validation: TAT ≥ due. Empty = today's notify-only escalation. |
| HD-2 | **[missed §15.3]** Rule "Purpose" (shown on the review step); validations "Assigned To and Intimate To cannot be the same", "Start time and end time should not be same" | Describes why a rule exists; prevents self-intimation and empty service windows | `service_resolver_rules`: name, priority, categories, services, companies, locations, assignee role / user, notify role, SLA policy, dates, status | PARTIAL | `service_resolver_rules.description` (text 500) and the two validations on the resolver-rule form. S. |

## 15. Recruitment

`/app/core/recruitment/recruitmentsetting` (Settings), the "candidate details" note on page access (AR-2), career
portal / job requisition / referral dialogs in `components.json`: **N-A (recruitment, separate RMS)**.

---

## Live Setup pages with no section in any matrix

| HROne route (live capture) | What the capture shows | Where this file covers it | Status |
|---|---|---|---|
| `/app/core/workforce/onboarding` | Redirects to the home page (not enabled for this tenant); dialogs `app-opf-*`, `app-welcome-employee` | §7 WF-6 … WF-13 | PARTIAL overall |
| `/app/core/work-from-home/office-master` | Redirects to home; dialog `app-office-master-form` | §8 TO-12 | MISSING |
| `/app/core/work-from-home/policy` | Redirects to home; dialogs `app-policy-step-1/2` | §8 TO-13 | MISSING |
| `/app/core/project-management/project-template`, `/app/core/projects/project-template` | Redirect to home; dialog `app-template-description` | §1 CH-18 | MISSING (with HP-5) |
| `/app/core/general/productroadmap` | Redirects to home | — | N-A (HROne's own product roadmap) |
| `/app/core/general/downloadinvoices` | Redirects to home | — | N-A (HROne's invoices to its customer; PeopleOS tenant billing view is P15, blocked by owner decisions) |
| `/app/core/recruitment/recruitmentsetting` | "Recruitment Settings — Settings" | §15 | N-A (recruitment, separate RMS) |

Every other live Setup route maps to an existing matrix section: `/general/dynamicOu` → 01 §1.9; `/integaration/biometric`
→ 09 "Biometric server"; `/timeoffice/shiftgroup` → 09 "Shift rotation and shift group"; `/workforce/single-sign-on`
→ 01 §6.9; `/general/emailsmsconfiguration/email` → 02 §02.1.

---

## Counts

| Group | Rows | HAVE | PARTIAL | MISSING | N-A |
|---|---|---|---|---|---|
| Core HR | 20 | 2 | 5 | 12 | 1 |
| Automation | 3 | 1 | 1 | 1 | 0 |
| Forms & templates | 2 | 0 | 1 | 1 | 0 |
| Governance | 1 | 0 | 0 | 0 | 1 |
| Access rights | 5 | 0 | 3 | 0 | 2 |
| Integration | 2 | 1 | 1 | 0 | 0 |
| Workforce | 13 | 4 | 4 | 5 | 0 |
| Time office | 13 | 0 | 7 | 5 | 1 |
| Payroll | 32 | 2 | 19 | 8 | 3 |
| Expense | 1 | 1 | 0 | 0 | 0 |
| Performance | 11 | 0 | 7 | 4 | 0 |
| Engage | 3 | 1 | 2 | 0 | 0 |
| Asset | 0 | — | — | — | — |
| Helpdesk | 2 | 0 | 2 | 0 | 0 |
| Recruitment | — | — | — | — | N-A |
| **Total** | **108** | **12** | **52** | **36** | **8** |

By tag: 54 **missed**, 43 **changed** (a matrix row exists but the live capture or the newer `components.json`
shows more or something else), 11 **no section**. Among the changed rows, 13 lower a matrix status of HAVE or BUILT
to PARTIAL or MISSING (CH-5, AU-1, WF-5, TO-1 … TO-6, PY-3, PY-19, PY-29, HD-1), and 16 fill in controls that the
matrices recorded as "not captured", "unknown" or inferred (WF-1 … WF-4, PY-20, PF-1 … PF-11).

## Biggest gaps

1. **Statutory engine correctness** (rule data, real pay impact): ESI coverage does not continue to the end of the
   contribution period (PY-12); EPS does not stop at 58 (PY-5); old-regime slabs ignore senior / super-senior ages
   (PY-26); no ESI ceiling or PT exemption for employees with a disability, no PT senior-citizen exemptions (PY-13,
   PY-16); employer retirement contributions above the aggregate limit are not taxed as a perquisite (PY-8).
2. **Statutory DETAILS dialogs** that matrix §10.12 rated HAVE hold about twenty employer processing choices
   (arrears, settlement, zero paid days, VPF basis, CTC provisioning, ECR gross basis, effective month) that PeopleOS
   does not offer (PY-2 … PY-19).
3. **Tax setting** (matrix: "controls unknown"): declaration / proof submission caps, leavers on verified proofs, HRA
   method, reimbursement bills month, TDS on held salary and reporting choices (PY-21 … PY-28); several exemption
   limits are not yet rule parameters (PY-20).
4. **Performance and 360 policies**: the real forms (cycles with windows, manual initiation, joiner cut-offs,
   per-level visibility, recommendations, appraisal basis; 360 relations with match criteria, per-relation
   templates and anonymity, client feedback, nomination role) differ from the inferred designs of HP-8h / HP-8i,
   which are pending rebuild (PF-1 … PF-11).
5. **Rule-based role membership and wider data scopes**: HROne assigns roles and data access by organisation rule
   (incl. designation, grade, level, region, reportees); PeopleOS assigns roles by hand and scopes on seven
   dimensions (AU-1, AR-3, AR-4).
6. **Transfer policy advance settings** (letter, pre- / post-transfer checklists, default allowances) and approval
   side effects (goals, salary revision, geo-location removal, attachments), though BP-6 is marked Done (WF-1 … WF-5).
7. **Time office depth** marked BUILT in matrix 09: absconding status, comp-off expiry and generation rules, punch
   near a request, manager / HR marking windows, short-hours alert, per-type leave cycles, full-week sandwich rule
   (TO-2 … TO-9).
8. **Master data at scale**: no import for organisation masters and no usage counts on master lists (CH-10, CH-11);
   business-unit and department applicability (CH-5, CH-7).
9. **Helpdesk SLA** is notify-only; HROne intimates named people at the due date and reassigns at TAT (HD-1).
10. **Pages with no matrix section**: hybrid work (office capacity, work-from-home policy) and the onboarding policy
    (ID cards, shortcuts, welcome / introduction steps) (TO-12, TO-13, WF-7 … WF-13); plus PII consent (CH-4).

## Build packages (ordered)

| # | Package | Scope (rows it closes) | Files likely touched | Depends on | Size (S/M/L) |
|---|---|---|---|---|---|
| SV-1 | Statutory correctness in rules and engine | PY-5, PY-8, PY-12, PY-13, PY-16, PY-17, PY-20, PY-26, PY-27 | `database/data/compliance/in.php` (EPF `eps_max_age` / `eps_age_basis`, ESI `wage_ceiling_disabled`, PT `exempt_from_age` / `exempt_disabled`, Punjab rule, TDS `age_slabs`, exemption limits, retirement-contribution limit, `lta_block`), `StatutoryEngine`, `TaxComputer`, `people.disability` migration + `DataInventory` | Owner statutory verification (rule versions are DRAFT); values never in settings | L |
| SV-2 | Statutory processing choices on establishment profiles | PY-2, PY-3, PY-6, PY-7, PY-9, PY-10, PY-14, PY-18, PY-19 | `config/peopleos.php` → `compliance.profile_settings` (EPF / ESI / PT / LWF keys, defaults = today), `EstablishmentStatutoryProfileResource`, `StatutoryEngine`, `StructureFormulas` (CTC provisioning), EPF export layout | §10.11 #7 `employer_wage_basis` | M |
| SV-3 | Tax-setting employer choices | PY-21, PY-22, PY-23, PY-24, PY-28 (PY-25 closes with BP-11) | `config/peopleos.php` settings `payroll.tax.*`, `payroll.reimbursement.bills_from_month`, `config/peopleos_setup.php` payroll, `TaxSettings`, `TaxComputer`, `InvestmentDeclarations`, `InvestmentProofs` | BP-8 | M |
| SV-4 | TDS deductor and challan-group details | PY-29, PY-30, PY-31 | `tds_profiles` migration, `TdsProfileResource`, Form 16 / 24Q builders (`app/Domain/Compliance/Services/Tds`), `statutory_registrations.metadata`, `StatutoryRegistrationResource` | BP-15 (challan grouping, §10.1 #3) | M |
| SV-5 | Time-office depth | TO-2, TO-3, TO-4, TO-5, TO-6, TO-8, TO-9, TO-10 | `config/peopleos.php` → `policies.types` (attendance, time-office, leave fields; defaults = today), `leave_types.cycle_start_month` migration, attendance day evaluator and absence job, leave accrual / carry-forward, `LeaveTypeResource` / `ShiftResource` code validation, events `attendance.absconding_suspected`, `attendance.short_hours_digest` | TO-3 / TO-4 / TO-7 (done) | L |
| SV-6 | Transfer policy advance settings and approval extras | WF-1, WF-2, WF-3, WF-4, WF-5 | `policies.types.transfer`, `clearance_checklists.kind` migration, `transfer_requests` columns, `TransferRequests` service, `Letters::generate`, geo-fence deactivation, `CompensationChange` draft | BP-6 (done) | M |
| SV-7 | Rule-based role membership and wider access scopes — **built (SV-7, with CA-9)** | AU-1, AR-1, AR-3, AR-4 | `role_membership_rules` + `RoleMembershipSync` (tag `source = rule`), `RoleGrants`, `UserAccessScope::DIMENSIONS`, `AccessScopes::constrainEmployees`, `config('peopleos.roles')` (`external`), fail-closed tests | CA-9 rebuild; IP-04 for AR-1 | L |
| SV-8 | Organisation master depth | CH-5, CH-6, CH-7, CH-9, CH-10, CH-13, CH-14, CH-15 | `business_units.applicability` / `logo_path`, `department_designation` pivot, `geo_groupings` (region parents, seeds), count columns on master tables, position form filters, `AssignPositionAction` warnings | CA-7 (geo groupings) for CH-9 / CH-14 | M |
| SV-9 | Organisation master imports | CH-11, CH-16 (import part), GV-1 (inactivate mode) | `App\Domain\Employment\Imports\RowImportTypes` (area `core`), new import services for departments, designations, grades, levels, business units, establishments, locations; `RecentUploads` | P2 import governance (done) | M |
| SV-10 | Helpdesk two-stage SLA | HD-1, HD-2 | `service_sla_policies.targets` (intimate / TAT / reassign per priority), `ServiceDeskProcessor`, `CaseAssignment`, `service_resolver_rules.description`, `ServiceSlaPolicyResource`, `ServiceResolverRuleResource` | HS-HD-B (done) | M |
| SV-11 | Performance policy per the real form | PF-6, PF-7, PF-8, PF-9, PF-10, PF-11 | Policy type `performance` in `policies.types`, `Appraisals`, `PerformanceCycles`, appraisal view policy (visibility), `CompensationCycles` (proposals) | HP-8i rebuild (replace its field list) | L |
| SV-12 | 360 policy per the real form | PF-1, PF-2, PF-3, PF-4, PF-5 | Policy type `feedback_360`, feedback rounds and nominations (as planned in §12.2), external reviewer links, `DataInventory` | HP-8h rebuild; P13 feedback templates (done) | L |
| SV-13 | Onboarding policy completion | WF-7, WF-8, WF-10, WF-13 | `onboarding_templates` (`shortcuts`, ID-card template), `peopleos.onboarding.item_types` (`introduction`, `welcome_message`), letter type `id_card`, Home cards, `people.social_links` | — | M |
| SV-14 | Personal-data consent | CH-4 | `privacy.pii_consent_required` setting, `privacy_consents` table, `EnforceAccountSecurity`, versioned notice article, `DataInventory` | Owner / legal notice text | M |
| SV-15 | Custom formula master | TO-7 | `custom_formulas` table + resource, `FormulaEngine` process variables, OT / comp-off / leave-credit references | SV-5 | M |
| SV-16 | Biometric sync-agent kit | TO-1 | Device page action and instruction sheet (`AttendanceDeviceResource`), agent specification under `docs/`; the agent itself is EXT | Owner decision (build or partner) | M |
| SV-17 | Outbound integrations: webhook auth and e-sign settings | IN-1, FT-2 | `webhook_endpoints` (`auth`, `headers`, `max_retries`, `max_per_second`; encrypted, audit-excluded), delivery job; e-sign adapter settings and `letter_templates.esign_type` | e-sign provider (EXT) for FT-2 | M |
| SV-18 | Hybrid work: office capacity and work plans | TO-12, TO-13 | `locations.seating_capacity` / `overbook_allowance`, policy type `work_plan`, `work_plans` table, Approval Center source, attendance status source `work_plan` | — | L |
| SV-19 | Small parity polish | CH-1, CH-3, EN-1, EN-2, EX-1, FT-1, AU-3 (`mail.from_name`, with P6), IN-2 | Plan-and-usage card (entitlements, read-only), `letter_signatories.employee_id`, `badges.give_limit` / `give_period`, `articles.attachments` / `media_url` / `service_definition_ids`, advance-policy cross-check, custom-field `max_length` / `decimals` / `unique` / `datetime`, SSO miniOrange preset | — | S each |

Rows that close only with the projects module (CH-16 clone / filters, CH-17, CH-18, CH-19, CH-20) belong to HP-5 and are
not given an SV package.

---

## Permission-tree cross-check (2026-10-29)

Purpose: make sure that no HROne Setup page escaped the gap matrices. The checklist is the "Setup [15]" branch of
HROne's page-access tree (`hrone-research/menu-permission-tree.md`, lines 929–1168): the root and 239 nodes, 240 in
all. Analysis only (no code, no tests, no HROne access); no personal data copied. New rows use the prefix **XC-**;
new packages continue the **SV-** series (SV-20 onward).

> **Progress (2026-10-29).** Done: all 240 nodes mapped to a matrix section (coverage table below); every node's
> permission actions read from `menu-catalogue.json` (`MenuAccessPermission/0`, e.g. Payment bank: Create, Update,
> View, Inactive; API key management: Regenerate api key, Delete api key, View logs); the components behind each node
> screened for form controls that no Setup matrix cites: the 482 components reachable from components the matrices
> name, plus about 120 Setup components that the matrices describe in prose without naming them (time office
> `ts-*`, `to-asf-*`, `to-ccs-*`, `leave-type-*`, `sls-*`, `wos-*`; payroll `bonus-sidebar`, `gratuity-sidebar`,
> `slabs`; workflow `information`, `approver`, `conditions`; webhooks; subscriptions; journal voucher); hand triage of
> every hit (a control a matrix describes in prose counts as covered). The new rail captures `rail_Setup__Employee*`,
> `rail_Setup__Customer` and `rail_Setup__Vendor*` were read; they add nothing beyond §1 except that the Relationship
> tab still opens the separation-reason page, so its real component (`app-employee-relationship`) was taken from
> `components.json`. PeopleOS facts were checked in the working tree. Not possible: live pages for Webhooks,
> Subscriptions and Journal voucher (not in the capture), so those rows come from compiled components only.

**Result.** No Setup node is missing a section: 206 nodes are covered, 23 are N-A and covered as such (recruitment,
HROne marketplace and partner pages), and 11 are only partly covered. The 11 partial nodes get 71 new rows below
(§2). Six rows of the earlier matrices carry a status that the later expense and asset builds have made out of date
(§3).

### 1. Coverage of every Setup node

Status: **Covered** = a matrix section or row describes the page and its dialogs; **Partly covered** = a section
exists but a dialog or several controls of the page are in no matrix (closed by the XC rows named); **N-A
(covered)** = listed as N-A with the reason in the matrix named. "01", "02", "08", "09", "11", "12" are the section
matrices in this folder, "13" is this file, "modules 21" is `docs/hrone-parity/modules/21-analytics-reports-projects.md`.

| # | Node path (Setup → …) | Covered by | Status |
|---|---|---|---|
| 1 | Setup | All 15 groups have sections: 01, 02, 08, 09, 11, 12 and this file | Covered |
| 2 | Core HR | 01 "01 Core HR"; 13 §1 | Covered |
| 3 | Core HR → Organization | 01 §1.1–1.9, §1.36 | Covered |
| 4 | Core HR → Organization → Enterprise | 01 §1.1; 13 CH-1, CH-2 | Covered |
| 5 | Core HR → Organization → Company | 01 §1.2; 13 CH-3, CH-4 | Covered |
| 6 | Core HR → Organization → Business unit | 01 §1.3; 13 CH-5, CH-6, CH-10, CH-11 | Covered |
| 7 | Core HR → Organization → Department | 01 §1.4; 13 CH-7, CH-10, CH-11 | Covered |
| 8 | Core HR → Organization → Sub department | 01 §1.5; 13 CH-10, CH-11 | Covered |
| 9 | Core HR → Organization → Designation | 01 §1.6; 13 CH-8, CH-11 | Covered |
| 10 | Core HR → Organization → Grade | 01 §1.7; 13 CH-11 | Covered |
| 11 | Core HR → Organization → Level | 01 §1.8; 13 CH-11 | Covered |
| 12 | Core HR → Organization → Dimension | 01 §1.9 | Covered |
| 13 | Core HR → Location | 01 §1.10–1.17; 13 CH-9 … CH-15 | Covered |
| 14 | Core HR → Location → Global Region | 01 §1.10; 13 CH-14 | Covered |
| 15 | Core HR → Location → Country | 01 §1.12 | Covered |
| 16 | Core HR → Location → Business Region | 01 §1.11; 13 CH-14 | Covered |
| 17 | Core HR → Location → State | 01 §1.13 | Covered |
| 18 | Core HR → Location → District | 01 §1.14; 13 CH-13 | Covered |
| 19 | Core HR → Location → City | 01 §1.15 | Covered |
| 20 | Core HR → Location → Region | 01 §1.10 as changed by 13 CH-9 | Covered |
| 21 | Core HR → Location → Branch | 01 §1.16 | Covered |
| 22 | Core HR → Location → Sub branch | 01 §1.17; 13 CH-15 | Covered |
| 23 | Core HR → Employee | 01 §1.18–1.27 | Covered |
| 24 | Core HR → Employee → Document | 01 §1.18 | Covered |
| 25 | Core HR → Employee → Qualification | 01 §1.19 | Covered |
| 26 | Core HR → Employee → Blacklist reason | 01 §1.20 | Covered |
| 27 | Core HR → Employee → Profile access | 01 §1.21 (the 11 live tabs match the row) | Covered |
| 28 | Core HR → Employee → Social profile access | 01 §1.22 | Covered |
| 29 | Core HR → Employee → Job function | 01 §1.23 | Covered |
| 30 | Core HR → Employee → Skill | 01 §1.24 | Covered |
| 31 | Core HR → Employee → Skill proficiency | 01 §1.25 | Covered |
| 32 | Core HR → Employee → Separation Reasons | 01 §1.26 | Covered |
| 33 | Core HR → Employee → Relationship | 01 §1.27 (master only; the capture showed the separation-reason page). Missing: the per-relationship section switches of `app-employee-relationship` → XC-1 … XC-6 | **Partly covered** |
| 34 | Core HR → Payment bank | 01 §1.28 (bank, branch, IFSC, account rules). Missing: payment mode and payout provider, bank-API credentials, BSR / MICR / IBAN, branch address, OTP before salary release → XC-7 … XC-15 | **Partly covered** |
| 35 | Core HR → Customer | 01 §1.29; 13 CH-16; modules 21 §21.4 | Covered |
| 36 | Core HR → Project | 01 §1.30–1.32, §1.35; 13 CH-18 … CH-20; modules 21 §21.4.1–21.4.4 | Covered |
| 37 | Core HR → Project → Project | 01 §1.30; modules 21 §21.4.4 (wizard steps field by field) | Covered |
| 38 | Core HR → Project → Project form | 01 §1.31; modules 21 §21.4.2 | Covered |
| 39 | Core HR → Project → Project stage | 01 §1.32; modules 21 §21.4.2 | Covered |
| 40 | Core HR → Vendor | 01 §1.33–1.34; 13 CH-17 | Covered |
| 41 | Core HR → Vendor → Vendor | 01 §1.33; 13 CH-17 | Covered |
| 42 | Core HR → Vendor → Vendor form | 01 §1.34 (live columns Fields · Enable · Mandatory · Rename match) | Covered |
| 43 | Automation | 02 "# 02 Automation"; 13 §2 | Covered |
| 44 | Automation → Role | 02 §02.8; 13 AU-1, AU-2 | Covered |
| 45 | Automation → Workflow | 02 §02.11 (steps, per-module request types). Missing: controls of the shared form steps (`app-information`, `app-approver`, `app-conditions`) → XC-16 … XC-22 | **Partly covered** |
| 46 | Automation → Workflow → Recruitment | 02 §02.11 row "Recruitment request types" | N-A (covered) |
| 47 | Automation → Workflow → Workforce | 02 §02.11 row "Workforce request types" | Covered |
| 48 | Automation → Workflow → Time Office | 02 §02.11 row "Time Office request types" | Covered |
| 49 | Automation → Workflow → Payroll | 02 §02.11 row "Payroll request types" | Covered |
| 50 | Automation → Workflow → Expense | 02 §02.11 row "Expense request types" | Covered |
| 51 | Automation → Workflow → Performance | 02 §02.11 row "Performance request types" | Covered |
| 52 | Automation → Workflow → Engage | 02 §02.11 row "Engage request type" | Covered |
| 53 | Automation → Workflow → Asset | 02 §02.11 row "Asset request type" | Covered |
| 54 | Automation → Task automation | 02 §02.10 | Covered |
| 55 | Automation → Task automation → Recruitment | 02 §02.10 row "Recruitment task" | N-A (covered) |
| 56 | Automation → Task automation → Workforce | 02 §02.10 row "Workforce tasks" | Covered |
| 57 | Automation → Task automation → Time Office | 02 §02.10 row "Time office tasks" | Covered |
| 58 | Automation → Task automation → Payroll | 02 §02.10 row "Payroll tasks" | Covered |
| 59 | Automation → Task automation → Expense | 02 §02.10 row "Expense tasks" (status out of date, see §3 below) | Covered |
| 60 | Automation → Task automation → Performance | 02 §02.10 row "Performance tasks" | Covered |
| 61 | Automation → Inbox | 02 §02.2, §02.3, §02.6 | Covered |
| 62 | Automation → Inbox → Inbox actions | 02 §02.2 | Covered |
| 63 | Automation → Inbox → Archive inbox | 02 §02.3 | Covered |
| 64 | Automation → Inbox → Employee request lock | 02 §02.6 (`app-ess-lock`: request type, lock on, grace period, exempted employees) | Covered |
| 65 | Automation → Services | 02 §02.9 | Covered |
| 66 | Automation → Notification | 02 §02.7 | Covered |
| 67 | Automation → Notification → Recruitment | 02 §02.7 row "Recruitment (16)" | N-A (covered) |
| 68 | Automation → Notification → Workforce | 02 §02.7 row "Workforce (64 processes)" | Covered |
| 69 | Automation → Notification → Time Office | 02 §02.7 row "Time Office (30)" | Covered |
| 70 | Automation → Notification → Payroll | 02 §02.7 row "Payroll (28)" | Covered |
| 71 | Automation → Notification → Expense | 02 §02.7 row "Expense (10)" (status out of date, see §3 below) | Covered |
| 72 | Automation → Notification → Performance | 02 §02.7 row "Performance (22)" | Covered |
| 73 | Automation → Notification → Engage | 02 §02.7 row "Engage (16)" | Covered |
| 74 | Automation → Notification → Asset | 02 §02.7 row "Asset (3)" (status out of date, see §3 below) | Covered |
| 75 | Automation → Notification → Helpdesk | 02 §02.7 row "Helpdesk (3)" | Covered |
| 76 | Automation → Communication | 02 §02.1; 13 AU-3 (the SMS tab `app-sms-configuration` holds only a display name and is not in the live menu) | Covered |
| 77 | Automation → Communication → Email | 02 §02.1; 13 AU-3 | Covered |
| 78 | Forms & templates | 02 "# 03 Forms & templates"; 13 §3 | Covered |
| 79 | Forms & templates → Code series | 02 §03.2 (`app-code-series-step-one/two`: module, series, organisation, employee type, prefix, padding, start number, suffix, sample code; status out of date, see §3) | Covered |
| 80 | Forms & templates → Forms | 02 §03.4; 13 FT-1 | Covered |
| 81 | Forms & templates → Letter | 02 §03.5; 13 FT-2 | Covered |
| 82 | Forms & templates → Letter → Recruitment | 02 §03.5 row "Offer letter (Recruitment)" | N-A (covered) |
| 83 | Forms & templates → Letter → Workforce | 02 §03.5 rows 1–7 | Covered |
| 84 | Forms & templates → Letter → Payroll | 02 §03.5 row "Payroll documents as templates" | Covered |
| 85 | Forms & templates → Letter → Expense | 02 §03.5 row "Engage / Expense / Performance letter templates" | Covered |
| 86 | Forms & templates → Letter → Performance | 02 §03.5 row "Engage / Expense / Performance letter templates" | Covered |
| 87 | Forms & templates → Letter → Engage | 02 §03.5 row "Engage / Expense / Performance letter templates" | Covered |
| 88 | Forms & templates → Letter acceptance | 02 §03.6 | Covered |
| 89 | Forms & templates → Checklist | 02 §03.1 (types and phases only). Missing: the task dialog `app-clearance-add-task` and list actions → XC-23 … XC-36 | **Partly covered** |
| 90 | Forms & templates → Feedback | 02 §03.3. Missing: the template form body `app-feedback-template-form-content` (sections, descriptions, scored options, 360 overall rating) and the Performance / Engage processes → XC-37 … XC-45 | **Partly covered** |
| 91 | Forms & templates → Feedback → Recruitment | 02 §03.3 row "Recruitment feedback" | N-A (covered) |
| 92 | Forms & templates → Feedback → Workforce | 02 §03.3 (built HS-P13); the shared form rows XC-37 … XC-42 apply | **Partly covered** |
| 93 | Forms & templates → Feedback → Performance | 02 §03.3 progress note only ("wait for their packages") → XC-37 … XC-44 | **Partly covered** |
| 94 | Forms & templates → Feedback → Engage | 02 §03.3 progress note only → XC-37 … XC-42, XC-45 | **Partly covered** |
| 95 | Governance | 02 "# 04 Governance"; 13 §4 | Covered |
| 96 | Governance → Security | 02 §04.5–04.8 | Covered |
| 97 | Governance → Security → Password policy | 02 §04.5 | Covered |
| 98 | Governance → Security → Disable login | 02 §04.6 | Covered |
| 99 | Governance → Security → Password protected reports | 02 §04.8 | Covered |
| 100 | Governance → Security → Override records | 02 §04.7 | Covered |
| 101 | Governance → Audit trail | 02 §04.1 | Covered |
| 102 | Governance → Data export | 02 §04.4 | Covered |
| 103 | Governance → Data Import | 02 §04.2–04.3; 13 GV-1 | Covered |
| 104 | Governance → Data Import → Import Approval | 02 §04.2 | Covered |
| 105 | Governance → Data Import → Recent Imports | 02 §04.3 | Covered |
| 106 | Governance → Subscriptions | 02 §04.9 (brief comparison) | Covered |
| 107 | Governance → Subscriptions → Subscriptions | 02 §04.9 row 1 (modules × users, add users). Missing: dashboard counters, renewals, user distribution, plan / payment history, modify-plan dialogs, module selection → XC-46 … XC-52 | **Partly covered** |
| 108 | Governance → Subscriptions → Trial product | 02 §04.9 row 5 (marketplace trials; `app-trial-product`, `app-stop-trial-flyer`) | N-A (covered) |
| 109 | Governance → Subscriptions → Invoice | 02 §04.9 row 4 (`app-invoice-grid` columns match) | Covered |
| 110 | Governance → Subscriptions → Credit | 02 §04.9 row 3 (resold credits). Its "View live logs" (`app-feature-logs` → `app-email-logs`, `app-whatsapp-logs`) are credit-consumption logs; PeopleOS keeps a delivery log (`NotificationDeliveryResource`) | N-A (covered) |
| 111 | Governance → Subscriptions → Billing contact details | 02 §04.9 row 2; 01 §1.1 contacts (`app-billing-details`) | Covered |
| 112 | Access rights | 01 "05 Access rights"; 13 §5 | Covered |
| 113 | Access rights → Page access | 01 §5.1; 13 AR-1, AR-2 | Covered |
| 114 | Access rights → Data access | 01 §5.2; 13 AR-3, AR-4 | Covered |
| 115 | Access rights → CXO access | 01 §5.3 (all 14 views of the permission list; status out of date for Expense, see §3) | Covered |
| 116 | Access rights → Widget access | 01 §5.6; 13 AR-5 | Covered |
| 117 | Access rights → Geo access | 01 §5.5 | Covered |
| 118 | Access rights → Calendar access | 01 §5.4 (status out of date for Expense, see §3) | Covered |
| 119 | Integration | 01 "06 Integration"; 13 §6 | Covered |
| 120 | Integration → API access | 01 §6.1 (live fields API URL, Authentication key, Domain code, EXPLORE API) | Covered |
| 121 | Integration → BGV verification | 01 §6.3 | Covered |
| 122 | Integration → Employee offers | 01 §6.11 | N-A (covered) |
| 123 | Integration → SCIM | 01 §6.8 | Covered |
| 124 | Integration → e-KYC | 01 §6.4 | Covered |
| 125 | Integration → Single sign on | 01 §6.9; 13 IN-2 | Covered |
| 126 | Integration → API key management | 01 §6.2; 13 IN-1 (webhook basic step only). Missing: webhook list, logs, failed deliveries, event test, secret rotation; API key regenerate / delete and the log columns → XC-53 … XC-63 (XC-53, XC-56 … XC-60, XC-62 and XC-63 built with CA-11 / SV-26; XC-54, XC-55 and XC-61 were HAVE) | **Partly covered** |
| 127 | Integration → Journal voucher | 01 §6.5–6.7 | Covered |
| 128 | Integration → Journal voucher → Journal voucher | 01 §6.5 (one summary row for the six-step form). Missing: every field of Basic info, Connection details, GL code, Report format, posting status and the JV log → XC-64 … XC-71 | **Partly covered** |
| 129 | Integration → Journal voucher → Configure ou | 01 §6.6 | Covered |
| 130 | Integration → Journal voucher → Employee | 01 §6.7 | Covered |
| 131 | Integration → One AI | 01 §6.10 | Covered |
| 132 | Integration → One AI → Credit configuration | 01 §6.10 | Covered |
| 133 | Recruitment | 13 §15; every matrix lists recruitment as N-A | N-A (covered) |
| 134 | Recruitment → Candidate | N-A (recruitment, separate RMS) | N-A (covered) |
| 135 | Recruitment → Candidate → Candidate access | N-A (recruitment, separate RMS) | N-A (covered) |
| 136 | Recruitment → Job requisition | N-A (recruitment, separate RMS) | N-A (covered) |
| 137 | Recruitment → Job requisition → Job requisition form | N-A (recruitment, separate RMS) | N-A (covered) |
| 138 | Recruitment → Job requisition → Job requisition TAT | N-A (recruitment, separate RMS; `app-tat-setting`) | N-A (covered) |
| 139 | Recruitment → Talent sourcing | N-A (recruitment, separate RMS) | N-A (covered) |
| 140 | Recruitment → Talent sourcing → Source category | N-A (recruitment, separate RMS) | N-A (covered) |
| 141 | Recruitment → Talent sourcing → Source | N-A (recruitment, separate RMS) | N-A (covered) |
| 142 | Recruitment → Talent sourcing → Career portal | N-A (recruitment, separate RMS) | N-A (covered) |
| 143 | Recruitment → Talent sourcing → Linkedin integration | N-A (recruitment, separate RMS) | N-A (covered) |
| 144 | Recruitment → Recruitment budget | N-A (recruitment, separate RMS) | N-A (covered) |
| 145 | Recruitment → Settings | N-A (recruitment, separate RMS; 13 §15) | N-A (covered) |
| 146 | Recruitment → Recruiter access | N-A (recruitment, separate RMS) | N-A (covered) |
| 147 | Recruitment → Recruiter access → Recruiter access | N-A (recruitment, separate RMS) | N-A (covered) |
| 148 | Workforce | 08 "# 08 Workforce"; 13 §7 | Covered |
| 149 | Workforce → Induction | 08 §08.2 | Covered |
| 150 | Workforce → Confirmation | 08 §08.1 | Covered |
| 151 | Workforce → Transfer | 08 §08.4–08.6; 13 WF-1 … WF-5 | Covered |
| 152 | Workforce → Transfer → Transfer policy | 08 §08.4; 13 WF-1 … WF-5 | Covered |
| 153 | Workforce → Transfer → Reason | 08 §08.5 | Covered |
| 154 | Workforce → Transfer → Transfer allowances form | 08 §08.6 | Covered |
| 155 | Workforce → Separation | 08 §08.3 | Covered |
| 156 | Workforce → Separation → Separation policy | 08 §08.3 | Covered |
| 157 | Workforce → Settings | 08 §08.7 (shared drawer; Workforce panel = `app-task-setting`, 02 §02.5) | Covered |
| 158 | Time office | 09; 13 §8 | Covered |
| 159 | Time office → Shift | 09 "Shift", "Shift rotation and shift group"; 13 TO-6, TO-10, TO-11 | Covered |
| 160 | Time office → Shift → Shift policy | 09 "Shift" (`ts-basic`, `ts-timing`, `ts-absence-criteria`, `ts-advance`, `ts-weekly-off`); 13 TO-6, TO-10 | Covered |
| 161 | Time office → Shift → Shift rotation policy | 09 "Shift rotation and shift group" (`to-sr-step1…3`) | Covered |
| 162 | Time office → Shift → Shift group policy | 09 "Shift rotation and shift group" (`to-sg-step1`); 13 TO-11 | Covered |
| 163 | Time office → Weekly off | 09 "Weekly off" (`wos-step-one`: policy name, day, week) | Covered |
| 164 | Time office → Holiday | 09 "Holiday" (`to-hsf-name`, holiday bank) | Covered |
| 165 | Time office → Check in | 09 "Check in" | Covered |
| 166 | Time office → Check in → Checkin Policy | 09 "Check in → Check in policy" (`to-ccs-step-one`, `to-ccs-request`) | Covered |
| 167 | Time office → Check in → Biometric server | 09 "Biometric server"; 13 TO-1 | Covered |
| 168 | Time office → Attendance | 09 "Attendance policy" (`to-asf-*`); 13 TO-2 … TO-5, TO-7 | Covered |
| 169 | Time office → Leave | 09 "Leave type and leave policy" (`leave-type-*`, `leave-policy-add`); 13 TO-8 … TO-10 | Covered |
| 170 | Time office → Short leave | 09 "Short leave" (`sls-step-one`, `sls-request`) | Covered |
| 171 | Time office → Settings | 09 "Time office → Settings" (`app-time-office-setting`) | Covered |
| 172 | Payroll | 08 "# 10 Payroll"; 13 §9 | Covered |
| 173 | Payroll → Statutory template | 08 §10.11 | Covered |
| 174 | Payroll → Statutory | 08 §10.12 (PF, ESI, LWF, PT, bonus, gratuity, tax, TDS deductor); 13 PY-1 … PY-29 | Covered |
| 175 | Payroll → Challan | 08 §10.1; 13 PY-30 … PY-32 | Covered |
| 176 | Payroll → Slabs | 08 §10.10 (LWF, PT, bonus, tax slabs) | Covered |
| 177 | Payroll → Pay group | 08 §10.2–10.6 | Covered |
| 178 | Payroll → Pay group → Pay group | 08 §10.2–10.3 (pay group, pay components, component report) | Covered |
| 179 | Payroll → Pay group → CTC formula tags | 08 §10.4 | Covered |
| 180 | Payroll → Pay group → Out of ctc allowances | 08 §10.5 | Covered |
| 181 | Payroll → Pay group → Payroll glossary | 08 §10.6 | Covered |
| 182 | Payroll → Settings | 08 §10.7–10.9 (incl. "Statutory skills", §10.7 #7) | Covered |
| 183 | Expense | 11 "# 11 Expense"; 13 §10 | Covered |
| 184 | Expense → Expense management | 11 §11.3–11.9 | Covered |
| 185 | Expense → Expense management → Expense policy | 11 §11.3–11.4 | Covered |
| 186 | Expense → Expense management → Location group | 11 §11.5 | Covered |
| 187 | Expense → Expense management → Tax | 11 §11.6 | Covered |
| 188 | Expense → Expense management → Currency | 11 §11.7 | Covered |
| 189 | Expense → Expense management → Expense form | 11 §11.8 | Covered |
| 190 | Expense → Expense management → Travel plan form | 11 §11.9 | Covered |
| 191 | Expense → Expense advance | 11 §11.2; 13 EX-1 | Covered |
| 192 | Expense → Settings | 11 §11.1 | Covered |
| 193 | Performance | 12 "## 12 Performance"; 13 §11 | Covered |
| 194 | Performance → Goal | 12 §12.3–12.8 | Covered |
| 195 | Performance → Goal → Goal sheet policy | 12 §12.3 | Covered |
| 196 | Performance → Goal → Performance terminology | 12 §12.6 | Covered |
| 197 | Performance → Goal → Unit of measurement | 12 §12.8 | Covered |
| 198 | Performance → Goal → Perspective | 12 §12.7 | Covered |
| 199 | Performance → Goal → Goal library | 12 §12.4 | Covered |
| 200 | Performance → Goal → Goal template | 12 §12.5 (its "Do you want employees to edit the goal template?" `isAllowToEmployeeEditGoalTemplate` is met by the goal-sheet policy field rights, §12.3) | Covered |
| 201 | Performance → Review | 12 §12.15–12.17 | Covered |
| 202 | Performance → Review → Review rating | 12 §12.15 | Covered |
| 203 | Performance → Review → Competency rating | 12 §12.16 | Covered |
| 204 | Performance → Review → KPI rating formula | 12 §12.17 | Covered |
| 205 | Performance → Performance | 12 §12.12–12.14 | Covered |
| 206 | Performance → Performance → Performance policy | 12 §12.12; 13 PF-6 … PF-11 | Covered |
| 207 | Performance → Performance → Bell curve | 12 §12.14 | Covered |
| 208 | Performance → Performance → 9 box | 12 §12.13 | Covered |
| 209 | Performance → Initiative | 12 §12.9 | Covered |
| 210 | Performance → Initiative → Initiative | 12 §12.9 | Covered |
| 211 | Performance → 360 Feedback | 12 §12.2 | Covered |
| 212 | Performance → 360 Feedback → 360 policy | 12 §12.2; 13 PF-1 … PF-5 | Covered |
| 213 | Performance → PIP | 12 §12.10–12.11 | Covered |
| 214 | Performance → PIP → PIP policy | 12 §12.10 | Covered |
| 215 | Performance → PIP → PIP reason | 12 §12.11 | Covered |
| 216 | Performance → Settings | 12 §12.0 | Covered |
| 217 | Performance → 1-on-1 | 12 §12.1 | Covered |
| 218 | Engage | 12 "## 13 Engage"; 13 §12 | Covered |
| 219 | Engage → Badges | 12 §13.1–13.2 | Covered |
| 220 | Engage → Badges → Badges | 12 §13.1; 13 EN-3 | Covered |
| 221 | Engage → Badges → Custom badges | 12 §13.2; 13 EN-1 | Covered |
| 222 | Engage → Happiness index | 12 §13.5 | Covered |
| 223 | Engage → Reward points | 12 §13.7 | Covered |
| 224 | Engage → Mission statement | 12 §13.6 | Covered |
| 225 | Engage → Social wall | 12 §13.8 | Covered |
| 226 | Engage → Celebration | 12 §13.3 | Covered |
| 227 | Engage → HR Handbook | 12 §13.4; 13 EN-2 | Covered |
| 228 | Asset | 11 "# 14 Asset"; 13 §13 | Covered |
| 229 | Asset → Asset management | 11 §14.2–14.5 | Covered |
| 230 | Asset → Asset management → Policy | 11 §14.2 | Covered |
| 231 | Asset → Asset management → Asset type | 11 §14.3 | Covered |
| 232 | Asset → Asset management → Asset sub type | 11 §14.4 | Covered |
| 233 | Asset → Asset management → Products | 11 §14.5 | Covered |
| 234 | Asset → Inventory | 11 §14.6 | Covered |
| 235 | Asset → Settings | 11 §14.1 | Covered |
| 236 | Helpdesk | 11 "# 15 Helpdesk"; 13 §14 | Covered |
| 237 | Helpdesk → Helpdesk management | 11 §15.2–15.4 | Covered |
| 238 | Helpdesk → Helpdesk management → Resolver rules | 11 §15.3; 13 HD-1, HD-2 | Covered |
| 239 | Helpdesk → Helpdesk management → Helpdesk policy | 11 §15.4 | Covered |
| 240 | Helpdesk → Settings | 11 §15.1 | Covered |

### 2. New rows for the partly covered nodes

Each element is tagged as in the rest of this file: **[missed §x]** (no row of matrix section §x covers it) or
**[changed §x]** (a row exists but the component shows more). Build notes follow the conventions in "How to read this
file" above.

#### Setup → Core HR → Employee → Relationship (`app-employee-relationship`; actions View, Create, Update, Inactive)

Page help: "Create relationships and choose to include under personal, family, health insurance, and nominee
sections." Grid columns: Relationship · Personal details · Family details · Health insurance · Nominee · Action.

| # | HROne element | What it does | PeopleOS today | Status | Build note |
|---|---|---|---|---|---|
| XC-1 | **[changed §1.27]** Relationship list, "Add Relationship" (`Enter relationship name`), SHOW MORE | Tenant list of relationship names | Tenant-extendable list `option_lists` key `family_relations` (eight built-in codes from `peopleos.people.family_relations`, renamable or hidden, plus the organisation's own; `OptionListResource`, CA-3) | HAVE | — |
| XC-2 | **[missed §1.27]** Column "Personal details" (per-relationship switch) | The relationship is offered in the personal-details section (contact person / emergency contact) *(inferred)* | `person_emergency_contacts.relation` is free text (string 64); it does not use the list | PARTIAL | Column `option_lists.applies_to` (json of `personal`, `family`, `insurance`, `nominee`; null = every section = today). The emergency-contact form offers a select from `family_relations` filtered by `personal`; older free-text values stay readable. |
| XC-3 | **[missed §1.27]** Column "Family details" | The relationship can be chosen for a family member | Family members validate `relation` against every active `family_relations` option (`ChangeFamilyMemberAction`) | HAVE | With XC-2: filter by `family` (null keeps today's "all"). |
| XC-4 | **[missed §1.27]** Column "Health insurance" | The relationship can be insured as a dependant | No health-insurance records (WF-10 MISSING); `person_family_members.is_dependent` only | MISSING | Store the `insurance` switch now; it takes effect when WF-10 adds insured dependants (until then it is shown as "used by health insurance, not built"). |
| XC-5 | **[missed §1.27]** Column "Nominee" | Only these relationships can be named as nominees | Any family member can be a nominee (`is_nominee`, `nominee_share` in `FamilyMembersRelationManager`) | PARTIAL | `applies_to` `nominee`: the Nominee toggle is offered only for such relationships; existing nominees are kept and listed for HR review, never removed. Statutory nomination rules (e.g. who counts as family for EPF) stay in rule data, not in this switch. |
| XC-6 | **[changed §1.27]** Action: edit, inactivate | Rename or retire a relationship | Edit; inactive hides an option; built-in codes cannot be deleted (`OptionList` model guard) | HAVE | — |

#### Setup → Core HR → Payment bank (`app-bank`; actions Create, Update, View, Inactive)

Matrix §1.28 covers bank name, bank code, branch, IFSC and the account-number restrictions. The dialog also holds the
controls below.

| # | HROne element | What it does | PeopleOS today | Status | Build note |
|---|---|---|---|---|---|
| XC-7 | **[missed §1.28]** List: card "Payment Bank – ‹name›", EDIT, inactivate, "SELECT FILTERS" (Bank, Status Active / Inactive), "Filters Applied · CLEAR ALL", SHOW MORE, "NO PAYMENT BANK" | Manage the company's paying accounts | `CompanyBankAccountResource` (Payroll → Payment banks): company filter, status, default flag | PARTIAL | Add Bank and Status filters (S). |
| XC-8 | **[missed §1.28]** Alias name `alias`, Account number `accountNumber` | Short name and debit account of the paying bank | `company_bank_accounts.alias`, `account_number` (encrypted) + `account_number_last4` | HAVE | — |
| XC-9 | **[missed §1.28]** "Select bank challan report format — Default / Custom" `isCustomReport`; "Bank challan report" `reportId`; "Format type" `customReport` | Which bank-transfer file layout this account uses: the bank's standard one or a custom report | `bank_file_layout_code` (null = plain salary file) on versioned `bank_file_layouts` (`BankFileLayouts`) | HAVE | — |
| XC-10 | **[missed §1.28]** "Payment mode — Manual transfer / Online transfer" `paymentMode` | Salary is paid by uploading a bank file (manual) or pushed to the bank through its API (online) | Bank file download only (`BankFile`); `employee_payment_modes.salary_mode` is the employee's mode (online transfer / cash / cheque), not the account's channel | PARTIAL | `company_bank_accounts.transfer_channel` (`file` / `api`, default `file` = today). `api` needs a payout connector (XC-11). |
| XC-11 | **[missed §1.28]** "Service provider" `paymentServiceProvider`; "Transaction type" `transactionType` | The payout provider or bank API and the transfer rail (e.g. NEFT / RTGS / IMPS) *(inferred)* | None. The `PaymentProvider` contract in `app/Domain/Payments` collects SaaS invoices from tenants and must not be reused for salary payouts | MISSING (EXT) | New boundary `PayoutProvider` (adapter per bank or provider, like `BgvProvider`); columns `payout_provider`, `transaction_type` (null = file). Provider choice is an owner decision; no adapter ships by default. |
| XC-12 | **[missed §1.28]** "Corporation ID", "User ID", "URN" (controls `merchantId`, `merchantKey`, `walletId`; mapping *(inferred)*) | Credentials of the corporate internet-banking API | — | MISSING (EXT) | `company_bank_accounts.payout_credentials` (encrypted json, audit-excluded via `auditSensitiveAttributes`, never re-displayed), read only by the XC-11 adapter. |
| XC-13 | **[missed §1.28]** "BSR code" `branchBasicStatisticalReturnsCode`, "MICR code" `magneticInkCharacterRecognitionCode`, "IBAN No." `internationalBankAccountNumber` | Bank identifiers used on tax challans (BSR), cheques (MICR) and foreign transfers (IBAN) | TDS challans carry a typed `bsr_code` per challan (`TdsQuarterlyReturns`); no MICR or IBAN on the paying account | PARTIAL | Nullable `bsr_code` (7 digits), `micr` (9 digits), `iban` (≤ 34, checksum-validated); the TDS challan entry offers the paying bank's BSR code as a default. |
| XC-14 | **[missed §1.28]** Branch "Location" `branchLocation`, "Address" `branchAddress` | Where the paying branch is | `branch_name` only | MISSING | Nullable `branch_city`, `branch_address`; available to bank-file layouts and bank letters. S. |
| XC-15 | **[missed §1.28]** "Do you want OTP verification before releasing salary?" Yes / No `isEmployeeAvailable`; "Search employee who receives OTP" `employeeForOnetimePassword` | A one-time code sent to a named person must be entered before salary is released from this account | Finalize and mark-paid need payroll permissions and separation of duties; bank-file download is audited; no second factor at release (`PayrollRuns::markPaid`, `BankFile`) | MISSING | Per account `release_requires_otp` (bool, default false) + `release_otp_user_ids`; marking paid and downloading the bank file then ask a fresh code from `MultiFactor` (TOTP) of one of the listed users, or an e-mailed one-time code when that user has no MFA; audited; never on by default. |

#### Setup → Automation → Workflow (shared form `app-workflow-form`: `app-information`, `app-approver`, `app-conditions`, used by every module tab)

Matrix §02.11 covers the steps and each module's request types; these controls inside the steps are in no row.

| # | HROne element | What it does | PeopleOS today | Status | Build note |
|---|---|---|---|---|---|
| XC-16 | **[missed §02.11]** Information: Workflow name, Description, "Request type(s)" `flowId` (several), "Flow Type — New / Cancellation" `flowType` | One workflow can serve several request types, and cancellations of approved requests get their own workflow | Name, key, description, one `trigger_event` per workflow (`WorkflowResource`); cancellations run on their own triggers (`leave.cancel_requested`, `attendance.timeoffice_cancel_requested`, `expense.advance_cancel_requested`, `expense.travel_cancel_requested`) | PARTIAL | Optional `workflows.additional_triggers` (json, default empty = today): one published definition starts on any listed trigger; or a "Duplicate workflow" action. Cancellation needs nothing new. |
| XC-17 | **[missed §02.11]** "Workflow Levels" `ruleAuthority`: per level a Role `roleId` (or relationship) and users `roleUser` (multiple); "Add new approver" | The approval chain, level by level | Approval nodes with approver types manager / N levels up / role / user / functional manager / HRBP and modes single / sequential / parallel / majority (`peopleos.workflows.approver_types`, `approval_modes`) | HAVE | — |
| XC-18 | **[missed §02.11]** Per level "Intimation only — Yes / No" `intimation` | The level is informed but does not decide | `notification` node in the builder (`peopleos.workflows.node_types`) | HAVE | — |
| XC-19 | **[missed §02.11]** Per level "Due day" `dueDays` ("Due day should not be greater than turnaround time") | The date the level should act by (reminders) | Task `config.sla_hours` + escalation step `remind` (`EscalationEngine`) | HAVE | Hours rather than days; no change. |
| XC-20 | **[changed §02.11 "TAT at last level"]** Per level "Do you want to enable Turnaround Time (TAT)?" `isTatEnabled`, TAT `turnaroundTime`, "Default functionality — Auto accept / Auto reject" `actionId` | When TAT passes, that level is decided automatically | Escalation action `auto_decide`, accepted on the last approval step only (`WorkflowDefinition::isLastApproval`, HS-P5); other steps remind or escalate | PARTIAL | Setting `workflow.auto_decide_any_level` (bool, default false = today). When on, publish accepts `auto_decide` on any approval step and records a system decision as today. Owner decision first: an automatic approval in the middle of a chain skips a human check. |
| XC-21 | **[missed §02.11]** Conditions: "Do you want to set conditional workflow?" `isConditionalWorkflowDefine`; rows Condition `conditionBasedOn` · Operator `conditionalOperator` · Value `conditionalValue`; "Select additional condition AND / OR" `isANDOperatorSelected`; per condition "Workflow to be followed" with its own levels (role, intimation, due day, TAT, auto accept / reject) `conditionalRuleAuthorityDetails` | Different approval chains for different request values | Condition node (all / any) branching to different approval nodes (`WorkflowBuilderSchema::conditionFields`) | HAVE | Mixed AND / OR is built by nesting condition nodes. |
| XC-22 | **[missed §02.11]** Form note "No workflow will run when an import is performed by a business partner user." | Imports by partner users skip approval | Imports route through `import.validated` (HS-P2); no business-partner users (IP-04) | N-A | Revisit with IP-04. |

#### Setup → Forms & templates → Checklist (`app-clearance`, `app-clearance-add-task`, `app-clearance-review`; actions Create, Update, View, Inactive)

Matrix §03.1 covers the checklist types (preboarding, onboarding, clearance) and their phases. The task dialog and
the list actions are in no row. PeopleOS holds these checklists in two places: onboarding templates
(`OnboardingTemplateResource`, `onboarding_template_items`) and clearance checklists (`ClearanceChecklistResource`,
`clearance_checklists.items` json: stage, item, due_days, mandatory).

| # | HROne element | What it does | PeopleOS today | Status | Build note |
|---|---|---|---|---|---|
| XC-23 | **[missed §03.1]** List: card "Checklist – ‹name›", Edit, **Clone**, filters Checklist (`Select checklist`) and Status, "Filters applied · CLEAR ALL", SHOW MORE, "NO CHECKLIST" | Find and copy checklists | Onboarding templates filter by kind; clearance checklists list name, item count, status; no copy | PARTIAL | "Clone" (replicate with items, new name, status inactive) on both resources; status filter on both. S. |
| XC-24 | **[changed §03.1]** Step "Checklist": Type `checklistTypeId`, Checklist name `checklistName` | Which process the checklist serves | Onboarding template `kind` + item phase; separate clearance checklists; transfer checklists planned (13 WF-2, `clearance_checklists.kind`) | HAVE | Transfer kinds come with SV-6. |
| XC-25 | **[missed §03.1]** "Use template", "Standard template", "Checklist template", "Select template", Preview | Start from HROne's standard checklists | Configuration packs (`ConfigurationPacks`, `resources/packs/*.json`, `Blueprints`); no checklist pack | PARTIAL | Pack `checklist-starter` (preboarding, onboarding, clearance examples) and "Add from library" on both resources, as done for feedback templates (HS-P13). |
| XC-26 | **[missed §03.1]** Per task "Assigning department" `department` and Role `authority` | The department and role that own the task | Onboarding item owner type (role, user, manager, buddy …) + `owner_role_id`; clearance item `stage` (manager, IT, finance, asset, HR) whose owner role is a setting | PARTIAL | Optional `owner_department_id` on onboarding items and a `department_id` key on clearance items: the task goes to the owner role's holders whose access scope includes that department; null = today. |
| XC-27 | **[missed §03.1]** Per task "Due day" `dueDay`, "Task description" `taskName` | When and what | `due_offset_days`, `title`, `description`; clearance `due_days`, `item` | HAVE | — |
| XC-28 | **[missed §03.1]** "Insert link — Video/Youtube link / Web link" `isVideoPathWebLink`, `videoPath` | A how-to video or web page on the task | Description text only | MISSING | `onboarding_template_items.link_url` + `link_kind` (video / web), copied to `onboarding_tasks`; `link_url` key on clearance items; https only (`SafeOutboundUrl`); shown as a button on My tasks. S. |
| XC-29 | **[missed §03.1]** "Attach file / Upload" | A reference file for the person doing the task | Item type `document` collects a file from the employee; nothing attaches a file to the task | MISSING | `onboarding_template_items.attachment_path` (documents disk, `peopleos.documents.max_kb` and `mimes`), downloadable by the task owner and assignee; DataInventory entry. |
| XC-30 | **[missed §03.1]** "Task dependent on" `taskDependentIds` (several) | A task opens only after the listed tasks are done | Tasks are independent; order comes from the due offsets | MISSING | `onboarding_template_items.depends_on` (json of item ids in the same template; cycles refused on save); a blocked task shows "waiting for …" and cannot be completed; default empty = today. |
| XC-31 | **[missed §03.1]** Reorder (arrow up / down) | Task order | Repeater reorder, `sort_order` | HAVE | — |
| XC-32 | **[missed §03.1]** "Is this mandatory for employee creation?" `mandatoryEmployeeCreation` | A preboarding task must be done before the joiner becomes an employee | `is_mandatory` only gates plan completion; joining is gated only by mandatory documents (`documents.block_joining_without_mandatory`, `LifecycleEngine`) | PARTIAL | Item flag `blocks_joining` (default false); the joining step refuses while such tasks are open, with an HR override that needs a reason (audited). |
| XC-33 | **[missed §03.1]** "Is this mandatory for employee settlement?" `mandatoryEmployeeSettlement` | A clearance task must be done before full and final settlement | Clearance items carry `mandatory`; stages are cleared as a whole; `FinalSettlements::approve` does not look at open clearance items | PARTIAL | Clearance item key `blocks_settlement`; with setting `exit.settlement_requires_clearance` (default false = today) `FinalSettlements::approve` refuses while such items are open. |
| XC-34 | **[missed §03.1]** "Auto-complete after due date" `isAutoApproved` | The task closes itself when its due date passes | Overdue tasks stay pending (reminders, ESS lock) | MISSING | Item flag `auto_complete_on_due` (default false); the daily lifecycle job closes such tasks as completed by the system, audited; refused together with `blocks_joining` / `blocks_settlement`. |
| XC-35 | **[missed §03.1]** "Do you want to enable sub status category in the checklist?" `isCategoryStatus` + "Sub-status" list `categoryStatus` | Owners record intermediate states (e.g. "laptop ordered") | Task status pending / completed / skipped | MISSING | `sub_statuses` (json list, default empty) on onboarding templates and clearance checklists; task column `sub_status` set by the owner; no effect on completion. S. |
| XC-36 | **[missed §03.1]** Step "Review" (`app-clearance-review`) | Summary before saving | Form and view pages show the whole template | HAVE | — |

#### Setup → Forms & templates → Feedback → Workforce / Performance / Engage (`app-feedback-template-common`, `app-feedback-template-form`, `app-feedback-template-form-content`, `app-default-templates`; actions Feedback, Question bank)

Matrix §03.3 (built in HS-P13 for Workforce) covers the template list, the process binding, the library and the
question bank. The form body and the Performance / Engage processes are in no row. PeopleOS: `feedback_templates`
(code, name, module, process, description, questions json: title, type, options, scale_max, is_mandatory, status),
`FeedbackTemplateResource`, processes in `peopleos.feedback.processes`.

| # | HROne element | What it does | PeopleOS today | Status | Build note |
|---|---|---|---|---|---|
| XC-37 | **[missed §03.3]** List: "Feedback Template – ‹name›", **Clone**, filters "Select set", "Select feedback", Status; question-bank tab filter "Type of question: Select All" | Find and copy templates | Filters "Used for" (process) and status; Preview, Edit; no copy; question banks on their own page | PARTIAL | "Clone" action (copy as inactive, new code); question-type filter on `QuestionBankResource`. S. |
| XC-38 | **[missed §03.3]** "Feedback name" `feedbackName`, "Feedback type" `feedbackTypeId` | Name and the process the template serves | `name`, `process` | HAVE | — |
| XC-39 | **[missed §03.3]** Sections `feedbackSectionList`: section name `sectionName`, section description `sectionDescription`, delete section ("You are going to permanently delete the section from the form") | Group questions under headed sections | One flat question list | MISSING | Optional `section` key on each question and `feedback_templates.sections` (json: key, title, description); forms and answer views group by section; existing templates = one untitled section. |
| XC-40 | **[missed §03.3]** Question `feedbackQuestion`, description `feedbackQuestionDescription`, response type `feedbackQuestionTypeCode`, mandatory `feedbackIsMandatory` | The question, a help text under it, its type, required | Title, type (survey question types), `is_mandatory`; no description | PARTIAL | Question key `help` (text 500) shown under the question. S. |
| XC-41 | **[missed §03.3]** Maximum rating `feedbackMaxRating`; options `questionOptionList` with option text `optionText` and option score `rating` | Rating scale size; choice answers that carry a score | `scale_max`; choice options without a score | PARTIAL | Options as [{label, score}] (score nullable = unscored = today); answer summaries average the scores. |
| XC-42 | **[changed §03.3 "Default templates library"]** "FEEDBACK TEMPLATES" library per module: Search template, PREVIEW, USE THIS / USE TEMPLATE | Start from HROne's templates in every module | Pack `feedback-starter` holds Workforce templates only (exit interview, probation, induction) | PARTIAL | Add 360, 1-on-1, performance-review and event templates to the pack when XC-44 / XC-45 add their processes. |
| XC-43 | **[missed §03.3]** 360 settings on the template: "allow to calculate 360 overall rating" `isAllowToCalculateThreeSixtyOverallRating`, "select the scale on which you want to rate the employee" `threeSixtyRatingScale`, "allow to edit 360 overall rating" `isAllowToEditThreeSixtyOverallRating` | A 360 form produces an overall rating on a chosen scale, which the reviewer may change | No 360 rounds (HP-8h pending; SV-12) | MISSING | Template fields `overall_rating` (bool, default false), `rating_scale_id` (`RatingScale`), `overall_editable` (bool); read by the SV-12 rounds. |
| XC-44 | **[missed §03.3]** Performance processes that pick a template: 360 per relation (manager, functional manager, peer, subordinate, client: `managerFeedbackId`, `peerFeedbackId` … in `app-policy360-step-one`), performance review (`isAllowMapFeedbackTemplate` in `app-goal-policy-step1`), goal template (`app-goal-feedback-template`), 1-on-1 feedback card | Each performance process asks its own question set | Only `goal_review` (HP-8e); 1-on-1 feedback is a fixed rating + comment (`OneOnOneFeedback`) | PARTIAL | Processes `feedback_360` (one template per relation, set on the SV-12 policy), `performance_review` (SV-11 policy) and `one_on_one` (optional; blank = today's rating + comment) in `peopleos.feedback.processes`, module `performance`. |
| XC-45 | **[missed §03.3]** Engage processes: "Pre-event feedback" and "Post-event feedback" templates on an event (`app-event-reward`) | Feedback before and after an engagement event | No events module (surveys and pulse only); `peopleos.feedback.modules` has no `engage` | MISSING | Module `engage` with processes `event_pre`, `event_post`, added together with an events feature (not planned yet); until then surveys remain the tool. |

#### Setup → Governance → Subscriptions → Subscriptions (`app-subscriptions-dashboard`, `app-plan`, `app-plan-detail`, `app-modify-plan`, `app-modify-user`, `app-view-subscribed-products`, `app-module-selection`; actions View, Add user)

Matrix §04.9 compares HROne's own billing in one row per page. The pages hold the elements below. PeopleOS keeps
plans, entitlements, subscriptions and invoices operator-side (`PlatformPlansPage`, `PlatformEntitlementsPage`,
`PlatformSubscriptionsPage`, `PlatformInvoicesPage`; SaaS.4–SaaS.7); the tenant sees only the subscription state on
`GettingStarted`. Prices and payments stay out of the tenant view until the SaaS.7 release decisions (B-4, B-7, B-8,
B-9) are made.

| # | HROne element | What it does | PeopleOS today | Status | Build note |
|---|---|---|---|---|---|
| XC-46 | **[changed §04.9]** Dashboard "Your subscriptions": Total users · Active users · Remaining users | Licence use at a glance | Limits evaluated operator-side (`PlanValues`, limit capabilities); planned tenant card 13 CH-1 | PARTIAL | Same card as CH-1 (SV-19): active users and employees against the plan's limits, read-only. |
| XC-47 | **[missed §04.9]** Total / active modules, add-ons, integrations; "Subscribed products" lists (modules, user add-ons with users, credit add-ons with credits); "Modification in Core HR will reflect across all modules" | What the tenant has switched on | `TenantEntitlement` capabilities and features per plan version, operator-only | PARTIAL | The CH-1 card lists the enabled capabilities and features by name; credit add-ons are N-A (no resold credits, §04.9). |
| XC-48 | **[missed §04.9]** "Upcoming renewals", "User distribution — View all", "Recent activity" | Renewal dates, users per module, subscription changes | `TenantSubscription` period end (SaaS.6) operator-only; users by role exist (`RolesByPerson`); plan changes are audited operator-side | PARTIAL | Card shows the current period end; "users by role" link to `RolesByPerson`; recent plan changes from the tenant's own audit events only (no operator notes). |
| XC-49 | **[missed §04.9]** Promotion banner (first-time subscription discount), "Add Product", "Unlock your HR potential" | Upsell inside the product | No in-product purchasing | N-A | Commercial decision outside HCM code. |
| XC-50 | **[changed §04.9 "Add users"]** "Add Users" / "Modify user counts for your active modules" (price per user, current users, add new user `userCount`, `productPrice`, `price`, total updated user count); "Modify Current Plan" (subscription cycle, add or subtract product users `proNumberOfUser` and add-on users `addOnNumberOfUser`; "contact HR-One support to add or subtract product or add on") | Self-service change of licence counts | None for the tenant; P15 plans a request to Markedge | PARTIAL | "Request a change" (number of users, modules) raising a platform request (`PlatformApprovalsPage` queue), no prices; self-service purchase only after the SaaS.7 release decisions. |
| XC-51 | **[missed §04.9]** "Current Plan" / "Upcoming subscription"; Payment details (last paid amount, next billing date, payment method, billing address); Payment history (transaction ID, type, plan name, transaction date, invoice amount, paid amount) | Plan, renewal and payments seen by the customer | Operator-side invoices and payments (`app/Domain/Billing`, `app/Domain/Payments`) | PARTIAL | Read-only tenant view with P15 once real invoicing is released (B-4 / B-7 / B-8 / B-9). Blocked until then. |
| XC-52 | **[missed §04.9]** "Module selection — select the modules you want to configure from the preconfigured setup": modules, payroll financial year, time-office leave cycle start / end month, "You won't be able to return to this page" *(placement inferred: first-run set-up)* | Choose modules and their basic calendars once, from preconfigured defaults | `GettingStarted` (first-run guide, read-only), configuration packs, `leave.year_start_month`; payroll year follows the statutory financial year | PARTIAL | A "Choose your modules" step on `GettingStarted` that applies the chosen configuration packs and the leave-year start; repeatable (PeopleOS keeps everything editable later, unlike HROne's one-time page). |

#### Setup → Integration → API key management: webhooks and key details (`app-webhook`, `app-webhook-form` → `-step-basic`, `-step-events`, `-step-review`; `app-webhook-logs`, `app-log-view-detail`, `app-webhook-failed-deliveries`, `app-webhook-test`, `app-webhook-view`; `app-api-key-auth-details`, `app-api-key-logs-details`, `app-api-key-overview`; actions View api key, Edit, Create api key, Regenerate api key, Delete api key, View logs)

Matrix §6.2 covers the API key list, scopes, IP list, rate limit, logs and usage dashboard at row level; 13 IN-1
covers the webhook "basic" step. PeopleOS: `WebhookEndpointResource` (name, URL, events, last delivered, failures,
status; "Send test"; Edit), `DeliveriesRelationManager` (created, event, status, attempts, HTTP code, next attempt,
delivered; status filter; payload; Retry now; Replay of dead letters via `Webhooks::replay`), signed deliveries
(`X-PeopleOS-Signature`, HMAC); `ApiKeyResource` (name, prefix, scopes, last used, expiry, status, creator; Revoke
with reason; secret shown once).

| # | HROne element | What it does | PeopleOS today | Status | Build note |
|---|---|---|---|---|---|
| XC-53 | **[missed §6.2]** Webhook list: "Add Webhook", search, "Filter by state", card with Target URL and **Success rate**, actions View, Edit, Test, Export | Manage outbound webhooks and see their health | List with failures count, last delivery, status; Send test; Edit | PARTIAL → built (SV-26) | Success-rate column (delivered ÷ attempted over 30 days from `webhook_deliveries`) and a status filter. S. Built: "Success rate (30 days)" column (delivered ÷ attempted among deliveries created in the last 30 days) and a status filter on `WebhookEndpointResource`; "All deliveries" link. |
| XC-54 | **[missed §6.2]** Step "Events": "Select events on which you want to receive alerts", search events, toggle per event | Event subscription | `CheckboxList` of `peopleos.enterprise.webhook_events`, bulk toggle | HAVE | Add a search box when the list grows. |
| XC-55 | **[missed §6.2]** Step "Review": basic information, events, custom headers, authentication summary; "Security note: We will generate a unique Secret key for this webhook. You MUST verify the X-Webhook-Signature header" | Confirm before saving; signature advice | Secret generated on create; helper text names `X-PeopleOS-Signature` (HMAC-SHA256 of timestamp.body) | HAVE | Auth and header summary come with IN-1 (SV-17). |
| XC-56 | **[missed §6.2]** "Logs" page across webhooks: filters Webhook name, Event name, Status, date range; columns Webhook name, Event name, Target URL, Status, Time stamp, State, **Latency**; "Export webhook log to Excel" (date range, background export) | Monitor all deliveries in one place | Deliveries per endpoint only; no duration; no export | PARTIAL → built (SV-26) | `webhook_deliveries.duration_ms`; page "Webhook deliveries" across endpoints (filters endpoint, event, status, dates) with a queued CSV export (audited). Built: `webhook_deliveries.duration_ms`; Enterprise → Webhook deliveries (`WebhookDeliveryResource`: webhook, target (scheme and host), event, status, attempts, HTTP, latency, acknowledged; filters webhook, event, status, acknowledged, dates); "Export CSV" queued (`IntegrationLogExports`, job `ExportIntegrationLog`; EXPORT audited, no payloads), downloaded from Integrations → Log exports by the requester only (DOWNLOAD audited). |
| XC-57 | **[missed §6.2]** Log detail (`app-log-view-detail`): webhook name, event type, delivery history, request (method, target, payload size, retry attempt, payload), response (status code, response time, response) | Diagnose one delivery attempt by attempt | Payload modal; last `response_code`, `response_excerpt`; `attempts` count | PARTIAL → built (SV-26) | Table `webhook_delivery_attempts` (delivery, attempt, status code, duration_ms, response excerpt, attempted_at), shown in the detail modal; retention as deliveries. Built: `webhook_delivery_attempts` (attempt, status code, duration_ms, response excerpt, signed_with_previous, attempted_at; deleted with the delivery); the "Details" modal shows the delivery history, payload, payload size and last response. |
| XC-58 | **[missed §6.2]** "Failed deliveries": search / filter by webhook, "Error breakdown", select all, bulk **Acknowledge** / **Requeue**, per-row Replay | Clear or resend failures in bulk | Dead-letter state; Replay one at a time (audited) | PARTIAL → built (SV-26) | Bulk Replay; Acknowledge (`acknowledged_at`, `acknowledged_by`; acknowledged dead letters leave the failure count); breakdown by HTTP status. Built: "Failed deliveries" tab (unacknowledged dead letters), "Error breakdown" (by HTTP status, "No response" for transport errors), bulk "Replay selected" (one audit operation) and "Acknowledge selected" / per-row Acknowledge (`acknowledged_at`, `acknowledged_by`, decrements `failure_count`, WEBHOOK_ACKNOWLEDGED audited); a replay clears the acknowledgement. |
| XC-59 | **[missed §6.2]** "Event test — Test webhook deliveries with simulated events": Trigger event (event type), webhook name, target endpoint, session delivery result | Send a sample of a chosen event | "Send test" posts a fixed `ping` event and reports the HTTP code | PARTIAL → built (SV-26) | Event-type select on the test action; payload = the event's sample from a catalogue of sample payloads (no real data). Built: "Send test" asks for the event (default ping, as before); payload from `WebhookSamples` (placeholder ids / SAMPLE- codes, `"sample": true`, envelope `"test": true`); `Webhooks::sendTest()`. |
| XC-60 | **[missed §6.2]** Secret key: view, copy, "Regenerate key"; "Rotate key" with "Old key grace period" ("A new key will be generated while the existing key remains valid for a defined transition period") | Change the signing secret without breaking the receiver | Secret set at creation (audit-sensitive); no rotation | MISSING → built (SV-26) | "Rotate secret": new secret shown once; `previous_secret` (encrypted) + `previous_secret_expires_at` (grace 1–72 h); during the grace period deliveries carry a second signature header; audited. Built: "Rotate secret" (list and edit page): `Webhooks::rotateSecret()` with a 1–72 h grace period, new secret shown once in the notification, previous secret encrypted at rest, hidden, excluded from the audit trail and the export (`DataInventory::SECRET_COLUMNS`), signing `X-PeopleOS-Signature-Previous` only until it expires, then cleared (`expirePreviousSecrets` each delivery run and in the retention purge); audited as SIGNING_SECRET_ROTATED with the reason. Editing the secret by hand still replaces it at once (no grace). |
| XC-61 | **[missed §6.2]** Webhook view page: Edit webhook, Delivery history with export, Metadata (Created on, Last modified) | One page per webhook | Edit page with the Deliveries tab; timestamps stored | HAVE | Export comes with XC-56. |
| XC-62 | **[changed §6.2]** API key "Auth" tab: key with Copy, **Regenerate** ("will immediately revoke your current api key and replace with the new one"), Scopes, **Delete API Key** (irreversible), Edit | Replace or remove a key | Secret shown once at creation; Revoke with reason; scopes fixed after creation | PARTIAL → built (SV-26) | "Regenerate" = revoke and issue with the same name and scopes in one audited step (new secret shown once); keep Revoke instead of Delete so the audit trail stays; scope edits audited. With the CA-11 rebuild. Built: `ApiKeys::regenerate()` (row lock; old key revoked with "Regenerated: <reason>", new key with the same name, description, scopes, expiry, IP allowlist and rate limit, `regenerated_from_id`; API_KEY_REGENERATED audited; new secret shown once); Edit of name, description, scopes, expiry, IPs and rate limit (`ApiKeys::update`, audited with the reason); Revoke kept, no Delete. |
| XC-63 | **[changed §6.2 "Details → Logs; Usage dashboard"]** Logs tab with sub-tabs **API Calls** and **Security**: filters date range, Method, HTTP status code, request URL, Action type; columns Date, User, Action, API key, HTTP status code, Method, Request URL, Response status; "Export API call logs (CSV)"; Overview chart of API calls by date | Who called what, and key lifecycle events | Only `last_used_at`; key issue and revoke are audited | MISSING → built (CA-11, SV-26) | §6.2's `api_request_logs` plus: Security tab = the key's audit events (created, regenerated, revoked); CSV export; overview chart from the same table. With the CA-11 rebuild. Built with CA-11: key page tabs "API calls" (`api_request_logs`: date, method, request URL, HTTP status, duration, IP, action / user agent; filters dates, method, outcome, status code; queued CSV export) and "Security" (the key's audit events: issued, changed, regenerated, revoked, call-log exported; user and reason), usage figures and a 30-day chart (`ApiKeyUsageChart`). Read with the new `api_key.view`. |

#### Setup → Integration → Journal voucher → Journal voucher (`app-journal-voucher`, `app-journal-voucher-form` → `app-basic-info`, `app-connection-details`, `app-gl-code`, `app-gl-code-mapped`, `app-report-format`, `app-review`; `app-export-jv`; `app-view-jv-log`, `-detail`, `-filter`; action Download jv)

Matrix §6.5 has one summary row for the six-step form and one for the export dialog; CA-13 plans the build. PeopleOS
has no journal vouchers; cost centres exist (`cost_centres`, on departments and positions) and the Integration Hub
has systems of kind `finance` with a settings json.

| # | HROne element | What it does | PeopleOS today | Status | Build note |
|---|---|---|---|---|---|
| XC-64 | **[changed §6.5]** List: JV cards with status, filters Journal Voucher and Status, "Assigned Employees", Export JV, View log, "No Journal Vouchers found" | Manage JV definitions | — | MISSING | `JournalVoucherDefinitionResource` (Payroll → Journal vouchers) as CA-13; "Assigned employees" = employees in the definition's scope. |
| XC-65 | **[changed §6.5]** Basic info: JV name `jvName`; "What type of JV would you like to create?" `jvTypeId` and Sub type `jvSubTypeId` (options include Report level / Receipt level; All / Only reimbursable / Only non-reimbursable; Only hold *(meaning inferred)*); "post debit/credit or total (debit+credit)?" — Debit/Credit · Total · Pivot `amountPostingFormat`; Group by — Employee / OU `jvGroupBy`; "mandatory filters for data posting" incl. Cost Distribution Yes / No `costOuKey` | What the voucher is built from and how lines are summed | — | MISSING | Definition columns `source` (payroll / expense), `source_filter` (expense report or receipt level; reimbursable or not; held salary), `posting_format` (dr_cr / net / pivot), `group_by` (employee or dimension list), `split_by_cost_allocation` (bool). |
| XC-66 | **[changed §6.5]** Connection details: "configure default connection details?" `isDefaultConnection`; File name `fileName` with tags; connection `jvConnectionId`: SAP Business One (server type, SQL version, HANA port, database name / user / password, serial code, SAP server / user / password), SAP S/4HANA on-premise RFC (host, system number, user, password, client, language, BAPI and BAPI-commit function), Oracle, FTP (host, port, user, password, folder), REST / SOAP API (URL, type, headers, sample payload, process name, authorization Basic / Bearer / Access key / Token generation with token URL and headers / Cookie, background processing, unique key, employee-wise posting `isEmployeeWisePosting`, posting based on `postingBasedOn`, notification recipients `postingStatusEmails`) | Push the voucher straight into the ERP | — | MISSING (EXT) | v1 = file only (CSV / XLSX in the XC-68 format, file name with tags). ERP posting through a `JournalPoster` contract with adapters per ERP (EXT, owner decision on which); credentials encrypted on the Integration Hub system, audit-excluded; posting result as events `payroll.journal_posted` / `payroll.journal_failed` for notification rules. |
| XC-67 | **[changed §6.5]** GL code grid per pay component (`app-gl-code`) and the mapped list (`app-gl-code-mapped`): Pay component, Type, GL code `mappedGlCode`, Posting key `postingKey`, Sequence, Component description, Component category, "Pay component in ERP" `mapKeyName`, Allow cost distribution, Group by `journalVoucherComponentGroupby`; Import / Export | Map each pay component to a ledger and posting key | — | MISSING | `journal_voucher_lines` (definition, salary component or expense category, side debit / credit, gl_code, posting_key, erp_code, description, category, sort, group_by, allow_cost_split) + spreadsheet import / export through `RowImportTypes`. |
| XC-68 | **[changed §6.5]** Report format (`app-report-format`): Fixed columns, Dynamic columns, "Additional Columns" (column name, column value with tags), "Column Sequence — arrange the order" | Layout of the voucher file | — | MISSING | `report_format` json on the definition (fixed columns, added columns with `{{tag}}` values from the line context, order); a preview with one month's totals. |
| XC-69 | **[changed §6.5 "Export JV"]** "JV posting status — Already posted / Not yet posted"; "Repost all employees" / "Repost new employees only"; "I have reviewed and confirm the above changes are correct"; REPORT VIEW preview with Credit and Debit totals; Download / Post | Post once per period, repost safely, check the balance | — | MISSING | `journal_voucher_runs` (definition, period, status, debit and credit totals, posted_by, posted_at) + run lines per employee; a repost creates a new run that supersedes the old one (never edits a posted run); posting refused when debit ≠ credit. Only finalized payroll runs are read. |
| XC-70 | **[missed §6.5]** JV log (`app-view-jv-log`): filters Status (In progress, Success, Partial success, Failed, Error), Month, Year, From / To date; columns Created at, Posted by, Background process, Status, Total count, Total success, Total failed; "View filter" (the filters used) | History of postings | — | MISSING | List of `journal_voucher_runs` with the filters stored on the run (`filters` json) and the counts. |
| XC-71 | **[missed §6.5]** JV log detail (`app-view-jv-log-detail`): delivery history, created at, request time, response time, method, target, request payload (download), status code, response, error message, search by value, status filter | Diagnose one posting call | — | MISSING | Per-call records for API posting (request and response excerpts, payload stored encrypted with a retention limit), visible to holders of the JV permission; file-only runs have none. |

### 3. Statuses in earlier matrices that are out of date

Found while mapping the nodes; the nodes stay "Covered", but these rows were written before the expense and asset
builds (EX-A … EX-G, AS-B) and now understate PeopleOS. Correct them when the matrices are next revised.

| Matrix row | Says | PeopleOS now | Status now |
|---|---|---|---|
| 02 §02.7 "Expense (10)" | No expense module · MISSING | 25 `expense.*` events in the notifications configuration (reports, advances, travel; EX-C, EX-D, EX-F); the per-module notification catalogue is still P6 | PARTIAL |
| 02 §02.7 "Asset (3)" | Asset request and acknowledgement come with HP-10 · PARTIAL | `asset.requested`, `asset.request_*`, `asset.return_requested`, `asset.acknowledgement_due` exist (AS-B); only the catalogue (P6) is open | PARTIAL (reason changed: events done) |
| 02 §02.10 "Expense tasks: Dispense advance, Travel agent" | Comes with HP-4 · MISSING | Advances are dispensed by `expense.pay` holders (EX-D); company travel legs become helpdesk booking requests (EX-F); no system-task catalogue entries (P7) | PARTIAL |
| 02 §03.2 "Expense advance / Travel / Expense report / Expense receipt codes" | Comes with HP-4 · MISSING | `NumberSeries` keys `expense_receipt`, `expense_report`, `expense_advance`, `travel_request` (`ExpenseReceipts`, `ExpenseReports`, `ExpenseAdvances`, `TravelRequests`) | HAVE |
| 01 §5.3 "Expense view" (CXO access) | No expense module (HP-4) · MISSING | Expense reports and datasets exist (EX-G); no CXO preset view with applicability and e-mail share | PARTIAL |
| 01 §5.4 "Expense events: Travel plan, Expense request" (Calendar access) | No expense module (HP-4) · MISSING | Travel requests and expense reports carry dates; no team calendar shows them (same position as the time-office events row) | PARTIAL |

### 4. Counts (permission-tree cross-check)

Nodes (the root and 239 nodes of the Setup branch):

| Group | Nodes | Covered | Partly covered | Not covered | N-A (covered) |
|---|---|---|---|---|---|
| Setup (root) | 1 | 1 | 0 | 0 | 0 |
| Core HR | 41 | 39 | 2 | 0 | 0 |
| Automation | 35 | 31 | 1 | 0 | 3 |
| Forms & templates | 17 | 10 | 5 | 0 | 2 |
| Governance | 17 | 14 | 1 | 0 | 2 |
| Access rights | 7 | 7 | 0 | 0 | 0 |
| Integration | 14 | 11 | 2 | 0 | 1 |
| Recruitment | 15 | 0 | 0 | 0 | 15 |
| Workforce | 10 | 10 | 0 | 0 | 0 |
| Time office | 14 | 14 | 0 | 0 | 0 |
| Payroll | 11 | 11 | 0 | 0 | 0 |
| Expense | 10 | 10 | 0 | 0 | 0 |
| Performance | 25 | 25 | 0 | 0 | 0 |
| Engage | 10 | 10 | 0 | 0 | 0 |
| Asset | 8 | 8 | 0 | 0 | 0 |
| Helpdesk | 5 | 5 | 0 | 0 | 0 |
| **Total** | **240** | **206** | **11** | **0** | **23** |

New rows (XC-1 … XC-71):

| HROne path | Rows | HAVE | PARTIAL | MISSING | N-A |
|---|---|---|---|---|---|
| Core HR → Employee → Relationship | 6 | 3 | 2 | 1 | 0 |
| Core HR → Payment bank | 9 | 2 | 3 | 4 | 0 |
| Automation → Workflow (shared form) | 7 | 4 | 2 | 0 | 1 |
| Forms & templates → Checklist | 14 | 4 | 5 | 5 | 0 |
| Forms & templates → Feedback | 9 | 1 | 5 | 3 | 0 |
| Governance → Subscriptions → Subscriptions | 7 | 0 | 6 | 0 | 1 |
| Integration → API key management (webhooks, key details) | 11 | 3 | 6 | 2 | 0 |
| Integration → Journal voucher → Journal voucher | 8 | 0 | 0 | 8 | 0 |
| **Total** | **71** | **17** | **29** | **23** | **2** |

By tag: 56 **missed**, 15 **changed**. MISSING includes 3 rows marked EXT (payout provider, bank-API credentials, ERP
connectors). With the 108 rows above, this file now holds 179 rows.

### 5. Biggest gaps (permission-tree cross-check)

1. **Journal voucher** has only a summary row in 01 §6.5: the definition (source, posting format, grouping, cost
   split), the GL mapping per component, the report format, posting runs with repost and balance check, and the
   posting log are all MISSING (XC-64 … XC-71); ERP connectors are EXT.
2. **Salary-release controls on the payment bank**: no OTP / second factor before salary is released, no online
   payout channel, provider or bank-API credentials, no BSR / MICR / IBAN or branch address (XC-10 … XC-15).
3. **Checklist tasks**: no task dependencies, links or reference files, no "mandatory before joining / settlement"
   gates, no auto-complete or sub-statuses, no clone or standard library (XC-23 … XC-36).
4. **Feedback templates**: flat question lists without sections, help text or scored options; no 360 overall rating;
   no performance (360, review, 1-on-1) or engage (event) processes (XC-37 … XC-45).
5. **Webhook operations**: no cross-endpoint delivery log with latency and export, no attempt history, no bulk
   handling of failures, test only with a fixed ping, no secret rotation; API keys cannot be regenerated and have no
   call log (XC-53 … XC-63).
6. **Relationship master** has no per-section switches, so nominees can be any relation and emergency-contact
   relations are free text (XC-2, XC-5).
7. **Workflow form**: one trigger per workflow and automatic decisions on the last step only (XC-16, XC-20).
8. **Tenant subscription view**: licence use, renewals and plan changes are operator-only; payments wait for the
   SaaS.7 release decisions (XC-46 … XC-52).
9. Six rows of earlier matrices understate the expense and asset builds (§3).

### 6. Build packages (permission-tree cross-check, ordered)

Additive; defaults reproduce today's behaviour; new permissions declared in `config/peopleos.php` then
`php artisan peopleos:sync-permissions`; organisation switches as Setup-centre settings; no statutory values in
settings.

| # | Package | Scope (rows it closes) | Files likely touched | Depends on | Size (S/M/L) |
|---|---|---|---|---|---|
| SV-20 | Relationship sections and nominee eligibility | XC-2, XC-4 (switch stored; effect with WF-10), XC-5 | `option_lists.applies_to` migration, `OptionLists` (section filter), `OptionListResource`, `ChangeFamilyMemberAction`, `FamilyMembersRelationManager` (nominee toggle), emergency-contact form and `EmergencyContactChange` | CA-3 (done); WF-10 for the insurance effect | S |
| SV-21 | Payment bank depth and salary-release OTP | XC-7, XC-10, XC-13, XC-14, XC-15; seam for XC-11, XC-12 | `company_bank_accounts` migration (`transfer_channel`, `bsr_code`, `micr`, `iban`, `branch_city`, `branch_address`, `release_requires_otp`, `release_otp_user_ids`, `payout_provider`, `transaction_type`, `payout_credentials` encrypted and audit-excluded), `CompanyBankAccountResource` (filters), `PayrollRuns::markPaid` and the bank-file download (fresh `MultiFactor` code), TDS challan BSR default, `PayoutProvider` contract without an adapter | BP-13 (done); payout provider and bank API = owner decision (EXT) | M |
| SV-22 | Workflow form parity | XC-16, XC-20 | `workflows.additional_triggers` migration, `WorkflowResource`, trigger dispatch, `WorkflowDefinition` publish check, setting `workflow.auto_decide_any_level` (`config/peopleos.php` → `settings`, `config/peopleos_setup.php` automation) | P5 (done); owner decision for XC-20 | S |
| SV-23 | Checklist task depth | XC-23, XC-25, XC-26, XC-28, XC-29, XC-30, XC-32, XC-33, XC-34, XC-35 | `onboarding_template_items` / `onboarding_tasks` columns (`link_url`, `link_kind`, `attachment_path`, `depends_on`, `owner_department_id`, `blocks_joining`, `auto_complete_on_due`, `sub_status`), `onboarding_templates.sub_statuses`, `clearance_checklists` item keys and `sub_statuses`, `Onboarding`, `LifecycleEngine` (joining guard), `FinalSettlements::approve` + setting `exit.settlement_requires_clearance` (default false), daily lifecycle job, Clone actions, pack `checklist-starter`, `DataInventory` | P13, WF-2 (done); SV-6 for transfer checklists | M |
| SV-24 | Feedback template structure and Performance / Engage processes | XC-37, XC-39, XC-40, XC-41, XC-42, XC-43, XC-44; XC-45 when an events feature exists | `feedback_templates.sections` migration, question keys `section`, `help`, option `score`, `FeedbackTemplateResource` (Clone), `FeedbackTemplates`, `QuestionBankResource` (type filter), `peopleos.feedback.processes` (`feedback_360`, `performance_review`, `one_on_one`), `OneOnOnes` (optional template), template fields `overall_rating`, `rating_scale_id`, `overall_editable`, pack `feedback-starter` | P13 (done); SV-11 and SV-12 for XC-43 / XC-44 | M |
| SV-25 | Tenant subscription view | XC-46, XC-47, XC-48, XC-50, XC-52; XC-51 after the release decisions | Read-only plan card (with CH-1) on `GettingStarted` / `AdminCentre`, entitlement read service, "Request a change" into the platform request queue (`PlatformApprovalsPage`), "Choose your modules" step applying configuration packs and `leave.year_start_month` | SV-19 (CH-1); P15; XC-51 Blocked by SaaS.7 release decisions B-4 / B-7 / B-8 / B-9 | M |
| SV-26 | Webhook operations and API key lifecycle (**built**, with CA-11) | XC-53, XC-56, XC-57, XC-58, XC-59, XC-60, XC-62, XC-63 | `webhook_deliveries` (`duration_ms`, `acknowledged_at`, `acknowledged_by`), `webhook_delivery_attempts`, `webhook_endpoints` (`previous_secret` encrypted, `previous_secret_expires_at`), `Webhooks` (timing, second signature in the grace period, bulk replay), "Webhook deliveries" page with queued CSV export, sample-payload catalogue for the event test, `ApiKeys::regenerate`, `api_request_logs` (§6.2) with a Security tab from audit events | CA-11 rebuild; SV-17 (webhook auth) | M |
| SV-27 | Journal voucher definitions, file export and posting runs | XC-64 … XC-71; seam only for the ERP connectors of XC-66 | Details CA-13: `journal_voucher_definitions` (+ `source`, `source_filter`, `posting_format`, `group_by`, `split_by_cost_allocation`, `report_format`), `journal_voucher_lines` (+ spreadsheet import / export via `RowImportTypes`), `journal_voucher_runs` and run lines (filters, counts, debit / credit totals; repost supersedes, never edits), `JournalVouchers` builder over finalized payroll runs, `JournalPoster` contract without an adapter, events `payroll.journal_posted` / `payroll.journal_failed`, new permissions → sync | CA-13 (pending; this package replaces its field list); ERP adapters EXT | L |
