# SaaS.3 — Capability Catalogue

**Status:** Implemented (shadow mode; nothing is enforced). **Source of truth:** `App\Domain\Entitlements\Enums\Capability`. Tests in `CapabilityCatalogTest` verify:
- every one of the 51 permission-key prefixes and every one of the 37 API scopes belongs to exactly one capability;
- features and limits sit inside a module;
- the protected set is as listed below.

## How to read this catalogue

| Term | Meaning |
|---|---|
| **Capability** | Anything a tenant can be commercially entitled to. Each one has a stable key and is one of three types (module, feature, limit) |
| **Module** | An area of PeopleOS, on or off |
| **Feature** | A narrower on/off ability inside a module. It is never available unless its module is |
| **Limit** | A numeric ceiling (no value = unlimited), compared with a measured usage. "Not available" (module or feature off) and "available but over the limit" are different decisions (`NOT_ENTITLED` vs `LIMIT_EXCEEDED`) |
| **Enforcement class** | Whether the capability may ever be enforced. `not commercial`: never an entitlement, always `NOT_APPLICABLE`. `protected`: statutory or lifecycle-critical; only through a separately approved rollout that never interrupts work in progress. `eligible`: may be enforced in a staged rollout later |
| **Authorisation it sits beside** | The permission-key prefixes whose checks the module would narrow *if* enforcement were ever approved. Today these permissions decide alone, exactly as before |
| **Observed at (surface)** | Where SaaS.3 records a shadow decision: one business action, not a UI element |

**What a capability never controls:**
- who inside a tenant may act (roles, permissions, organisation and relationship scope, field security and record policies are unchanged);
- the HCM core;
- any security control (MFA, audit, encryption, sensitive-access auditing, IP allow-list, tenant isolation, data export).

Security is never an upsell.

**Packaging is not decided here.** Which capabilities form which plan, every limit value and every trial term are open decisions (SaaS.1 D-1, D-4, D-13, D-14, D-16). The catalogue is the vocabulary those decisions will use.

## Modules

| Key | Purpose (what the tenant gets) | Controls (when enforced) | Does NOT control | Permission prefixes | API scopes | Observed at | Class |
|---|---|---|---|---|---|---|---|
| `core` | People records, organisation, documents, workflows, forms, policies, configuration, users, roles, notifications, audit, security | Nothing: never commercial | — | tenant, company, user, role, settings, features, audit, organisation, people_setup, custom_field, form, policy, configuration, blueprint, workflow, task, document, legal_entity, establishment, security, currency, notification, employee | employees.read, employees.write, employees.sensitive.read, organisation.read, documents.read, workflows.read | — (`NOT_APPLICABLE`) | not commercial |
| `onboarding` | Onboarding plans and tasks, background verification, RMS pre-employee hand-over | Starting onboarding and BGV, the RMS hand-over API | Hiring itself (employee lifecycle is core) | onboarding, bgv | rms.write, rms.read, bgv.write | API requests with those scopes | **protected** (lifecycle) |
| `attendance` | Punches, shifts, schedules, attendance processing, regularisation | Recording punches, processing attendance days | Leave balances; payroll | attendance | attendance.read, attendance.write | `attendance.punch.record`, `attendance.day.process`, API | eligible |
| `leave` | Leave policies, balances, requests, approvals | Requesting and approving leave | Attendance; payroll deductions already computed | leave | leave.read, leave.write | `leave.request`, API | eligible |
| `payroll` | Payroll runs, payslips, statutory compliance (EPF, ESI, PT, LWF, TDS) and returns | Opening, calculating and finalising runs; statutory returns | A run already started (never interrupted: §16 of the report) | payroll, compliance | payroll.read, compliance.read | `payroll.run.open`, `payroll.run.calculate`, `payroll.run.finalize`, API | **protected** (statutory) |
| `compensation` | Salary structures, pay ranges, compensation cycles | Compensation planning | Existing salary assignments used by payroll | compensation | compensation.read, compensation.sensitive | API | eligible |
| `performance` | Goals, appraisal cycles, check-ins, feedback, PIPs | Creating goals, launching cycles | Historical reviews (stay readable) | performance | performance.read, performance.write | `performance.goal.create`, `performance.cycle.launch`, API | eligible |
| `learning` | Catalogue, enrolments, paths, certificates, skills, development plans | Enrolment | Completed records | learning, skills, development | learning.read, learning.write, learning.costs | `learning.enrol`, API | eligible |
| `talent` | Career profiles, talent pools and reviews, succession | Talent processes | Employee records | career, talent, succession | career.read, talent.read, succession.read | API | eligible |
| `workforce` | Positions, workforce plans and scenarios | Planning | Positions used by the core | workforce | positions.read, workforce.read, workforce.costs | API | eligible |
| `assets` | Asset register, assignment, recovery | Asset management | Exit clearance already open | asset | assets.read | API | eligible |
| `service_desk` | HR requests, grievances, knowledge base | Service requests | Statutory grievance obligations (to be checked before enforcement) | servicedesk, grievance, kb | servicedesk.read | API | eligible |
| `engagement` | Surveys, announcements, communication campaigns | Running surveys and campaigns | — | communication, engagement | engagement.read, communications.read | API | eligible |
| `exit` | Resignations, exit cases, clearance, full and final settlement, letters, alumni | Exit processing | An exit already started; statutory settlement | exit, letter, alumni | — | — (core lifecycle actions are not observed) | **protected** (lifecycle, statutory settlement) |
| `analytics` | Reports, dashboards, exports, people analytics | Running reports | Operational lists inside modules | analytics | reports.run | `analytics.report.run`, API | eligible |
| `ai` | AI assistants (employee, policy, manager, HR) | Asking assistants | The deterministic rules the assistants read | ai | — | `ai.ask` | eligible |
| `integrations` | API keys, Integration Hub, inbound events, outbound webhooks | Integrations | Data already exchanged | api_key, integration, webhook | webhooks.read, integrations.read, integrations.write | via its features | eligible |
| `enterprise_identity` | SSO (OIDC) and SCIM provisioning | Enterprise identity | Password login, MFA (core security) | sso | scim | API (`scim`) | eligible |
| `warehouse` | Data warehouse feed | Exports to a warehouse | Other exports, the offboarding export | warehouse | — | — | eligible |

## Features

| Key | Module | Purpose | Observed at | Class | Relation to today's feature flags |
|---|---|---|---|---|---|
| `ai.external_model` | ai | Sending grounded facts to an external language model | `ai.external_model.request` (only when the flag and the tenant's AI data policy already allow the call) | eligible | The flag `ai.llm` stays the operational switch; this records the commercial question beside it |
| `analytics.scheduled_reports` | analytics | Scheduled report runs and e-mailed exports | `analytics.report.scheduled` | eligible | — |
| `integrations.api` | integrations | The REST API | `api.request` (every API request) | eligible | — |
| `integrations.webhooks` | integrations | Outbound webhooks | `webhooks.publish` (only when an endpoint receives the event) | eligible | — |

## Limits

| Key | Unit | Usage source in SaaS.3 | Observed at | Class |
|---|---|---|---|---|
| `active_employees.max` | employees | `BillableUnits::activeEmployees()`: employed lifecycle states, tenant-wide, counted only for a tenant with a finite limit | `employee.activate` (every move into an employed state: hire, mark joined, rehire, lifecycle API) | **protected** (blocking a hire is lifecycle-critical) |
| `users.max` | users | Not yet measured (contract only) | — | eligible |
| `admin_users.max` | users | Not yet measured | — | eligible |
| `legal_entities.max` | legal entities | Not yet measured | — | eligible |
| `locations.max` | locations | Not yet measured | — | eligible |
| `storage_bytes.max` | bytes | Not yet measured (needs the storage ledger, SaaS.1 G-MET-3) | — | eligible |
| `api_requests_monthly.max` | requests per month | Not yet measured (usage events, SaaS.1 §8.3) | — | eligible |
| `ai_requests_monthly.max` | requests per month | Not yet measured | — | eligible |

A limit evaluated without a measured usage answers `UNKNOWN` (`USAGE_UNAVAILABLE`) and carries the limit value, so the contract is usable before metering exists.

**SaaS.5 (limit model, ADR-0035).**
- Each limit's unit, module, measurement and minimum are methods of the same enum: `unit()`, `module()`, `measured()`, `minimumLimit()`, `followsModule()`.
- `api_requests_monthly.max` (module `integrations`) and `ai_requests_monthly.max` (module `ai`) never outlive their module. While the module is not entitled, they are **not included** (`DENY` / `MODULE_NOT_ENTITLED`).
- The other six limits belong to the core and are always applicable.
- A limit a plan leaves out is **not set**: no agreed limit, `UNKNOWN` / `LIMIT_NOT_CONFIGURED`.
- Full table: [SaaS-5 report §5](SaaS-5-Commercial-Packaging-Report.md#5-limit-model).

## Instrumented surfaces (summary)

| Surface | Capability | Code |
|---|---|---|
| `payroll.run.open` / `.calculate` / `.finalize` | payroll | `PayrollRuns::open/calculate/finalize` |
| `attendance.punch.record` | attendance | `PunchIngestion::record` (all punch sources) |
| `attendance.day.process` | attendance | `AttendanceProcessor::process` |
| `leave.request` | leave | `Leaves::request` |
| `performance.goal.create`, `performance.cycle.launch` | performance | `Goals::create`, `Appraisals::launch` |
| `learning.enrol` | learning | `Learning::enrol` |
| `analytics.report.run` | analytics | `ReportRunner::execute` (interactive and scheduled) |
| `analytics.report.scheduled` | analytics.scheduled_reports | `ReportSchedules::runDue` |
| `ai.ask` | ai | `AiGateway::ask` |
| `ai.external_model.request` | ai.external_model | `AiGateway::ask`, external-model branch |
| `api.request` | integrations.api + the module of each required scope | `AuthenticateApiKey` |
| `webhooks.publish` | integrations.webhooks | `Webhooks::publish` |
| `employee.activate` | active_employees.max | `LifecycleEngine::transition` |

Not instrumented on purpose:
- UI elements and navigation;
- the HCM core;
- exit and onboarding UI actions (protected; the API surfaces cover onboarding);
- statutory returns (part of payroll, protected).
