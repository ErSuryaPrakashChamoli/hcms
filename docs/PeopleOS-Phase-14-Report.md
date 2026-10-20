# PeopleOS Phase 14 Report: Final Integration, Intelligence, Platform Hardening & Production Readiness

For: the architecture reviewer and the product owner. Date: 3 October 2026. Branch:
`feature/oct_1_phase_1`. Nothing was pushed, merged or deployed.

```
SOFTWARE READY  ≠  STATUTORY RULES VERIFIED  ≠  BACKUP / DR VERIFIED  ≠  PRODUCTION READY
```

## 1. Executive summary

Phase 14 joins the PeopleOS domains into one platform. It adds:

- **an Integration Hub:** external references, signed and idempotent inbound events, mappings, and outbound dead letters with replay;
- **a hardened API:** one error envelope, correlation ids, a generic `Idempotency-Key`, sorting, safe rate-limit keys, and OpenAPI 3.1 with 131 operations;
- **an Employee 360 orchestration surface** with a typed, permission-gated timeline;
- **Change Intelligence** over the audit trail;
- **cross-domain People analytics**, gated and suppressed;
- **AI assistive controls:** a data boundary, proposals only, AI-generated marking, and retirement of the per-employee attrition-risk score;
- **operations hardening:** health and readiness, log redaction, bounded and tenant-safe queues, isolated scheduler runs, truthful and async notifications, and storage hardening.

Security fixes from the discovery map are closed:

- Livewire requests now run the tenant chain.
- Pickers no longer list other tenants' users.
- Duplicate-person checks see the whole tenant.
- SCIM returns 409 instead of 500.
- Scheduled reports run as their owner.
- Audit reads are organisation-scoped.

Thirty final architecture invariants are executable tests.

Evidence on the final code:

- **Full suite:** 926 tests: 868 passed, 58 skipped (MySQL-only), 0 failed.
- **MySQL concurrency:** the 10 new races run three times, plus 47 earlier races; lock-removal proofs for every guard group.
- **Scale:** constant query counts from 15 to 10,000 employees.
- **Migration replay:** twice, identical schemas.
- **Audit chains:** intact on development, replay, restore and concurrency.
- **Backup:** a real local backup / restore test.

**PeopleOS is not declared production-ready.** Three things still stand in the way:

- The statutory production gate is **BLOCKED** (24 rules / 0 verified / 5 open notices).
- Backup / DR is **not verified** in a target environment.
- Several deployment blockers remain (§29).

## 2. Starting commit

`906f719` (frozen Phase 13 baseline: docs(peopleos): phase-13.7 engagement and communication architecture and Phase 13 report).

## 3. Ending commit

The 14.8 commit that adds this report (`git log -1`; the last code commit before it is `ad52cbc`, and 14.8 adds the readiness service, page, command and tests). This report commit is the final commit of Phase 14.

## 4. Commits

| Commit | Scope |
|---|---|
| `623c0c2` | 14.1 Discovery map (A exists / B incomplete / C duplicated / D violates / E extendable / F new / G deferred) |
| `b2f03db` | 14.2 Integration Hub |
| `be4de3f` | 14.3 API platform and OpenAPI 3.1 |
| `59ffdcf` | 14.4 Employee 360, Change Intelligence, cross-domain analytics, analytics leak fixes |
| `2120aa4` | 14.5 AI assistive controls, AttritionRisk retired |
| `dd1aa88` | 14.6 Observability, queues, scheduler, notifications, storage |
| `ad52cbc` | 14.7 Security hardening, tenant isolation, 30 invariants, MySQL concurrency and scale |
| 14.8 (this commit) | 14.8 Production readiness (report, page, command), operations docs, this report |

Totals 906f719..HEAD (before 14.8): 200 files changed, 19,077 insertions, 502 deletions.

## 5. Discovery

`docs/PeopleOS-Phase-14-Discovery.md` (14.1). It was written before any code change.

**Architecture violations (D):**
- a per-employee flight-risk score (`AttritionRisk`);
- a cross-tenant user picker (talent reviews);
- non-persistent Livewire tenant middleware;
- scope-limited duplicate-person checks;
- scheduled reports running without a user (every sensitive field, no scope);
- ungated KPIs;
- timeline leaks (performance entries, exit reason);
- audit search ignoring organisation scope;
- the AI log readable by every auditor;
- the rate limiter keyed by the raw API key;
- suspended tenants' keys still working;
- SCIM returning 500 for another tenant's login;
- synchronous email inside transactions.

Every D item is fixed (§6–§16). The incomplete items (B) were completed or explicitly deferred (§27).

## 6. Integration Hub

| Element | Implementation |
|---|---|
| Systems | `integration_systems`: code per tenant, kind, status, encrypted inbound secret (`whsec_…` shown once), rotation (audited), signature tolerance, bound API key, allowed event types |
| External references | `external_references`: unique `(tenant, system, external type, external id)`. Never re-pointed or deleted, only retired (audited). PeopleOS ids are never replaced |
| Inbound events | `POST /api/v1/integrations/{system}/events`. Scope `integrations.write`, key bound to the system, HMAC-SHA256 over `timestamp.body` within the tolerance window (rejections audited). Unique idempotency key per system; same key + different body → 409. Payload encrypted, SHA-256 kept, purged after retention. States: received → processing → succeeded / failed / retrying → dead_letter, reprocess audited. Leased claim plus an in-lock attempt check. Handlers `reference.link` and `reference.retire` (vendor-neutral) |
| Mappings | `integration_mappings` per dimension; the PeopleOS code is the default |
| Outbound | Webhook envelope gains tenant and `correlation_id`; payload guard strips denied keys; leased delivery claim; dead letter and replay audited; `X-Correlation-Id` header |
| Processing | `ProcessInboundEvent` (tenant-aware, unique, after commit); `peopleos:integrations:process` every minute |
| Admin | Integration systems resource (events, references, mappings; rotate secret), inbound events resource, webhook delivery retry / replay |
| Tests | `IntegrationHubTest` (7); MySQL races 1–4 |

No vendor adapters and no RMS (invariant 1).

## 7. API and OpenAPI

- **Error envelope** for every `/api/*` error: `{message, code, request_id, errors?}`. 404s no longer name model classes; 5xx detail is scrubbed outside debug.
- **Correlation ids:** `X-Correlation-Id` / `X-Request-Id` accepted (8–64 safe characters) or minted; echoed; stored as `request_id` on audit rows.
- **`Idempotency-Key`** on v1 writes (`api_idempotency_keys`, encrypted replay): replay sets `Idempotent-Replayed`; another body → 422; in flight → 409; 5xx releases the key. Endpoints with domain idempotency (leave requests, goal progress, enrolments, integration events) opt out.
- **Sorting** (`sort=field,-field`, 422 on unknown fields) through one paginator; six private copies removed.
- **Rate limits** keyed by key prefix or SHA-256, never the secret. Suspended tenants' keys refused. An `ai` limiter was added.
- **OpenAPI 3.1:** `docs/api/openapi.json`, 131 operations, with `x-scope`, `x-audience` (integration) and `x-data-classification`. Deferred user-token APIs are described in `info`. `peopleos:openapi --check` passes on the final tree.
- **Tests:** `ApiPlatformTest` (6), plus the existing API suites.

## 8. Employee 360

`Experience\Services\Employee360` builds one section per domain:

> identity, employment (seat, FTE, manager, tenure), organisation, attendance, leave, payroll (payslip references only), performance, goals, learning, skills, career, talent, succession, compensation (access level and assignment date; no amounts), documents, letters, HR requests (case access), engagement (identified surveys only), communications, exit, alumni.

- **Gating:** each section needs its domain's own rule.
- **No duplication:** owns nothing, writes nothing.
- **UI:** the overview sits on the employee page, with new Goals / Payslips / Letters / Exit / Communications tabs; the duplicate Skills / Certifications tabs were removed.
- **Timeline:** typed (lifecycle / employment / service / communication / domain) with kind and category filters. Sensitive, performance and verification categories are hidden per viewer. Exit descriptions need `exit.view`, and initiating an exit no longer copies the reason.
- **Tests:** `IntelligenceTest`; invariant 30.

## 9. Analytics

- **People analytics** (`PlatformAnalytics`, new page): people, workforce, attendance, leave, performance (latest cycle), learning, career and talent, compensation, HR service, engagement and communication.
  - Each area needs its domain analytics permission and comes from that domain's own service.
  - Counts below `PEOPLEOS_ANALYTICS_MIN_GROUP` (5) are suppressed.
  - No scoring, ranking or prediction (asserted).
- **Leak fixes:**
  - protected KPIs show "restricted" without the domain permission, and small-population shares are suppressed;
  - dataset fields fail closed without a user;
  - scheduled reports claim their slot and run as the owner (no owner, no run);
  - the runner filters before the cap and flags truncation;
  - the warehouse feed is non-sensitive unless `warehouse.include_sensitive` is set, and its manifest records truncation.
- **Change Intelligence** (page and service): see §16.

## 10. AI controls

ADR-0016. `AiDataPolicy` classifies facts, the question and the draft:

- **prohibited** (passwords, keys, tokens, secrets, private keys, signatures): never sent, and stripped from the AI log;
- **restricted** (bank / statutory identifiers, amounts, ratings, grievance, health, personal contact): sent only under the tenant's `ai.external_data_policy = restricted`;
- **`none`** keeps every answer deterministic; an unknown setting fails closed.

Each external call is audited (`AI_EXTERNAL_REQUEST`) with counts, never content.

Further controls:

- model text is marked AI-generated, and the data-boundary outcome is stored per interaction;
- actions are proposals only: internal links confirmed on the domain screen, with external and script URLs dropped and no execute path;
- a per-user rate limit applies;
- only `ai.admin` reads others' conversations;
- the HR Copilot gates every topic by its domain permission, and summarises records through the Employee 360 and changes through Change Intelligence (counts only).

**`AttritionRisk` retired:** the service, its config and the risk table are gone. Risk, promotion and termination questions get a no-prediction answer. Invariant 19 is a test (no domain actions, no writes in `app/Domain/Ai`).

## 11. Notifications

- **In-app** messages are stored immediately (`notifyNow`). Before, Filament queued them, so "sent" could be untrue.
- **Email and the other external channels** go through `DeliverNotification`, dispatched after commit: claimed queued → sending, retried with backoff 60 / 300 s, failed on the last attempt. A rolled-back business action sends nothing, and no transaction waits on a mail server.
- **Every delivery** carries a correlation id and a per-tenant dedupe key; the same message in one operation, including a retried job, is recorded once.
- **Templates** no longer see a classified record's values (only identity, status, number, code, dates).
- **Tests:** `OperationsHardeningTest`; MySQL races 6–7.

## 12. Queues

- **Bounds:** `retry_after` defaults to 3900 s (database / Redis / beanstalkd), above the 3600 s payroll calculation. Every job declares a timeout, and jobs with more than one try declare a backoff (test-enforced).
- **`BindTenantContext`:**
  - refuses a tenant-aware job without a tenant;
  - skips (and logs) a suspended tenant's job;
  - clears cached organisation scopes around worker jobs (not around sync jobs, which run inside their caller's request).
- **Failed jobs** are logged with job, connection, queue, attempts and exception class.
- **Inventory:** `docs/operations/queue-and-scheduler.md`.

## 13. Scheduler

| Control | Implementation |
|---|---|
| Overlap / multi-server | Every entry `withoutOverlapping()->onOneServer()` (invariant 26) |
| Tenant iteration | `TenantRunner` in all 21 tenant-iterating scheduled commands: suspended tenants skipped (retention excepted), each tenant's failure isolated and reported, exit code non-zero on failure |
| Once-per-period | `scheduler_claims`. Lifecycle reminders claim one sweep per tenant per day (`--force` to re-run); report schedules claim `next_run_at` conditionally |
| Correlation | Each command run gets a `request_id` in Context |
| Heartbeat | `peopleos:scheduler:heartbeat` every minute, read by readiness |
| Inventory | 23 entries tabulated with frequency and idempotency mechanism, and tested against the actual schedule |

## 14. Storage

- The local private disk no longer serves files (`serve = false`); files go only through authorised, audited routes.
- The certificate store follows the configurable document disk.
- **Grievance evidence:** moved to `tenants/{tenant}/grievances/{case}/{ulid}.{ext}`, type and size checked, SHA-256 recorded and verified on download (409 on mismatch).
- **Audited:** every document download (not only sensitive ones), letter downloads and blueprint exports.
- **Report re-downloads:** a stored export is re-downloadable only by its producer (or the owner, for a scheduled run), audited.
- **S3:** the adapter (`league/flysystem-aws-s3-v3`) is not installed. Readiness flags it, and it is a multi-node deployment blocker (§29).

## 15. Observability

| Item | Implementation |
|---|---|
| Liveness | `GET /health/live`: process only, no dependency, no session |
| Readiness | `GET /health/ready`: database, cache, storage probe critical (503 "down"); queue backlog, failed jobs, scheduler heartbeat, integration dead letters, Redis (when used) are warnings ("degraded"). No tenant data; counts only with `PEOPLEOS_HEALTH_TOKEN` |
| Logs | `RedactSensitiveLogData` tap on single / daily / stderr / syslog / errorlog / papertrail. It redacts secrets, tokens, keys, authorization, bank / statutory identifiers and pay amounts (by key and value), and stamps the tenant id |
| Slow queries | Logged at `PEOPLEOS_SLOW_QUERY_MS` (1000) with SQL text only, never bindings |
| Readiness report | `peopleos:readiness` and the Platform readiness page (§22, §30) |

## 16. Security

Fixes (D items and 14.7):

- Livewire `/livewire/update` now runs `ResolveTenant` and `EnforceSecurityPolicy` (persistent).
- Talent review participants are validated in the domain; every picker resolves submitted user ids with `forCurrentTenant()`; report-schedule and notification-bridge recipients are tenant-filtered.
- Duplicate-person and employee-code checks run tenant-wide. Out-of-scope matches are disclosed without name, code or ids, and scoped users cannot override them.
- SCIM returns 409 with a neutral message for a login held anywhere (create / replace / patch).
- Audit list and Change Intelligence are organisation-scoped, with classified values masked.
- The AI log is limited to `ai.admin`.
- Rate limits are keyed without secrets; suspended tenants' keys are refused.
- Scheduled reports run as their owner.
- The engagement raw aggregates carry explicit tenant filters.
- `AccessScopes::allows` no longer crashes on models without `employee_id` (scoped auditors could not open the audit list).

Review of raw queries and bypasses: `docs/architecture/security-invariants.md`, Phase 14 section:

- 9 `bypass()` calls, all on the allow-list;
- 345 organisation-scope removals in 110 files plus 32 `withoutScoping()` calls, reviewed by category (each keeps the fail-closed tenant scope);
- 1 `withoutGlobalScopes()`, narrowed;
- 13 `DB::table()` calls, each with explicit tenant or key filters;
- 5 interpolated fragments, all from code constants or allow-lists.

Tests: `TenantIsolationHardeningTest` (5), `IntelligenceTest`, `AiAssistiveControlsTest`, `OperationsHardeningTest`, the architecture suites.

## 17. Performance (scale)

Query counts per surface are **identical at every tier** (Phase 14 read surfaces):

| Surface | Queries |
|---|---|
| Employee 360 | 29 |
| People analytics | 64 (69 before the cycle bound) |
| Change Intelligence page | 3 |
| Health ready | 7 |
| API employee page | 15 |

Wall times (local machine; indicative, not a production latency claim):

| Tier | SQLite: 360 / analytics / API (ms) | MySQL: 360 / analytics / API (ms) |
|---|---|---|
| 15 | 24 / 47 / 19 | 25 / 72 / 31 |
| 150 | 13 / 64 / 29 | 27 / 64 / 46 |
| 1,500 | 25 / 290 (pre-fix) / 17 | 25 / 64 / 35 |
| 10,000 | — | 22 / **137** / 58 |

The MySQL run found two real problems, both fixed:

- People analytics took 4.5 s at 10,000 employees: a cycle-less performance summary loaded the whole workforce. It is now bounded to the latest cycle (137 ms).
- The feedback count used a column that does not exist. SQLite reads an unknown double-quoted identifier as a string literal, so only MySQL could catch it.

The API key's `last_used_at` write is time-throttled, not data-driven, and is excluded from the counts.

## 18. MySQL concurrency

The races ran on the dedicated database `hcm_p14_concurrency` (created for this phase; the name
contains "concurrency"). Harness: forked workers with the writes slowed down.

| # | Race | Guard | Runs |
|---|---|---|---|
| 1 | Same inbound event twice | Unique idempotency key | pass ×3 |
| 2 | Same inbound event processed by two workers | Leased claim + in-lock attempt check | pass ×3 |
| 3 | Two links for one external id | Row lock + unique key | pass ×3 |
| 4 | Webhook delivery picked up twice (sends counted) | Leased claim | pass ×3 |
| 5 | Two identical API writes, same `Idempotency-Key` | Unique key per API key | pass ×3 |
| 6 | Same notification twice in one operation | Dedupe pre-check + unique key | pass ×3 |
| 7 | Two delivery jobs for one notification | Claim queued → sending | pass ×3 |
| 8 | Two runs for one scheduler slot | `scheduler_claims` unique key | pass ×3 |
| 9 | Overlapping report-schedule runs | Conditional `next_run_at` claim | pass ×3 |
| 10 | Two concurrent hires | Employee-code sequence row lock | pass ×3 |

Every race also verifies the race tenant's audit chain. Cross-phase: all 57 MySQL tests (47 earlier,
10 new) pass together.

**Locks removed** (each removed, its race run, the file restored from a backup):

| Guard removed | Expected failure observed |
|---|---|
| Inbound claim + in-lock attempt check | Race 2: event applied twice (2 PROCESSED audits) |
| Inbound idempotency unique key | Race 1: two inbound rows |
| External reference unique key only | Race 3 still passed: the row lock is a second guard |
| External reference row lock + unique key | Race 3: two references for one external id |
| Webhook claim (first version of race 4) | Race 4 passed: it checked `attempts`, which a lost update hides. The race was strengthened to count real sends |
| Webhook claim (strengthened race) | Race 4: two sends |
| API idempotency unique key | Race 5: two employees |
| Notification dedupe (pre-check + unique key) | Race 6: two deliveries |
| Notification delivery claim | Race 7: two sends |
| Scheduler-claim unique key | Race 8: both runs claimed the slot |
| Report-schedule slot claim | Race 9: two exports |
| Employee-code sequence lock | Race 10: duplicate-key violation |

Every guard group produced its expected failure, and the restored code passes.

## 19. Migration replay

**Database:** `hcm_p14_replay`. It was created empty for the replay, after confirming it did not exist.
Every destructive step re-checked the effective database name and refused otherwise.

**Sequence**, run twice: fresh → seed → rollback (the 4 Phase 14 migrations) → migrate → seed → rollback → migrate.

| Check | Run 1 | Run 2 |
|---|---|---|
| Fresh migrate | 109 migrations | 109 migrations |
| Seed, then re-seed after the cycle | clean (no errors) | clean |
| Snapshot (tables / columns / indexes / FKs / checks) | 296 / 4,516 / 1,557 / 1,121 / 23 | same |
| Cycle 1 identical to the seeded snapshot | yes | yes |
| Final identical to cycle 1 | yes | yes |
| Audit chains | platform 2, demo 685 verified | same |
| Failed jobs | 0 | 0 |

**Final replay vs development (`hcm`, migrated forward):** identical tables, columns, indexes,
unique keys, foreign keys and CHECK constraints. One difference: `tenants.base_currency varchar(3)
NOT NULL DEFAULT 'INR'` exists only in development. It is the known historical difference, left
unchanged.

**Development** was backed up first (§21), then migrated forward only (2 pending Phase 14
migrations; 14.2 / 14.3 had been applied earlier). Permissions synced: 0 created.

## 20. Audit verification

| Database | Result |
|---|---|
| Development (`hcm`) | PASS: platform 40, demo 587 events |
| Replay (`hcm_p14_replay`) | PASS: platform 2, demo 685 events (both runs) |
| Restore (`hcm_p14_restore`) | PASS: platform 40, demo 587 events |
| Concurrency (`hcm_p14_concurrency`) | PASS: 59 chains (platform + 58 race / scale tenants), 14,377 events verified, 0 broken; failed jobs 0 |

The `audit_chain_locks` mechanism stays the platform-wide invariant. Phase 14 rewrote no audit row and
did not change the hash formula.

## 21. Backup and DR

`docs/operations/backup-and-disaster-recovery.md`: strategy, object storage, encryption-key recovery,
restore procedure, RPO / RTO targets, tenant-level recovery, audit preservation, and queue /
integration replay.

**Local restore test (executed):**

- `mysqldump` of `hcm` (2 s, 220 KB gzip) restored into the new disposable schema `hcm_p14_restore` (12 s);
- schema identical (295 tables), row counts identical (296 tables, 5,301 rows);
- audit chains verified on the restored copy.

**Not verified:**
- production volume;
- object storage;
- key recovery;
- point-in-time recovery;
- cross-host restore;
- the RTO / RPO targets.

**DR is not claimed verified.**

## 22. Production configuration

`docs/operations/production-configuration.md` is the checklist. It covers application, database,
cache / session / queue, storage, mail, security, AI, observability and the deploy sequence.
`peopleos:readiness` checks every machine-checkable item (PASS / WARN / FAIL / BLOCKED /
UNVERIFIED) and exits non-zero on any FAIL. The Platform readiness page shows the same report to
platform administrators.

On development the report shows no FAIL and ten expected development warnings: local environment,
debug on, http URL, debug log level, insecure cookie, log mailer, example from-address, no health token,
S3 adapter absent, and no scheduler heartbeat (cron not running here).

## 23. Statutory gate

**BLOCKED: 24 rules / 0 verified / 5 open notices** (development; it is part of the readiness report and
page). No rule was rewritten, verified or re-dated. No rate was invented. Production enforcement of
verified rules cannot be switched off (invariant 29).

## 24. Tests

**Authoritative full suite:** `php artisan test` (Pest, SQLite in-memory) on the final code tree,
3 October 2026, 11:46:51 → 12:06:23.

| Tests | Passed | Skipped | Failed | Assertions | Duration |
|---|---|---|---|---|---|
| 926 | 868 | 58 | 0 | 11,322 | 1,171.7 s |

The skipped tests are the MySQL-only suites, which skip without `PEOPLEOS_MYSQL_CONCURRENCY_DB`. They
ran separately on `hcm_p14_concurrency`:

| Separate run | Result |
|---|---|
| MySQL concurrency, all phases | 58 / 58 on the final code (47 earlier-phase races + 10 Phase 14 races + 1 scale). The 10 Phase 14 races passed in 3 runs; audit chains verified afterwards |
| MySQL scale | 1 / 1 (to 10,000) |
| Pint `--test` | pass |
| OpenAPI `--check` | up to date |

The code tree that was tested is byte-identical to the committed code (fingerprint of `app`, `config`,
`database`, `routes`, `resources`, `tests`, `bootstrap`, composer files and `phpunit.xml` taken before the
run and again after the MySQL run). After the run, only documentation changed. New Phase 14 test files:

- `IntegrationHubTest` (7);
- `ApiPlatformTest` (6);
- `IntelligenceTest` (7);
- `AiAssistiveControlsTest` (6);
- `OperationsHardeningTest` (9);
- `TenantIsolationHardeningTest` (5);
- `PlatformReadinessTest` (3);
- `PlatformScaleTest` (1);
- `PlatformInvariantsTest` (30);
- MySQL `PlatformConcurrencyTest` (10) and `PlatformScaleTest` (1).

**Infrastructure unavailable** (not tested): Redis, S3 / object storage, a real mail transport, a second
node, an external AI provider (faked over HTTP in tests).

## 25. Architecture tests

The 30 final invariants are in `tests/Feature/Architecture/PlatformInvariantsTest.php`, one test each:

1. no RMS;
2. Employee as the canonical employment identity;
3. Person as the canonical natural person;
4. the Employee 360 is not a system of record;
5. Payroll does not own salary assignment;
6. Compensation owns it;
7. Attendance consumes Leave through its contract;
8. Payroll consumes Attendance through its contract;
9. Payroll consumes Compensation through its contract;
10. Performance does not mutate Learning;
11. Learning does not mutate Career;
12. Career does not mutate Employment;
13. Workforce Planning does not mutate employment;
14. the Service Desk is not a system of record for other domains;
15. Engagement does not mutate Performance;
16. Communication stores no employee state;
17. the audit trail is append-only;
18. the audit chain stays valid;
19. AI cannot mutate protected domains;
20. the Integration Hub holds no employee identity;
21. external ids never replace PeopleOS ids;
22. tenant isolation is mandatory;
23. sensitive fields are protected;
24. mentor / buddy / project lead is never the manager;
25. queue jobs preserve tenant context;
26. scheduled jobs are overlap-protected;
27. effective dating is intact;
28. history is reconstructable;
29. the statutory gate cannot be bypassed;
30. the Employee 360 respects every domain permission.

The existing architecture and domain invariant suites (Architecture, Compensation, Compliance,
Engagement, Learning, Performance, Service Desk, Talent, Workforce) still pass. Documented exceptions
were added with reasons: the inbound event log and the idempotency replay cache are not auto-audited;
health and readiness are on the tenant-bypass allow-list (counts only).

## 26. Known limitations

- **People analytics** is an overview. It is computed on request (137 ms at 10,000 employees on local MySQL), with no caching or materialisation.
- **BGV callback** (`POST /api/v1/bgv/cases/{reference}/checks`) is still authenticated by API key scope only; it has no request signature or domain idempotency of its own. The generic `Idempotency-Key` covers retries that send the header.
- **Workflow webhook node and outbound webhook URLs** are admin-configured. Private-network (SSRF) URL filtering is not implemented.
- **AI data classification** is pattern- and key-based; it can miss free-text personal data that matches no pattern. The default tenant policy `allowed` still redacts recognisable identifiers.
- **Lifecycle reminders** claim per day; other reminder sweeps use their own reminder logs (unchanged).
- **The scale and concurrency evidence** is from one local machine.
- **The security review** of the 345 organisation-scope removals was done by category, not line by line.

## 27. Deferred

- vendor adapters (RMS, payroll providers, devices, finance, IdP, benefits, BGV vendors);
- per-API-key organisation scope;
- user-token APIs (self-service / manager / HR);
- status and compensation integration mappings;
- statutory rule verification, EPF September 2026 split and TDS changes (statutory track);
- a tenant-level restore tool;
- embeddings / vector search, streaming and multi-turn AI;
- real SMS / WhatsApp / push providers.

Predictive or scoring analytics and recruitment / ATS are **prohibited**, not deferred.

## 28. Infrastructure unavailable

Not available in this environment, so not tested:

- Redis (no extension, no server);
- S3 / object storage (no adapter, no service);
- a real SMTP / mail provider;
- multiple application nodes;
- a production or staging database;
- an external AI provider (tests use an HTTP fake);
- a secret store.

## 29. Deployment blockers

1. **Statutory production gate BLOCKED:** 24 rules / 0 verified / 5 open notices. Rules must be verified with authoritative evidence (second-person verification) before statutory payroll or returns run in production.
2. **Backup / DR not verified** in the target environment: §9 of the runbook, with measured RPO / RTO.
3. **Multi-node storage:** the S3 adapter is not installed; documents are on the local disk.
4. **Production configuration:** `APP_ENV=production`, `APP_DEBUG=false`, https `APP_URL`, secure cookies, a real mailer and from-address, `PEOPLEOS_HEALTH_TOKEN`, a running scheduler (heartbeat) and supervised workers. `peopleos:readiness` must show no FAIL.
5. **Redis or another shared cache** for multi-node scheduler locks: verified only with the database store here.
6. **Operator sign-off** on the readiness report.

## 30. Final readiness status

| Dimension | Status |
|---|---|
| Software (this phase's scope) | Implemented and tested on the final tree (926 tests: 868 passed, 58 skipped (MySQL-only), 0 failed) |
| Configuration (development) | No FAIL; development warnings expected |
| Statutory production gate | **BLOCKED** (24 / 0 / 5) |
| Backup / DR | **Locally restore-tested; NOT verified** in a target environment |
| Infrastructure (Redis, S3, mail, multi-node) | **Not available; not verified** |
| **PeopleOS production readiness** | **NOT DECLARED** |

Phase 14 is complete. PeopleOS must not be called production-ready until the §29 blockers are cleared
with evidence. Do not start another phase without explicit approval.
