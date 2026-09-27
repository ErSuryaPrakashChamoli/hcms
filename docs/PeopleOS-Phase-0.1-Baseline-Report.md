# PeopleOS Phase 0.1 Baseline Report

Implementation baseline, code reconciliation and Git freeze for Markedge PeopleOS. Performed 27 September 2026 on `/home/administrator/Documents/hrms/hcms`. This phase established truth about the working tree and froze it in Git; it added no features.

Vocabulary: **Not Started / Scaffold Only / Partial / Functional / Hardened / Production Candidate** for maturity; **EXISTS / PARTIAL / NOT IMPLEMENTED** for capabilities.

## 1. Executive Summary

- The entire PeopleOS implementation (blueprint phases 1–16) was outside Git at the start of the phase: HEAD c956846 tracked 98 files, only 4 under `app/`. The working tree held 1,066 untracked files, 12 modified and 1 deleted tracked file. Every path was inventoried and classified; nothing was reset, stashed, cleaned or deleted.
- A secret and generated-file review found nothing that must be kept out of Git among the candidate paths. `.env`, the SQLite file, `storage/` runtime, `node_modules`, `public/build` and view caches are all ignored by existing rules and were never staged. Demo credentials in the seeder are non-secret placeholders (`admin@demo.local` / `password`) and are documented as such.
- Baseline commit 430e0f7 ("chore(peopleos): establish implementation baseline") captured 1,079 paths (1,066 added, 12 modified, 1 deleted). Post-commit verification: clean working tree, 68/68 migrations recognised, 284 passed, 0 failed, 2,535 assertions, 452 s, Pint passing.
- The migration chain was replayed into a temporary MySQL database and produces the same 170 tables and identical indexes as the development database; the only difference is a stray `tenants.base_currency` column that exists in the development database but is created by no migration (left over from an earlier failed migration attempt; unused by code).
- Reconciliation confirmed: tenant isolation is fail-closed and tested; the audit chain is intact (append-only, per-tenant hash chain, verifier command); RMS is absent as a dependency; PeopleOS runs standalone.
- Two corrections were made because leaving them would have committed known inaccuracies: an empty `docs/guide/` directory created by a documentation build was removed (never tracked), and one sentence in the Administrator Guide that referred to a non-existent "import centre" was corrected and the PDF re-rendered.
- Highest-priority findings for the next phase, in order: (1) no CI and no static analysis to protect the baseline; (2) ABAC rule engine exists but data-access enforcement is not complete; (3) in-app notifications depend on a queue worker that is not running in the development environment (10 stale jobs found); (4) ticket attachments are linked through a plain storage URL rather than a signed, authorised download; (5) statutory rates are illustrative and unverified.

## 2. Repository Baseline

| Item | Value |
|---|---|
| Branch | main (tracking origin/main, `github.com/ErSuryaPrakashChamoli/hcms.git`) |
| HEAD before phase | c956846 "first push hcms project phase 1" |
| Baseline commit | 430e0f7 |
| HEAD after phase | the commit that adds this report (see `git log`) (baseline commit plus this report) |
| Laravel | 13.33.0 |
| PHP | 8.5.4 |
| Filament | ^5.8 |
| Database | MySQL, database `hcm` (tests: SQLite in-memory) |
| Tables | 170 (169 created by migrations + `migrations`) |
| Migrations | 68, all ran, 0 pending |
| Tests before / after | 284 passed, 2,535 assertions / 284 passed, 0 failed, 2,535 assertions, 452 s |
| Pint | passing before and after |
| Queue / cache / session / disk | database / database / database / local |
| Scheduler | 13 `peopleos:*` commands |

## 3. Working Tree Reconciliation

### 3.1 Modified tracked paths (12) — all committed

| Path | Change | Class |
|---|---|---|
| CLAUDE.md | Project notes appended to the Boost guidelines | G Documentation (tooling) |
| app/Providers/AppServiceProvider.php | Policy registration, event subscriptions, AI provider binding, rate limiter, strict models | A Core |
| app/Providers/Filament/AdminPanelProvider.php | Panel: nav groups, tenant/security middleware, MFA | A Core |
| bootstrap/app.php | API routes, request-id middleware, `api.key` alias, API throttle | A Core |
| bootstrap/providers.php | Import style only | A Core |
| config/auth.php | User model moved to `App\Domain\Identity\Models\User` | F Configuration |
| database/factories/UserFactory.php | Tenant/platform-admin/suspended states | E Test support |
| database/seeders/DatabaseSeeder.php | Idempotent demo tenant seed | D Database (demo data, non-secret) |
| routes/console.php | 13 scheduled commands | A Core |
| routes/web.php | Root redirect, exit-tenant, signed document download, SSO | A Core |
| tests/Feature/ExampleTest.php | Root redirect assertion | E Test |
| tests/Pest.php | RefreshDatabase + tenancy helpers | E Test |

### 3.2 Deleted tracked path (1) — reviewed, deletion committed

`app/Models/User.php` — the stock Laravel user model. Replaced by `app/Domain/Identity/Models/User.php` (tenant-aware, MFA contracts, SSO/SCIM fields); `config/auth.php`, factories and tests reference the new class; no remaining reference to `App\Models\User` exists in the code.

### 3.3 Untracked paths (1,066 files) — classification

| Group | Files | Class | Decision |
|---|---|---|---|
| app/Domain/** (32 modules: models, services, actions, policies, enums, events, listeners, providers, adapters, assistants, datasets) | 395 | B Domain implementation | commit |
| app/Filament/** (91 resources, 20 pages, widgets, relation managers, support helpers) | 425 | B / C Phase implementation (UI) | commit |
| app/Console/Commands/** (18 commands) | 18 | B | commit |
| app/Http/** (controllers Api/V1, Scim, Sso, document download, exit tenant; 5 middleware) | 13 | A Core | commit |
| app/Support/** (tenancy, effective dating) | 6 | A Core | commit |
| config/peopleos.php | 1 | F Configuration (catalogue; reads AI provider settings via `env()`, no values) | commit |
| routes/api.php | 1 | A Core | commit |
| database/migrations/2026_09_26_073744 … 2026_09_28_120001 | 65 | D Database | commit |
| database/factories/*Factory.php (20 new) | 20 | E Test support | commit |
| database/data/compliance/{in,ae}.php, database/data/countries/packs.php | 3 | D Reference data (platform-owned, illustrative rates flagged in file header) | commit |
| resources/packs/*.json (7 configuration packs) | 7 | F Configuration data | commit |
| resources/views/filament/**, resources/views/mail/notification.blade.php | 24 | C Phase implementation (Blade for custom pages) | commit |
| tests/Feature/** (27 areas, 64 files incl. helper files) | 69 | E Test | commit |
| docs/peopleos-blueprint.md | 1 | G Documentation (authoritative product blueprint) | commit |
| docs/architecture/phase-1 … phase-16 (16 files) | 16 | G Documentation (authoritative engineering notes) | commit |
| docs/PeopleOS-Administrator-Guide.html / .pdf | 2 | G Documentation (H generated from config + hand-written sections; PDF 634 KB) | commit; builder script is not in the repo (TD-22) |
| docs/guide/ (empty directory) | 0 | I Temporary | removed (never tracked) |

No path was left UNKNOWN. No third-party (J) code exists outside `vendor/` and `node_modules/`, both ignored.

### 3.4 Excluded paths and reasons

| Path | Reason | Mechanism |
|---|---|---|
| .env | local environment with credentials | `.gitignore` |
| database/database.sqlite | local SQLite file | `database/.gitignore` |
| storage/app/private/tenants/, storage/app/private/warehouse/ | runtime document and export files | `storage/app/private/.gitignore` |
| storage/framework/views/*, storage/framework/testing/* | compiled views, test disks | storage gitignores |
| bootstrap/cache/*.php | generated package/service caches | `.gitignore` pattern in bootstrap/cache |
| node_modules/, public/build/, public/{css,fonts,js}/ | dependencies and built assets | `.gitignore` |
| vendor/ | Composer dependencies | `.gitignore` |

Tracked tooling files retained as-is from HEAD: `.claude/skills/**` (35 Laravel Boost skill files), `.mcp.json` (Boost MCP command, no secrets), `boost.json`, `AGENTS.md`.

## 4. Phase-to-Code Matrix

| Phase | Domain | Code | DB | Tests | Maturity | Notes |
|---|---|---|---|---|---|---|
| 1 | Foundation: tenant, identity, RBAC, audit, settings/features | yes | 8 migrations | Tenancy 8, Identity 9, Audit 12, Platform 6 | Hardened | Fail-closed scope, hash chain, permission catalogue; no ABAC data scope |
| 2 | Organisation | yes | 15 migrations | Organisation 17 | Functional | Effective-dated units, designer; no Legal Entity/Establishment |
| 3 | Employee core | yes | 15 migrations | Employment 17, Documents 6, Bgv 4 | Functional | Person/employee/position/reporting; sensitive fields encrypted and audited |
| 4 | Configuration platform | yes | 4 migrations | Configuration 28 | Functional | Change Centre, policies, rules, custom fields, forms, packs; simulation absent |
| 5 | Workflow platform | yes | 2 migrations | Workflow 16, Notifications 5 | Functional | Node types and 4 approval modes; no amount/dynamic approvers, no generic retry |
| 6 | Lifecycle & onboarding | yes | 4 migrations | Lifecycle 5, Onboarding 5, Api 5 | Functional | Config-owned transitions; pre-employee ingress idempotent |
| 7 | Attendance | yes | 3 migrations | Attendance 16 | Functional | Devices, processor, exceptions, regularisation; synchronous processing |
| 8 | Leave | yes | 1 migration | Leave 12 | Functional | Ledger balances, accrual command |
| 9 | Payroll & compliance | yes | 2 migrations | Payroll 14 | Partial | Pipeline and statutory engine work; rates illustrative, arrears/ESI period/filings missing |
| 10 | Performance & talent | yes | 1 migration | Performance 7 | Functional | Cycles, appraisals, calibration, PIPs, career passport |
| 11 | Learning & assets | yes | 2 migrations | Learning 4, Assets 3 | Functional | Thin test coverage |
| 12 | Employee experience (service desk, grievance, KB, communication, portal) | yes | 2 migrations | Experience 6 | Functional | Attachment URL issue (TD-05) |
| 13 | Exit, letters, alumni | yes | 1 migration | Exit 3 | Functional | HTML letters only; gratuity unwired |
| 14 | Analytics | yes | 1 migration | Analytics 5 | Functional | In-memory runner, CSV only |
| 15 | AI & intelligence | yes | 1 migration | Ai 5 | Functional | Gated, logged, deterministic fallback; keyword retrieval |
| 16 | Enterprise & international | yes | 1 migration | Enterprise 8 | Partial | OIDC, SCIM, MFA, webhooks, security policy work; SAML, translations, dedicated-DB routing absent |
| — | Admin panel render coverage | — | — | Admin 53 | — | Every resource/page renders for an admin |

No phase is rated Production Candidate. Payroll, compliance and enterprise are Partial because known functional gaps affect correctness or completeness, not because code is missing.

## 5. Domain Implementation Matrix

| # | Domain | Implementation | Models / tables | Services | Policies / resources | Routes / jobs | Tests | Limitations and concerns |
|---|---|---|---|---|---|---|---|---|
| 1 | Identity | EXISTS | User, Role, Permission | PermissionRegistry | 7 policies; Users, Roles | SSO, SCIM | Identity 9 | User intentionally unscoped |
| 2 | Tenant | EXISTS | Tenant, TenantSetting, TenantFeature | SettingsRepository, FeatureFlags, ProvisionTenantAction | Tenants, TenantSettings, TenantFeatures | exit-tenant | Platform 6, Tenancy 8 | Dedicated-DB tier metadata only |
| 3 | Organisation | EXISTS | 16 | OrganisationTree | 3 policies; 14 resources + Designer | — | Organisation 17 | Legal Entity/Establishment collapsed (§12) |
| 4 | People | EXISTS | Person + 7 satellites, Skill | — | via Employee | — | Employment tests | Rehire UI absent |
| 5 | Employment | EXISTS | Employee, EmployeePosition, ReportingRelationship, bank, statutory | EmployeeCodeGenerator, SensitiveAccessAuditor, 4 actions | 3 policies; Employees (360) | pre-employees API | Employment 17 | — |
| 6 | Employee Lifecycle | EXISTS | transitions, timeline | LifecycleEngine, Timeline | — | reminders cmd | Lifecycle 5 | — |
| 7 | Configuration | EXISTS | ConfigurationChange, Policy*, CustomField*, Form* | ConfigurationChanges, ImpactPreview, Blueprints, Policies, CustomFields, Forms | 4 policies; 6 resources + 2 pages | publish-due cmd | Configuration 28 | No simulation |
| 8 | Policy / Rules | EXISTS | PolicyVersion, PolicyAssignmentRule | RuleEngine, PolicyResolver | — | — | Configuration | Limited operators; no nested groups; not applied to data access |
| 9 | Workflow | EXISTS | 5 | WorkflowEngine, ApproverResolver, EscalationEngine | 2 policies; Workflows, WorkflowInstances, TaskInbox | tick cmd, SendWebhook job | Workflow 16 | See §28 gaps |
| 10 | Notifications | EXISTS / channels PARTIAL | template, rule, delivery | Engine, Notifier, AudienceResolver, TemplateRenderer | 2 policies; 3 resources | queue (Filament DB notifications) | Notifications 5 | sms/whatsapp/push log-only; worker required |
| 11 | Attendance | EXISTS | 11 | Processor, PunchIngestion, ShiftResolver, HolidayResolver, Regularisations | 2 policies; 6 resources + Exception Centre | punches API, process cmd | Attendance 16 | Synchronous processing |
| 12 | Leave | EXISTS | 5 | 7 | 2 policies; 4 resources | accrue cmd | Leave 12 | — |
| 13 | Payroll | EXISTS (Partial maturity) | 10 | 8 | 3 policies; 6 resources + Control Room | read API | Payroll 14 | Arrears, mid-month proration, simulation, filings |
| 14 | Compliance | EXISTS (India) | ComplianceRule, CompanyStatutoryProfile, EmployeeTaxDeclaration | StatutoryEngine, TaxComputer, ComplianceRules, FinancialYear | 2 policies; ComplianceRules, StatutoryProfiles, TaxDeclarations | sync cmd | StatutoryTest | Rates unverified; no legal entity/establishment on rules |
| 15 | Performance | EXISTS | 16 | 6 | 2 policies; 9 resources + Calibration | — | Performance 7 | No 9-box/succession |
| 16 | Goals / OKR | EXISTS | Goal, KeyResult, GoalCheckIn, Kra | Goals | Goals, Kras | — | Performance | Templates per designation absent |
| 17 | Skills / Career | EXISTS | Skill, PersonSkill, CareerPath*, CareerAspiration | CareerPassport | Skills, CareerPaths + Passport page | — | Performance | — |
| 18 | Learning | EXISTS | 11 | Learning, Assessments, TrainingSessions | 2 policies; 6 resources | tick cmd | Learning 4 | No content upload/SCORM |
| 19 | Compensation | PARTIAL | EmployeeSalaryAssignment (+ payroll structures) | Salaries | via Payroll | — | Payroll | No bands, cycles, budgets, revision letters (§23) |
| 20 | Assets | EXISTS | 7 | Assets | 1 policy; 3 resources | read API | Assets 3 | No depreciation/audits |
| 21 | Documents | EXISTS | DocumentType, EmployeeDocument | Documents | 2 policies; DocumentTypes + 360 tab | signed download route | Documents 6 | Retention purge excludes documents |
| 22 | Letters | EXISTS | LetterTemplate, Letter | Letters, LetterDefaults | 2 policies; 2 resources | — | Exit 3 | HTML only, no PDF/e-sign |
| 23 | Service Desk | EXISTS | Ticket, TicketCategory, TicketComment | ServiceDesk | 2 policies; 2 resources | tick cmd | Experience 6 | Attachment URL not signed (TD-05) |
| 24 | Grievance | EXISTS | 3 | Grievances | 2 policies; 2 resources | tick cmd | Experience | — |
| 25 | Communication | EXISTS | Announcement, AnnouncementRead | Communications | 1 policy; Announcements + feed | — | Experience | No email digest |
| 26 | Exit | EXISTS | ExitCase, ExitClearance, ExitInterview | Exits, ExitInterviews | 2 policies; ExitCases + Insights | tick cmd | Exit 3 | Global checklist only |
| 27 | Final Settlement | EXISTS | FinalSettlement, lines | FinalSettlements | SettlementPolicy | — | Exit | Gratuity unwired |
| 28 | Alumni | EXISTS | AlumniProfile, AlumniRequest | Alumni | AlumniPolicy; AlumniProfiles + portal | — | Exit | No public verification links |
| 29 | Reporting | EXISTS | Report, ReportRun, ReportSchedule | DatasetRegistry, ReportRunner, ReportExports, ReportSchedules | ReportPolicy; Reports | run-due cmd, report API | Analytics 5 | CSV only, 10k rows |
| 30 | Analytics | EXISTS | Dashboard | WorkforceMetrics, Dashboards, AnalyticsDefaults | DashboardPolicy; Dashboards + Command Centre | — | Analytics | Static widgets |
| 31 | Integration | PARTIAL | ApiKey, WebhookEndpoint, WebhookDelivery | ApiKeys, Webhooks, WebhookEventBridge, Bgv providers, attendance adapters | ApiKeyPolicy, EnterprisePolicy | api.key middleware, deliver cmd | Api 5, Enterprise 8 | No external_references / mappings / inbound events (§11) |
| 32 | AI | EXISTS | AiInteraction | AiGateway + 6 assistants + 3 detectors | AiInteractionPolicy; 4 pages + log | — | Ai 5 | Keyword retrieval; single provider |
| 33 | Audit | EXISTS | AuditEvent, AuditEventChange | AuditRecorder, AuditIntegrityVerifier | AuditEvents + history tabs | verify/export cmds | Audit 12 | Missing actions (§7) |
| 34 | Administration | EXISTS | — | — | Control Centre nav, settings, features, packs | — | Admin 53 | — |
| 35 | Security | PARTIAL | SsoConnection; user MFA/SSO columns | Sso, Scim, SecurityPolicy | EnterprisePolicy; SsoConnections + Security page | sso routes, EnforceSecurityPolicy | Enterprise | No SAML, session/device mgmt |
| 36 | Observability | PARTIAL | failed_jobs | — | — | `/up`, request id | — | No metrics/alerts/Horizon |
| 37 | API | EXISTS | — | — | — | 27 v1 + 8 SCIM routes | Api, Enterprise | No OpenAPI, no idempotency keys, no v2 |
| 38 | Import / Export | PARTIAL | ReportRun | Blueprints (config import/export), ReportExports, WarehouseExport, audit export, BankFile | ConfigurationPacks page | blueprint cmds | Configuration, Analytics | No employee/attendance/leave bulk data import |
| 39 | Search | PARTIAL | — | ConfigurationSearch; Filament global search on 4 resources | Configuration Finder page | — | Ai | No cross-module people search beyond `PeopleQuery` |
| 40 | Custom Fields | EXISTS | CustomField, CustomFieldValue | CustomFields | CustomFields resource; schema helper | — | Configuration | — |
| 41 | Form Builder | EXISTS | Form, FormVersion, FormSubmission | Forms | Forms resource | — | Configuration | — |
| 42 | Employee Experience | EXISTS | — | NeedsAttention | My Day, Assistant, feeds, portal | — | Experience 6 | — |
| 43 | Manager Experience | EXISTS | — | — | My Team, approvals via inbox | — | Experience | — |
| 44 | HR Experience | EXISTS | — | — | People control centre widgets, HR Copilot | — | Admin | — |
| 45 | Payroll Control Room | EXISTS | — | PayrollRuns | PayrollControlRoom page, PayrollAuditor | — | Payroll pages | — |
| 46 | Configuration Control Centre | EXISTS | ConfigurationChange | ConfigurationChanges | ConfigurationChanges resource, Finder, Packs | publish-due cmd | Configuration 28 | No simulation, no "archived/superseded" states (§27) |

## 6. Security Review

**Secrets.** Pattern scan (private keys, provider key prefixes, cloud key ids, hard-coded secrets and passwords) over all 1,079 candidate paths produced one hit, which is a `'encrypted'` cast declaration for the SSO client secret, not a value. Passwords in code are: factory default (`password`), demo seed (`password`), random SCIM/SSO placeholders, and test fixtures. `.env.example` contains no values. `.env` is ignored and untouched. No rotation is required.

**Tenant isolation (verified, not weakened).** Explicit `TenantContext`; `TenantScope` adds `1 = 0` when no tenant is bound (fail closed) and is disabled only inside `bypass()`; `BelongsToTenant` stamps and refuses cross-tenant writes; `bypass()`/`withoutTenancy()` appear in exactly five application files (AuditRecorder, AuditIntegrityVerifier, ApiKeys, ProvisionTenantAction, SsoController), all platform-level lookups. Scheduled commands iterate tenants with `runAs`; the single queued job resolves tenant from its delivery record; exports and API reads run inside a bound tenant; Filament resources rely on the global scope. Six models intentionally lack the trait: **Tenant** (is the tenant), **User** (looked up at login before a tenant is known; listings use `forCurrentTenant()`), **Permission** (platform catalogue), **AuditEvent** and **AuditEventChange** (nullable tenant for platform events; reads scoped explicitly; writes only via `AuditRecorder`), **ComplianceRule** (platform-owned statutory rules). Gaps recorded: no architecture test asserting the trait on every other model; no automated cross-tenant test for exports or AI; per-tenant storage prefixes are convention, not enforced.

**RBAC.** 39 permission groups, 13 role templates, 56 policy classes; Filament resources authorise through registered model policies (generic `PermissionPolicy` maps abilities to `{resource}.{action}`; specialised policies for employee, grievance, exit, letters, reports, dashboards, AI, enterprise). Sensitive keys gate CTC, bank, statutory, payslips; views are audited with purpose.

**ABAC.** `RULE ENGINE EXISTS` (12 position dimensions + lifecycle, gender, tenure). `DATA-ACCESS ENFORCEMENT = NOT COMPLETE`: record-level checks exist only for self, line manager, grievance handlers and dataset permissions; an HR Admin sees all companies and locations of the tenant; no field-level matrix. Recorded as a security priority (TD-02).

**API security.** API keys hashed (SHA-256) with prefix lookup, scopes, expiry, revocation; 120 req/min throttle; JSON errors; tenant bound from the key; SCIM under the same guard. No OAuth, no idempotency keys, no inbound signature verification for third parties (only PeopleOS-signed outbound).

**Storage security.** Employee documents: private disk, path per record, download only via temporary signed route + policy + access audit. Finding: HR service-desk ticket attachments are rendered with `Storage::disk(...)->url()` (no signature, no authorisation, and unusable on the private local disk) — TD-05. No permanent public URLs for employee documents were found.

## 7. Audit Review

Confirmed present: append-only (model refuses updates/deletes; tests), per-tenant SHA-256 hash chain with verifier and tamper test, actor id/name/roles snapshot, tenant, action, module, entity type/id/label, timestamp, IP, user agent, request id, source, field-level before/after with sensitive masking, reason, approval reference, effective date, sensitive-view events (bank/statutory/payslip/grievance/document/AI), export events, login/logout/failed-login/password/MFA events, platform-level events without tenant.

Known missing first-class actions, recorded as hardening requirements (not redesigned here): **ARCHIVE**, **ASSIGN** (assets/positions are CREATE/UPDATE with metadata), **STATUS_CHANGE** (generic; lifecycle-specific actions exist), **DOWNLOAD** (recorded as VIEW with purpose "download"), **batch/bulk operation id** (request id links events; no batch grouping). The action enum is stored data, so additions must be additive.

## 8. Database Baseline

- 68 migrations, timestamps 2026_09_26_073744 → 2026_09_28_120001, no duplicate timestamps, no duplicate `Schema::create`, all ran on dev MySQL, 0 pending.
- 169 tables from migrations; 458 foreign-key constraints, 115 explicit indexes, 98 unique constraints; no `softDeletes` anywhere (deletions are audited hard deletes or status changes); 24 effective-dated tables; tenant key on every tenant-owned table (framework/platform tables excepted).
- **Replay test**: the full chain was run into a temporary MySQL database `hcm_phase01_check` (created for the test, dropped afterwards). Result: 170 tables, column set and index set identical to `hcm` except one column present only in dev, `tenants.base_currency varchar(3) NOT NULL`, which no migration creates and no code reads (currency comes from `tenants.currency` and a setting). Recommendation: drop it in dev by hand or leave it; do not add a migration for it.
- **Migration safety**: no `dropColumn`/`drop` in any `up()`, no `->change()`, no data transformations; `2026_09_28_120001_create_enterprise_tables.php` guards each added column with `Schema::hasColumn` because MySQL DDL is non-transactional. Naming is consistent (`create_*_tables`, `add_*_to_*_table`). Ordering is correct (dependencies precede dependants). No historical migration needs rewriting; future corrections must be forward migrations.

## 9. Test Baseline

| Metric | Before baseline | After baseline |
|---|---|---|
| Tests | 284 passed, 0 failed, 0 skipped | 284 passed, 0 failed, 0 skipped |
| Assertions | 2,535 | 2,535 |
| Duration | 300 s | 452 s |
| Warnings | none | none |
| Pint | passed | passed |

Suites: 27 feature areas (counts of test cases): Admin 53, Configuration 28, Employment 17, Organisation 17, Attendance 16, Workflow 16, Payroll 14, Audit 12, Leave 12, Identity 9, Enterprise 8, Tenancy 8, Performance 7, Documents 6, Experience 6, Platform 6, Ai 5, Analytics 5, Api 5, Lifecycle 5, Notifications 5, Onboarding 5, Bgv 4, Learning 4, Assets 3, Exit 3, Support 3; plus one unit example.

**Test-gap register** (areas with little or no coverage):

| Gap | Current | Needed |
|---|---|---|
| TG-1 Architecture invariants | none | every Domain model uses BelongsToTenant/Auditable unless allow-listed; no `bypass()` outside platform namespaces |
| TG-2 Cross-tenant export/AI leakage | isolation tested at ORM only | export, warehouse, report API and assistants under two tenants |
| TG-3 Queue behaviour | sync driver in tests | worker-level tests for SendWebhook retries, DB-notification delivery |
| TG-4 Compliance rates | StatutoryTest against illustrative pack | golden cases from official examples once rates verified |
| TG-5 Payroll edge cases | 14 tests | mid-month join/exit, revision, LOP, negative net, reopen |
| TG-6 Learning / Assets / Exit | 3–4 each | lifecycle sequences, clearance with assets, settlement math |
| TG-7 Imports | blueprint import only | none exist for data import (feature absent) |
| TG-8 Document security | 6 | signature expiry, cross-tenant download, purpose audit |
| TG-9 SCIM/SSO error paths | 8 enterprise tests | domain rejection, deprovision, replay of state |
| TG-10 Browser | none | Livewire component tests exist; no Dusk/Playwright |

## 10. Infrastructure Baseline

- **Queue**: `database` driver; tables present; 10 pending `Filament\Notifications\DatabaseNotification` jobs dated 2026-09-26 and 0 failed jobs were found, showing that Filament's in-app notifications are queued and **no worker runs in this environment** — in-app notifications will not appear until `php artisan queue:work` runs. Business logic is not coupled to the driver (jobs use the standard `ShouldQueue` contract). Synchronous heavy operations to move to jobs later: attendance processing, leave accrual, payroll calculate/finalize, report runs and exports, warehouse export, learning tick, notification fan-out, SCIM bulk operations.
- **Cache / session**: database. Fine for single node; move to Redis with the queue.
- **Storage**: local private disk; documents keyed by disk+path; signed downloads. Object-storage migration requirements: S3-compatible disk in `filesystems.php`, per-tenant prefix in `Documents::store`, server-side encryption, temporary URLs replacing the streamed download or kept behind the same signed route, backfill by copying existing paths, retention purge for documents.
- **Scheduler**: 13 commands; none use `withoutOverlapping()` or `onOneServer()` — required before multi-node deployment.
- **Redis/Horizon readiness**: env names exist; no package; roadmap item (infra phase).

## 11. Integration Readiness

**RMS IS NOT A PEOPLEOS DEPENDENCY.** No RMS code, package, table, model, service or migration exists in PeopleOS; employee creation, onboarding and every module work without any external system. The only recruitment-facing surface is PeopleOS-owned: `POST /api/v1/pre-employees` (scope `rms.write`) accepting an external offer reference and organisation codes, idempotent on the reference, and `GET /api/v1/pre-employees/{reference}`. The external reference is stored as an opaque string (`employees.external_reference`, `employees.source`).

Future contract (documented, not implemented):

| Object | Purpose | Status |
|---|---|---|
| `external_references` (tenant, entity_type, entity_id, external_system, external_entity_type, external_entity_id, external_reference, metadata; unique per system+type+id) | any PeopleOS entity ↔ any external id | NOT IMPLEMENTED (single column today) |
| `inbound_events` (tenant, external_system, event_id unique, type, occurred_at, payload, signature, status, attempts, error, processed_at, correlation_id) | idempotency, retry, dead-letter, replay | NOT IMPLEMENTED |
| `integration_mappings` (tenant, external_system, dimension, external_value, peopleos_id) for company, legal entity, department, location, designation, level, grade, employment type, category, work mode, manager | organisation mapping | NOT IMPLEMENTED (code resolution is implicit mapping) |
| `status_mappings` (external status → lifecycle action) | e.g. Offer Accepted → preboarding | NOT IMPLEMENTED |
| `compensation_mappings` (offered components → salary structure + component values → draft salary assignment for review) | compensation hand-over | NOT IMPLEMENTED |
| Document hand-off (signed pull URL or push to quarantine type → classify → verify → EmployeeDocument) | documents | NOT IMPLEMENTED (verification flow reusable) |
| Inbound security (per-integration secret, HMAC signature + timestamp, replay window, rate limit) | mirror of outbound scheme | NOT IMPLEMENTED |
| Correlation ids | request id exists; propagate external event id into audit metadata | PARTIAL |

Naming only: the scopes `rms.write`/`rms.read`, the default `employees.source` value `rms` in `CreatePreEmployeeAction` and one label in `config/peopleos.php` mention RMS by name. They are strings, not dependencies; rename them to `recruitment.*` when the hub is built so the contract names no specific product.

## 12. Architecture Risks

| Risk | Detail | Owner phase |
|---|---|---|
| ABAC enforcement gap | Rule engine exists; data access not scoped by company/location/department for HR roles; no field matrix | Access & security hardening |
| Legal Entity / Establishment modelling | **Current model**: Company = legal entity; statutory registrations (PF code, ESI code, PT registration, TAN, PAN, PT/LWF states) live on `company_statutory_profiles` (one per company). **Affected tables**: companies, company_statutory_profiles, payroll_runs (per company), payroll_entries, compliance_rules (jurisdiction + state, no entity), employee_positions (company_id). **Payroll dependency**: runs, bank files and payslips are per company; PT/LWF state comes from the profile, not from the employee's work location. **Compliance dependency**: filings and registers are per establishment in law; multi-state employers cannot be represented. **Migration implication**: additive — introduce `legal_entities` (optional, default one per company) and `establishments` (registrations per state/location) with nullable foreign keys on profiles, positions and runs, backfilled from companies; no destructive change. **Recommended resolution**: decide before compliance filings are built; this is a **future architecture decision**, not changed in 0.1 | Compliance / organisation hardening |
| Integration hub gaps | §11 objects absent; single external_reference column | Integration phase |
| Audit action gaps | ARCHIVE, ASSIGN, STATUS_CHANGE, DOWNLOAD, batch id | Audit hardening |
| Infrastructure limitations | database queue/cache/session, no worker running, synchronous heavy jobs, local disk, no overlap guards, no CI | Infrastructure phase |
| Compensation gaps | no bands, cycles, budgets, revision workflow; salary history only | Compensation phase |
| Compliance verification | India rates illustrative (file header says so); AE pack partial; no Form 16/ECR/challan/registers; rules lack legal entity/establishment | Compliance hardening with official sources |
| Observability gaps | no metrics, tracing, alerting, job dashboard | Infrastructure phase |
| Storage exposure | ticket attachments via plain storage URL | Security hardening (small fix) |
| Notification delivery | in-app depends on queue worker; sms/whatsapp/push placeholders | Infrastructure + notification providers |

## 13. Technical Debt Register

| ID | Item | Severity | Recommendation |
|---|---|---|---|
| TD-01 | No CI, no static analysis, no architecture tests | High | GitHub Actions: pint --test, php artisan test, Larastan; arch tests (TG-1) |
| TD-02 | ABAC data-access enforcement not complete | High | Role access scopes over position dimensions using RuleEngine |
| TD-03 | In-app notifications queued with no worker; heavy work synchronous | High (ops) | Document worker requirement now; Redis/Horizon + queued jobs later |
| TD-04 | Statutory rates illustrative; filings absent; no establishment | High (go-live) | Verified packs with sources; filings; establishment model |
| TD-05 | Ticket attachments served via `Storage::url()` without auth/signature | Medium-High | Route through a signed, policy-checked download like documents |
| TD-06 | `league/csv` and `guzzlehttp/guzzle` used directly but not declared in composer.json | Medium | `composer require` both (no new install; lock update only) |
| TD-07 | Stray `tenants.base_currency` column in dev DB, absent from migrations | Low | Drop manually in dev; no migration |
| TD-08 | Scheduler lacks `withoutOverlapping()/onOneServer()` | Medium | Add before multi-node |
| TD-09 | Payroll: mid-month revision proration, arrears, ESI period lock, simulation, workflow approval | High | Payroll hardening |
| TD-10 | Compensation planning absent (bands, cycles, budgets) | Medium | Compensation phase |
| TD-11 | Workflow: no amount/dynamic approvers, no generic node retry, no delegation UI | Medium | Workflow hardening |
| TD-12 | Configuration: no simulation; statuses lack archived/superseded | Medium | Configuration hardening |
| TD-13 | Rule engine: limited operators, no nested groups | Low-Medium | Extend |
| TD-14 | Audit actions missing (ARCHIVE, ASSIGN, STATUS_CHANGE, DOWNLOAD, batch id) | Medium | Additive enum + recorder support |
| TD-15 | Letters HTML only; no PDF/e-sign | Medium | PDF renderer + adapter |
| TD-16 | Reporting in-memory, 10k rows, CSV only | Medium | SQL push-down; xlsx |
| TD-17 | AI keyword retrieval, single provider, no quotas | Low | Embeddings; quotas |
| TD-18 | No SAML, SCIM groups→roles, dedicated-DB routing | Medium (enterprise) | Enterprise hardening |
| TD-19 | No data import (employees, attendance, leave, balances) | Medium | Import centre with dry-run + audit |
| TD-20 | Large classes: WorkflowEngine 548, Appraisals 412, AppServiceProvider 404, ViewEmployee 350 | Medium | Split when touched; per-module providers |
| TD-21 | `@php` blocks in 16 Blade pages (presentation formatting; no queries found) | Low | Move to view models when touched |
| TD-22 | Administrator Guide builder script not in repo; README is stock Laravel | Low | Add `scripts/build-admin-guide.py`; project README |
| TD-23 | Document retention purge excludes documents; no per-tenant storage prefix enforcement | Medium | Storage phase |
| TD-24 | No OpenAPI spec; controllers build arrays inline; no idempotency keys | Medium | API hardening |
| TD-25 | Notification channels sms/whatsapp/push are LogChannel | Low | Provider adapters behind `Channel` contract |

## 14. Recommended Next Phase

**Phase 0.2 — Baseline Protection and Security Hardening**, before any roadmap feature work:

1. CI pipeline (Pint, Pest, Larastan) and architecture tests for tenancy/audit invariants (TD-01, TG-1, TG-2).
2. Declare the undeclared Composer dependencies (TD-06) and add scheduler overlap guards (TD-08).
3. Fix the ticket-attachment download path (TD-05) and document the queue-worker requirement (TD-03).
4. ABAC access scopes for HR roles (TD-02) — the largest security gap with a small design surface because the rule engine already exists.
5. Additive audit actions (TD-14).

After 0.2, the roadmap order should be: infrastructure (Redis/Horizon, object storage) → compliance verification and Establishment decision → payroll hardening → integration hub. Feature phases 10–16 already exist and should not be re-run; their gaps are tracked above.

## Phase gate

```
PHASE 0.1 STATUS: BASELINE ESTABLISHED

Baseline Commit:
430e0f7
Tests:
284 passed, 0 failed, 2,535 assertions, 452 s
Pint:
passed
Git Status:
CLEAN
RMS Dependency:
NONE
Next Recommended Phase:
Phase 0.2 — Baseline Protection and Security Hardening (CI + architecture tests, ABAC access scopes, attachment download fix, audit action additions)
```
