# Integration Contract

Frozen in Phase 0.3. Defines how external systems (recruitment, ATS, ERP, background verification, biometric devices, payment providers, messaging providers, identity providers) connect to PeopleOS. Nothing here obliges PeopleOS to any specific vendor; RecruitmentEdge RMS is one *possible* future source and must never become a dependency.

```
External system  ──►  PeopleOS Integration Hub  ──►  PeopleOS domain
   (RMS, ATS, ERP…)     auth · validate · map · idempotency · audit     (never the reverse)
```

## 1. What exists today

| Capability | Implementation |
|---|---|
| Credentials | `api_keys` (tenant, hashed secret with prefix, `scopes`, expiry, status); `X-Api-Key` or Bearer; 120 req/min per key |
| Read API | `/api/v1/{employees, attendance/records, leave/requests, leave/balances, payroll/runs, payroll/payslips, documents, assets, performance/appraisals, performance/goals, workflows/instances, workflows/tasks, reports/{id}/run}` — paginated, filtered by simple query parameters, tenant bound by the key |
| Write API | `POST /api/v1/pre-employees` (recruitment hand-over, idempotent on `offer.external_reference`), `POST /api/v1/bgv/cases/{reference}/checks` (**signed since the production readiness closure**, see §6), `POST /api/v1/attendance/devices/{device}/punches` |
| SCIM 2.0 | `/api/scim/v2/Users` with key scope `scim` |
| Outbound webhooks | `webhook_endpoints` subscriptions; `webhook_deliveries` with exponential backoff (5 attempts), delivery log, test ping |
| External ids | `employees.source`, `employees.external_reference`, `bgv_cases.external_reference`, `attendance_punches.external_id`, `users.external_id` (SCIM) |
| Adapters | `BgvProvider` (manual), attendance device `GenericJsonAdapter`, `AiProvider`, notification `Channel`s, SSO presets |

## 2. External references (ADR-0011): implemented in Phase 14

> **Phase 14:** built as `external_references` (`Integration\Models\ExternalReference`, `Services\ExternalReferences`). It is keyed by `integration_system_id` rather than a free-text system name. A reference is never re-pointed or deleted, only retired (audited). Linkable entities: employee, person, company, business unit, department, location, designation, grade, employment type, position. The design below is kept as the original contract.

```
external_references
  id, tenant_id, entity_type, entity_id, external_system, external_entity_type,
  external_entity_id, external_reference (opaque string), metadata (json), created_at, updated_at
  unique (tenant_id, external_system, external_entity_type, external_entity_id)
  index  (tenant_id, entity_type, entity_id)
```

- PeopleOS primary keys are never derived from, equal to, or replaced by external ids.
- One PeopleOS entity may carry many references (RMS candidate id, ATS application id, ERP employee id, BGV reference, payroll-provider id).
- References are audited (`Auditable`) and tenant-scoped; deleting an entity cascades its references.
- Until built, `employees.external_reference` (+ `source`) is the only reference slot and is limited to the recruitment hand-over.

## 3. Inbound integration events (ADR-0012): implemented in Phase 14

> **Phase 14:** `POST /api/v1/integrations/{system}/events`, with an API key scoped `integrations.write` and bound to the system, and an HMAC signature over `timestamp.body` checked against the system's tolerance window. Unique `(tenant, system, idempotency key)`; the same key with a different body → 409. The payload is encrypted, its SHA-256 kept, and the body purged after `PEOPLEOS_INTEGRATION_PAYLOAD_DAYS`. Processing uses a leased claim plus an in-lock attempt check. Retry backoff is `min(60, 2^attempts)` minutes; dead letter at `max_attempts`; reprocess is audited. Built-in handlers: `reference.link`, `reference.retire`. Status: `GET /api/v1/integrations/{system}/events/{eventId}`.

```
inbound_events
  id, tenant_id, source_system, event_type, external_id, correlation_id, idempotency_key,
  payload_metadata (json: keys, sizes, checksums — not the sensitive body), payload_ref (nullable
  pointer to a purgeable store), status, attempts, last_error, received_at, processed_at, next_attempt_at
  unique (tenant_id, source_system, idempotency_key)
```

States: `received → processing → succeeded | failed → retrying → dead_letter → reprocessed`.

Rules: tenant-aware (key → tenant), authenticated, validated against the event's schema, idempotent (same key ⇒ same outcome, no duplicate employees / onboarding / documents / salary assignments / transitions), auditable (every state change is an audit event with the correlation id), traceable (correlation id propagates into audit metadata and outbound webhooks). Sensitive payload bodies are purged after processing plus a short retention window; metadata stays.

## 4. Mappings (ADR-0014 addendum): dimension mappings implemented in Phase 14

> **Phase 14:** `integration_mappings(tenant, system, dimension, external_value → PeopleOS code)`, with the PeopleOS code as the default mapping. Status and compensation mappings remain deferred: no handler needs them yet, and none may finalise pay.

```
integration_mappings(tenant_id, external_system, dimension, external_value, peopleos_type, peopleos_id, status)
status_mappings(tenant_id, external_system, external_status, lifecycle_action, parameters)
compensation_mappings(tenant_id, external_system, external_component, salary_component_id, rule)
```

Dimensions: company, legal entity, establishment, location, department, business unit, division, team, designation, level, grade, employment type, employee category, work mode, manager. External values are opaque strings; enum equality is never assumed. Today the pre-employee ingress resolves organisation *codes*, which is implicit mapping and remains valid as the default mapping (external value = PeopleOS code).

Compensation: offered pay → mapping → salary structure + component values → **draft** `employee_salary_assignment` for HR review; never auto-finalised into payroll.

## 5. Future recruitment boundary (RMS or any ATS)

| External event (proposed) | PeopleOS action |
|---|---|
| `candidate.selected` | create/attach Person + pre-employee (`pre_employee`) with external references |
| `offer.created` / `offer.sent` | update offer metadata on the pre-employee |
| `offer.accepted` | `preboarding` initiated; onboarding template by rule; documents hand-off requested |
| `offer.withdrawn` / `candidate.declined` | cancel pre-employee (transition back / archive) |
| `joining.initiated` | expected joining date confirmed; `joined` on the day by HR |

Payload: person (name, contacts, identity metadata), candidate/application/requisition/job/offer references, selection (position codes, joining date, manager code, compensation), offer (dates, status), documents (external document reference + type + secure transfer reference), joining (reference, expected date, status). No RMS database, table, model or primary key ever enters PeopleOS (architecture test).

Documents hand-off: external document → signed, expiring pull URL (or push upload) → quarantine document type → classification → verification → `EmployeeDocument`; the existing verification flow is reused.

## 6. Security

**BGV callback (production readiness closure).** `POST /api/v1/bgv/cases/{reference}/checks` needs:
1. an API key with scope `bgv.write`;
2. an active Integration Hub system of kind `bgv` bound to that key;
3. `X-PeopleOS-Timestamp` and `X-PeopleOS-Signature: sha256=HMAC-SHA256(secret, "timestamp.body")`, verified in constant time inside the system's window before the body is read.

Results are stored once as a `bgv.results` hub event (encrypted payload, audited) under
`Idempotency-Key`, else `X-PeopleOS-Event-Id`, else a digest of the signed timestamp and body. A replay
or duplicate returns the stored outcome (`Idempotent-Replayed: true`); the same key with a different
body is a 409. Missing or invalid signatures and stale timestamps return 401; a key without a bound
`bgv` integration returns 403; a case outside the tenant returns 404. Provider-specific signature
schemes are **pending**: none is implemented, because no provider contract is available.


- Credentials: API keys (hashed, scoped, expiring) today; OAuth 2 client-credentials when a partner needs it.
- Inbound webhooks: per-integration secret, HMAC-SHA256 over `timestamp.body`, `X-PeopleOS-Timestamp` within ±5 minutes, replay protection through the idempotency key, rate limit per key, payload validation, audit.
- Outbound webhooks (current envelope): body `{id, event, occurred_at, subject{type,id}, data}`; headers `X-PeopleOS-Event`, `X-PeopleOS-Delivery` (event id), `X-PeopleOS-Timestamp`, `X-PeopleOS-Signature: sha256=HMAC(timestamp.body)`. Consumers must verify the signature, reject stale timestamps, dedupe on the delivery id, expect retries and keep their own dead-letter. Phase 14 added the tenant slug and `correlation_id` to the envelope, an `X-Correlation-Id` header, leased delivery claims, `dead_letter` (audited) and replay (audited).
- No integration path bypasses PeopleOS authorisation: keys carry scopes, tenant binding happens before any lookup, and (future) per-key organisation scope applies the same `AccessScopes`.

## 7. API conventions (ADR-0014)

- Versioned prefixes `/api/v1` (current) and `/api/v2` (reserved for breaking changes); additive changes stay in v1.
- Authentication: API key; tenant resolved from the key before binding; scope per route (`api.key:<scope>`).
- Responses: `{"data": [...], "meta": {pagination}}` for lists, `{"data": {...}}` for records; errors `{"message": "...", "errors": {field: [...]}}` with 401 (no/invalid key), 403 (scope, tenancy), 404 (unknown or other-tenant id), 422 (validation / business rule), 429 (rate limit).
- Filtering by documented query parameters; sorting by `sort=field,-field` where offered; pagination `page`/`per_page` (max 200).
- Idempotency (Phase 14): an `Idempotency-Key` header on v1 write endpoints (`api_idempotency_keys`, encrypted replay with the `Idempotent-Replayed` header; another body → 422; in flight → 409). Endpoints with their own domain idempotency opt out.
- Every write is audited with source `api:<key name>`; reads of sensitive resources require the sensitive scope and are audited as exports when bulk.
- OpenAPI 3.1 (Phase 14): `docs/api/openapi.json`, generated by `peopleos:openapi`; CI checks drift with `--check`. The error envelope is `{message, code, request_id, errors?}`.

## Phase 5 — compliance API (read-only)

Scope `compliance.read`, under `/api/v1/compliance`: `establishments`, `registrations` (numbers
always masked), `rules`, `rules/{id}` (payload and verification history), `returns` (filters: type,
status, period, establishment_id), `returns/{id}` (validation, rule versions, production-gate
readiness, action log), `returns/{id}/entries` (UAN / IP number / PAN masked; each read recorded as
`STATUTORY_OUTPUT_ACCESSED`), `reconciliation/{id}`. No write verbs; no bank credentials, payroll
secrets, tax or portal credentials, or tokens are ever returned. Records of another tenant return
404.
