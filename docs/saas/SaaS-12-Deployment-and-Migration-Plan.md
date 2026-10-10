# SaaS.12 — Deployment and migration plan

> **Superseded on 11 October 2026 by `SaaS-Final-Deployment-and-Rollback-Runbook.md`** (kept as history; its tested facts are carried over).

Date: 10 October 2026. **For:** whoever performs the staging and production deployments.

**Status: prepared, not executed.**
- No environment exists.
- No deployment is authorised: staging needs O-8, production needs O-9.
- Measured figures come from this workstation (MySQL 8.4, shared and busy). They are not production measurements.

## 1. Supported upgrade path

No version of PeopleOS has ever been deployed, so there is **no upgrade source to support**. The first production
deployment is a **fresh install**. That path was tested here:
- 126 of 126 migrations from an empty schema;
- a second run is a no-op;
- rollback of recent steps, and the full set of `down()` migrations, followed by an identical re-apply (SaaS.11
  migration-integrity run, plus this phase's drill: 126 migrations in **74 s**).

Incremental upgrades were exercised at every phase on the development and showcase databases. SaaS.12 added one
migration (below).

## 2. Migration inventory

| Item | Value |
|---|---|
| Migrations | 126 files (`database/migrations`), all additive. Latest: `2026_11_02_100001_saas12_role_template_keys` |
| SaaS.12 change | `roles.template_keys` JSON, nullable, after `is_system`. MySQL 8.4 adds it with `ALGORITHM=INSTANT` (verified here with the algorithm forced: metadata lock only); no backfill |
| Destructive operations in migrations | None that drop customer data on `up()`. `down()` methods drop what their `up()` created |
| Seeders | None runs in production: `DatabaseSeeder` and all three showcase seeders refuse production (tested) |
| Post-migration commands | `peopleos:sync-permissions`: additive, preserves tenants' removals (SaaS.12), audits introductions |

## 3. Production deployment sequence (first install)

Preconditions:
- every mandatory gate is satisfied (`SaaS-12-Final-Release-Gate-Matrix.md`);
- the release candidate is frozen;
- O-9 is recorded;
- the backup system is ready (an empty database still gets its backup schedule before go-live).

| # | Step | Command or action | Expected | Stop if |
|---|---|---|---|---|
| 1 | Confirm the release | The commit equals the frozen release candidate; `composer.lock` and `package-lock.json` hashes match the release-candidate manifest | Match | Any difference |
| 2 | Configuration | Secrets from the store; `php artisan peopleos:config:validate --as-production` | Exit 0 | Any error |
| 3 | Build | `composer install --no-dev --optimize-autoloader`; `npm ci && npm run build` (or the prebuilt artefact) | Success | Failure |
| 4 | Database | `php artisan migrate --force` from **one** server (first install: no `--isolated` with the database cache store) | 126 migrations, about 1–2 minutes | Any error (see §5) |
| 5 | Permissions | `php artisan peopleos:sync-permissions` | Catalogue synced | Error |
| 6 | Caches | `config:cache`, `route:cache`, `view:cache`, `event:cache` | Success | Error |
| 7 | Workers and scheduler | Start the supervised workers; enable `schedule:run` on one server | Heartbeat under 3 minutes | No heartbeat |
| 8 | Readiness | `php artisan peopleos:readiness` | No FAIL; statutory BLOCKED (expected until G5) | Any FAIL |
| 9 | Operators | Create platform operators; each enrols MFA at once | Readiness MFA check PASS | Unenrolled operator |
| 10 | Smoke tests | §6 | All pass | Any critical failure: stop and roll back (§4) |
| 11 | Monitoring | Health endpoints wired; a test alert reaches on-call | Alert received | No alert |
| 12 | Release switches | Registration, deletion and live payments stay **off** unless separately approved | Validator clean | — |

**Never in production:**
- `db:seed`;
- `migrate:fresh`, `migrate:reset` or `db:wipe`;
- turning a release switch on without its recorded authorisation;
- copying the development `.env`.

## 4. Upgrade sequence (later releases)

As in `docs/operations/production-configuration.md` §9:
1. `php artisan down` (or blue/green).
2. **A fresh backup**, verified. Do not proceed without it.
3. Install and build.
4. `php artisan migrate --force --isolated` (concurrent runs serialised, tested).
5. `peopleos:sync-permissions`.
6. Caches.
7. `queue:restart`.
8. Readiness.
9. `php artisan up`.

A release that adds a permission to a system-role template says so in its notes. The audit trail shows the grant per
tenant.

## 5. Rollback and interrupted migrations

| Situation | Action |
|---|---|
| Code defect, no migration in the release | Redeploy the previous artefact; `queue:restart` |
| A migration completed but the release must be undone | Restore the step 2 backup (or run the specific reviewed `down()` on staging first), then redeploy the previous artefact |
| **A migration was interrupted** | Do **not** re-run blindly. Tested: MySQL DDL is not transactional; a killed run left a half-applied migration, and the re-run failed with "table already exists". Restore the step 2 backup, then run the deployment again. A manual repair needs a reviewed plan |
| Two servers run migrations at once | Prevented by `--isolated` on upgrades (tested: one runs, the other exits "already running"). On the first install, run from one server |

## 6. Smoke tests (post-deploy, non-destructive)

1. `/health/live` 200; `/health/ready` 200 with the token, showing database, cache, queue, storage and scheduler ok.
2. An operator signs in **with MFA**, opens Platform → Readiness and finds no FAIL.
3. A test tenant (fictional) is provisioned by the operator. Its administrator signs in and creates one employee. A
   second test tenant cannot see it (isolation).
4. Payroll on the test tenant shows statutory rules **blocked** (the verified-rules gate).
5. An audit event is recorded, and `peopleos:audit:verify` passes.
6. A notification e-mail reaches a monitored mailbox.
7. A document uploads and downloads through the authorised route; a direct storage URL is refused.
8. Registration pages show the "not available" notice; deletion and payments are refused.
9. `peopleos:deletions:reconcile` reports nothing to reconcile.
10. Afterwards, the test tenants are offboarded or kept as agreed with the owner.

## 7. Monitoring after release

The window and its owners are **owner decisions** (O-10). None is invented here. Section 21 of the programme lists the
checks to run during it.
