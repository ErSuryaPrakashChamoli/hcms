# Phase 14: integration, intelligence and platform hardening

What Phase 14 built, the conventions to follow, and where each rule is enforced. The scope comes from
the Phase 14 prompt. The full account is in `docs/PeopleOS-Phase-14-Report.md`; the discovery map is in
`docs/PeopleOS-Phase-14-Discovery.md`.

## 1. Integration Hub (`app/Domain/Integration`), ADR-0011 / ADR-0012 implemented

| Table | Purpose | Key rules |
|---|---|---|
| `integration_systems` | One per external system per tenant (`code`, `kind`, status, encrypted inbound secret, signature tolerance, bound API key, allowed event types) | Secret shown once (`whsec_…`), rotation audited |
| `external_references` | PeopleOS entity ↔ external id | Unique `(tenant, system, external type, external id)`. A reference is never re-pointed or deleted, only retired. PeopleOS ids are never replaced |
| `inbound_events` | Signed, idempotent inbound events | Unique `(tenant, system, idempotency key)`; same key + different body → 409. Payload encrypted and purged after retention (metadata and SHA-256 stay). States `received → processing → succeeded / failed / retrying → dead_letter → (reprocess) received`. Leased claim (conditional update) plus an in-lock attempt check |
| `integration_mappings` | External value → PeopleOS code per dimension | The PeopleOS code is the default mapping |
| `webhook_deliveries` (+ `correlation_id`, `dead_lettered_at`, `replay_count`) | Outbound | Leased claim, backoff, dead letter (audited), replay (audited), `X-Correlation-Id` |

Handlers implement `Contracts\InboundEventHandler` and are registered in `peopleos.integration.handlers`.
Two vendor-neutral handlers are built in: `reference.link` and `reference.retire`. No vendor adapters
and no RMS.

## 2. API platform

- `ApiResponseContract`: every `/api/*` error is `{message, code, request_id, errors?}`; 5xx detail is scrubbed outside debug.
- `AssignRequestId`: accepts `X-Correlation-Id` / `X-Request-Id` (8–64 safe characters) or mints a ULID; echoed back and stored as `request_id` on audit rows.
- `EnforceIdempotency` (`api.idempotent`, v1 write routes): `api_idempotency_keys` (encrypted response). Replay sets `Idempotent-Replayed`; another body → 422; in flight → 409; a 5xx releases the key. Routes with domain-level idempotency opt out.
- `PaginatesApi::sorted()` is the single paginator; an unknown sort field → 422.
- Rate limits are keyed by the key prefix or its SHA-256, never the secret. Suspended tenants' keys do not authenticate.
- OpenAPI 3.1: `docs/api/openapi.json` (`peopleos:openapi [--check]`) with `x-scope`, `x-audience` and `x-data-classification`.

## 3. Employee 360 and timeline

- `Experience\Services\Employee360::for(viewer, employee)`: one section per domain, each computed only when that domain's own rule allows it (compensation access level, case access, configured manager relationships, identified-only engagement, …). It owns nothing and writes nothing; no amounts or identifiers (invariant 30).
- `Lifecycle\Support\TimelineCategories`: every timeline category has a kind (lifecycle / employment / service / communication / domain) and a per-viewer visibility rule.

## 4. Change Intelligence and analytics

- `Audit\Services\ChangeIntelligence`: a read-only lens on the audit trail, organisation-scoped through employee- and person-linked records. Classified values are masked without `employee.sensitive.view`. The generic audit list applies the same scope.
- `Analytics\Services\PlatformAnalytics`: a cross-domain overview composed from the domains' own analytics, each area gated by its domain permission. Groups below `PEOPLEOS_ANALYTICS_MIN_GROUP` are suppressed. No scoring, ranking or prediction.
- Leak fixes:
  - KPI gating (`WorkforceMetrics::PERMISSIONS`).
  - Fields fail closed without a user.
  - Scheduled reports claim their slot and run as the owner.
  - Truncation is flagged.
  - The warehouse feed is non-sensitive by default.

## 5. AI assistive controls (ADR-0016)

- `AiDataPolicy` classifies data as allowed / restricted / prohibited, under the tenant setting `ai.external_data_policy` (`none | allowed | restricted`). Prohibited data is never sent or stored. Each external call is audited (`AI_EXTERNAL_REQUEST`) with counts only.
- Model text is marked AI-generated. Actions are proposals: internal links, confirmed on the domain screen, no execute path.
- There is a per-user rate limit. Only `ai.admin` reads others' conversations.
- `AttritionRisk` is retired. The Manager and Workforce assistants never predict.

## 6. Operations

- **Health:** `/health/live` checks only the process. `/health/ready` treats database, cache and storage as critical; queue, failed jobs, scheduler heartbeat, dead letters and Redis are warnings.
- **Logging:** a redaction tap on every channel; a correlation id per command run; failed-job and slow-query logging.
- **Queues:** `retry_after` is 3900 s by default; every job has a timeout (and backoff when retried). `BindTenantContext` refuses tenant-aware jobs without a tenant and skips suspended tenants. See `docs/operations/queue-and-scheduler.md`.
- **Scheduler:** `TenantRunner` gives per-tenant isolation and skips suspended tenants; `scheduler_claims` holds once-per-period slots; the heartbeat runs every minute.
- **Notifications:** in-app messages are stored at once; external channels are delivered by `DeliverNotification` after commit (claimed). Correlation id plus dedupe key. Templates get masked classified subjects.
- **Storage:** no framework file serving. Grievance evidence is tenant-prefixed and fingerprinted. Every download is audited. Stored report exports can be re-downloaded only by their producer or owner.
- **Readiness:** `peopleos:readiness` / the Platform readiness page. It never declares production readiness (see `docs/operations/production-configuration.md`).

## 7. Security fixes (14.7)

- Livewire requests run `ResolveTenant` and `EnforceSecurityPolicy` (persistent middleware).
- User ids from form input resolve with `User::forCurrentTenant()`. Talent review participants are validated in the domain.
- `PersonMatcher` and `EmployeeCodeGenerator` look across the whole tenant. Matches outside the caller's scope are disclosed minimally, and a scoped user can never override them.
- A SCIM userName held anywhere returns 409 with a neutral message.
- The engagement raw aggregate queries filter by tenant explicitly.

## 8. Where the rules are enforced

| Rule | Test |
|---|---|
| 30 final architecture invariants | `tests/Feature/Architecture/PlatformInvariantsTest.php` |
| Integration Hub behaviour | `tests/Feature/Integration/IntegrationHubTest.php` |
| API platform | `tests/Feature/Platform/ApiPlatformTest.php` |
| 360, timeline, Change Intelligence, analytics | `tests/Feature/Platform/IntelligenceTest.php` |
| AI controls | `tests/Feature/Ai/AiAssistiveControlsTest.php`, `AiTest.php` |
| Operations hardening | `tests/Feature/Platform/OperationsHardeningTest.php` |
| Tenant isolation fixes | `tests/Feature/Platform/TenantIsolationHardeningTest.php` |
| Scale (SQLite 15 / 150 / 1,500; MySQL to 10,000) | `tests/Feature/Platform/PlatformScaleTest.php`, `tests/MySql/PlatformScaleTest.php` |
| MySQL concurrency (10 races) | `tests/MySql/PlatformConcurrencyTest.php` |
