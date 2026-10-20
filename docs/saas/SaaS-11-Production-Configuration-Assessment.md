# SaaS.11 — Production configuration assessment

Date: 10 October 2026. **Gate: BLOCKED. No production environment exists to assess.**

The only environment available is a development workstation. This assessment therefore covers two things:
- the **rules** the platform enforces on a production configuration, which are tested here;
- what the **target environment** must show before release, which cannot be tested here.

No secret value was printed, copied or written while preparing it. The commands report variable names and rule
categories only. Nothing in any environment was changed silently: the only configuration changes are new rules in
the validator and readiness (§3) and documentation (`.env.example`, `docs/operations/production-configuration.md`).

## 1. Evidence: the validator run as production on this machine

`php artisan peopleos:config:validate --as-production --json`, on the development `.env`, at `62f2603`:
**11 errors, 2 warnings.** All are expected on a development machine, and each must be cleared in the target.

| Variable | Category | Severity | What production needs |
|---|---|---|---|
| `APP_ENV` | insecure | error | `production` |
| `APP_DEBUG` | insecure | error | `false` (production also forces it off at boot) |
| `APP_URL` | insecure, invalid | 2 errors | the public `https://` address |
| `DB_USERNAME` | insecure | error | a dedicated least-privilege user, never `root` |
| `SESSION_SECURE_COOKIE` | insecure | error | `true` |
| `MAIL_MAILER` | incompatible | error | a real transport (`log` delivers nothing) |
| `MAIL_FROM_ADDRESS` | missing | error | a monitored address on the platform's domain |
| `LOG_LEVEL` | insecure | error | `info` or above |
| `PEOPLEOS_HEALTH_TOKEN` | missing | error | at least 24 random characters, in the secret store |
| `PEOPLEOS_SEED_PASSWORD` | insecure | error (**new in SaaS.11**) | unset |
| `SESSION_ENCRYPT` | advisory | warning | `true` recommended |
| `PEOPLEOS_TRUSTED_PROXIES` | advisory | warning | the load balancer's addresses |

## 2. Assessment by area

| Area | Rule enforced in code | Evidence here | Target status |
|---|---|---|---|
| `APP_DEBUG=false` | error in the validator, readiness FAIL, forced off at boot | tested (`ProductionConfigurationTest`) | NOT VERIFIED (no target) |
| `APP_URL` | https and a valid URL | tested | NOT VERIFIED |
| Secure cookies | `SESSION_SECURE_COOKIE` error; `SESSION_ENCRYPT` advisory | tested | NOT VERIFIED |
| Real mail and notification recovery | `log` and `array` mailers are refused. The audit records only what the transport accepted. Signup e-mails are sent synchronously (never queued); other notifications are queued and retried, and failures land in `failed_jobs` (alerting on them is a deployment task) | tested; the SMTP flow was exercised against a local sink in SaaS.10 | NOT VERIFIED: needs the production transport, SPF and DKIM, and a monitored bounce address |
| Trusted proxies | advisory when empty | tested | NOT VERIFIED: depends on the load balancer |
| HSTS | sent on HTTPS; **SaaS.11: below one year is an error** | tested | NOT VERIFIED |
| Indexability and robots | **SaaS.11:** `X-Robots-Tag: noindex, nofollow` on every response; `robots.txt` disallows everything; an empty tag is a warning | tested (`SecurityHardeningTest`) | NOT VERIFIED (and no public host exists yet) |
| Database | MySQL (the concurrency guarantees were proven on MySQL 8.4); not `root` | MySQL suite (see the final report) | NOT VERIFIED: a managed MySQL with backups and binary logs |
| Redis | optional. If cache, queue or sessions use it: the client must be installed, a host set, and Redis must require authentication. TLS is not checked | detection tested; no Redis exists here, so never against a live Redis | NOT VERIFIED |
| Queues | not `sync`; `retry_after` above the longest job timeout | tested; the restore rehearsal drained restored jobs | NOT VERIFIED: needs a supervised worker |
| Scheduler | one server runs `schedule:run` every minute; the heartbeat is read by `/health/ready` and readiness | the restore rehearsal read the heartbeat back on the restored copy | **NOT RUNNING anywhere for PeopleOS** (the crontab and workers here belong to another project) |
| Storage | the documents disk is private; the local disk never serves files; S3 needs its adapter | tested | NOT VERIFIED: object storage with versioning and server-side encryption |
| Secret management | values never printed; the repository holds only `.env.example` | history scan of 284 commits (security findings §2) | NOT VERIFIED: no secret store exists. One credential leaked in the past (F1) |
| Logging and monitoring | every channel carries the redaction tap; liveness and readiness probes; slow-query logging; audit verification daily | tested | NOT VERIFIED: no alerting is wired |
| Deployment and rollback | the deploy sequence (`production-configuration.md` §9); additive migrations; the latest migration's rollback and re-apply were exercised on a restored copy | restore rehearsal step 14; migration integrity (final report) | NOT VERIFIED: no pipeline deploys to any environment. CI runs Pint and the SQLite suite only |
| Release switches | **SaaS.11:** registration and deletion refused in production without a recorded authorisation reference; Razorpay and live keys refused | tested, mutation-tested | Gate holds by default: every switch is off |
| Operator MFA | **SaaS.11:** readiness FAILs in production while an operator who can sign in has no authenticator | tested, mutation-tested | **Open here:** 1 of 1 operator unenrolled in each local database |

## 3. Configuration rules added in this phase

| Rule | Where | Why |
|---|---|---|
| `PEOPLEOS_PUBLIC_SIGNUP_ENABLED` needs `PEOPLEOS_PUBLIC_SIGNUP_AUTHORISATION` | validator, error | D10-1: switching registration on is the owner's decision. A switch flipped without a recorded decision must not pass the gate |
| `PEOPLEOS_TENANT_DELETION_ENABLED` needs `PEOPLEOS_TENANT_DELETION_AUTHORISATION` | validator, error | D-8 and F11 are open; deletion is irreversible |
| Razorpay disabled; no `rzp_live_` key | validator, errors | Live payments are not released (SaaS.7 B-gates) |
| `PEOPLEOS_SEED_PASSWORD` unset | validator, error | A demo password must never reach production (F13) |
| HSTS at least one year | validator, error | Below a year weakens HTTPS pinning |
| `X-Robots-Tag` present | validator, warning; middleware sends it | Private platform; tokens in links |
| Operators' MFA enrolled | readiness, FAIL in production | F8 |

An authorisation reference is a decision identifier, never a secret. The validator only checks that it is present.
Whether it is genuine is for the people reviewing the deployment.

## 4. What the target environment must show (before the gate can pass)

1. `peopleos:config:validate --as-production` exits 0 **in the target**, with output kept as release evidence.
2. `peopleos:readiness` shows no FAIL. Statutory stays BLOCKED until §5 of the release-gate register is met.
3. Secrets are delivered from a secret store; the `.env` is not edited by hand on the server; the leaked credential
   (F1) is not reused anywhere.
4. A worker is supervised, and the scheduler runs on exactly one server; the heartbeat is under three minutes old.
5. Mail goes through a real transport with SPF, DKIM and DMARC; a test message reaches a monitored mailbox.
6. `/health/ready` is wired to monitoring with the health token. Failed jobs, a broken audit chain and dead letters
   alert someone.
7. The restore and DR exercise (`SaaS-11-Backup-and-DR-Test-Report.md` §4) has been run there and signed.
8. Every platform operator has MFA enrolled.
9. All release switches are off, or on with their recorded authorisation.
