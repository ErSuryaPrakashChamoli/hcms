# PeopleOS Production Readiness Closure Report

For: the architecture reviewer, operations and the authorised go-live approver. Date: 3 October 2026.
Branch `feature/oct_1_phase_1`. Nothing was pushed, merged or deployed.

```
CODE REMEDIATION  ≠  ENVIRONMENT VERIFICATION  ≠  STATUTORY VERIFICATION  ≠  OPERATOR SIGN-OFF
```

## 1. Executive Summary

This exercise closed the **code** side of the Phase 14 production blockers, without adding modules or
redesigning domains.

**Closed in code:**

| Blocker | Fix |
|---|---|
| 1. BGV callback | Now signed, replay-safe and idempotent. It runs through the Integration Hub's existing secure boundary |
| 2. Outbound SSRF | Webhooks, workflow webhook nodes and SSO endpoints now pass a DNS-checked guard with pinned connections and no redirects |
| 5. S3 | The official S3 adapter is installed; every storage role is configurable; no code pins the local disk; server-side file safety added |
| 6. Production configuration | `peopleos:config:validate` added, wired into readiness (503 in production on errors); trusted proxies; debug can never be on in production |
| 7. Redis / shared cache | Detected and validated (client, extension, credentials, lock-capable store, lock probe); not verified against a live Redis |

**Not closed** (outside code; requires people or infrastructure):

- statutory verification (still **BLOCKED**, 24 rules / 0 verified / 5 open notices);
- target-environment backup / DR (**NOT VERIFIED**; a full local rehearsal was performed);
- production configuration and infrastructure (**NOT VERIFIED**);
- operator sign-off (**PENDING**).

Final regression on the final code tree: **959 tests, 899 passed, 60 skipped (MySQL-only, run separately), 0 failed, 11,543 assertions**. MySQL: 60 / 60 passed on the dedicated `hcm_p14_concurrency` database (47 earlier-phase races, 10 Phase 14 races, 1 scale test, 2 closure races).

**This report does not declare PeopleOS production-ready.**

## 2. Baseline

- Baseline: Phase 14 HEAD `382c7df` (approved, frozen), working tree clean.
- Baseline evidence: 926 tests (868 passed, 58 MySQL-only skipped, 0 failed, 11,322 assertions); 58 MySQL tests.
- No Phase 14 scope was reopened.

## 3. Security Remediation

| Commit | Change |
|---|---|
| `dce6c58` PR.1 | Discovery: every blocker re-verified in code before any change (`docs/production/closure-discovery.md`) |
| `8978e1a` PR.2 | Signed BGV callback |
| `faacccc` PR.3 | Outbound SSRF guard; encrypted workflow webhook jobs; secret-safe records |
| `456c235` PR.4 | S3 capability; storage roles; file safety |
| `4fcdb7a` PR.5 | Configuration validator; trusted proxies; debug guard; config and lock readiness |
| `f9833cb` PR.6 | DR runbook with local rehearsal; smoke test and go-live checklists |
| PR.7 / PR.8 (this commit) | Regression evidence, worker / timeout documentation, this report |

## 4. BGV Callback

`POST /api/v1/bgv/cases/{reference}/checks`. In order, **before the body is read**:

1. API key `bgv.write`.
2. An active `bgv` Integration Hub system bound to that exact key.
3. HMAC-SHA256 over `timestamp.body` with the system's secret, compared in constant time, inside the system's timestamp window. Enforced even if the system does not otherwise require signatures.

After authentication:

- The payload is validated (422; nothing recorded).
- The event is stored once as `bgv.results` (encrypted payload, received / processed audit events) under `Idempotency-Key`, else `X-PeopleOS-Event-Id`, else `sha256(timestamp.body)`.
- It is applied in the hub's processing transaction.

| Situation | Result |
|---|---|
| Replay or duplicate | Stored outcome, nothing reapplied (`Idempotent-Replayed: true`) |
| Same key, different body | 409 |
| Invalid / missing signature, modified body, stale timestamp | 401 (audited) |
| Key without a bound integration | 403 (audited) |
| Inactive integration | 409 |
| Another tenant's case | 404 |

The `bgv.results` handler is also reachable through the generic hub endpoint, for `bgv` integrations
only. The generic `Idempotency-Key` middleware was removed from this route, so it can never cache a
response before the signature check.

**Provider-specific signature schemes: PENDING.** No provider contract is available, so none was
invented; providers sign with the documented PeopleOS scheme.

Tests:
- `BgvCallbackSecurityTest`: valid, invalid, missing, modified body, stale, replay, duplicate, cross-tenant, invalid integration, malformed, no payload / secret / signature in logs;
- MySQL `ClosureConcurrencyTest`: concurrent replay applied once; the same key with different bodies at the same moment means one wins and the other gets a 409.

The existing callback test was updated to sign its requests. This is an intentional contract change.

## 5. Webhook SSRF

The guard covers every tenant-controlled outbound destination: webhook endpoints, workflow webhook
nodes, and SSO token / userinfo endpoints. The AI endpoint is operator-configured. Full boundary:
`docs/production/security-boundaries.md`.

- **Scheme / port / credentials:** https only; allowed ports only; no credentials in the URL.
- **Reserved names refused:** `localhost`, `*.local`, `*.internal`, metadata names, single-label hosts, ambiguous numeric hosts.
- **After DNS:** every A / AAAA answer must be public. IPv4 and IPv6 reserved ranges are refused, including 169.254.169.254, fd00:ec2::254, ULA, link-local and IPv4 embedded in IPv6.
- **Rebinding:** the connection is pinned to the validated address (`CURLOPT_RESOLVE`).
- **Redirects:** never followed.

Enforcement points:

| Point | Behaviour |
|---|---|
| Save time (no DNS) | Endpoint and SSO URLs checked by model hooks and form rules |
| Every request | Full check after DNS, pinned connection |
| Blocked webhook | Recorded on the delivery and audited (`OUTBOUND_DESTINATION_BLOCKED`, reason and redacted destination only) |
| Blocked workflow node | `webhook.blocked`, never retried |
| Blocked SSO endpoint | The login fails |
| Exemptions | Operators only, by exact host (`PEOPLEOS_OUTBOUND_ALLOWED_HOSTS`) |

Secret safety:
- `SendWebhook` is `ShouldBeEncrypted`, so headers, URL and body are encrypted in the queue tables;
- workflow logs keep scheme and host only;
- transport errors drop URL paths and queries from delivery records;
- no secret, signature or `Authorization` value reaches logs or audit (tested).

`OutboundSsrfTest` covers: localhost, 127/8, ::1, private IPv4 and IPv6, link-local, metadata, public,
host-to-private, mixed answers, unresolvable names, rebinding pinning, redirect to metadata, the
webhook / workflow / SSO paths, and error paths.

One render test changed its placeholder IdP hosts (`https://t`, `https://u`) to real-looking names,
because single-label hosts are now refused by design.

## 6. S3

- **Adapter:** `league/flysystem-aws-s3-v3` 3.35.3 (with `aws/aws-sdk-php` 3.399.1; no security advisories).
- **Disk:** `s3` is private and throws on failure.
- **Roles** (default local for development): documents, staging, compliance (statutory evidence and returns), warehouse, and `FILESYSTEM_DISK` (Livewire temporary uploads).
- **No local-disk dependencies:** compliance evidence, statutory exports, learning evidence and every Filament staging upload previously pinned `local`; the document upload used a local `->path()`.
- **Downloads** stay signed, authorised, audited routes that stream the object. No public object URLs exist. Pre-signed URLs are supported by the driver but are not used for downloads, because streaming keeps authorisation and audit on every access.
- **File safety (server-side):** extension allowlist, size, non-empty, and refusal of sniffed active content (HTML, SVG, scripts, executables), sniffed from the stored bytes. Issued letters are explicitly exempt as system-generated.
- **Tests** (`StorageProductionTest`):
  - real adapter, private and throwing, pre-signed URLs computed offline;
  - unreachable bucket fails loudly with no row;
  - all roles on object storage, tenant prefix, staging cleanup, signed audited download, cross-tenant 404;
  - panel upload through staging;
  - file safety;
  - no local pins.
- **Production bucket: NOT VERIFIED** (none available).

## 7. Redis

Redis is optional (database cache and queue are valid shared backends).

- **Validator checks:** the client (phpredis needs the extension; predis needs the package), host and auth whenever cache, queue or session uses Redis; a lock-capable shared cache store.
- **`/health/ready`:** pings Redis when it is used (critical) and exercises an atomic lock on the shared store (`locks`).
- **Tenant isolation:** a real database-queue worker round-trip keeps the dispatching tenant; a tenant-less job is refused and recorded as failed; cache keys are per tenant (tested).
- **Live Redis: NOT VERIFIED.** No server or extension exists on the workstation.

## 8. Configuration

`ProductionConfigValidator` / `peopleos:config:validate [--as-production] [--json]` covers:

- APP_ENV / DEBUG / KEY / cipher / URL;
- the database (MySQL, dedicated user, strong password);
- Redis;
- cache, session (shared store, Secure, HttpOnly, SameSite) and queue (real driver, retry_after, failed jobs);
- mail;
- every storage role (private, S3 credentials and adapter, throw, shared);
- local disk serving;
- CORS credentials;
- panel CSRF;
- rate limits;
- outbound https;
- log redaction and level;
- health token, heartbeat, AI key.

Behaviour:

- **Findings:** key / category (missing, insecure, invalid, incompatible, advisory) / rule, **never values** (tested with planted secrets).
- **Exit and readiness:** the command exits non-zero on errors; readiness includes the result; `/health/ready` returns 503 in production while an error remains.
- **Debug:** in production `APP_DEBUG=true` is forced off at boot and logged critical.
- **Proxies:** `PEOPLEOS_TRUSTED_PROXIES` sets which proxies' `X-Forwarded-*` headers are trusted (tested both ways).

On development: 10 errors / 2 advisories against production rules, all expected for a development
workstation. **Production configuration: NOT VERIFIED.**

## 9. Queues

| Setting | Value |
|---|---|
| Job `$timeout` | 60–3600 s |
| Worker `--timeout` | 3700 s |
| `retry_after` | 3900 s (validated > 3600) |
| Backoff | 60 / 300 / 900 s |
| `--memory` / `--max-time` | 512 MB / 3600 s |
| Supervisor | restart, `stopwaitsecs` 3720 |

- Claims inside jobs make duplicate deliveries no-ops (Phase 14 races 2, 4, 7 and 9).
- Tenant-aware jobs without a tenant fail (real worker test).
- **Supervised production workers: NOT VERIFIED.**

## 10. Scheduler

- **Inventory:** 23 entries, no duplicates (`schedule:list`); all `withoutOverlapping()->onOneServer()`; every tenant-iterating command uses `TenantRunner` (verified by script and by test).
- **Behaviour:** suspended tenants are skipped (retention excepted); one tenant's failure is isolated (non-zero exit); once-per-period claims; heartbeat every minute.
- **Final inventory:** `docs/operations/queue-and-scheduler.md`, unchanged by the closure.
- **Production cron: NOT VERIFIED.**

## 11. Health Checks

- **`/health/live`:** process only.
- **`/health/ready`:**
  - critical: database, cache, storage, configuration (in production), Redis when used;
  - warnings: queue backlog, failed jobs, scheduler heartbeat, dead letters, locks.
- **Disclosure:** no tenant data or credentials. Details require `X-Health-Token` (`PEOPLEOS_HEALTH_TOKEN`, ≥ 24 characters).
- **Monitoring access:** the probe runner sends the token header. Without it, only statuses are returned.

## 12. Backup

`docs/production/disaster-recovery.md` §1–§4 covers database dumps plus binlogs, object-storage
versioning / lock / replication, key handling and retention.

**Production backup jobs: NOT VERIFIED.**

## 13. Restore

**Local rehearsal** of the full runbook. Source: development `hcm`. Target: new disposable
`hcm_closure_restore`.

| Measure | Result |
|---|---|
| Backup | 2.1 s (25.5 MB source, 233 KB gzip), checksum verified |
| Restore | 10.6 s |
| Schema | Identical (296 tables) |
| Row counts | Identical (297 tables, 5,304 rows) |
| Migrations on boot | 108 / 108 ran |
| Audit chains | Verified (40 + 587 events) |
| Tenants / users / employees | Consistent |
| Employee 360 smoke | 21 sections |
| Document files | 2 / 2 present |
| Cache / queue / locks | ok |
| Statutory gate | Unchanged |
| **Observed local recovery** | **38.5 s** |

**Target restore: NOT VERIFIED.**

## 14. DR

**TARGET DR = NOT VERIFIED.** No staging environment, object storage, secret store or second site was
available. RPO / RTO targets are documented (15 min / 4 h) but **not measured in a target
environment**. The required infrastructure and access are listed in DR doc §12. Tenant-level
recovery and rollback procedures are documented.

## 15. Smoke Testing

`docs/production/smoke-test-checklist.md` has 20 steps. Each is backed by automated evidence on the
workstation. **Staging / production smoke: NOT EXECUTED** (no environment).

## 16. Security Testing

Every security-relevant suite ran in the authoritative full suite (0 failures) and, where MySQL-only, in the separate MySQL run (60 / 60).

New security tests:

| Test | Count |
|---|---|
| BgvCallbackSecurityTest | 8 |
| OutboundSsrfTest | 10 |
| StorageProductionTest | 6 |
| ProductionConfigurationTest | 7 |
| MySQL ClosureConcurrencyTest | 2 |

Existing security suites (Architecture, Tenancy, TenantIsolationHardening, OperationsHardening, AI
controls, Integration Hub, API platform) pass on the final tree.

## 17. Concurrency

MySQL suite on the final code: **60 / 60**. That is 47 earlier-phase races, the 10 Phase 14 platform races, the 10,000-employee scale test, and 2 new closure races: a concurrent BGV replay applied once, and two bodies under one idempotency key with one applied and the other refused with 409. Every race tenant's audit chain verified.

The BGV callback reuses the Integration Hub guards whose lock-removal proofs were recorded in Phase 14
(races 1–2: unique idempotency key, leased claim plus in-lock check). No new locking mechanism was
introduced, so no new lock-removal run was needed.

## 18. Performance

No read surface changed. The Phase 14 scale evidence (constant query counts from 15 to 10,000
employees) stands. Re-run on the final code (MySQL, 10,000 employees): query counts constant at every tier (Employee 360 29, People analytics 64, Change Intelligence 3, readiness 7, employee API page 15). Times at 10,000: 360 29.6 ms, analytics 159.4 ms, API page 65.2 ms (local machine; indicative only). `/health/ready` gained the configuration and lock checks without adding queries.

## 19. Migration Replay

The closure added **no migrations**. The replay was re-run twice on disposable `hcm_p14_replay`:
fresh → seed → rollback (4 Phase 14 migrations) → migrate → seed → rollback → migrate.

- Every snapshot was identical: 296 tables, 4,516 columns, 1,557 indexes, 1,121 FKs, 23 CHECKs.
- The seeds ran clean.
- Audit chains verified (platform 2, demo 685); 0 failed jobs.
- Compared with development: identical, except the known `tenants.base_currency`.

## 20. Audit Verification

| Database | Result |
|---|---|
| Development `hcm` | Platform 40, demo 587 events verified |
| Replay `hcm_p14_replay` | Platform 2, demo 685 verified (both runs) |
| Restore rehearsal | 40 + 587 verified |
| Concurrency `hcm_p14_concurrency` | 61 chains (platform and every race / scale tenant), 14,812 events verified, 0 broken; failed jobs 0 |

## 21. Statutory Gate

**STATUTORY READINESS = BLOCKED: 24 rules / 0 verified / 5 open notices.**

- No rule was verified, rewritten or re-dated; no rate was guessed; no notice was closed.
- Enforcement was not weakened.
- The only statutory-adjacent change is the storage disk for statutory evidence and return files, which is now configurable (default unchanged). No gate logic was touched.

## 22. Operator Sign-off

`docs/production/go-live-checklist.md` has sections A–T with STATUS / EVIDENCE / OWNER / DATE:

- engineering-verified items are PASS;
- environment items are NOT VERIFIED;
- statutory is FAIL (BLOCKED);
- **Operator approval: PENDING.**

Section T is reserved for the authorised human and was not filled in.

## 23. Remaining Blockers

1. Statutory production gate BLOCKED (24 / 0 / 5): requires the two-person verification with authoritative evidence.
2. Target backup / restore / DR not verified (DR doc §12).
3. Production configuration not set or verified (`peopleos:config:validate` must show 0 errors in production).
4. Production infrastructure not verified: S3 bucket, Redis (if chosen), supervised workers, cron, TLS / proxies, egress filtering, monitoring and alerts.
5. Operator sign-off pending.

## 24. Remaining Unverified Items

- S3 production bucket (policy, encryption, versioning, round trip).
- Live Redis.
- Production mail transport.
- Multi-node behaviour.
- Staging / production smoke test.
- Measured RPO / RTO.
- Independent penetration test.
- Data migration and reconciliation.
- Business configuration review.
- Incident response readiness.

## 25. Deferred Items

- BGV provider-specific signature adapters (no provider contract).
- Tenant-level restore tool.
- Per-API-key organisation scope.
- User-token APIs.
- Real SMS / WhatsApp / push providers.
- Pre-signed-URL download mode (streaming is kept for audit).
- Signatures on other machine callbacks:
  - `POST /api/v1/pre-employees` (key scope plus domain idempotency on the offer reference);
  - device punches (key scope).
  These were not listed as blockers and are recorded as known limitations.

## 26. Test Results

**Authoritative full suite** on the final code tree. Code fingerprint `195b538344c3170a`, unchanged across the
run; only documentation changed afterwards. 3 October 2026, 15:24:09 → 15:55:26 (slower than Phase 14 because the migration replay ran in parallel).

| | Tests | Passed | Skipped | Failed | Assertions | Duration |
|---|---|---|---|---|---|---|
| Phase 14 baseline | 926 | 868 | 58 | 0 | 11,322 | 1,171.7 s |
| Closure final | 959 | 899 | 60 | 0 | 11,543 | 1,875.3 s |

**Why the numbers changed** (exactly accounted):

| Change | Tests | Passed | Skipped |
|---|---|---|---|
| New feature tests (`BgvCallbackSecurityTest` 8, `OutboundSsrfTest` 10, `StorageProductionTest` 6, `ProductionConfigurationTest` 7) | +31 | +31 | — |
| New MySQL-only tests (`ClosureConcurrencyTest`; skipped in the default suite, run separately) | +2 | — | +2 |
| **Total vs baseline** | 926 → 959 | 868 → 899 | 58 → 60 |

Two existing tests were changed for intentional security behaviour (no count change, nothing weakened):
- the BGV callback test now signs its requests;
- one render test's placeholder IdP hosts became real-looking names.

No test was removed or relaxed.

| Separate run | Result |
|---|---|
| MySQL suite (dedicated `hcm_p14_concurrency`) | 60 / 60 passed on the dedicated `hcm_p14_concurrency` database (47 earlier-phase races, 10 Phase 14 races, 1 scale test, 2 closure races) |
| Pint `--test` | pass |
| OpenAPI `--check` | up to date |
| Migration replay | ×2 pass |
| Audit chains | pass |
| Failed jobs | 0 (development, replay, concurrency) |

## 27. Git State

Branch `feature/oct_1_phase_1`. Closure commits `dce6c58`, `8978e1a`, `faacccc`, `456c235`, `4fcdb7a`, `f9833cb`, and the final documentation commit that adds this report. The working tree is clean after it. **No push, no merge, no deployment, no history rewrite.**

Artifacts:
- **Removed:** `hcm_closure_restore`, `hcm_p14_restore` and both development dumps (they held development data).
- **Kept as documented disposable databases:** `hcm_p14_concurrency` and `hcm_p14_replay` (synthetic / seeded data only, for reproducing the evidence).
- No dump or credential file exists in the repository or the scratchpad.

## 28. Final Readiness Matrix

| Area | Status |
|---|---|
| Application | PASS (code, tested) / production NOT VERIFIED |
| Security | PASS (code: BGV, SSRF, storage, configuration, isolation) |
| Tenant isolation | PASS |
| API | PASS |
| Integration Hub | PASS |
| BGV callback | PASS (generic scheme); provider-specific scheme PENDING |
| Webhook SSRF | PASS |
| Storage | PASS (code) |
| S3 | NOT VERIFIED (capability PASS; no bucket) |
| Redis | NOT VERIFIED |
| Queues | PASS (code) / production workers NOT VERIFIED |
| Scheduler | PASS (code) / production cron NOT VERIFIED |
| Observability | PASS (code) / monitoring NOT VERIFIED |
| Backup | NOT VERIFIED (procedure documented) |
| Restore | NOT VERIFIED (local rehearsal PASS) |
| DR | NOT VERIFIED |
| Performance | PASS (10,000 employees, constant queries) |
| Concurrency | PASS |
| Audit | PASS |
| Configuration | NOT VERIFIED (validator PASS; production not configured) |
| Statutory | BLOCKED |
| Operator Sign-off | PENDING |

**Engineering Readiness:** READY FOR STAGING. The code blockers are closed and tested on the final
tree.

**Operational Readiness:** NOT VERIFIED.

**Security Readiness:** code PASS. Environment items (TLS, proxies, egress, penetration test) are NOT
VERIFIED.

**Statutory Readiness:** BLOCKED.

**Deployment Readiness:** NOT VERIFIED.

**Operator Approval:** PENDING.

PeopleOS is **not** declared production-ready.
