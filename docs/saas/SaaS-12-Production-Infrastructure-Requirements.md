# SaaS.12 — Production infrastructure: requirements and status

Date: 10 October 2026. **For:** the owner and whoever will provide and operate the infrastructure.

**Status: BLOCKED — ENVIRONMENT.**
- No development, testing, staging or production environment exists beyond this workstation.
- Nothing below describes an existing system. It states what PeopleOS requires, from the code and its tests, and which
  choice belongs to the owner.
- No hosting provider, region, size or vendor has been chosen here.

The development workstation is **not** a model for production. It shares one MySQL server with many unrelated projects,
runs as `root` against the database, uses the `log` mailer and has a world-readable `.env`. The 8090 showcase is a demo
with shared fixture passwords.

## 1. Environments

| Environment | Purpose | Data | Required before |
|---|---|---|---|
| Development | This workstation | Fictional | Exists |
| Testing (CI) | Pint, SQLite suite on pull requests and `main` | None | Exists. Recommended: add the MySQL suite and the browser suites (SaaS.11 S11-6) |
| Staging | Release-candidate validation, independent security review, signup and billing test mode | **Fictional only** | Release candidate (G24) |
| Isolated restore target | DR exercise, production-copy offboarding rehearsal | Production copy under access control, with secrets replaced, mail and payments inert | G13–G16 |
| Production | Customers | Real | Every gate |

Production data never goes to development, CI or staging. A production-like restore goes only to the isolated target,
under written authorisation.

## 2. Components PeopleOS requires

| Component | Requirement (from code and tests) | Owner decision |
|---|---|---|
| Compute | PHP 8.5 with mbstring, intl, pdo_mysql, bcmath and gd; the web tier behind TLS; workers and the scheduler | Provider, size, region (D10-11, D8-7) |
| Database | MySQL 8.4, InnoDB (the concurrency guarantees were proven on MySQL 8.4); a dedicated schema and a least-privilege user (never root); binary logs for point-in-time recovery | Managed or self-run; backup retention (D8-5) |
| Cache, sessions, locks | Shared store (Redis with authentication, or the database). Scheduler locks, rate limits, the signup provisioning lock and `migrate --isolated` depend on it | Redis or database |
| Queue workers | Supervised `queue:work` processes; `retry_after` above 3,600 s; failed jobs recorded | Process manager |
| Scheduler | `schedule:run` every minute on **one** server (heartbeat under 3 minutes) | — |
| Private object storage | Documents, exports, warehouse feeds: private, server-side encrypted, versioned. The AWS S3 adapter is installed | Provider and buckets |
| Deletion register | A separate private disk (`PEOPLEOS_DELETION_REGISTER_DISK`): versioned, ideally object-locked, backed up separately from the database | Bucket and retention |
| Network and firewall | Database and Redis reachable only from the application tier; egress limited (SSRF guard plus network policy) | — |
| DNS and TLS | The public host for `APP_URL`; certificates with renewal; HSTS at least one year | Domain |
| Reverse proxy or load balancer | TLS termination; `PEOPLEOS_TRUSTED_PROXIES` set to its addresses | — |
| Mail | A real transport; SPF, DKIM and DMARC; a monitored bounce address | Provider |
| Secret management | `APP_KEY` (and `APP_PREVIOUS_KEYS` during rotation), database, Redis, mail, storage and health token come from a secret store. `.env`, if any, is mode 640 or 600 | Secret store |
| Logging | Every channel carries the redaction tap; level `info` or above; retention per D8 | Log destination |
| Monitoring and alerting | `/health/live` (liveness), `/health/ready` with `X-Health-Token`; alerts on failed jobs, dead letters, a broken audit chain and a stale heartbeat | **Provider (O-11), on-call (O-10)** |
| Error reporting | None integrated: only logs | Provider (O-11) |
| Backups | Database (daily full plus binary logs), objects (versioning), the deletion register separately; encrypted; restore tested | Retention (D8-5); objectives (O-6) |
| Deployment | Nothing exists in the repository (no container, infrastructure code or deploy script). The required sequence is in `SaaS-12-Deployment-and-Migration-Plan.md` | Mechanism |
| Rollback | Previous artefact kept; the database restored from the pre-deploy backup if a migration must be undone | — |
| External integrations | Razorpay (test mode only; production refuses it); AI provider off unless approved; BGV providers per contract | Each one, per contract |

## 3. What must be true in the target before configuration can pass

1. `php artisan peopleos:config:validate --as-production` exits 0. The output, with no values, is kept as evidence.
2. `php artisan peopleos:readiness` has no FAIL. The statutory gate stays BLOCKED until verification.
3. Every platform operator has MFA enrolled.
4. Release switches are off, or on with their recorded authorisation. The deletion register disk is configured before
   deletion is ever considered.
5. Health endpoints are wired to monitoring, and a test alert reached the on-call person.
6. The DR exercise ran in the isolated target and was signed (`SaaS-11-Backup-and-DR-Test-Report.md` §4).
