# HROne Setup → PeopleOS configuration gaps: 01 Core HR, 05 Access rights, 06 Integration

Prepared 2026-10-28, read-only. HROne side: `spec/01_Core_HR.md`, `spec/05_Access_rights.md`, `spec/06_Integration.md`, plus `spec/common-settings-core.md` (Timesheet settings panel), checked against the page text captures in `hrone/setup/*.txt` and the captured setup API responses in `hrone/dump/setup-api.json` (field names only; no values copied). PeopleOS side: the code on branch `feature/oct_22_salary_module` (working tree, including uncommitted files): migrations, models, Filament resources/pages, `config/peopleos.php`, `config/peopleos_setup.php`, `app/Domain/Configuration/Services/SetupCatalogue.php`. The working tree changed during this analysis (HS / TO-2 check-in controls: polygon geo locations, policy-level Wi-Fi and IP checks, `docs/architecture/hrone-parity.md` § "HROne setup parity"); §1.17 reflects that state.

**Status key.** HAVE = PeopleOS lets an admin configure the same thing and code uses it. PARTIAL = something equivalent exists but narrower, elsewhere, or not configurable. MISSING = nothing equivalent. N-A = deliberately out of scope (reason given).

**How build notes are written.** They follow the HROne-parity rules in `docs/architecture/hrone-parity.md`: additive; defaults reproduce today's behaviour; tenant settings get a default in `config/peopleos.php` → `settings` and an entry in `config/peopleos_setup.php` (the Setup centre lists only keys that code reads); per-group rules go into a Policy Engine type (`peopleos.policies.types`); new masters are tenant-scoped (`BelongsToTenant` + `Auditable`), classified in `App\Domain\Offboarding\Support\DataInventory`, guarded by a policy, with permission keys in `config/peopleos.php` → `permissions` (then `php artisan peopleos:sync-permissions`). Existing gap IDs from `docs/hrone-parity/HROne-Gap-Register.md` are cited where they apply.

**Shared "Settings" flyer.** `<app-common-settings>` (Delete transaction, Time office, Task, Payroll, Helpdesk, Timesheet, Engage points, Performance, Asset, Expense panels) is embedded on Core HR → Employee / Profile access / Company / Designation and Access rights → Page access. The coordinator split it per module (`spec/common-settings-*.md`); it is analysed by the owners of those files. This file covers only its **Timesheet** panel (§1.35). See §1.37 for the hand-off list.

---

## 01 Core HR

**Progress (2026-10-29, HS-CA-1 … CA-4 built; CA-6 built — §1.21–1.22 closed; `docs/architecture/hrone-parity.md` § "Core HR → Setup plumbing, organisation
fields, employee masters, document rules").** Closed: Enterprise industry, website, logo and contacts; company industry,
phone, fax, contact person, GSTIN, social links, theme colour / switcher, logo; sub-departments; designation experience,
job description, hazardous, wage skill category, primary skill; every document-type rule; qualifications and
specialisations; blacklist (rehire-block) reasons; skill proficiency scale; separation reasons; relationships; plus the
Setup types `matrix` / `file`, the `articles` / `skill_scales` / `dashboards` / `industries` option sources and an
Access rights module. Open in rows 1.1–1.28: identifier 1 / 2 (purpose to confirm), billing contact read-only (billing
stays outside HCM code by architecture rule), login-screen customisation (needs a tenant-aware login URL), company address
city (CA-7), data privacy acceptance (the `articles` source is ready; the enforcement in `EnforceAccountSecurity` is not
built), §1.9–1.17 (CA-7 / CA-8 / CA-12); logo on letters
and payslips. §1.37 is a hand-off list with nothing to build here. `options_from` `projects` waits for CA-17.

### 1.1 Organization → Enterprise (`app-enterprise`, `app-contact-details-flyer`, `app-customize-flyer`)

| HROne setting (label · control · options) | PeopleOS today | Status | Build note |
|---|---|---|---|
| Enterprise name · `enterpriseName` | `tenants.name` (set by the platform at provisioning) + setting `branding.display_name` (Setup → Core HR, `config/peopleos_setup.php`), read by `App\Domain\People\Services\Birthdays` | HAVE | — |
| Nature of industry · `natureOfIndustry` (text) | Industry exists only per establishment: `establishments.industry` (vocabulary `peopleos.organisation.industries`, `EstablishmentResource`) | PARTIAL → built (HS-CA-2) | Setting `organisation.industry` (select, `options` = `peopleos.organisation.industries`, default `null`); used as the default for new establishments and companies (§1.2). |
| Website · `website` | — | MISSING → built (HS-CA-2) | Setting `branding.website` (text, max 255, default `null`); expose as letter variable `{organisation.website}` in the letter variables guide (`app/Domain/Letters`). |
| Identifier 1 / Identifier 2 · `organizationKeyOne`, `organizationKeyTwo` (selects; options not captured — appear to pick the employee attributes that identify a person, e.g. at sign-in) | Sign-in is by `users.email` only; employee code is searchable (`PeopleVisibility::search`) | PARTIAL | Purpose not certain from the capture. If they are sign-in identifiers: setting `identity.login_identifiers` (multiselect `email`, `employee_code`; default `['email']`) resolved in a custom Filament login page to the user via `employees.employee_code` — needs a tenant-identifying login URL (see Login screen row). Confirm with a live HROne screen before building. |
| Studio organisation id · `accessOrganisationId` (text, 500) | External ids live in the Integration Hub (`external_references`, `IntegrationSystemResource`) | N-A | Link id to an HROne partner product; PeopleOS keeps any such id as an Integration Hub external reference. |
| "Go live" (subscription starts today) | Platform → Subscriptions (`PlatformSubscriptionsPage`, operator side) | N-A | HROne billing action; PeopleOS subscriptions are operator-managed (SaaS.6). |
| Logo upload (max 5 MB, `logoFile`) | — (no tenant/company logo; panel brand is fixed in `app/Providers/Filament/AdminPanelProvider.php`) | MISSING → built (HS-CA-2) | Setting `branding.logo_path` (new Setup type `file`, image ≤ 5 MB on the documents disk, default `null` = PeopleOS mark); `AdminPanelProvider` `brandLogo()` closure reads it per tenant; payslip/letter PDFs use it when present. Per-company logo: `companies.logo_path` (§1.2). Built: workspace brand shows it; letters keep the client's Word letterhead and payslips have no PDF, so neither prints it yet. |
| Contact details · billing contact (`billingContactName/Email/MobileNumber/Designation`) | Billing contact is held operator-side on the billing profile (`database/migrations/2026_10_25_100001_create_billing_tax_payment_tables.php`: `billing_email`, `contact_name`) | PARTIAL | Show it read-only in the tenant (Core HR → Enterprise) from the billing profile; changes stay an operator action. |
| Contact details · HR, decision-maker, technical-admin contacts (`humanResource*`, `decisionMaker*`, `technicalAdmin*`: name, email, mobile, designation), per company (`companies`, "same contact for all") | — | MISSING → built (HS-CA-2) | Master `organisation_contacts` (company_id nullable = all companies, `type` hr\|decision_maker\|technical_admin, name, email, mobile, designation, status) + `OrganisationContactResource` (Core HR links in `peopleos_setup.php`); `PlatformOperationsPage` lists them so operators know whom to notify (security / release notices go to technical_admin). |
| Login screen → Customize: heading `backgroundHeading`, sub-heading `backgroundSubheading`, colour, link, background image `enterprisePath`; Preview | — (one Filament login for all tenants; `branding.primary_colour` has a default in `config/peopleos.php` but no code reads it) | MISSING | Settings `branding.login_heading` (text 120), `branding.login_subheading` (text 240), `branding.login_colour` (text, hex), `branding.login_link` (text, URL), `branding.login_image` (file); all default `null` = today's login. Needs a tenant-aware login URL (`/login/{tenant-slug}`, `tenants.slug` exists) and a custom `Filament\Auth\Pages\Login` subclass that reads them. Wire `branding.primary_colour` at the same time. |

### 1.2 Organization → Company (`app-company`)

PeopleOS splits HROne's "company" across `companies` (`CompanyResource`), `legal_entities` (`LegalEntityResource`) and `establishments` (`EstablishmentResource`).

| HROne setting | PeopleOS today | Status | Build note |
|---|---|---|---|
| Company name · `companyName`; Status | `companies.name`, `legal_name`, `status` (`app/Filament/Resources/Companies/Schemas/CompanyForm.php`); `legal_entities.legal_name` | HAVE | — |
| Industry · `industryType` / `natureOfIndustry` | `establishments.industry` only | PARTIAL → built (HS-CA-2) | Column `companies.industry` (string 32, nullable, options `peopleos.organisation.industries`); `EstablishmentResource` defaults to the company's value. |
| Address · `companyAddress`, `pinCode`, `countryCode`, `stateCode`, `cityCode` | `companies.country_code`; registered address on the principal establishment (`establishments.address`, `state`, `district`, `postal_code`, `establishment_type = registered_office`) | PARTIAL | Keep the address on the establishment (no duplicate); add `establishments.city` (string 128) — or `city_id` once the cities list of §1.15 exists; CompanyInfolist shows the principal establishment's address. |
| Phone · `phone`; Fax · `fax` | — | MISSING → built (HS-CA-2) | `companies.phone` (string 32), `companies.fax` (string 32), nullable; letter variables `{company.phone}`. |
| Currency · `currencyCode` | `companies.currency` | HAVE | — |
| Contact person · `contactPerson` / `searchEmployee` (employee picker) | — | MISSING → built (HS-CA-2) | `companies.contact_employee_id` (FK employees, nullable) with an employee picker (`CreateEmployee::managerOptions()` pattern); letter variable. |
| GST numbers · `companyGoodsServiceTaxNumberList`, `goodsServiceTaxNumber` | `statutory_registrations` covers EPF/ESI/TAN/PAN/PT/LWF/S&E (`peopleos.compliance.registration_types`), not GST | MISSING → built (HS-CA-2) | Add registration type `gstin` (`authority` GST, `level` establishment, `state_required` true, `statute` null) to `peopleos.compliance.registration_types`; `StatutoryRegistrationResource` already masks/verifies numbers; validate the 15-character GSTIN and its state prefix. Needed later by vendors and expense invoices. |
| Social handles · `companySocialHandles`, `handleLink` (LinkedIn, Facebook, YouTube … URLs) | — | MISSING → built (HS-CA-2) | `companies.social_links` (json: linkedin, facebook, x, instagram, youtube; URL-validated); shown on the company card and directory. Low priority. |
| Theme + "employee may change" · `themeId`, `isEmployeeEditable` | Users set density in `app/Filament/Pages/Preferences.php`; light/dark from Filament; no company theme | PARTIAL → built (HS-CA-2) | Apply `branding.primary_colour` per tenant in `AdminPanelProvider` (`colors()` from settings at request time); setting `branding.employee_may_change_theme` (boolean, default `true`). Built: workspace colour is the new `branding.theme_colour`: `branding.primary_colour` was stored for every tenant at provisioning but never applied. |
| Data privacy · `isDataPrivacyCompany`, `isDataPrivacyEmployee` (privacy-policy file, view policy, DPA addendum) | Versioned HR articles with acknowledgement (`app/Domain/Knowledge/Models/ArticleRead.php`, `ArticleResource`) can carry a privacy policy; nothing forces acceptance | PARTIAL | Settings `privacy.policy_article_id` (select, new `options_from` `articles`, default `null`) and `privacy.require_employee_acknowledgement` (boolean, default `false`); when on, `app/Http/Middleware/EnforceAccountSecurity.php` sends an employee who has not acknowledged the current version to the article. HROne's DPA download is N-A (vendor contract; PeopleOS's DPA is platform legal). |
| Logo / document viewer (company logo) | — | MISSING → built (HS-CA-2) | `companies.logo_path` (image) used on letters and payslips of that company; falls back to `branding.logo_path`. Built: on the company card; not printed on letters / payslips (see Logo upload). |

### 1.3 Organization → Business Unit (`app-business-unit`)

| HROne setting | PeopleOS today | Status | Build note |
|---|---|---|---|
| Business unit name · `businessUnitName`; Status | `business_units.name`, `code`, `status`, effective dates (`BusinessUnitForm.php`) | HAVE | — |
| Company · `companyCode` (required) | `business_units.company_id` (optional) | HAVE | Optional in PeopleOS by design (a BU may span companies). |

### 1.4 Organization → Department (`app-department`)

| HROne setting | PeopleOS today | Status | Build note |
|---|---|---|---|
| Department name · `departmentName`; Status | `departments.name`, `code`, `company_id`, `cost_centre_id`, `status` (`DepartmentForm.php`) | HAVE | — |

### 1.5 Organization → Sub department (`app-sub-department`)

| HROne setting | PeopleOS today | Status | Build note |
|---|---|---|---|
| Sub-department name · `subDepartmentName`; Status | A department can sit under a department in the organisation tree (`peopleos.organisation.node_types.department.children` includes `department`; `OrganisationDesigner` page; `organisation_nodes.parent_id`) | PARTIAL → built (HS-CA-2) | See next row. |
| Parent department · `departmentCode` (required, one parent) | No parent field on `DepartmentForm`; `employee_positions` holds a single `department_id`; datasets and the rule engine see one department level | PARTIAL → built (HS-CA-2) | `departments.parent_department_id` (FK, nullable; kept in sync with the OrganisationNode on save) + "Parent department" select on `DepartmentForm`; datasets (`EmployeesDataset`, payroll/attendance datasets) add `department_parent`; `EmployeeRuleContext` adds `parent_department_id` so policies can target either level. Default null = today. Built: OrganisationNode sync both ways; datasets `department_parent`; rule field `parent_department_id`. |

### 1.6 Organization → Designation (`app-designation`)

| HROne setting | PeopleOS today | Status | Build note |
|---|---|---|---|
| Designation name · `designationName`; Status | `designations.name`, `code`, `status`, level, department, employment types (`DesignationForm.php`) | HAVE | — |
| Experience min / max · `designationMinExperience`, `designationMaxExperience` | Role requirements per designation carry `min_experience_years` (`app/Filament/Resources/RoleRequirements/RoleRequirementResource.php`, `RoleRequirementVersion`); no maximum | PARTIAL → built (HS-CA-2) | `designations.min_experience_years`, `max_experience_years` (decimal 4,1, nullable) shown on the designation card; or add `max_experience_years` to the role-requirement version — pick one owner (recommend designation, as HROne shows it on the card). Built: on the designation. |
| Salary min / max · `designationMinSalary`, `designationMaxSalary` | `compensation_ranges` (grade + optional designation, min/mid/max, currency, frequency, approval) — `CompensationRangeResource` | HAVE | — |
| Job function · `jobFamilyId` | `designations.job_family_id` | HAVE | — |
| Grade · `gradeId` | `designations.grade_id` | HAVE | — |
| Statutory skill · `statutorySkillId` (minimum-wage skill class) | — | MISSING → built (HS-CA-2) | `designations.wage_skill_category` (string 16: unskilled\|semi_skilled\|skilled\|highly_skilled, nullable), vocabulary `peopleos.compliance.wage_skill_categories`; feeds the minimum-wage check owned by the payroll settings analysis (`ensureMinimumWage`) and the wages register. Built: vocabulary only; the minimum-wage check itself belongs to the payroll settings work. |
| Job description · `jobDescriptionBodyWithHtml` (rich text); "Give me a job description…" (AI) | `designations.description` (plain textarea, 1000 chars) | PARTIAL → built (HS-CA-2) | `designations.job_description` (longText) edited with `RichEditor`; AI drafting only behind `ai.llm` (see §6.10). Built: AI drafting waits for CA-16. |
| Hazardous · `hazardousStatus` | — | MISSING → built (HS-CA-2) | `designations.is_hazardous` (boolean, default false); available to compliance registers (Factories Act hazardous processes) and reports. |
| Skills + proficiency · `designationSkillDetails`, `skillId`, `proficiencyId` | Role requirements: skills repeater with level on the skill's scale and `required` (`RoleRequirementResource.php`) | HAVE | — |
| Primary skill · `primarySkillId` | `required` flag on each skill line only | PARTIAL → built (HS-CA-2) | Add `is_primary` (one per version) to the role-requirement skill lines. |

### 1.7 Organization → Grade (`app-grade`)

| HROne setting | PeopleOS today | Status | Build note |
|---|---|---|---|
| Grade name · `gradeName`; Status | `grades.name`, `code`, `level_id`, `rank`, `status` (`GradeForm.php`) | HAVE | — |

### 1.8 Organization → Level (`app-level`)

| HROne setting | PeopleOS today | Status | Build note |
|---|---|---|---|
| Level name · `levelName`; Status | `levels.name`, `code`, `rank`, `status` (`LevelResource`) | HAVE | — |

### 1.9 Organization → Dimension (`app-dynamic-ou`, `app-ou-flyer`)

| HROne setting | PeopleOS today | Status | Build note |
|---|---|---|---|
| Dimension type · `organizationTypeId`; value name · `organizationUnitName`; Status | Fixed extra dimensions only: cost centres, profit centres (`CostCentreResource`, `ProfitCentreResource`); custom fields on employees (`CustomFieldResource`, dropdown type) can hold values but are not dimensions | PARTIAL | Masters `org_dimensions` (code, name, sort, status) + `org_dimension_values` (dimension_id, code, name, parent_value_id, status) + effective-dated `employee_dimension_values` (employee_id, dimension_id, value_id, effective_from/to); `OrgDimensionResource` with a values relation manager; Hire wizard / position change gets one select per active dimension. |
| Depends on · `dependentOUId` (value valid only under another OU value); filter · `dynamicOUFilter` | — | MISSING | `org_dimensions.depends_on` (an organisation dimension key such as `department_id` or another dimension) + `org_dimension_values.parent_value_id`; the employee select filters values by the chosen parent. |
| Dimension fields (OU) → Applicability priority → Review | Policy applicability uses fixed fields (`peopleos.rules.fields`; `EmployeeRuleContext` reads only position dimensions); rule priority exists (`policy_assignment_rules.priority`) | PARTIAL | `EmployeeRuleContext::build` adds `dimension.{code}` values; `RuleEngine` field list is extended at runtime from active dimensions; datasets get one column per dimension; optional `UserAccessScope::DIMENSIONS` entry later. |

### 1.10 Location → Global region / Region (`app-global-region`; the "Region" tab captured the same screen)

| HROne setting | PeopleOS today | Status | Build note |
|---|---|---|---|
| Global region name · `globalRegionName`; Status | — | MISSING | One master `geo_groupings` (kind global_region\|business_region\|region, code, name, parent_id, `country_codes` json for business regions, status) + `GeoGroupingResource` (Core HR). |
| Region (branch grouping, e.g. "North"; used as `regionCode` on Branch) | — | MISSING | Same table, kind `region`; `establishments.region_id` (FK, nullable); reports group by region; later an access-scope dimension. |

### 1.11 Location → Business Region (`app-business-region`)

| HROne setting | PeopleOS today | Status | Build note |
|---|---|---|---|
| Name · `businessRegionName`; global region · `globalRegionCode`; countries · `countryCode`; Status | — | MISSING | `geo_groupings` kind `business_region`, parent = global region, `country_codes` = ISO codes (validated against `App\Support\Geography\Countries::all()`). |

### 1.12 Location → Country (`app-country`)

| HROne setting | PeopleOS today | Status | Build note |
|---|---|---|---|
| Country name · `countryName`; Status | `App\Support\Geography\Countries` (every ISO 3166-1 country from ICU, platform-owned); `companies.country_code`, `locations.country_code` | HAVE | Tenant editing of the ISO list is N-A by design (statutory and holiday code depends on ISO codes). |
| International (ISD) code · `internationalCode`; Nationality · `nationality` | — (`people.nationality` free text, default "Indian" in `EmployeeForm.php`) | MISSING | `app/Support/Geography/CountryFacts.php` (dial code + demonym per ISO code); phone fields get a country-code prefix; `EmployeeForm` nationality becomes a select (text kept for old rows). |
| Global region · `globalRegionId` | — | MISSING | Via `geo_groupings.country_codes` (§1.10/1.11). |

### 1.13 Location → State (`app-state`)

| HROne setting | PeopleOS today | Status | Build note |
|---|---|---|---|
| State name · `stateName`; country · `countryCode`; Status | Platform list of regions per country (`App\Domain\Attendance\Models\CountryRegion`, `CountryRegions` service) and `peopleos.compliance.states` used by `EstablishmentResource` | HAVE | Platform-owned by design (PT/LWF rules key on state codes). |
| Global / business region · `globalRegionCode`, `businessRegionCode` | — | MISSING | Resolved through `geo_groupings` (business region → countries); no per-state link needed unless a business region splits a country — then `geo_groupings.region_codes` json. |

### 1.14 Location → District (`app-district`)

| HROne setting | PeopleOS today | Status | Build note |
|---|---|---|---|
| District name · `districtName` + parents (`globalRegionCode`, `countryCode`, `businessRegionCode`, `stateCode`); Status | `establishments.district` free text | MISSING | Platform reference `country_districts` (country_code, region_code, name, status), loaded like `CountryRegions` (India: LGD district list); `EstablishmentResource` district becomes a select filtered by state (free text kept for history). Tenant editing N-A (reference data). |

### 1.15 Location → City (`app-city`)

| HROne setting | PeopleOS today | Status | Build note |
|---|---|---|---|
| City name · `cityName` + parents (`globalRegionCode`, `countryCode`, `businessRegionCode`, `stateCode`, `districtCode`); Status | `locations.city` free text | MISSING | Platform reference `cities` (country_code, region_code, district_id, name, status) + `locations.city_id` (nullable). |
| City class (list shows "Metro / Non Metro City") | Metro is asked of the employee in `app/Filament/Pages/DeclareInvestments.php` (rent toggle) and appears only as a formula example in `app/Domain/Payroll/Support/FormulaVariables.php` | MISSING | `cities.class` (metro\|non_metro); formula variable `work_city_class`; the rent step pre-fills metro from the work location's city (employee can still change it). |

### 1.16 Location → Branch (`app-branch`)

| HROne setting | PeopleOS today | Status | Build note |
|---|---|---|---|
| Branch name · `branchName`, address · `branchAddress`, pin · `pin`, country · `countryCode`, state · `stateCode`; Status | Establishment = registered premises: `establishments.name`, `address`, `postal_code`, `country`, `state`, `status` (`EstablishmentResource`) | HAVE | — |
| Region · `regionCode` | — | MISSING | `establishments.region_id` → `geo_groupings` kind `region` (§1.10). |
| Include LWF slab · `includeLabourWelfareFundSlab`; include PT slab · `includeProfessionalTaxSlab` | `establishment_statutory_profiles` (statute PT / LWF, `applicable`, effective-dated) via `EstablishmentStatutoryProfileResource`; read by `StatutoryEngine` | HAVE | — |

### 1.17 Location → Sub branch (`app-sub-branch`, `app-add-polygon`, `app-add-wifi-bank`, `app-polygon-bank`, `app-wifi-bank`)

| HROne setting | PeopleOS today | Status | Build note |
|---|---|---|---|
| Sub-branch name · `subBranchName`, address · `address`, pin · `pin`; Status | Work location: `locations.name`, `address_line_1/2`, `city`, `postal_code`, `status` (`app/Filament/Resources/Locations/Schemas/LocationForm.php`) | HAVE | — |
| Branch · `branchCode` (sub-branch belongs to one branch) | `locations.establishment_id` exists (fillable on `Location`) but `LocationForm` does not show it | PARTIAL | Add an "Establishment (branch)" select to `LocationForm`, filtered by the chosen company; list column + filter on the locations table. |
| Minimum / maximum workforce · `minimumWorkforce`, `maximumWorkforce` | Headcount can be planned per location in workforce plans (`workforce_plan_lines.location_id`, `headcount`) | PARTIAL | `locations.min_headcount`, `max_headcount` (unsigned int, nullable); `WorkforceCommandCentre` "needs attention" card when actual headcount is outside the band. |
| Geo-location mapping · `allowGeoLocationMapping`, `geoLocation`, `latitude`, `longitude` | Geo work locations per location or employee: `attendance_geo_fences` (lat, long, radius) via `GeoFenceResource`; `checkin` policy `geo_fence_required`, `outside_fence_action` | HAVE | — |
| Polygon mapping · `allowPolygonMapping` | Built in the working tree today (HS / TO-2): `attendance_geo_fences.shape` circle\|polygon + `polygon` points + validity dates (`database/migrations/2026_11_14_100001_extend_checkin_controls.php`, `GeoFenceResource`, `App\Domain\Attendance\Services\GeoLocations`, `CheckInGuard`) | HAVE | — |
| Polygon bank UI: choose existing / create new, search location on a map, search by polygon or employee name | Points are typed into a repeater (`GeoFenceResource`); geo locations are listed and reusable per location or employee; no map search or drawing | PARTIAL | Map picker field (Leaflet-based; owner decision on the tile/geocoding provider) for circle centre and polygon points; table search by employee name. |
| Wi-Fi mapping · `allowWifiMapping`; Wi-Fi name · `wifiName`; BSSID/MAC · `wifiAddressList`, `macAddress` | `checkin` policy `wifi_attendance` + `wifi_networks` (tags, SSID or BSSID) in `config/peopleos.php` → `policies.types.checkin`; punches keep `wifi_ssid` / `wifi_bssid`; enforced by `CheckInGuard` — networks are listed per policy, not per work location | PARTIAL | Optional reusable bank `attendance_wifi_networks` (name, location_id, ssids json, bssids json, public_ips json, status) + `WifiNetworkResource`; policy option `wifi_networks_from_location` (yes_no, default no) so the employee's work location supplies the list. Mobile SSID/BSSID still needs the native app to send them (EXT, TO-17). |
| Router gateway IP · `routerGatewayIp` | — | MISSING | `attendance_wifi_networks.gateway_ip`; only usable if the mobile app reports the gateway (EXT). |
| Public IP · `publicIp` | Web check-in IP validation per policy: `web_ip_validation` + `web_allowed_ips` (tags, IPs/CIDR), punch `ip_address` | PARTIAL | `attendance_wifi_networks.public_ips` feeding the same check when `wifi_networks_from_location` is on. |

### 1.18 Employee → Document (`app-document`; also the Core HR → Employee landing page)

| HROne setting | PeopleOS today | Status | Build note |
|---|---|---|---|
| Document name · `documentName`; Status | `document_types.name`, `code`, `status` (`app/Filament/Resources/DocumentTypes/DocumentTypeResource.php`) | HAVE | — |
| Document type · `documentType` + "Manage document type" (tenant list: Identification, Education, Employee, Other) | `document_types.category` from a fixed list `peopleos.documents.categories` | PARTIAL → built (HS-CA-4) | Master `document_categories` (code, name, status) seeded from the config list; config `sensitive_categories` stays platform-owned (classification); `DocumentTypeResource` category select reads the master. Built: categories are the option list `document_categories` (People Setup → Option lists), not a separate table. |
| Has expiry date · `isDateOfExpiry` | `document_types.requires_expiry`, enforced on upload (`Employees/RelationManagers/DocumentsRelationManager.php`) | HAVE | — |
| Expiry alert days · `expiryAlertDueDay` | One tenant-wide setting `documents.expiry.reminder_days` (Setup → Core HR) read by `app/Console/Commands/LifecycleReminders.php` | PARTIAL → built (HS-CA-4) | `document_types.expiry_alert_days` (unsigned small int, nullable = use the tenant setting). |
| Sample file · `file`; instructions · `sampleInstructions` | — | MISSING → built (HS-CA-4) | `document_types.sample_path` (file on the documents disk), `sample_instructions` (text 1000); shown in every upload dialog (`DocumentsRelationManager`, My HR). |
| Employee can view · `employeeView` | All-or-nothing permission `document.own` (`EmployeeDocumentPolicy`, `MyHr.php`) | PARTIAL → built (HS-CA-4) | `document_types.employee_can_view` (boolean, default true); `EmployeeDocumentPolicy::view` checks it for own documents. |
| Employee can upload/edit · `employeeEdit` | Only holders of `document.upload` (HR) upload | MISSING → built (HS-CA-4) | `document_types.employee_can_upload` (boolean, default false); upload action on `app/Filament/Pages/MyHr.php` through `App\Domain\Documents\Services\Documents` with status `pending` (existing `document.verify` review). |
| Mandatory for employee · `mandatoryEmployee` | — | MISSING → built (HS-CA-4) | `document_types.mandatory_for_employee` (boolean, default false); missing mandatory documents appear as a Home "attention" item for the employee and in a "Missing documents" report (extend `EmployeeDocumentsDataset`). |
| Mandatory before converting to employee · `mandatoryConvertEmployee`; mandatory for upcoming joiner · `mandatoryUpcomingJoin` | `document_types.mandatory_for_onboarding` is stored and listed but not enforced anywhere | PARTIAL → built (HS-CA-4) | Setting `documents.block_joining_without_mandatory` (boolean, default false): the pre_employee → joined transition refuses when a `mandatory_for_onboarding` document is missing; pre-joiner self-upload waits for WF-13 (EXT). Built: pre-joiner self-upload still waits for WF-13 (EXT). |
| Map document to a profile field · `formId`, `masterInfoId`, `isDocumentMappingRequired`, `isFieldDocMandatory` (e.g. PAN document required when PAN is entered) | — | MISSING → built (HS-CA-4) | `document_types.linked_field` (select from a config list: `statutory.pan`, `statutory.aadhaar`, `statutory.uan`, `bank_account`, `address`, `passport`) + `required_with_field` (boolean); `ChangeBankAccountAction` / statutory-detail save / `ProfileChangeGuard` require an attached document of that type when the linked field changes. Built: fields: PAN, Aadhaar, UAN, bank account, address (passport has no profile field yet). |
| Recruiter / candidate rights · `isRecruiterView`, `isRecruiterEdit`, `candidateView`, `candidateEdit`, `mandatoryCandidate`, `candidateMasterInfoId`, `isCandidateFieldDocMandatory` | — | N-A | Recruitment (separate RMS behind `POST /api/v1/pre-employees`). |

### 1.19 Employee → Qualification (`app-qualification`)

| HROne setting | PeopleOS today | Status | Build note |
|---|---|---|---|
| Qualification · `qualificationName`; Status | `person_qualifications.qualification` free text (`QualificationsDataset`) | MISSING → built (HS-CA-3) | Master `qualifications` (name, level school\|diploma\|graduate\|postgraduate\|doctorate\|other, status) + `QualificationResource` (Core HR links); `person_qualifications.qualification_id` (nullable FK; text column kept); employee form and imports resolve by name. Built: no qualification import exists; the Employee 360 form picks from the master and typed text links by name. |
| Specialisations · `qualificationSpecializationList`, `specializationName` | `person_qualifications.specialisation` free text | MISSING → built (HS-CA-3) | `qualification_specialisations` (qualification_id, name, status) as a relation manager on `QualificationResource`; `person_qualifications.specialisation_id` (nullable). |

### 1.20 Employee → Blacklist reason (`app-blacklist-reason`)

| HROne setting | PeopleOS today | Status | Build note |
|---|---|---|---|
| Blacklist reason · `blacklistReasonName` (HROne: "causes of blacklisting a candidate"; also used when an exited employee is barred) | Exit records `is_rehire_eligible` without a reason (`exit_cases`, `alumni_profiles`; toggle in `app/Filament/Support/ExitActions.php`) | PARTIAL → built (HS-CA-3) | Master `rehire_block_reasons` (name, status) + `exit_cases.rehire_block_reason_id` (required when not eligible) in `ExitActions::initiate`; expose `rehire_eligible` + reason on the pre-employee API so the RMS can stop re-hiring. Candidate blacklisting itself is N-A (RMS). Built: uses the WF-2 `blacklist` reasons; `exit_cases.rehire_block_reason_id`; pre-employee API `rehire_eligible` + `rehire_block_reason`. |

### 1.21 Employee → Profile access (`app-employee-access`)

| HROne setting | PeopleOS today | Status | Build note |
|---|---|---|---|
| Profile tabs × visible "For employee / For manager / For other" (`visibleToEmployee`, `visibleToManager`, `visibleToOther`) for: Bank, Candidate profile, Documents, Family, Health insurance, History, Personal, Previous employer, Professional, Skills, Other details | Employee 360 sections are gated in code by permission keys and own/manages checks (`app/Domain/Experience/Services/Employee360.php`); directory previews show directory fields only (`PeopleVisibility`); not tenant-configurable | PARTIAL → built (CA-6) | Built: `ProfileSectionVisibility` (sections personal, family, professional, skills, bank, documents, previous employer, other details, history; people with `employee.update` / `employee.sensitive.update` are not an audience) gates the 360 tabs, summaries, recent changes, "Other details", My HR documents and My profile (MS-2). Config `peopleos.profile.sections` (key, label) + setting `profile.section_visibility` (new Setup type `matrix`: rows = sections, columns = employee\|manager\|others, default all ticked = today). `Employee360` and `MyHr` apply it as an extra AND — it can only hide, never grant beyond permissions (fail-closed). Candidate profile N-A (RMS); Health insurance waits for WF-10. |

### 1.22 Employee → Social profile access (`app-social-profile-access`)

| HROne setting | PeopleOS today | Status | Build note |
|---|---|---|---|
| Sections visible on social profile / directory · `controlFields`, `isEditableForCompany` (About, Badges, Experience, Education, Goals, Skills & endorsement, Hobbies, Planned leaves, last check-in on directory, RH/holidays/week-offs on directory) | No social profile (AN-03); directory preview fixed | MISSING → built (CA-6) | Built: `DirectorySections` on the person sheet (directory and command-search preview), each section through its domain (goals only where the viewer may already open them; planned leaves as "Away" dates). Setting `directory.sections_visible` (multiselect: experience, education, skills, goals, planned_leaves, last_checkin, off_days; default `[]` = today's directory); rendered by the directory preview (`PeopleVisibility` / `CommandSearch` preview). About, hobbies and badges wait for AN-03 / EN-01. |
| Allow employee to hide this section · `isEditableForEmployee` | Precedent: an employee can hide their birthday (`people.birthday_shared`) | MISSING → built (CA-6) | Built: Preferences → Directory card (only sections shown and allowed; the person still sees their own). Setting `directory.sections_employee_may_hide` (multiselect, same options, default `[]`) + `people.directory_hidden` (json) edited on Preferences. |

### 1.23 Employee → Job function (`app-job-function`)

| HROne setting | PeopleOS today | Status | Build note |
|---|---|---|---|
| Job function · `jobFunctionName` | Job families: `job_families.name`, `code`, `description`, `status` (`JobFamilyResource`); designation links to it | HAVE | — |

### 1.24 Employee → Skill (`app-skills`; no form controls captured)

| HROne setting | PeopleOS today | Status | Build note |
|---|---|---|---|
| Skill master list (add skill) | `skills` (name, code, category, skill_type, description, skill_scale_id, status) via `SkillResource` | HAVE | — |

### 1.25 Employee → Skill proficiency (`app-skill-proficiency`)

| HROne setting | PeopleOS today | Status | Build note |
|---|---|---|---|
| Proficiency · `proficiencyMasterName` (e.g. Beginner, Medium, Advance) | Tenant-defined, versioned skill scales (`skill_scales`, `skill_scale_versions.levels`; `SkillScaleResource`) used by employee skills and role requirements; but the profile skill list (`person_skills.proficiency`) uses the fixed `peopleos.people.skill_proficiencies` | PARTIAL → built (HS-CA-3) | Setting `skills.profile_scale` (select, new `options_from` `skill_scales`, default `null` = today's fixed list); when set, the profile skill proficiency select offers that scale's levels. Built: career path steps use the levels (profile skills have no edit screen). |

### 1.26 Employee → Separation reasons (`app-reason`)

| HROne setting | PeopleOS today | Status | Build note |
|---|---|---|---|
| Reason · `reasonName`; Status | Exit `reason` is free text (`ExitActions::initiate`); exit types and exit-interview reasons are fixed config (`peopleos.exit.types`, `peopleos.exit.interview_reasons`) | MISSING → built (HS-CA-3) | Master `separation_reasons` (name, code, kind, for_employee, for_approver, approver_remark_required, status) + `SeparationReasonResource` (Workforce links); `exit_cases.separation_reason_id` (nullable; text kept); `ExitsDataset` adds reason and kind. CF-13. Built: extends the WF-2 `workforce_reasons` (kind separation) instead of a new table. |
| Type · `resignationType` (Voluntary / Involuntary) | — | MISSING → built (HS-CA-3) | `separation_reasons.kind` (voluntary\|involuntary); attrition analytics split by kind. |
| Used by employee · `isForEmployee`; used by approver · `isForApprover` | — | MISSING → built (HS-CA-3) | Booleans on the master; the employee resignation form lists `for_employee` reasons, HR initiation / approval lists `for_approver` reasons. |
| Approver remark mandatory · `isApproverRemarkMandatory` | No resignation approval step (WF-02) | MISSING → built (HS-CA-3) | Boolean on the master, enforced by the resignation approval step when WF-02 is built. Built: enforced when HR initiates the exit; a resignation approval step (WF-02) does not exist yet. |

### 1.27 Employee → Relationship (capture shows the separation-reason component; own controls not captured)

| HROne setting | PeopleOS today | Status | Build note |
|---|---|---|---|
| Relationship master (family relations) | Fixed list `peopleos.people.family_relations` (8 values) | PARTIAL → built (HS-CA-3) | Tenant-extendable vocabularies: `option_lists` (list_key, code, label, sort, status) + `OptionListResource`, seeded from config; `people.family_relations` reads config ∪ tenant rows (config codes cannot be removed, only hidden). Reusable for other vocabularies. |

### 1.28 Payment bank (`app-bank`)

> **Progress (2026-10-29, built with HS-BP-13 in one design; `docs/architecture/hrone-parity.md` § "Payroll → CTC formula
> tags, loans / claims / variable pay, bank and pay modes").** The payment-bank master is `company_bank_accounts`
> (Payroll → Payment banks: company, name, bank, salary bank code, branch, IFSC, debit account encrypted + last four,
> file layout code, default, status); bank-specific file formats are versioned data (`bank_file_layouts`); the bank
> file groups transfers by paying account (the employee's payment mode, else the account at the employee's bank by IFSC
> bank code, else the company default) and writes each account's file in its layout. Account number restrictions are
> `bank_account_rules` (bank name / IFSC bank code, length, digits / letters / special characters), applied by
> `ChangeBankAccountAction` and bank detail uploads. Branches are recorded per account (one branch each), not as a
> list under a bank.

| HROne setting | PeopleOS today | Status | Build note |
|---|---|---|---|
| Bank name · `bankName`; bank code · `salaryBankCode`; branches · `branchs`, `branchName`, IFSC · `indianFinancialSystemCode` (the captured list also holds the company's debit account, alias, bank-file format) | No company payment-bank master; the salary file (`app/Domain/Payroll/Services/BankFile.php`) is one CSV of employee accounts with no debit account | MISSING → built (HS-BP-13 / CA-5) | Master `payment_banks` (company_id, alias, bank_name, bank_code, branch_name, ifsc, account_number encrypted + last4, file_format, status) + `PaymentBankResource` (Payroll links); `BankFile` groups by the paying company's bank and adds a debit-account header. WF-12. |
| Restrict employee account-number values · `bankAccountValueRestriction` + `bankAccountMinimumValue`, `bankAccountMaximumValue`, `bankAccountOnlyNumericValue`, `bankAccountOnlyAlphabeticalValue`, `bankAccountOnlySpecialCharacters` | One global rule: `account_number` max 34 (`app/Domain/Employment/Actions/ChangeBankAccountAction.php::rules`, `BankAccountImports`) | MISSING → built (HS-BP-13 / CA-5) | `bank_account_rules` (bank name/IFSC prefix, min_length, max_length, numeric_only, letters_allowed, special_allowed) or columns on `payment_banks`; `ChangeBankAccountAction::rules` and `BankAccountImports` apply the matching rule (none = today's max 34). |

### 1.29 Customer (`app-customer-layout`)

| HROne setting | PeopleOS today | Status | Build note |
|---|---|---|---|
| Customer name · `customerName`, code · `customerCode`, contact · `contactName`, `emailAddress`, `phoneNumber`, address · `address`, `countryId`, `stateId`, `cityId`, `pincode` | — | MISSING | `customers` (code, name, contact_name, email, phone, address, country_code, state_code, city, postal_code, status) + `CustomerResource` in a new `app/Domain/Projects`. PR-01. Used by projects, timesheets and expenses at customer level. |

### 1.30 Project → Project (`app-project-layout`, `app-project-form`, `app-review`)

| HROne setting | PeopleOS today | Status | Build note |
|---|---|---|---|
| Project wizard: Project policy, Advance, Budget, Billable, Timesheet details, Visibility, Task fields, Review | — | MISSING | `projects` (code, customer_id, name, currency, owner_employee_id, start/end, status, stage_id, tags, value, budget, budget_hours, expense_budget) + `project_members`; policy type `project` in `peopleos.policies.types` (fields: advance_allowed, validate_budget_hours, validate_milestone, restrict_extra_effort, billable, timesheet_track_hours, effort_by, backdated_timesheet_days, future_timesheet_allowed, visibility). PR-02..05. |
| Review conditions · Condition / Operator / Value / Role (who reviews) | Rule engine operators exist (`peopleos.rules.operators`) | MISSING | Project review rules reuse `RuleEngine` conditions + role picker; stored as `project_review_rules` (project_id, conditions json, role_id). |

### 1.31 Project → Project form (`app-project-fields-setting`)

| HROne setting | PeopleOS today | Status | Build note |
|---|---|---|---|
| Per field (Project code, Customer, Name, Currency, Owner, Collaborator, Attachment, Start/End date, Status, Sub-status, Actual closed date, Tag, Member access, Terms, Project field 1–10, Type, Value, Budget, Budget hours, Expense budget, Validate budget hour, Validate milestone, Restrict extra efforts, Timesheet track hours, Effort by, Billable category, Expense billability, Backdated / future timesheet, Based on employee): rename `displayName`, enable `isEnabled`, mandatory `isMandatory`, visible to owner `visibleToOwner`, edited by owner `editByOwner`, visible to team `visibleToTeam` | — | MISSING | Config `peopleos.projects.fields` (standard fields) + setting `projects.field_rules` (Setup type `matrix`; columns label, enabled, required, owner_view, owner_edit, team_view; default = all enabled, none required); Project field 1–10 = Custom Field Engine with new entity `project` in `peopleos.custom_fields.entities`. |

### 1.32 Project → Project stage (`app-project-status`)

| HROne setting | PeopleOS today | Status | Build note |
|---|---|---|---|
| Stage name · `projectSubStatus` (sequence, active) | — | MISSING | `project_stages` (name, sort, status) seeded Not started / In process / Pending / Completed + `ProjectStageResource`. |

### 1.33 Vendor (`app-vendor`, `app-add-vendor-flyer`)

| HROne setting | PeopleOS today | Status | Build note |
|---|---|---|---|
| Vendor code · `vendorCode`, company name · `companyName`, address · `vendorAddress`, `countryCode`, `stateCode`, `cityCode`, `pin` | Assets store `vendor` as free text (`database/migrations/2026_09_27_100002_create_asset_tables.php`) | MISSING | `vendors` (code, name, address, country_code, state_code, city, postal_code, gstin, status) + `VendorResource`; `assets.vendor_id` (nullable FK; text kept). PR-01. |
| GSTIN · `gServiceTin` | — | MISSING | `vendors.gstin` with the GSTIN validation of §1.2. |
| Primary contact · `primaryName`, `primaryMobileNo`, `primaryPhoneNo`, `primaryEmailId`, `primaryContactFor`; secondary contact · `secName`, `secMobileNo`, `secPhoneNo`, `secEmailId`, `secContactFor` | — | MISSING | `vendor_contacts` (vendor_id, is_primary, name, mobile, phone, email, contact_for). |
| Vendor field 1–5 · `vendorField1..5` | Custom Field Engine (entities: employee, company, department, location, designation, asset) | MISSING | Add entity `vendor` to `peopleos.custom_fields.entities`. |

### 1.34 Vendor → Vendor form (`app-fields`)

| HROne setting | PeopleOS today | Status | Build note |
|---|---|---|---|
| Per vendor field: enable `isEnable`, mandatory `isMandatory`, rename `displayName` | Custom fields already carry label, `is_required`, `status` (`CustomFieldResource`) | MISSING | Covered once vendors are a custom-field entity (§1.33): label = rename, `is_required` = mandatory, `status` = enable. No separate screen needed. |

### 1.35 Timesheet settings (`app-timesheet-setting`, from `spec/common-settings-core.md`; belongs with Core HR → Project)

The panel saves to HROne's `/api/project/setup/setting`; its Yes/No questions are server-driven (labels are not in the client code, so they were not captured). Each setting row is `{settingId, status}` where status is `Y`/`N`, or — for the workflow question — a list of project ids.

| HROne setting | PeopleOS today | Status | Build note |
|---|---|---|---|
| Server-driven Yes/No timesheet settings · `groups`, `settings`, `status` (Update per row) | — (no timesheets; PR-04) | MISSING | Capture the live labels before building; implement each as a `timesheet.*` boolean in `config/peopleos.php` → `settings` (default = HROne's default) listed under a new Setup module `projects`. Likely candidates mirror the project form switches (backdated / future timesheets). |
| Timesheet workflow: "No, all projects have the same workflow" / "Yes, every project has a different workflow" / "Following projects will have an exclusive workflow" | Workflows already support start conditions (`workflows.start_conditions`, `WorkflowResource`) | MISSING | Setting `timesheet.workflow_mode` (select `single`\|`per_project`\|`listed`, default `single`); `WorkflowTrigger` passes `project_id` in the `timesheet.submitted` context so a workflow's start conditions can match a project. |
| Exclusive-workflow projects · `conditionalTimesheetProjects` (multi-select of projects) | — | MISSING | Setting `timesheet.exclusive_projects` (multiselect, new `options_from` `projects`, default `[]`), used when mode = `listed`. |

### 1.36 Organization structure container (`app-organization-structure`)

| HROne setting | PeopleOS today | Status | Build note |
|---|---|---|---|
| Tabbed navigation for Enterprise, Company, BU, Department, Sub-department, Designation, Grade, Level, Dimension | Setup → Core HR lists the same masters (`config/peopleos_setup.php` → `core.links`) + `OrganisationDesigner` | HAVE | Add new masters from this file to `core.links` as they are built. |

### 1.37 Shared "Settings" flyer (`app-common-settings`) — handed off

| Panel (component) | Covered in | Status | Build note |
|---|---|---|---|
| Delete transactions (`performanceCycleId`, `taskTypeIds`, `remarks`), Task (`app-task-setting`) | `spec/common-settings-automation.md` | N-A | Analysed with Automation. |
| Time office (`app-time-office-setting`) | `spec/common-settings-timeoffice.md` | N-A | Analysed with Time office. |
| Payroll (`app-payroll-setting`) | `spec/common-settings-payroll.md` | N-A | Analysed with Payroll. |
| Performance (`app-setting`), Engage points (`app-eng-setting`) | `spec/common-settings-performance-engage.md` | N-A | Analysed with Performance / Engage. |
| Expense, Asset, Helpdesk (`app-expense-settings`, `app-asset-setting`, `app-help-desk-settings`) | `spec/common-settings-expense-asset-helpdesk.md` | N-A | Analysed with those modules. |
| Recruitment (`app-recruitment-settings`) | — | N-A | Recruitment out of scope. |

The Timesheet panel (`app-timesheet-setting`) is analysed in §1.35 of this file.

---

## 05 Access rights

PeopleOS today: roles carry permission keys (`config/peopleos.php` → `permissions`; `RoleResource`, `app/Filament/Resources/Roles/Schemas/RoleForm.php`), users get roles (optionally per company: `role_user.company_id`) and one organisation scope (`user_access_scopes`, dimensions company / location / business_unit / division / department / team / establishment, `app/Domain/Identity/Services/AccessScopes.php`, edited in `app/Filament/Resources/Users/Schemas/UserForm.php`). Setup centre has no "Access rights" module; Roles sit under Automation and Users under Governance.

### 5.1 Page access (`app-page-access-right-home`, `-add`, `app-par-employee-view`, `app-par-group-view`, `-update`, `-filter`)

| HROne setting | PeopleOS today | Status | Build note |
|---|---|---|---|
| View: Group view / Employee view (`Select view`) | Roles list (`RoleResource`) and users with their roles (`UserResource`) | HAVE → built (CA-9: Setup → Access rights groups Roles, Roles by person, Users, Data access groups, Team calendar, Geo dashboard and the widget / calendar / geo settings) | Optional "Access rights" Setup module grouping Roles, Users and the new screens below. |
| Add group → Group details (name, status) | `roles.name`, `slug`, `description`; no status | PARTIAL → built (HS-P14 `roles.status`; CA-9: page access per My space item on the role, `roles.myspace_hidden_items`) | `roles.status` (active\|inactive, default active); an inactive role grants nothing (`User::hasPermission` skips it); filter by status on the roles table. |
| Assign rights (pages and actions view / edit / export) | Permission keys are action-level (e.g. `employee.update`, `analytics.export`) chosen in `RoleForm` | HAVE | — |
| Review | Saves are audited; risky changes can route through the Change Centre (`configuration.approval` feature) | HAVE | — |
| Employee view → Add group to an employee | `UserForm` roles (with company) | HAVE | — |
| Filter → Status | — | PARTIAL → built (HS-P14, roles table status filter) | Comes with `roles.status`. |

### 5.2 Data access (`app-data-access`, `app-add-data-access`, `app-employee-view-data-access`, `app-group-view-data-access`, `app-data-access-filter`)

| HROne setting | PeopleOS today | Status | Build note |
|---|---|---|---|
| Group details: group name, module (Workforce, Time office, Payroll, Performance, Engage, Expense, Asset, Recruitment), status, members | One organisation scope per user, applied to every module (`UserAccessScope`) | PARTIAL → built (CA-9: `access_scope_groups` + members + rules, Access → Data access groups; recruitment N-A) | Masters `access_scope_groups` (name, module key from `peopleos.access.modules`, status) + `access_scope_group_users` + `access_scope_group_rules` (dimension, scope_id); `AccessScopeGroupResource`. |
| Assign rights: which employees' data the group reaches in that module | `AccessScopes::constrainEmployees` with the user's single scope | PARTIAL → built (CA-9: group layers in `AccessScopes`, AND-ed with the user's own scope, by organisation unit, named employees, reportees, sub-departments; malformed = nobody) | `AccessScopes::constrainEmployees($query, $user, ?string $module)`: when the user is in a group for that module, that group's rules apply, otherwise the user-level scope (today). Only narrows; module = permission prefix of the screen. Needs careful tests (fail-closed tenancy). |
| Review; Employee view / Group view; filter by module | User edit page shows the user's scope | PARTIAL → built (CA-9: group list filtered by module and status; the user form lists the person's groups; every change audited with a reason) | Employee view = user page lists group memberships; group view = resource table filtered by module. |
| Recruitment data group | — | N-A | Recruitment out of scope. |

### 5.3 CXO access (`app-analytics-access-setting` and its views)

Each HROne view has: view name, applicability (`filterType` + `filterValue`, e.g. Enterprise or chosen companies/departments), "share view with" (`ownerId`), "share by email?" (`mail` Yes/No) and email receivers (`receiverId`). PeopleOS today: dashboards visible by role (`dashboards.role_ids`, `DashboardResource`), report schedules with recipients (`report_schedules.recipient_user_ids`, frequency, day), executive pages behind `analytics.executive` (`WorkforceCommandCentre`).

| HROne setting | PeopleOS today | Status | Build note |
|---|---|---|---|
| Common view fields: name, applicability (`filterType`, `filterValue`), owners (`ownerId` / `viewOwnerIds`), email share (`mail` / `ismail`), receivers (`receiverId` / `viewReceiverIds`) | Dashboards: name + role visibility; report schedules: recipients; no org applicability filter, no per-user owners | PARTIAL → built (CA-10: `dashboards.applicability` — one organisation dimension, a slice AND-ed with each viewer's own scope (`AccessScopes::withinSlice`), never a grant —, `viewer_user_ids`, `email_user_ids` + cadence (a link only, through the notification path, audited, `analytics.views.email`), `preset`; Clone / Inactivate / Send now on the list) | `dashboards.applicability` (json: dimension → ids, applied to every widget query through an `AccessScopes`-style constraint), `dashboards.viewer_user_ids` (json, besides `role_ids`), `dashboards.email_user_ids` + `email_frequency` (reuse `ReportSchedules` delivery). Preset view templates in `App\Domain\Analytics\Services\AnalyticsDefaults`. |
| Happiness index view (`app-moodbot-view`, `app-add-moodbot-view`) | No mood / happiness data (EN-07) | MISSING → built (CA-10 over HP-9f's `happiness_index` dataset: preset `happiness`, whole organisation only — the index is never shown to a narrowed reader) | Preset view after EN-07 (mood check-ins). |
| ROI – return on investment view (`app-roi-view`; Enterprise only) | No automation-ROI metric (AN-06) | MISSING (CA-10 checked: AN-06 / AN-11 not built; the preset waits) | Preset view after AN-06. |
| Employee movement view (`app-employee-movement-view`) | `EmployeeMovementsDataset`, `ManagerChangesDataset`, workforce analytics | PARTIAL → built (CA-10 preset `movement`) | Preset dashboard on those datasets with the common fields above. |
| HR Ops view (`app-employee-efficiency-view`) | Approvals / service-desk turnaround (`ServiceDeskAnalyticsPage`), no HR-ops efficiency view | PARTIAL → built (CA-10 preset `hr_ops`, whole organisation only: approvals waiting, decided on time and days to decide (new metrics), open tickets, the HR queues; per-task targets wait for AN-14 / IP-03) | Preset dashboard: request turnaround, on-time approvals (needs IP-03 inbox metrics for full parity). |
| Payroll view (`app-payroll-analytics-view`) + "Payroll dashboard will be updated on day N of each month" | `PayrollControlRoom`, `CompensationAnalyticsPage`, `PayrollDataset` (computed live) | PARTIAL → built (CA-10 preset `payroll` + `dashboards.refresh_day`: payroll figures and payroll-run report widgets stand at the payroll finalized before the latest refresh day, through the new `PayrollSummaryReader` contract) | Preset view + `dashboards.refresh_day` (1–28, null = live) so payroll figures freeze to the last finalised run until that day. |
| Helpdesk view (`app-helpdesk-view`: `helpdeskViewName`, `viewFilterType`, `viewFilterValue`, `viewOwnerIds`, `ismail`, `viewReceiverIds`) | `ServiceDeskAnalyticsPage` (permission-gated) | PARTIAL → built (CA-10 preset `helpdesk`) | Preset view with the common fields. |
| Engage view (`app-engage-analytics-view`) | `SurveyResults`; no badges / feed data | PARTIAL → built (CA-10 preset `engage`: badges and reward points from HP-9c / HP-9d) | Preset view; badge/feed figures after EN-01/EN-04. |
| Other views via `app-analytics-access-multiple` (`viewName`, `viewFilterType`, `viewFilterValue`, `viewOwnerIds`, `viewReceiverIds`): 9 box, Performance, Survey, Pulse, Time office | 9-box from `TalentAssessments`, `PerformanceAnalyticsPage`, `SurveyResults` (survey type `pulse` exists), attendance datasets | PARTIAL → built (CA-10 presets `nine_box` (counts only, suppressed), `performance`, `survey`, `pulse` (EngagementAnalytics rules), `time_office`) | Preset views for each; same common fields. |
| Expense view | No expense module (HP-4) | MISSING → built (CA-10 preset `expense` over EX-G's expense figures) | After HP-4. |
| Recruitment view | — | N-A | Recruitment out of scope. |

### 5.4 Calendar access (`app-calendar-access`)

Per module, each calendar event has "Visible to manager" / "Visible to HR" (`isManagerApplicable`, `isAdminApplicable`). PeopleOS has a leave calendar table (`app/Filament/Pages/LeaveCalendar.php`), a birthdays page (`Birthdays`) and the employee's own attendance calendar; no team/HR event calendar.

| HROne setting | PeopleOS today | Status | Build note |
|---|---|---|---|
| Workforce events: Birthdays, Work anniversary, Confirmation, New joins, Last working day, Passport expiry | Data exists (`people.birthday_*`, `employees.joining_date` / `probation_end_date` / `expected_joining_date`, `exit_cases.last_working_day`, `employee_documents.expires_on`); shown only in separate lists | PARTIAL → built (CA-9: Team calendar; passport expiry as the expiry of any document type) | New page `TeamCalendar` (manager: reportees; HR: access scope) merging these events; settings `calendar.manager_events` and `calendar.hr_events` (multiselect, options from config `peopleos.calendar.events`, default = all available). |
| Workforce events: Retirement date, Marriage anniversary | No retirement date (WF-08) or wedding date field | MISSING → built in part (CA-9: marriage anniversaries from `people.wedding_anniversary_*`, HP-9a; retirement date waits for WF-08) | Add after WF-08 (retirement) and an optional `people.wedding_date` (EN-09). |
| Time office events: Leave, Working hours, Shift, Overtime, Comp-off generated | Leave calendar; attendance records, shift roster, time-office requests | PARTIAL → built in part (CA-9: leave, overtime, comp-off; working hours and shift wait for TX-5 / MS-11) | Same page and settings. |
| Performance events: 1-on-1, PIP end date | One-on-ones, improvement plans exist | PARTIAL → built (CA-9) | Same page and settings. |
| Performance event: Initiative due date | No initiatives (PF-05) | MISSING → built (CA-9, over the HP-8j initiatives) | After PF-05. |
| Expense events: Travel plan, Expense request (mileage / amount) | No expense module (HP-4) | MISSING → built (CA-9, over EX-C / EX-F: approved trips; expense claims with mileage, never amounts) | After HP-4. |
| Engage events: Badge received, Scheduled events | No badges / events (EN-01, EN-06) | MISSING → built in part (CA-9: badges received, HP-9c; scheduled events wait for EN-06) | After EN-01 / EN-06. |
| Recruitment event: Interview | — | N-A | Recruitment out of scope. |

### 5.5 Geo access (`app-geo-access`)

| HROne setting | PeopleOS today | Status | Build note |
|---|---|---|---|
| Time office → Mobile check-in map, visible to manager / HR | Punches carry latitude/longitude; no map view | MISSING → built in part (CA-9: Geo dashboard list of today's located check-ins and `geo_dashboard.checkins_visible_to` (default nobody, PRV-14), openings audited; the map waits for CA-8) | Page `GeoDashboard` (today's web/mobile punches with location, within the viewer's scope; Time office) + setting `geo_dashboard.checkins_visible_to` (multiselect manager\|hr, default `['hr']`). Location data is personal data: classify in `DataInventory`, audit opens. Map component as in §1.17; the new `attendance_location_pings` (continuous tracking) can feed a trail view later. |
| Workforce → Headcount by sub-branch map, visible to manager / HR | Headcount by location exists in analytics; no map | MISSING → built in part (CA-9: headcount by location with the work-location pin and `geo_dashboard.headcount_visible_to`; the map waits for CA-8) | Same page, headcount per location pinned at geo-fence coordinates; setting `geo_dashboard.headcount_visible_to` (default `['hr']`). |

### 5.6 Widget access (`app-widget-access`)

HROne list (type, `widgetName`, `isActive`, `sequenceNo`, `widgetMode`): Inbox, Wish, Contest, Calendar, Wall of fame (badges, rewards), Leave balance, Task for today, Team leave, Goal, Referral, People on leave today, Initiative, Profile completion, Core values, Open position, Yesterday attendance, Upcoming events, Achievements, Happiness index. PeopleOS Home is composed per role lens (`app/Domain/Experience/Services/HomeComposer.php`: MAIN/SIDE blocks; `HomeBlocks.php`) and each user can hide cards (`ExperiencePreferences` `home_hidden`).

| HROne setting | PeopleOS today | Status | Build note |
|---|---|---|---|
| Widget on/off for everyone · `isActive`; filter by `widgetType` | Per-user hide only | PARTIAL → built (CA-9: `home.hidden_blocks`) | Setting `home.hidden_blocks` (multiselect, options = HomeComposer block keys, default `[]`); `HomeComposer::for` merges it with the user's own hidden list. |
| Widget order · `sequenceNo` | Fixed order per lens | MISSING → built (CA-9: optional `home.block_order`, per AR-5) | Setting `home.block_order` (text list of block keys, default empty = built-in order) applied to MAIN/SIDE. |
| Widgets that exist: Inbox (decisions), Wish (birthdays), Leave balance (tile `balance`), Task for today (day / focus), Team leave and People on leave today (manager team block), Open position (workforce for executives) | Present in `HomeComposer` / `HomeBlocks` | HAVE → built (CA-9: mapped in `HomeComposer::blockOptions()` with HROne's names) | Map them in the options list. |
| Widgets partly there: Goal (performance momentum), Yesterday attendance (AN-02) | Partial cards | PARTIAL | Follow AN-02. |
| Widgets missing: Contest, Calendar, Wall of fame (badges / rewards), Initiative, Profile completion, Core values, Upcoming events, Achievements, Happiness index | — | MISSING | Each arrives with its feature (EN-01/02/03/05/06/07/08, PF-05, WF-07, §5.4 calendar); then add to the option list. |
| Referral widget | — | N-A | Recruitment out of scope. |

---

## 06 Integration

### 6.1 API access (`app-developer-layout`)

| HROne setting | PeopleOS today | Status | Build note |
|---|---|---|---|
| API URL · `url`; authentication key · `key`; domain code · `domain` | Named API keys with scopes (`app/Filament/Resources/ApiKeys/ApiKeyResource.php`; secret shown once; tenant implied by the key; `/api/v1/*`) | HAVE | PeopleOS deliberately never re-displays a secret (stronger than a standing org key). |
| "Explore API" | OpenAPI file generated (`docs/api/openapi.json`, `app/Console/Commands/GenerateOpenApi.php`) but not served in the app | PARTIAL → built (CA-11) | Serve the spec read-only at `/api/docs` (authenticated, `api_key.view`) with a viewer page linked from `ApiKeyResource`. Built as the panel page Integrations → API reference (`App\Filament\Pages\ApiReference`, `api_key.view` or `api_key.manage`; search; "Download OpenAPI (JSON)"), generated from the route table like `peopleos:openapi`; not under `/api` (that group has no session sign-in). |

### 6.2 API key management (`app-api-key-management`, `app-api-key-form`, `app-view-api-key-details`)

| HROne setting | PeopleOS today | Status | Build note |
|---|---|---|---|
| Basic info (name, description, logo) | `api_keys.name`, `expires_at`, `status`; revoke with reason | HAVE → description built (CA-11) | Description/logo optional: `api_keys.description` (text 255). Built: `api_keys.description`; no logo. |
| Scopes | `api_keys.scopes` from `peopleos.api.scopes` | HAVE | — |
| IP address list · `ipLists` | — | MISSING → built (CA-11) | `api_keys.allowed_ips` (json of IPs/CIDRs, empty = any); checked in `app/Http/Middleware/AuthenticateApiKey.php`. Built: validated on save (`IpAllowlist`), refused with 403 `ip_not_allowed`, malformed stored entries never match. |
| Rate limit · `rateLimit`, `rateLimitApplicableOn` | One global limit `peopleos.api.rate_limit_per_minute` (bucket per key prefix) | PARTIAL → built (CA-11) | `api_keys.rate_limit_per_minute` (nullable = global); the limiter reads it. Built (1–10 000 a minute); a key used from outside its allowlist is limited by the caller's IP instead. |
| Details → Overview, Auth | Key prefix, scopes, last used (`last_used_at`) | HAVE | — |
| Details → Logs; Usage dashboard | Only `last_used_at`; inbound integration events are logged (`inbound_events`) | MISSING → built (CA-11) | `api_request_logs` (api_key_id, method, route name, status, duration_ms, ip, created_at; no bodies) written by `AuthenticateApiKey` (terminable), retention via `retention.*`; "Usage" tab on the key and a usage chart. Built with path and user agent too; `retention.api_request_logs_days` (90); key page "API calls" tab, usage figures and a 30-day chart. |
| Details → IP address tab | — | MISSING → built (CA-11) | Comes with `allowed_ips`. Built: "IP addresses and rate limit" on the key page. |

### 6.3 BGV verification (`app-bgv-verification`)

| HROne setting | PeopleOS today | Status | Build note |
|---|---|---|---|
| Enable background verification? · `backgroundVerificationIsEnabled` | BGV is always available to holders of `bgv.*` (`ViewEmployee` action, `BgvCaseResource`) | PARTIAL | Setting `bgv.enabled` (boolean, default `true` = today) hiding the initiate action when off. |
| Enterprise-level or per company · `isEnterprise`, `companyCode` | Provider list is global config (`peopleos.bgv.providers` = manual only) | PARTIAL | Provider configuration stored per company on an Integration Hub system of kind `bgv` (`integration_systems.settings` json: company_ids). |
| Vendor connections: OnGrid (`ongridConfigurationDetails`, `isEnable`, `clientId`, `communityId`, `secretKey`), C First (`cFirstConfigurationDetails`, `cFirstPackages`, `isActive`), Spring Verify (`springVerifyMappingMode`, `springVerifyConfigurationDetails`, `springVerifyPackages`) | Provider contract exists (`app/Domain/Bgv/Providers/BgvProvider.php`, `ManualProvider`); results can arrive signed through the Integration Hub (`bgv.results` handler) | PARTIAL | One `BgvProvider` adapter per vendor (credentials encrypted in `integration_systems.settings`), registered in `peopleos.bgv.providers`. EXT: vendor contracts and API documentation. |
| Offerings / packages · `backgroundVerificationOfferings`, `offeringCount` | Fixed check types `peopleos.bgv.check_types`, default set `peopleos.bgv.default_checks` | PARTIAL | `bgv_packages` (provider, name, check types json, vendor package code, status) + setting `bgv.default_package` (select). |

### 6.4 e-KYC (`app-ekyc`, `app-ekyc-settings`; tabs Employee, Candidate, Upcoming joiners)

| HROne setting | PeopleOS today | Status | Build note |
|---|---|---|---|
| Enable e-KYC for employees · `isKycEnable` / `isKycForEnable` | — (statutory ids stored, no verification; bank accounts have an unused `verified_at`) | MISSING | `KycProvider` contract (like `BgvProvider`) + `kyc_verifications` (employee_id, check pan\|aadhaar\|bank_account\|uan, status, provider_reference, result hash, verified_at) + setting `kyc.employees_enabled` (boolean, default false). EXT: KYC provider. IP-08. |
| Enable for upcoming joiners | — | MISSING | Setting `kyc.joiners_enabled` (boolean, default false); runs at pre_employee stage. |
| KYC checks per audience · `kycSettingDetail` | — | MISSING | Setting `kyc.checks` (multiselect pan, aadhaar_offline, bank_account, uan; default `[]`). |
| Enable for candidates | — | N-A | Recruitment out of scope. |

### 6.5 Journal voucher → Journal voucher (`app-journal-voucher`, `app-export-jv`, `app-allow-cost-distribution`, `app-set-cost-distribution`, `app-journal-voucher-form`)

| HROne setting | PeopleOS today | Status | Build note |
|---|---|---|---|
| JV definitions list (name, status) + JV form: Basic info, Connection details, GL code, GL code mapped, Report format, Review | — | MISSING | `journal_voucher_definitions` (integration_system_id of kind `finance`, name, status, report_format csv columns, grouping dimensions) + `journal_voucher_lines` (salary component or statutory classification → GL account, side debit\|credit); `JournalVoucherDefinitionResource`. CF-22 / IP-08. |
| Export JV: month / year or from–to dates, data-access type, employees, org filters (company, BU, department, sub-department, designation, grade, level, region, branch, sub-branch, employee type, status, other status), options; Download / Post | Payroll datasets and exports exist; no GL output | MISSING | `App\Domain\Payroll\Services\JournalVouchers::build(PayrollRun, Definition, filters)` → rows grouped by GL + mapped keys; CSV download (audited like `BankFile`); "Post" = signed webhook `payroll.journal_ready` to the finance system (EXT: ERP connector). |
| Cost distribution: filters (organisation, location, employment, employee) → set % per dimension (total 100%) | — | MISSING | `employee_cost_allocations` (employee_id, dimension, scope_id, percent, effective_from/to; validated = 100) + bulk editor; JV splits lines by it. |

### 6.6 Journal voucher → Configure OU (`app-configure-ou`)

| HROne setting | PeopleOS today | Status | Build note |
|---|---|---|---|
| GL filters per OU (Company, BU, Department, Sub-department, Designation, Grade, Level, Region, Branch, Sub-branch): `jviMapKeyName`, `jviMapKey`, `jviMapKeyOne`…`jviMapKeyFive`; Import | Integration Hub maps a dimension value to one external value per system (`integration_mappings`: dimension, external_value, peopleos_type, peopleos_id; `IntegrationSystemResource` relation managers) | PARTIAL | Add `integration_mappings.extra_keys` (json, up to 5 named keys) or five nullable `key_1..key_5` columns; CSV import of mappings. |

### 6.7 Journal voucher → Employee (`app-employee`)

| HROne setting | PeopleOS today | Status | Build note |
|---|---|---|---|
| Employee ERP / vendor code and GL codes · `glCode`, `glCodeOne`…`glCodeFive`; filters; Import | `external_references` (entity employee ↔ external id per integration system) | PARTIAL | `external_references.metadata` holds `gl_1..gl_5` (or dedicated columns); bulk import through the existing upload pattern. |

### 6.8 SCIM (`app-scim-integration`, `app-scim-dashboard`)

| HROne setting | PeopleOS today | Status | Build note |
|---|---|---|---|
| Enable SCIM provisioning? · `isActive` | Inbound SCIM 2.0 server (an identity provider provisions users into PeopleOS): `routes/api.php` `scim/v2/Users`, `app/Domain/Enterprise/Services/Scim.php`, API scope `scim` | PARTIAL | HROne pushes employees out to the directory (HR-driven provisioning); PeopleOS only receives. Setting `scim.outbound_enabled` (boolean, default false). |
| Provider and credentials · `providerDetails`, `tenantId`, `clientId`, `clientSecret`, `jobId`, `objectId`, per company `companyCode` | — | MISSING | Integration system of kind `identity` with encrypted settings; `ScimOutbound` job on employee events (`employee.joined`, `employee.exited`, transfers) calling Microsoft Graph / Google Admin. EXT: tenant app registration. |
| User principal name rule · `prefixUserPrincipalName`, `sufixUserPrincipalName` | — | MISSING | Settings `scim.upn_prefix_field` (select employee_code\|first.last) and `scim.upn_domain` (text). |
| Disabled employees → OU · `rerouteDisabledEmployee`, `rerouteOUPath` | — | MISSING | Settings `scim.move_disabled` (boolean, default false), `scim.disabled_ou_path` (text). |
| Provision logs: search, status filter, Refresh, Re-initiate failed | SCIM calls are audited; inbound events have a reprocess pattern (`inbound_events`, `InboundEventResource`) | PARTIAL | `scim_provision_events` (employee_id, action, status, attempts, last_error) with retry, shown like `InboundEventResource`. |

### 6.9 Single sign-on (`app-singlesignon`)

| HROne setting | PeopleOS today | Status | Build note |
|---|---|---|---|
| Authentication type · `logOnType` (OAuth 2.0 / SAML 2.0), provider template · `subLogOnType` (O365, Google, Okta) | OIDC connections with presets Entra / Google / Okta / generic (`app/Filament/Resources/SsoConnections/SsoConnectionResource.php`, `peopleos.enterprise.sso_presets`) | PARTIAL | OAuth/OIDC is HAVE; add `sso_connections.protocol` (oidc\|saml, default oidc). |
| OAuth: `clientId`, `authorizationPath`, `loginPath` | `client_id`, `client_secret`, `authorization_url`, `token_url`, `userinfo_url`, scopes, allowed domains, auto-provision, default role, enforce | HAVE | — |
| SAML: `issuer`, `acsBasePath`, `isSpInitiate`, certificate · `certificateExpiryDate`, `certificateExpiryIntimationEmail` | — | MISSING | SAML columns (idp_entity_id, sso_url, slo_url, x509_certificate, certificate_expires_at, expiry_notify_emails json, sp_initiated boolean) + SP metadata/ACS routes using a SAML library (composer dependency: owner approval); daily reminder before certificate expiry (scheduler, like `LifecycleReminders`). IP-08. |
| Log-off path · `logOffPath` | No IdP sign-out | MISSING | `sso_connections.logout_url` (OIDC end-session or SAML SLO) called on sign-out. |

### 6.10 One AI → Credit configuration (`app-credit-configuration`; feature list from the page capture)

| HROne setting | PeopleOS today | Status | Build note |
|---|---|---|---|
| Credits (total / used / remaining) | Plan entitlement `ai_requests_monthly.max` (`App\Domain\Entitlements\Enums\Capability`, operator side) | PARTIAL | Tenant read-only usage meter (Governance) showing AI requests this month vs entitlement. |
| Analytics agent | HR / workforce assistants (`AssistantPage`, features `ai.assistants`, `ai.workforce_intelligence`, `ai.llm`; setting `ai.external_data_policy`) | PARTIAL | Already switchable per tenant through features; list them in Setup → Governance. |
| Content creation (give badge, HR post, announcement, events, continuous feedback, create goal, performance review comments, job description, give reward, wish message) | No AI drafting | MISSING | Setting `ai.drafting_features` (multiselect announcement, goal, review_comment, feedback, job_description, wish; default `[]`) — each "Draft with AI" action exists only when listed and `ai.llm` is on. Badge/reward/HR post/events wait for EN-01/02/04/06. |
| Receipt parser (expense), ticket parser (travel desk), receipt duplicity | No expense module (HP-4) | MISSING | After HP-4. |
| Resume parser, internal recruiter, career portal, interview feedback | — | N-A | Recruitment out of scope. |

### 6.11 Employee offers (`app-my-benefits`)

| HROne setting | PeopleOS today | Status | Build note |
|---|---|---|---|
| Partner offers marketplace (salary advance, loans, pension, tax filing, OPD; each "Offer inactive / Explore now") | — | N-A | HROne's commercial partner marketplace, not tenant configuration. If wanted later, publish partner offers as HR articles (`ArticleResource`). |

---

## Counts

| Section | HAVE | PARTIAL | MISSING | N-A |
|---|---|---|---|---|
| 01 Core HR | 25 | 27 | 50 | 3 |
| 05 Access rights | 5 | 17 | 11 | 4 |
| 06 Integration | 5 | 13 | 16 | 3 |

(Table rows above, one row per HROne control or tightly related control group; the six §1.37 hand-off rows are not counted. N-A is recruitment, HROne billing/partner items and HROne-internal ids.)

Five biggest gaps:
1. **Projects, customers, vendors and timesheets** (§1.29–1.35, PR-01..05): a whole module with its form-field rules and timesheet workflow settings.
2. **Module-level data access and CXO shared views** (§5.2, §5.3): access is one organisation scope per user for all modules; dashboards cannot be scoped to an org slice, shared with named people or emailed as a view.
3. **Location hierarchy** (§1.10–1.17): no global/business region, region, district or city masters (no metro class for HRA), no ISD code / nationality list, no headcount band per location, and sub-branch → branch (location → establishment) is not editable on the location form. (Polygon and Wi-Fi check-in landed today at policy level.)
4. **Journal voucher** (§6.5–6.7): no GL mapping beyond one external value per dimension, no cost distribution, no JV build/export/post.
5. **Document and profile visibility rules** (§1.18, §1.21, §1.22): document types lack per-audience view/upload/mandatory rules, sample files and field mapping; profile tab and directory visibility are hard-coded by permission.

---

## Build packages

Ordered by dependency and value. Sizes: S ≤ 2 days, M ≈ 1 week, L ≥ 2 weeks. Each package keeps today's behaviour by default, registers models in `DataInventory`, adds policies and permission keys (then `peopleos:sync-permissions`), lists settings in `config/peopleos_setup.php` (with defaults in `config/peopleos.php`) and extends `tests/Feature/HroneParity/SetupCentreTest.php`.

1. **CA-1 Setup plumbing (S).** New Setup setting types `matrix` (rows × boolean columns, stored as json) and `file` (image/document on the documents disk); new `options_from` sources `articles`, `skill_scales`, `projects`, `dashboards`; a Setup module `access` (Roles, Users, access groups, widget/calendar/geo settings). Files: `app/Domain/Configuration/Services/SetupCatalogue.php`, `app/Filament/Pages/SetupCentre.php`, `config/peopleos_setup.php`, Setup centre test. Depends on: nothing.
2. **CA-2 Organisation master fields (M).** Companies: industry, phone, fax, contact employee, social links, logo; GSTIN registration type; designations: experience range, rich job description, hazardous, wage skill category, primary skill on role requirements; departments.parent_department_id (+ datasets, rule context); roles.status; enterprise settings (`branding.website`, `organisation.industry`, `branding.logo_path`, theme colour wiring, `branding.employee_may_change_theme`); organisation contacts master. Files: migrations, `Companies/Schemas/CompanyForm.php`, `Designations/Schemas/DesignationForm.php`, `Departments/Schemas/DepartmentForm.php`, `Roles/Schemas/RoleForm.php`, `app/Domain/Identity/Models/User.php`, `config/peopleos.php` (`compliance.registration_types`, `compliance.wage_skill_categories`, settings), `app/Domain/Configuration/Services/EmployeeRuleContext.php`, `AdminPanelProvider.php`, Analytics datasets. Depends on: CA-1 (file type for logo).
3. **CA-3 Employee masters (M).** Qualifications + specialisations, separation reasons (kind, employee/approver use, remark flag), rehire-block reasons, generic `option_lists` (family relations first), `skills.profile_scale`. Files: new models under `app/Domain/People` and `app/Domain/Exit`, resources, `app/Filament/Support/ExitActions.php`, `app/Domain/Exit/Services/Exits.php`, employee form/imports, `QualificationsDataset`, `ExitsDataset`, pre-employee API resource. Depends on: CA-1. Feeds WF-02 (resignation approval).
4. **CA-4 Document rules (M, CF-14).** `document_categories`; document_types: sample file + instructions, employee view/upload, mandatory for employee, expiry alert days, linked field + required-with-field; `documents.block_joining_without_mandatory`; My HR upload; missing-documents report. Files: `DocumentTypeResource.php`, `EmployeeDocumentPolicy.php`, `Documents.php`, `MyHr.php`, `DocumentsRelationManager.php`, `LifecycleReminders.php`, `ChangeBankAccountAction.php`, `ProfileChangeGuard.php`, lifecycle transition service, `EmployeeDocumentsDataset`. Depends on: CA-1 (file).
5. **CA-5 Payment banks (M, WF-12) — built with HS-BP-13.** `payment_banks` with debit accounts and per-bank account-number rules; bank file grouped by paying account. Files: `app/Domain/Payroll/Services/BankFile.php`, `ChangeBankAccountAction.php`, `BankAccountImports.php`, new resource in Payroll links. Depends on: none.
6. **CA-6 Profile and directory visibility (M, CF-15) — built (2026-10-29, migration `2026_12_05_200001`; `docs/architecture/hrone-parity.md` § "Core HR → Profile access, Social profile access (CA-6) and My profile (MS-2)").** `profile.section_visibility` matrix; `directory.sections_visible`, `directory.sections_employee_may_hide`, `people.directory_hidden`. Files: `app/Domain/Experience/Services/Employee360.php`, `MyHr.php`, `PeopleVisibility.php`, `CommandSearch.php`, `Preferences.php`. Depends on: CA-1; social sections partly on AN-03/EN-01.
7. **CA-7 Geography and location hierarchy (M/L, WF-12).** `geo_groupings` (global region, business region, region), `establishments.region_id`, platform `country_districts` and `cities` (metro class), `CountryFacts` (dial code, nationality), `locations.city_id`, min/max headcount per location. Files: `app/Support/Geography/*`, `app/Domain/Attendance/Services/CountryRegions.php` pattern, `EstablishmentResource.php`, `LocationForm.php`, `EmployeeForm.php`, `FormulaVariables.php`, `DeclareInvestments.php`, `WorkforceCommandCentre.php`, datasets. Depends on: none (reference data load needs an owner-approved source for districts/cities).
8. **CA-8 Check-in location banks (S/M; mobile Wi-Fi data EXT).** Polygon geo locations and policy-level Wi-Fi/IP checks were added in the working tree today (HS / TO-2); what remains is a reusable per-location Wi-Fi bank (`attendance_wifi_networks`, `wifi_networks_from_location` policy option) and a map picker for geo locations. Files: `GeoFenceResource.php`, new `WifiNetworkResource`, `CheckInGuard.php`, `config/peopleos.php` (`policies.types.checkin`). Depends on: map/geocoding provider decision; the TO-2 work landing.
9. **CA-9 Access rights (L, CF-20 / IP-05) — built (2026-10-30, with SV-7; migration `2026_12_13_200001`; `docs/architecture/hrone-parity.md` § "CA-9 / SV-7 Access rights"; the geo map waits for CA-8).** Module data-access groups in `AccessScopes`; widget access (`home.hidden_blocks`, `home.block_order`); `TeamCalendar` page with `calendar.manager_events` / `calendar.hr_events`; `GeoDashboard` with visibility settings. Files: `app/Domain/Identity/Services/AccessScopes.php`, `UserForm.php`, new `AccessScopeGroupResource`, `HomeComposer.php`, `HomeBlocks.php`, new pages, `DataInventory`. Depends on: CA-1; CA-8 map component for geo; fail-closed tests are the main cost.
10. **CA-10 Shared CXO views (M) — built (2026-10-30; migration `2026_12_16_200001`; `docs/architecture/hrone-parity.md` § "CA-10 Shared CXO views"; ROI waits for AN-06 / AN-11).** Dashboard applicability filters, named viewers, email delivery, payroll refresh day; preset views (movement, HR ops, payroll, helpdesk, engage, 9-box, performance, survey/pulse, time office). Files: `dashboards` migration, `DashboardResource.php`, `app/Domain/Analytics/Services/Dashboards.php`, `ReportSchedules.php`, `AnalyticsDefaults.php`. Depends on: CA-9 (scope constraint helper); happiness/ROI/expense views wait for EN-07, AN-06, HP-4.
11. **CA-11 API key hardening (M).** Per-key IP allowlist and rate limit, request log + usage tab, in-app OpenAPI viewer. Files: `api_keys` migration, `ApiKeyResource.php`, `AuthenticateApiKey.php`, the `api` rate limiter in `app/Providers/AppServiceProvider.php`, `routes/api.php`, retention purge. Depends on: none.
12. **CA-12 Custom dimensions (L).** `org_dimensions`, values with dependency, effective-dated employee values, rule-engine fields, dataset columns, optional access-scope dimension. Files: new `app/Domain/Organisation/Models/*`, `EmployeeRuleContext.php`, `RuleEngine.php`, `config/peopleos.php` (`rules.fields` runtime extension), hire wizard and position change, Analytics datasets. Depends on: CA-2 (sub-department pattern).
13. **CA-13 Journal voucher (L; ERP post EXT).** JV definitions and GL lines, five GL keys on integration mappings and employee references, cost allocations, `JournalVouchers` builder, CSV export, `payroll.journal_ready` webhook. Files: `app/Domain/Integration/Models/IntegrationMapping.php`, `ExternalReference`, new Payroll service + resources, `config/peopleos.php` (`enterprise.webhook_events`). Depends on: CA-12 (if dimensions feed GL keys), CA-7 (region/branch filters).
14. **CA-14 Identity integrations (L, EXT).** SAML 2.0 connections with certificate-expiry alerts and logout URL; outbound SCIM provisioning to Entra/Google with UPN rules, disabled-user OU and provision log/retry. Files: `SsoConnectionResource.php`, `app/Domain/Enterprise/Services/Sso.php`, `Scim.php`, new jobs, routes, scheduler. Depends on: owner approval of a SAML library and tenant IdP app registrations.
15. **CA-15 BGV vendors and e-KYC seam (M seam; vendors EXT).** `bgv.enabled`, per-company provider config on integration systems, `bgv_packages`; `KycProvider`, `kyc_verifications`, `kyc.*` settings. Files: `app/Domain/Bgv/*`, `ViewEmployee.php` BGV action, new KYC domain, `config/peopleos.php` (`bgv`). Depends on: vendor contracts for live adapters.
16. **CA-16 AI feature controls (S/M).** Usage meter vs `ai_requests_monthly.max`; `ai.drafting_features` with "Draft with AI" actions on announcements, goals, review comments, feedback, job description, wishes. Files: `app/Domain/Ai/Services/AiGateway.php`, the respective Filament forms, Setup → Governance. Depends on: `ai.llm` enabled; CA-2 (job description field).
17. **CA-17 Projects and timesheets (L, HP-5).** Customers, vendors (+ contacts, asset link, custom-field entity), projects (policy type `project`, members, stages, review rules, field rules matrix, custom-field entity `project`), timesheets with `timesheet.workflow_mode` / `timesheet.exclusive_projects` and the server-driven `timesheet.*` switches once their labels are captured. Files: new `app/Domain/Projects/*`, resources, `config/peopleos.php` (`policies.types.project`, `custom_fields.entities`, settings), `config/peopleos_setup.php` (module `projects`), `WorkflowTrigger`, asset forms. Depends on: CA-1 (matrix, `projects` options), CA-2 (GSTIN validation); expense (HP-4) links projects later.
