# Go-live checklist

Prepared in the production readiness closure (3 October 2026, branch `feature/oct_1_phase_1`).
**This checklist is not signed.**

- Engineering filled in only what it could verify on the build workstation.
- Environment items stay **NOT VERIFIED** until an operator records evidence from the target
  environment.
- Section T is for the authorised human approver only.

Status values: **PASS**, **FAIL**, **NOT VERIFIED**, **NOT APPLICABLE**. An item becomes PASS only
with evidence from the environment named in the item.

## A. Application

| Item | STATUS | EVIDENCE | OWNER | DATE |
|---|---|---|---|---|
| Release built from the reviewed commit; full test suite green on that commit | PASS | Closure report §26 (full suite and MySQL suite on the final tree) | Engineering | 2026-10-03 |
| `APP_ENV=production`, `APP_DEBUG=false`, `APP_KEY` set (32 bytes), https `APP_URL` in production | NOT VERIFIED | `peopleos:config:validate` output from production | Operations | |
| `peopleos:config:validate` shows 0 errors in production | NOT VERIFIED | Command output (keys only) | Operations | |
| Caches built (`config`, `route`, `view`, `event`) | NOT VERIFIED | Deploy log | Operations | |

## B. Database

| Item | STATUS | EVIDENCE | OWNER | DATE |
|---|---|---|---|---|
| MySQL 8, dedicated schema, dedicated non-root user, strong password, TLS if remote | NOT VERIFIED | Validator; DBA record | Operations | |
| Migrations applied (`migrate:status` all ran) | NOT VERIFIED | Command output | Operations | |
| Migration replay safe (fresh → seed → rollback → migrate, twice, identical schema) | PASS | Closure report §19 (disposable `hcm_p14_replay`) | Engineering | 2026-10-03 |
| Concurrency guarantees on MySQL (races and lock-removal proofs) | PASS | Closure report §17 (`hcm_p14_concurrency`) | Engineering | 2026-10-03 |

## C. Redis

| Item | STATUS | EVIDENCE | OWNER | DATE |
|---|---|---|---|---|
| Decision recorded: Redis, or database store / queue | NOT VERIFIED | Architecture decision | Operations | |
| If Redis: extension or predis present, host and password set, `/health/ready` redis `ok` | NOT VERIFIED | Readiness output (no Redis available to engineering) | Operations | |
| Cache store supports atomic locks (`/health/ready` locks `ok`) | NOT VERIFIED | Readiness output in production (passes on the workstation with the database store) | Operations | |

## D. Queues

| Item | STATUS | EVIDENCE | OWNER | DATE |
|---|---|---|---|---|
| Job bounds: every job has a timeout below `retry_after` (3900 s), backoff when retried, tenant refused when missing | PASS | OperationsHardeningTest; ProductionConfigurationTest (real worker) | Engineering | 2026-10-03 |
| Supervised workers: `queue:work --timeout=3700 --memory=512 --max-time=3600`, restarted on deploy | NOT VERIFIED | Supervisor / systemd config | Operations | |
| Failed-job alerting wired | NOT VERIFIED | Monitoring rule | Operations | |

## E. Scheduler

| Item | STATUS | EVIDENCE | OWNER | DATE |
|---|---|---|---|---|
| 23 entries, overlap and multi-server protected, tenant-isolated (TenantRunner), claims, heartbeat | PASS | Closure report §10; inventory test | Engineering | 2026-10-03 |
| Cron on exactly one node; heartbeat younger than 3 minutes in production | NOT VERIFIED | `/health/ready` scheduler `ok` | Operations | |

## F. Storage

| Item | STATUS | EVIDENCE | OWNER | DATE |
|---|---|---|---|---|
| No local-disk pinning in code; staging, documents, compliance and warehouse roles configurable | PASS | StorageProductionTest | Engineering | 2026-10-03 |
| Server-side file safety (type, size, active content) and signed, audited downloads | PASS | StorageProductionTest, DocumentsTest | Engineering | 2026-10-03 |
| Every storage role points at shared private storage; `PEOPLEOS_SHARED_STORAGE_REQUIRED=true` on multi-node | NOT VERIFIED | Validator output | Operations | |

## G. S3

| Item | STATUS | EVIDENCE | OWNER | DATE |
|---|---|---|---|---|
| S3 adapter installed; private, failure-raising disk; pre-signed URLs computable | PASS | StorageProductionTest (offline) | Engineering | 2026-10-03 |
| Production bucket: Block Public Access, encryption, versioning, object lock on evidence prefixes | NOT VERIFIED | Bucket policy export | Operations | |
| Upload / download round trip against the real bucket; anonymous GET refused | NOT VERIFIED | Smoke test rows 8 and 20 | Operations | |

## H. Security

| Item | STATUS | EVIDENCE | OWNER | DATE |
|---|---|---|---|---|
| BGV callback signed, replay-safe, tenant-bound | PASS | BgvCallbackSecurityTest; MySQL ClosureConcurrencyTest | Engineering | 2026-10-03 |
| Outbound SSRF guard (DNS-checked, pinned, no redirects) on webhooks, workflow webhooks, SSO | PASS | OutboundSsrfTest | Engineering | 2026-10-03 |
| Tenant isolation, access scope, field security, 30 architecture invariants | PASS | Architecture and security suites | Engineering | 2026-10-03 |
| TLS, HSTS, trusted proxies (`PEOPLEOS_TRUSTED_PROXIES`), Secure / HttpOnly / SameSite cookies | NOT VERIFIED | Validator; TLS scan | Operations | |
| Egress filtering from application nodes (no route to metadata / private ranges) | NOT VERIFIED | Network policy | Operations | |
| Penetration test by an independent party | NOT VERIFIED | Report | Security | |
| BGV provider-specific signature scheme | NOT APPLICABLE until a provider contract exists (providers sign with the PeopleOS scheme) | Security boundaries doc §1 | Engineering | 2026-10-03 |

## I. APIs

| Item | STATUS | EVIDENCE | OWNER | DATE |
|---|---|---|---|---|
| Error envelope, idempotency, rate limits, OpenAPI up to date | PASS | ApiPlatformTest; `peopleos:openapi --check` | Engineering | 2026-10-03 |
| Production API keys issued per integration with minimum scopes and expiry | NOT VERIFIED | Key register | Operations | |

## J. Webhooks

| Item | STATUS | EVIDENCE | OWNER | DATE |
|---|---|---|---|---|
| Signed deliveries, dead letters, replay; no secrets in records or logs | PASS | EnterpriseTest; OutboundSsrfTest | Engineering | 2026-10-03 |
| Production endpoints registered (https, public); consumers verify signatures | NOT VERIFIED | Endpoint register | Operations | |

## K. Monitoring

| Item | STATUS | EVIDENCE | OWNER | DATE |
|---|---|---|---|---|
| `/health/live` as liveness, `/health/ready` as readiness; `PEOPLEOS_HEALTH_TOKEN` set | NOT VERIFIED | Probe configuration | Operations | |
| Alerts: readiness down / degraded, failed jobs, dead letters, slow queries, audit-chain verification (daily) | NOT VERIFIED | Alert rules | Operations | |
| Log redaction tap active on the production channels | PASS (code) / NOT VERIFIED (production) | Validator `LOG_CHANNEL` | Engineering / Operations | 2026-10-03 / |

## L. Backup

| Item | STATUS | EVIDENCE | OWNER | DATE |
|---|---|---|---|---|
| Backup procedure documented | PASS | `docs/production/disaster-recovery.md` §1–§4 | Engineering | 2026-10-03 |
| Daily encrypted dumps plus binlog shipping running in production; checksums recorded | NOT VERIFIED | Backup job logs | Operations | |
| `APP_KEY` versions stored with the backup sets | NOT VERIFIED | Secret-store record | Operations | |

## M. Restore

| Item | STATUS | EVIDENCE | OWNER | DATE |
|---|---|---|---|---|
| Local restore rehearsal of the full runbook | PASS (local only) | DR doc §10 (38.5 s, identical schema / rows, audit verified, 360 smoke) | Engineering | 2026-10-03 |
| Restore in the target / staging environment from a production backup | NOT VERIFIED | DR doc §12 evidence | Operations | |

## N. DR

| Item | STATUS | EVIDENCE | OWNER | DATE |
|---|---|---|---|---|
| Target DR exercise with measured RPO and RTO | NOT VERIFIED | DR doc §11–§12 | Operations | |
| Object-storage and key recovery exercised | NOT VERIFIED | Exercise record | Operations | |

## O. Data migration

| Item | STATUS | EVIDENCE | OWNER | DATE |
|---|---|---|---|---|
| Employee / organisation data loaded through the import pipeline (validated, approved, audited) | NOT VERIFIED | Import records | HR operations | |
| Reconciliation of loaded data against the source system signed off | NOT VERIFIED | Reconciliation sheet | HR operations | |
| Opening balances (leave, payroll YTD) verified | NOT VERIFIED | Reconciliation | Payroll | |

## P. Statutory

| Item | STATUS | EVIDENCE | OWNER | DATE |
|---|---|---|---|---|
| Statutory production gate | **FAIL (BLOCKED)**: 24 rules / 0 verified / 5 open notices | `peopleos:readiness` | Compliance | 2026-10-03 |
| Each rule verified with authoritative evidence by two different platform administrators | NOT VERIFIED | Verification records | Compliance | |
| Open regulatory notices resolved with evidence | NOT VERIFIED | Notice records | Compliance | |
| Parallel payroll reconciled for at least one period | NOT VERIFIED | Parallel-run records | Payroll | |

## Q. Business configuration

| Item | STATUS | EVIDENCE | OWNER | DATE |
|---|---|---|---|---|
| Tenant organisation, policies, workflows, notification rules, security policy (MFA, IP allow-list, idle timeout) configured and reviewed | NOT VERIFIED | Configuration review | HR operations | |
| AI provider decision and tenant data policy recorded | NOT VERIFIED | Decision record | Product owner | |

## R. Incident response

| Item | STATUS | EVIDENCE | OWNER | DATE |
|---|---|---|---|---|
| On-call rota, escalation paths, contact list | NOT VERIFIED | Runbook | Operations | |
| Data-breach procedure (notification obligations) | NOT VERIFIED | Policy | Legal / DPO | |

## S. Rollback

| Item | STATUS | EVIDENCE | OWNER | DATE |
|---|---|---|---|---|
| Rollback procedure documented | PASS | DR doc §14 | Engineering | 2026-10-03 |
| Rollback rehearsed in staging | NOT VERIFIED | Rehearsal record | Operations | |

## T. Operator approval

**Reserved for the authorised approver. Engineering must not complete this section.**

| Item | STATUS | EVIDENCE | OWNER | DATE |
|---|---|---|---|---|
| All blocking items above are PASS or formally accepted with a documented risk decision | NOT VERIFIED | | Authorised approver | |
| Go-live approval | **PENDING** | | Authorised approver (name, role, signature) | |
