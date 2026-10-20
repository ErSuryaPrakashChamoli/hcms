# PeopleOS Phase 14 — Discovery map

For: engineers and reviewers of Phase 14 (final integration, intelligence, platform hardening and
production readiness).

**Baseline.** `906f719` (Phase 13 approved), branch `feature/oct_1_phase_1`, clean tree, 40 commits
ahead of origin, 0 pushes.

**Method.** Read-only discovery before any code change:
- **Documents:** all architecture documents, the Phase 0–13 reports, the decision register
  (ADR-0001–0015), the security invariants, the integration contract, queue / scheduler operations and
  statutory production readiness.
- **Code traced:** integration, API, Employee 360, timeline, analytics, audit read side, AI,
  notifications, queues, scheduler, storage, observability, caching, and every raw query and
  scope-bypass site under `app/`.

Statutory status at the start (unchanged by discovery):
- 24 rule versions;
- 0 verified;
- 5 open notices;
- production statutory readiness NOT DECLARED.

## A. What already exists

| Area | Existing implementation |
|---|---|
| Credentials | `api_keys`:<br>- tenant-bound; prefix plus SHA-256 of a 40-character random secret;<br>- scopes, expiry, status;<br>- `AuthenticateApiKey` binds the tenant before route binding and stamps the audit source `api:<key>` |
| Read / write API | `/api/v1/*`:<br>- about 100 routes over 35 scopes;<br>- `{data, meta}` pagination (`PaginatesApi`, max 200);<br>- explicit per-field arrays (one Resource class: `EmployeeApiResource` with a sensitive include);<br>- write endpoints for employees, lifecycle, punches, regularisations, leave, learning, goal progress, pre-employees, BGV checks, device punches;<br>- `Idempotency-Key` on leave and goal progress; natural keys on punches and pre-employees |
| SCIM 2.0 | `/api/scim/v2/Users` (scope `scim`) |
| Outbound webhooks | - `webhook_endpoints` (encrypted secret, event subscriptions);<br>- `webhook_deliveries` rows written when the event fires: an outbox, delivered by `peopleos:webhooks:deliver` every minute, outside any business transaction;<br>- HMAC `sha256(timestamp.body)` headers, exponential backoff, 5 attempts, retry action;<br>- allow-listed events with redacted context (`WebhookEventBridge`) |
| External ids | `employees.source` / `external_reference`, `bgv_cases.external_reference`, `attendance_punches.external_id` + `correlation_id`, `users.external_id` |
| Request ids | `AssignRequestId`:<br>- ULID or the client's `X-Request-Id`;<br>- carried in Context, echoed in the response;<br>- stored on every audit event;<br>- visible Context flows into logs and queued jobs |
| Employee 360 | `EmployeeResource` view:<br>- infolist (identity, position, custom fields, applicable policies);<br>- about 30 relation-manager tabs with policy gates (timeline, onboarding, attendance, leave, performance, learning, skills, career, talent, succession, certifications, development, assets, requests, employment, compensation, compensation changes, reporting, person satellites, documents, BGV, bank, workflows, audit history);<br>- sensitive reveals audited |
| Timeline | `Timeline::record()`:<br>- the single writer, with 14 categories written by the domains;<br>- sensitive categories (compensation, bank, statutory, personal) hidden without `employee.sensitive.view` |
| Analytics | - 9 report datasets (`DatasetRegistry`) with sensitive-field stripping;<br>- `ReportRunner`; dashboards / widgets; `WorkforceMetrics`;<br>- module analytics with small-group suppression (performance, learning, service desk, compensation, workforce, talent, engagement) |
| Audit | - Hash-chained, append-only `AuditRecorder` with `audit_chain_locks` (Phase 13) and anonymous mode;<br>- operation ids and request ids;<br>- `AuditEventResource` (filters: action, module, date) with chain verification;<br>- per-entity history relation manager |
| AI | - `AiGateway`: feature flag, per-assistant permission, length check; LLM only with feature `ai.llm` (default off) and a configured provider;<br>- `ai_interactions` log;<br>- 6 read-only assistants; deterministic answers first |
| Notifications | `NotificationEngine` (tenant rules → audience → channels → templates) → `Notifier` → `notification_deliveries` (queued / sent / failed / read) |
| Queues | - 22 job classes, all `TenantAwareJob` + `BindTenantContext` (architecture test), most `ShouldBeUnique`;<br>- `failed_jobs` (database-uuids) |
| Scheduler | 21 `peopleos:*` entries, all `withoutOverlapping()->onOneServer()`, iterating tenants with `runAs`; per-module reminder logs |
| Storage | - Private documents disk (`peopleos.documents.disk`);<br>- 5 signed, re-authorised download routes (documents, ticket and grievance attachments, certificates, announcements);<br>- no `Storage::url`, no public uploads |
| Statutory gate | - `ComplianceReadiness::forReturn()`: 14 checks, no override, `assertExportable()`;<br>- verified-rule enforcement at payroll finalisation (always on in production);<br>- Compliance Control Room;<br>- 24 rule versions, 0 verified, 5 open notices |
| Observability | Laravel `/up`, request ids, audit; nothing else |

## B. What is incomplete

**Integration Hub:**
- **Missing tables:** none of ADR-0011 `external_references`, ADR-0012 `inbound_events` (states,
  idempotency, dead-letter) or the ADR-0014 `integration_mappings`.
- **No inbound signed webhooks:** no timestamp / replay window, no per-integration secret.
- **No dead-letter state** for outbound deliveries.
- **Envelope gaps:** no correlation id or tenant in the outbound envelope; no unique
  `(endpoint, event_id)`.
- **BGV callback** has no signature and no idempotency.

**API platform:**
- **Error format:** no consistent error envelope. 404 messages leak model class names, and there is no
  error code or request id in error bodies.
- **Sorting:** no `sort` parameter anywhere.
- **Pagination:** six private copies of the pagination helper.
- **Idempotency:** no generic `Idempotency-Key` for writes (POST employees, lifecycle,
  regularisations, BGV checks, learning enrolments, where the key is validated but ignored).
- **OpenAPI:** no description at all.
- **Per-key organisation scope:** deferred (ADR-0014).

**Employee 360:**
- **Duplicate tabs:** Skills and Certifications are registered twice.
- **Missing domains:** no Payroll (payslips), Goals, Exit, Letters, Alumni, Communication
  (acknowledgements) or Workforce (seat and organisation path).
- **Engagement:** correctly shows nothing per person for anonymous / confidential surveys.

**Timeline:**
- No distinction between lifecycle, employment, service, communication and domain events.
- The category filter mismatches (`documents` vs `document`, and most categories are missing).

**Change intelligence:**
- Audit search cannot filter by employee, entity, actor, source, request / correlation id or operation
  id (operation id is not even shown).
- Per-employee history covers only the Employee row, not the employee's child records.

**Analytics:**
- No cross-domain foundation.
- `ReportRunner` caps rows *before* filtering (silent truncation).
- `WorkforceMetrics` has no per-metric permission check and no suppression.

**AI:**
- **No data-classification boundary:** the question and the draft answer go to the provider
  unredacted.
- **No rate limit.**
- **No explicit proposal → confirmation pattern:** actions are URL suggestions only, which is safe but
  undeclared.
- **No "AI-generated" marking.**

**Notifications:**
- **Synchronous email:** `Mail::send()` runs inside requests and domain transactions, where it is a
  fragile external call.
- **In-app "sent" is only queued:** Filament queues the in-app write, so "sent" can mean "queued".
- **No correlation id, no duplicate protection** on deliveries.
- **Templates can read every visible attribute of the subject model**, including salary, statutory and
  confidential models.

**Queues:**
- **Timeout vs retry window:** long jobs (`$timeout` 1800 / 3600) exceed `retry_after` 90 and can run
  twice.
- **No backoff** on any job.
- **Null tenant:** a `TenantAwareJob` with a null tenant runs unbound and silently does nothing.
- **Suspended tenants** are not refused.
- **Stale scope cache:** the `AccessScopes` singleton memo is never reset between jobs.

**Scheduler:**
- Suspended tenants are processed.
- One tenant's exception stops the loop for every later tenant.
- `lifecycle:reminders` re-emits daily (no claim log).
- `reports:run-due` has no claim.
- `warehouse:export` truncates silently.

**Storage:**
- Grievance evidence is stored flat (`grievances/`), with no tenant prefix, allow-list or fingerprint.
- Document downloads are audited only for sensitive types.
- These downloads are not audited:
  - report re-download (which also checks only `analytics.export`, not the report policy);
  - letter HTML;
  - blueprint export.
- `local` disk `serve => true` registers an unused signed `/storage` route.
- Six services hard-code the `local` disk.
- The S3 adapter package is not installed.

**Observability:**
- **Health:** only `/up`. No ready / live split, no dependency checks, no scheduler heartbeat.
- **Logging:** no log redaction, no slow-query logging, no failed-job hook.
- **Request ids:** the client-supplied `X-Request-Id` is trusted unvalidated.
- **No tenant in logs.**

**Production readiness:**
- No readiness dashboard or command, no production configuration checklist, no backup / DR document.
- The statutory gate covers statutory returns only.

## C. What is duplicated

- The pagination helper (six copies in API controllers).
- Employee 360 registers `SkillsRelationManager` and `CertificationsRelationManager` twice.
- A duplicate route name, `api.v1.attendance.punches.store` (lines 48 and 205), breaks `route:cache`.
- Two tenant re-binding mechanisms for jobs: `BindTenantContext` and `Context::hydrated`. Both are
  kept: the second covers dehydrated context generally. Documented, not removed.

## D. What violates the current architecture

| Finding | Rule violated |
|---|---|
| **`AttritionRisk`**: a named per-employee flight-risk score (tenure, pay, rating, learning, 1:1s, absences, feedback, open grievance), shown to managers, HR and executives (Workforce Intelligence page, Manager and Workforce assistants). It reads grievances and feedback past their policies | "No flight risk / likelihood to leave / hidden scoring" (Phases 13–14), ADR-0015 spirit, grievance confidentiality |
| `TalentReviewResource` participant picker lists users of **every tenant** (`User::query()` without `forCurrentTenant()`) | Tenant isolation |
| Panel `ResolveTenant` / `EnforceSecurityPolicy` are not Livewire-persistent, so `/livewire/update` runs without the tenant bound (fails closed) and without the IP allow-list or idle timeout | Security chain, tenant resolution |
| Duplicate-person (`PersonMatcher`) and employee-code checks run inside the HR user's organisation scope. A scoped HR user can create a second Person for someone outside their scope | One Person = one natural person (ADR-0002) |
| Scheduled reports run with `fieldsFor(null)`: all sensitive fields, and no organisation scope in the console | Field security, organisation scope |
| `WorkforceMetrics` people cost, high performers and open grievances visible to any `analytics.view` holder | Field security, permission per domain |
| Timeline `performance` entries (PIP opened, appraisal outcome) visible to anyone who can view the employee; the exit reason is stored as free text in the timeline description | Field security, confidential ER information |
| Audit search ignores organisation scope (an `audit.view` holder sees every tenant event) | Organisation scope |
| AI interaction log (payslip answers) readable by every `audit.view` holder | Field security (financial) |
| API rate limiter keys cache entries by the **raw API key** | Secrets never in logs / storage |
| Suspended tenants' API keys still authenticate | Tenant status |
| SCIM create / replace on an email that exists in another tenant gives a 500 that reveals the email exists | Tenant isolation |
| Synchronous email inside domain transactions | "No fragile external calls inside critical transactions" |

## E. What can be extended (no rebuild)

- **Webhooks:** extended into the Integration Hub's outbound side (correlation id, tenant, dead letter,
  replay, unique event per endpoint, timestamp-tolerant verify).
- **`api_keys`:** stay the integration credential.
- **`AssignRequestId`:** becomes the correlation id (validated, plus `X-Correlation-Id`).
- **`PaginatesApi`:** the one pagination and sorting helper.
- **Notifications:** `Notifier` / `notification_deliveries` gain correlation id, a dedupe key and
  asynchronous email.
- **`AuditRecorder` / audit events:** the Change Intelligence read model (no new store).
- **Employee 360:** `EmployeeResource` gains the missing tabs over the owning domains' models,
  policies and services.
- **Analytics:** module analytics services (with their suppression) behind one cross-domain
  analytics facade.
- **AI:** `AiGateway` gains the data-classification boundary, rate limit and AI-generated marking.
  Assistants keep their permissions.
- **Statutory gate:** `ComplianceReadiness` feeds the platform readiness checklist unchanged.
- **Scheduler:** commands iterate tenants through one shared helper (active tenants, per-tenant
  isolation).

## F. What genuinely requires new code

- **Integration Hub:**
  - integration systems (per-integration inbound secret);
  - `external_references`;
  - `inbound_events` (state machine, idempotency, dead letter, reprocess, payload purge);
  - `integration_mappings`;
  - the signed inbound endpoint, handler registry and processing job.
- **API:** generic idempotency store (`api_idempotency_keys`), consistent error renderer, sorting,
  OpenAPI generator and committed specification.
- **Employee 360:** an orchestration service (permission-aware section summaries).
- **Change Intelligence:** service and page.
- **Analytics:** cross-domain service and page.
- **AI:** data policy (allowed / restricted / prohibited) and payload guard.
- **Health and readiness:** live / ready health endpoints, scheduler heartbeat, health checks.
- **Logging:** log redaction processor, slow-query and failed-job logging.
- **Production readiness:** platform readiness service, page and command, production configuration
  checklist, backup / DR runbook with a local restore test.
- **Tests:** the final 30-invariant architecture suite, the Phase 14 MySQL races and the scale suite.

## G. What must remain deferred

- **Vendor adapters:** any vendor-specific integration (RMS, payroll providers, devices, finance, IdP,
  benefits, BGV vendors). The hub is generic.
- **API scope:** per-API-key organisation scope (ADR-0014 data contract) and user-token APIs (no
  Sanctum / OAuth). Today's API is the integration (machine) API; self-service, manager and HR user
  APIs are documented as deferred surface.
- **Statutory:** rule verification, the EPF September 2026 split and TDS changes (statutory track; the
  gate stays enforced and BLOCKED).
- **Infrastructure:** production backups, S3 and Redis verification (not available here), and HA / DR
  drills on production infrastructure.
- **AI:** embeddings / vector search, streaming, multi-turn AI.
- **Predictive analytics:** any predictive or scoring analytics (prohibited, not merely deferred).
- **Recruitment:** recruitment, ATS and requisitions (out of scope by rule).

## Plan (commits)

| Commit | Content |
|---|---|
| 14.1 | This discovery |
| 14.2 | Integration Hub |
| 14.3 | API platform and OpenAPI |
| 14.4 | Employee 360, timeline, Change Intelligence, cross-domain analytics, analytics leak fixes |
| 14.5 | AI assistive foundation (data policy, rate limit, marking, proposals) and `AttritionRisk` retirement |
| 14.6 | Observability, queues, scheduler, notifications, storage |
| 14.7 | Security fixes, tenant isolation, MySQL concurrency, scale |
| 14.8 | Production readiness, backup / DR, documentation and report |
