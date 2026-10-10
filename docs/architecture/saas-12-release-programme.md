# SaaS.12 — Release programme: what was built and the conventions to follow

SaaS.12 is a release programme, not a feature phase. It changed code only where a finding required it. Reports and
registers: `docs/saas/SaaS-12-*.md`.

## 1. Deletion register and restore reconciliation (closes S11-5 / F11)

**Problem.** A deletion's certificate lives in `tenant_deletions`. A database restored to a point before a deletion
loses the deletion and its proof, so a legitimately deleted tenant could come back and serve traffic unnoticed.

**Design.**
- `DeletionRegister` (Offboarding/Services) writes each completed, certified deletion to
  `deletion-register/<reference>.json` on `peopleos.offboarding.register_disk` (`PEOPLEOS_DELETION_REGISTER_DISK`).
  - The entry carries the signed certificate, its SHA-256 and the tenant's id and slug.
  - It is written once: a different entry under the same reference is refused.
  - `DeletionExecutor::finish` writes it after certification; a failure is logged and the offboarding tick retries it
    (`syncMissing`).
- An entry is evidence only if its certificate hashes to the recorded SHA-256 and its HMAC signature verifies with the
  current or a previous application key (`Signatures::verifyWithAnyKey`).
- `DeletionReconciliation::check()` classifies every entry against the database:

  | Outcome | Meaning |
  |---|---|
  | `consistent` | The database holds the same completed deletion (same certificate SHA-256) |
  | `reapplied` | A later completed deletion of the tenant lists it in `plan.reapplies` |
  | `not_present` | The tenant id is not in this database |
  | `resurrected` | The tenant is present with the same slug, but the deletion is not |
  | `identity_mismatch` | A tenant with that id but another slug |
  | `invalid` | The hash or signature fails |

  It also reports completed deletions without an entry (`unregistered`).
- `contain()` acts only on `resurrected` entries:
  - it suspends an operating tenant through `TenantSuspensions` (sessions end, sign-in, API keys and scheduled work
    stop);
  - it records `DELETION_REAPPLICATION_REQUIRED` once per deletion, on both chains.
- **It never deletes.**
- Doubtful entries (`identity_mismatch`, `invalid`) are never acted on.
- `TenantDeletions::request` stores `plan.reapplies` (the resurrected entries of that tenant, with their original
  approval). The plan fingerprint is unchanged: it covers what is deleted, not the history.
- The certificate copies `reapplies`. Re-application is therefore the ordinary workflow (holds, retention, financial
  gates, maker-checker, cooling-off), linked to the original approval.

**Entry points.**
- `php artisan peopleos:deletions:reconcile [--contain] [--json]` (exit 1 while anything needs a person).
- The offboarding tick (every 15 minutes): writes missing entries, contains.
- Readiness: "Deletion register reconciled". FAIL in production while a resurrected tenant is operating, or while an
  entry is in doubt; WARN while one is contained or unregistered.
- Validator: `PEOPLEOS_DELETION_REGISTER_DISK` is required when deletion is enabled, and must name a configured disk.

**Conventions.**
- The register disk is private and versioned (object lock where available), and it is backed up separately from the
  database. A restore never restores it.
- A rehearsal or staging copy gets its own register.
- Never edit an entry. A correction is a new deletion that re-applies.

## 2. System-role top-up on deploy

**Problem.** `peopleos:sync-permissions` (deploy step) topped up system roles with `syncWithoutDetaching`. Tenants can
edit system-role permissions, so a deliberate removal came back, unaudited, at every deploy.

**Design.**
- `roles.template_keys` (JSON, nullable) records the template keys a system role has been given.
- `ProvisionTenantAction::seedSystemRoles()` gives only keys not yet recorded (what a release introduced), audits them
  (`ROLE_CHANGED`, no actor) and records the template.
- A role without a value takes its current template as its baseline and is given nothing. That is exact for SaaS.12,
  which adds no permission key.
- The bookkeeping attribute is excluded from auditing.

**Convention.** A release that adds a permission key to a system-role template should say so in its release notes. The
audit trail shows each grant per tenant.

## 3. Production gate additions

| Rule | Where |
|---|---|
| `PEOPLEOS_DELETION_REGISTER_DISK` required with deletion; must be a configured disk | `ProductionConfigValidator::releaseGates` |
| `.env` not readable or writable by other accounts (`ENV_FILE_PERMISSIONS`); nothing checked without a file | `ProductionConfigValidator::operations` |
| `storage/` and `bootstrap/cache` writable (`STORAGE_PERMISSIONS`) | `ProductionConfigValidator::operations` |
| Showcase seeders refuse production whatever the database is called | `UxShowcaseSeeder`, `UxShowcaseNotificationsSeeder` |

## 4. Deployment facts established by test (no code change)

| Fact | Consequence |
|---|---|
| An interrupted `migrate` leaves a half-applied migration (MySQL DDL is not transactional); a re-run fails with "table already exists" | Recovery is the pre-deploy backup. The deploy sequence keeps "backup before migrate" mandatory |
| `migrate --isolated` serialises concurrent runs (one runs, the other exits "already running") but fails on a first install with the database cache store (no `cache_locks` yet) | Use `--isolated` for upgrades; run the first install from one server without it |
| Production error responses carry no exception detail (panel and API) | Regression test in `SecurityHardeningTest` |
