# Production configuration

Phase 14 (3 October 2026), extended in the production readiness closure. This is the checklist an
operator works through before a PeopleOS environment serves real employees.

- **`php artisan peopleos:config:validate [--as-production] [--json]`** (closure) checks the
  configuration. Each finding has a key, a category (missing / insecure / invalid / incompatible /
  advisory) and a rule, **never a value**. The command exits non-zero on any error. In production the
  same validator makes `/health/ready` return 503 while an error remains.
- **`php artisan peopleos:readiness`** combines the validator with runtime health, audit chains, the
  statutory gate and operator evidence. The **Platform readiness** page shows the same report.
- **In production `APP_DEBUG=true` is ignored at boot** (forced off and logged critical); the validator
  still reports it so it gets fixed.

> Software readiness is not production readiness. Passing every check below means the environment
> is *configured*. Statutory payroll stays **BLOCKED** until rules are verified with authoritative
> evidence (see `docs/architecture/statutory-production-readiness.md`). Backups and disaster recovery
> stay unverified until restored in the target environment (see `backup-and-disaster-recovery.md`).

## 1. Application

| Setting | Required value | Checked by readiness |
|---|---|---|
| `APP_ENV` | `production` | yes (FAIL otherwise) |
| `APP_DEBUG` | `false` | yes (FAIL) |
| `APP_KEY` | set, 32-byte base64; kept in the secret store, never in git | yes (FAIL if missing) |
| `APP_PREVIOUS_KEYS` | set only during a key rotation | — |
| `APP_URL` | the public `https://` URL (used for signed links and webhook / AI proposal host checks) | yes (WARN if not https) |
| `LOG_LEVEL` | `info` or `warning` (never `debug`) | yes (WARN) |
| `LOG_CHANNEL` / `LOG_STACK` | `stack` → `daily` or `stderr` (all carry the redaction tap) | yes (FAIL if a channel without the tap) |

## 2. Database

| Setting | Required value | Checked |
|---|---|---|
| `DB_CONNECTION` | `mysql` (8.0+; InnoDB, REPEATABLE READ: the concurrency guarantees were proven on MySQL) | yes (WARN for others) |
| `DB_DATABASE` | a dedicated schema; never shared with another application | — |
| User grants | DML + DDL for migrations only from the deploy role; the runtime user needs no `DROP` | — |
| TLS | `MYSQL_ATTR_SSL_CA` set when the database is remote | — |
| Backups | see the backup / DR runbook | readiness reports "not verified" |

## 3. Cache, session, queue

| Setting | Required value | Checked |
|---|---|---|
| `CACHE_STORE` | `redis` or `database`. Never `array` or `file` on more than one node: scheduler locks (`onOneServer`) and the heartbeat need a shared store | yes (FAIL for array) |
| `SESSION_DRIVER` | `redis` or `database` | yes (WARN for file / array) |
| `SESSION_SECURE_COOKIE` | `true` | yes (WARN) |
| `SESSION_ENCRYPT` | `true` recommended | — |
| `QUEUE_CONNECTION` | `redis` or `database`, **never `sync`** in production | yes (FAIL for sync) |
| `DB_QUEUE_RETRY_AFTER` / `REDIS_QUEUE_RETRY_AFTER` | ≥ 3900 (above the longest job timeout) | yes (FAIL) |
| Workers | supervised `queue:work --timeout=3700` (or Horizon), restarted on deploy | heartbeat / failed jobs via `/health/ready` |
| Scheduler | cron `* * * * * php artisan schedule:run` on one node | heartbeat age |

## 3a. Proxies and HTTPS (closure)

| Setting | Required value | Checked |
|---|---|---|
| `PEOPLEOS_TRUSTED_PROXIES` | The load balancer / TLS terminator addresses or CIDRs (comma-separated), or `*` only when nothing but the proxy can reach the app. `X-Forwarded-*` headers are trusted only from these | advisory when empty |
| HSTS | At the proxy | — |

Without trusted proxies, a TLS-terminating load balancer makes the app see plain http. Secure cookies,
https URLs and signed links then break, and clients could not be told apart by IP.

## 3b. Redis (closure)

Redis is optional. The `database` store and queue are valid shared backends. When any of cache, queue
or session uses Redis:

- the `redis` PHP extension is loaded (`REDIS_CLIENT=phpredis`), or `predis/predis` is installed;
- `REDIS_HOST` and `REDIS_PASSWORD` are set;
- `/health/ready` pings Redis (critical).

The cache store must support atomic locks, since scheduler `onOneServer` / `withoutOverlapping`
depend on them. `/health/ready` exercises a lock (`locks` check). **Not verified here:** no Redis
server or extension exists on the build workstation.

## 4. Storage

| Setting | Required value | Checked |
|---|---|---|
| `PEOPLEOS_DOCUMENTS_DISK` | a private disk. `local` is acceptable on one node with backed-up storage; multi-node deployments need shared object storage (S3-compatible) | yes (probe write / read / delete) |
| `filesystems.disks.local.serve` | `false` (Phase 14: files are served only through authorised, audited routes) | yes (FAIL) |
| S3 (closure) | The `league/flysystem-aws-s3-v3` adapter **is installed**; the `s3` disk is private and throws on failure. Configure `AWS_ACCESS_KEY_ID`, `AWS_SECRET_ACCESS_KEY`, `AWS_DEFAULT_REGION`, `AWS_BUCKET` (`AWS_ENDPOINT` / `AWS_USE_PATH_STYLE_ENDPOINT` for S3-compatible stores) for a bucket with Block Public Access, default encryption and versioning | yes (missing credentials, adapter) |
| Storage roles (closure) | `PEOPLEOS_DOCUMENTS_DISK`, `PEOPLEOS_STAGING_DISK` (form uploads), `PEOPLEOS_COMPLIANCE_DISK` (statutory evidence and returns), `PEOPLEOS_WAREHOUSE_DISK`, `FILESYSTEM_DISK` (Livewire temporary uploads). On more than one node, set all to `s3` and `PEOPLEOS_SHARED_STORAGE_REQUIRED=true` | yes (every role) |
| Moving existing files | Changing a role's disk does not move files already stored; copy them first (paths are unchanged, under `tenants/{id}/…`) | — |
| `PEOPLEOS_WAREHOUSE_DISK` | private; the feed carries non-sensitive fields unless a tenant enables `warehouse.include_sensitive` | — |

## 5. Mail and notifications

| Setting | Required value | Checked |
|---|---|---|
| `MAIL_MAILER` | a real transport (`smtp`, `postmark`, `ses`, `resend`), not `log` or `array` | yes (WARN) |
| `MAIL_FROM_ADDRESS` | a monitored address on the tenant-facing domain | yes (WARN if the default) |
| SMS / WhatsApp / push | still the log placeholder driver; real providers are Integration Hub work (deferred) | — |

## 6. Security

| Setting | Required value | Checked |
|---|---|---|
| HTTPS | enforced at the proxy; HSTS | — |
| Trusted proxies | configured for the load balancer | — |
| `PEOPLEOS_HEALTH_TOKEN` | set (≥ 24 random characters). Without it `/health/ready` shows statuses only. Monitoring sends it as `X-Health-Token` | yes |
| Outbound SSRF guard (closure) | `PEOPLEOS_OUTBOUND_ALLOW_HTTP=false`; `PEOPLEOS_OUTBOUND_ALLOWED_PORTS` (default 443,80,8443,8080); `PEOPLEOS_OUTBOUND_ALLOWED_HOSTS` empty unless an on-premises receiver is approved | yes |
| BGV callback (closure) | Each provider gets an Integration Hub system of kind `bgv` bound to its own API key; the provider signs every callback | — |
| `PEOPLEOS_ENFORCE_VERIFIED_RULES` | leave unset or `true`. Production enforces verified statutory rules whatever this says | — |
| Tenant security policy | MFA, IP allow-list and idle timeout per tenant (Security policy page) | — |
| API keys | issued per integration with the minimum scopes; expiry dates set | — |
| Secrets | `APP_KEY`, DB / Redis / mail / AWS credentials, `ANTHROPIC_API_KEY` and integration secrets live in the secret store; **never committed** | the repository holds only `.env.example` |

## 7. AI

| Setting | Required value | Checked |
|---|---|---|
| `PEOPLEOS_AI_PROVIDER` | `none` (deterministic answers only) unless the organisation approved an external provider | yes (WARN when external is on and a tenant's data policy is `restricted`) |
| `ANTHROPIC_API_KEY` | only when the provider is `anthropic` | — |
| Tenant `ai.external_data_policy` | `allowed` (default) or `none`; `restricted` only with a documented decision | per tenant |
| `PEOPLEOS_AI_RATE_LIMIT` | 20 per user per minute (default) | — |

## 8. Observability

| Item | Requirement |
|---|---|
| `/health/live` | wire as the liveness probe (no dependencies) |
| `/health/ready` | wire as the readiness probe; alert on `down` (503) and on `degraded` lasting more than 10 minutes |
| `PEOPLEOS_SLOW_QUERY_MS` | 1000 (default); review `Slow query` log lines weekly |
| Failed jobs | alert on any `Queue job failed` line or a non-zero `failed_jobs` count |
| Dead letters | alert on `integrations` warnings (inbound or webhook dead letters) |
| Audit chain | run `php artisan peopleos:audit:verify` daily and alert on a broken chain |

## 9. Deploy sequence

1. `php artisan down` (or blue/green).
2. Back up the database (see the backup runbook). **Do not proceed without a fresh backup.**
3. `composer install --no-dev --optimize-autoloader`
4. `php artisan migrate --force` (migrations are additive. Each Phase 14 `down()` was replayed twice on a disposable database; see the Phase 14 report).
5. `php artisan peopleos:sync-permissions`
6. `php artisan config:cache && php artisan route:cache && php artisan view:cache && php artisan event:cache`
7. `php artisan queue:restart`
8. `php artisan peopleos:readiness`: no FAIL lines.
9. `php artisan up`.
