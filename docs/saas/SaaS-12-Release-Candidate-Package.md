# SaaS.12 — Release candidate package (DRAFT)

**For:** the owner (approval), operations (deployment), and the independent reviewer (scope).

**Status: DRAFT — NOT FROZEN, NOT APPROVED, NOT DEPLOYED.**
- A release candidate is frozen only after staging validation and the owner's approval.
- Neither staging nor approval exists.
- Any code change after freezing invalidates the affected validation and requires the tests to be re-run.

## 1. Identity

| Item | Value |
|---|---|
| Repository | `github.com/ErSuryaPrakashChamoli/hcms` (public; see O-2) |
| Branch | `feature/oct_1_phase_1` (not merged; PR #1 to `main` is open on an older head) |
| Candidate code commit | `1907743`. The documents commit that follows changes no code |
| `composer.lock` SHA-256 | `7677279e9fe646b9…` (436 packages) |
| `package-lock.json` SHA-256 | `88a5ca54bd519642…` (after the SaaS.12 audit fix) |
| Runtime | PHP 8.5, Laravel 13.33, Filament 5, MySQL 8.4, Node 22 (build only) |
| Build artefact | Not produced. No artefact store or pipeline exists (O-7) |

## 2. Migrations

- 126 files, all additive.
- New in SaaS.12: `2026_11_02_100001_saas12_role_template_keys` (instant column add).
- Plan: `SaaS-12-Deployment-and-Migration-Plan.md`.

## 3. Configuration requirements

Variable **names** only, never values. Values come from the secret store.

| Group | Variables |
|---|---|
| Application | `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL` (https), `APP_KEY`, `APP_PREVIOUS_KEYS` (rotation only), `LOG_CHANNEL`, `LOG_LEVEL` |
| Database | `DB_CONNECTION=mysql`, `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME` (not root), `DB_PASSWORD` |
| Cache, sessions, queue | `CACHE_STORE`, `SESSION_DRIVER`, `SESSION_SECURE_COOKIE=true`, `SESSION_ENCRYPT`, `QUEUE_CONNECTION`, `DB_QUEUE_RETRY_AFTER` / `REDIS_QUEUE_RETRY_AFTER`, `REDIS_*` if used |
| Mail | `MAIL_MAILER` (real), `MAIL_HOST`, `MAIL_FROM_ADDRESS` |
| Storage | `PEOPLEOS_DOCUMENTS_DISK`, `AWS_*` or the provider's equivalents, `PEOPLEOS_DELETION_REGISTER_DISK` (before deletion is ever enabled) |
| Security and HTTP | `PEOPLEOS_TRUSTED_PROXIES`, `PEOPLEOS_HSTS_MAX_AGE` (at least 31536000), `PEOPLEOS_ROBOTS_TAG` (default), `PEOPLEOS_HEALTH_TOKEN`, `PEOPLEOS_PLATFORM_MFA_REQUIRED=true`, `PEOPLEOS_OUTBOUND_*` |
| Release switches (off) | `PEOPLEOS_PUBLIC_SIGNUP_ENABLED`, `PEOPLEOS_TENANT_DELETION_ENABLED`, `PEOPLEOS_RAZORPAY_ENABLED`, with their authorisation references only when approved |
| Must be unset | `PEOPLEOS_SEED_PASSWORD` |

Gate: `peopleos:config:validate --as-production` exits 0 in the target.

## 4. Release notes (since the last pushed state, `36a9292`)

- **SaaS.10:**
  - self-service signup and idempotent provisioning (switched **off**);
  - the Account Owner role and the delegation ceiling (E1–E3).
- **SaaS.11:**
  - role-permission changes audited;
  - noindex;
  - release-switch gates;
  - operator-MFA readiness gate.
- **SaaS.12:**
  - **deletion register and restore reconciliation** (a restore never silently brings back a deleted tenant);
  - deploys no longer re-grant permissions tenants removed from system roles;
  - showcase seeders refuse production;
  - production gate checks `.env` and storage permissions, and requires the register disk with deletion;
  - production error responses tested for leaks;
  - npm build-tool advisories fixed;
  - CI style failure fixed.

## 5. Known limitations

- Statutory: 0 of 24 rules verified; payroll for any covered statute is blocked by design. Gratuity is not computed.
- Signup, deletion and live payments are off (separate gates).
- Entitlements are in shadow mode (D-16).
- No error-reporting or alerting integration (O-11).
- The SaaS.10 residual risks L1, L3–L9 (`SaaS-11-Security-Findings.md` §6).
- An interrupted migration needs a restore (documented).

## 6. Rollback, health checks, smoke tests, monitoring

See `SaaS-12-Deployment-and-Migration-Plan.md` §3–§7:
- health: `/health/live`, `/health/ready` with `X-Health-Token`;
- smoke: §6, ten non-destructive checks;
- monitoring: failed jobs, dead letters, audit verification, heartbeat, error rates. The window and on-call are
  decisions O-10 and O-11.

## 7. Validation evidence for this candidate

Results are in `SaaS-12-End-to-End-Production-Release-Report.md` §17–§20, with SQLite and MySQL reported separately.
That evidence is from this workstation. It is **not** staging or production evidence.

## 8. Freeze and approval (to be completed)

| Field | Value |
|---|---|
| Staging deployment authorisation (O-8) | |
| Staging validation completed (date, evidence) | |
| Independent security review signed (G3) | |
| Frozen commit | |
| Owner approval of the release candidate | |
| Production deployment authorisation (O-9) | |

## 9. Handover index (prepared now, completed after deployment)

| Topic | Document |
|---|---|
| Architecture | `docs/architecture/*` (one file per phase), `docs/peopleos-blueprint.md` |
| Environment inventory | **To be written** when the environment exists |
| Secret-management references | **To be written** (names only, in the secret store) |
| Deployment and rollback | `SaaS-12-Deployment-and-Migration-Plan.md`, `docs/operations/production-configuration.md` |
| Backup, restore and DR | `docs/operations/backup-and-disaster-recovery.md`, `docs/operations/runbooks/recovery.md` |
| Monitoring and incident response | `docs/production/*` (pending O-10, O-11) |
| Tenant lifecycle | `docs/operations/tenant-operations-runbook.md`, `docs/operations/runbooks/*` |
| Operator access and MFA | `docs/operations/production-configuration.md` §6 |
| Statutory status | `SaaS-12-Statutory-Verification-Register.md` |
| Retention and deletion controls | `SaaS-9-Retention-Decision-Register.md`, runbooks |
| Signup and Account Owner | `docs/operations/signup-configuration.md`, runbooks |
| Billing | `docs/operations/commercial-configuration-runbook.md` |
| Outstanding decisions | `SaaS-12-Owner-Decision-Register.md` |
| Post-release monitoring results | **After deployment** |
