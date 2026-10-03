# Production readiness closure: discovery (PR.1)

Date: 3 October 2026. Baseline `382c7df` (branch `feature/oct_1_phase_1`, Phase 14 frozen and
approved). This was written before any closure change. It records the exact current implementation
behind each documented blocker. Nothing below assumes the Phase 14 report; every item was re-read in
code.

## 1. Git state at start

- Branch `feature/oct_1_phase_1`, HEAD `382c7df`, working tree clean, 48 commits ahead of origin.
- Nothing pushed, merged or deployed.

## 2. Blocker-by-blocker findings

| # | Blocker (Phase 14 report) | Current implementation (verified in code) | Still present? | Track |
|---|---|---|---|---|
| 1 | BGV callback has no request signature | `POST /api/v1/bgv/cases/{reference}/checks` (`BgvCallbackController`). Authenticated only by an API key with scope `bgv.write`. No signature, no timestamp window, no replay protection, no idempotency record, no binding to an integration identity. Results are written directly through `Bgv::recordCheck` | **Yes** | A: code |
| 2 | Webhook URLs not filtered for private networks | Four outbound HTTP paths use `Http::` with no destination checks. **Tenant-controlled URLs:** `Enterprise\Services\Webhooks::attempt` (webhook endpoints), `Workflow\Jobs\SendWebhook` (workflow webhook node URL, method, headers), `Enterprise\Services\Sso` (`token_url`, `userinfo_url`, tenant-configured). **Operator-configured:** `Ai\Providers\AnthropicProvider` (env endpoint). No DNS-resolution check. Redirects are followed (Guzzle default: up to 5), so a public URL can redirect into a private network. The resolved address is not pinned, so DNS rebinding is possible. The `SendWebhook` job payload (URL, configured headers such as `Authorization`, body) is serialised unencrypted into the queue tables | **Yes** | A: code |
| 3 | Statutory gate BLOCKED | Unchanged: 24 rules / 0 verified / 5 open notices. Production enforcement is unconditional (`ComplianceRules::enforced()`) | Yes (by design) | C: statutory, **out of scope; not touched** |
| 4 | Backup / DR not verified in a target environment | Runbook `docs/operations/backup-and-disaster-recovery.md`; one local dump / restore test. No staging or target environment is reachable from this workstation | Yes | B: environment |
| 5 | S3 adapter not installed | `league/flysystem-aws-s3-v3` absent (Composer can reach Packagist). The `s3` disk is defined in `config/filesystems.php` without `visibility` / `throw`. Several stores **hard-code the `local` disk**: compliance evidence (`RuleVerifications`, `RecordVerifications`, `ExportLayouts`), statutory return exports (`StatutoryReturns`), learning evidence (`LearningEvidenceService`), and every Filament staging upload (documents, certificates, learning evidence, compliance evidence, blueprints, parallel runs). `DocumentsRelationManager` builds an `UploadedFile` from `Storage::disk('local')->path()`, which works only on a local disk | **Yes** (adapter, plus local-filesystem dependencies) | A: code (capability) + B: environment (bucket) |
| 6 | Production configuration not configured / verified | `PlatformReadiness` checks APP_ENV / DEBUG / KEY / URL, logging, cache, session driver and secure cookie, queue, storage serve flag, mail, health token, AI. **Missing:** a standalone validator command, session `http_only` / `same_site`, CORS posture, trusted proxies (none configured: behind a TLS-terminating proxy Laravel sees plain http, which breaks secure cookies and signed URLs), CSRF middleware presence, encryption cipher, DB credential posture, S3 / Redis credential completeness, Livewire temporary-upload disk. A production `APP_DEBUG=true` is reported but not neutralised | **Partly** | A: code (validator) + B: environment |
| 7 | Redis / shared cache not verified | No Redis server and no `redis` PHP extension on this workstation. Config supports Redis (cache, queue, session). `/health/ready` pings Redis when any of cache / queue / session uses it. Readiness rejects `array` cache in production. Scheduler `onOneServer` needs a lock-capable shared store (database and Redis both qualify) | Yes (environment) | B: environment |
| 8 | Operator sign-off missing | No go-live checklist with owner / date / evidence fields | Yes | D: operator |

## 3. Areas reviewed and found adequate (no change planned)

| Area | Evidence |
|---|---|
| Health endpoints | `/health/live` (process only) and `/health/ready` (database, cache, storage critical; queue, failed jobs, heartbeat, dead letters, Redis warn). No session, no tenant data, details only with `PEOPLEOS_HEALTH_TOKEN`. **Gap:** readiness does not include configuration safety (added in PR.5) |
| Queue bounds | `retry_after` 3900 s > longest job timeout 3600 s; every job declares a timeout (and a backoff when retried), test-enforced; tenant-aware jobs refuse a missing tenant; suspended tenants skipped |
| Scheduler | 23 entries, all `withoutOverlapping()->onOneServer()`; every tenant-iterating command uses `TenantRunner`; heartbeat; claims. Inventory tested against the live schedule |
| Integration Hub | Signed (HMAC-SHA256 over `timestamp.body`, constant-time compare, tolerance window), idempotent, audited inbound events. `bgv` already exists as an integration kind. **This is the existing secure-callback boundary that the BGV callback will reuse** |
| Download authorisation | Every download route is signed, authorises, audits, and streams from the private disk (no public object URLs) |
| Statutory gate | Enforced and BLOCKED; not touched by this closure |

## 4. Closure plan (narrow)

| Commit | Change |
|---|---|
| PR.1 | This discovery |
| PR.2 | BGV callback: require an active `bgv` integration bound to the API key, the Integration Hub signature (timestamp window, constant-time), replay / duplicate protection and an audit record through `inbound_events`, all before any processing; a `bgv.results` hub handler. Provider-specific signature schemes stay **pending** (no vendor contract available) |
| PR.3 | Outbound HTTP guard for every tenant-controlled destination (webhooks, workflow webhook node, SSO endpoints): scheme / port / credentials rules, DNS resolution with every address checked against private, loopback, link-local, metadata, reserved and multicast ranges (IPv4 and IPv6, including mapped forms), connection pinned to the validated address (anti-rebinding), redirects disabled. Encrypted `SendWebhook` payload; secret-safety tests |
| PR.4 | S3 capability: install the official Laravel S3 adapter; a private `s3` disk; configurable stores for compliance evidence, statutory exports and learning evidence (default unchanged); a configurable staging disk for uploads; no `->path()` dependency |
| PR.5 | Production configuration validator (`peopleos:config:validate`, used by readiness and by `/health/ready` in production), trusted proxies from configuration, production debug neutralised, Redis / S3 credential checks |
| PR.6 | `docs/production/disaster-recovery.md`, smoke-test checklist, go-live checklist, local restore rehearsal (target DR stays NOT VERIFIED) |
| PR.7 / PR.8 | Final regression evidence, readiness matrix, closure report |
