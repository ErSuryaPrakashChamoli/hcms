# Compliance work: migration and permission-sync runbook

Prepared 2026-10-28.

**Nothing here has been run** on any shared or production environment. Each step needs the owner's approval for that
environment. A migration that passed on a disposable test database does not authorise running it anywhere else.

## 1. What has to run

### 1.1 Migrations from the compliance work (all schema-only: no data is read or written)

| # | Migration | Purpose | Depends on | `up()` | `down()` (rollback) |
|---|---|---|---|---|---|
| M1 | `2026_11_09_100001_create_compliance_report_packs` | Table for compliance report packs (requests, status, ZIP path, fingerprint) | `tenants`, `companies`, `users` | Additive: new table | Drops the table and every pack record. **Destructive** for packs created since |
| M2 | `2026_11_09_100002_compliance_applicability_and_pack_traceability` | `establishments.appropriate_government`, `industry`; pack `scope_fingerprint`, `metadata`, review columns | M1 | Additive: nullable columns | Drops those columns. **Destructive** for values entered since |
| M3 | `2026_11_09_100003_establishment_social_security_appropriate_government` | `establishments.ss_appropriate_government` | M2 (column order only) | Additive: nullable column | Drops it. **Destructive** for values entered |
| M4 | `2026_11_09_100004_export_layout_checksum_algorithm` | `statutory_export_layouts.checksum_algorithm` (D7). Existing rows stay NULL, meaning the original algorithm | — | Additive: nullable column; **no existing value changes** | Drops it. Layouts created after M4 then read as original-algorithm rows and fail closed |
| M5 | `2026_11_11_100001_create_statutory_profile_corrections` | Table for C12-W correction requests | `tenants`, `companies`, `establishments`, `users` | Additive: new table | Drops the table and every correction record. **Destructive** for the audit context of corrections (the audit events stay) |

Other uncommitted migrations in the working tree belong to other work and need their own owners' approval:
- `2026_11_08_100001_bulk_uploads`;
- `2026_11_08_100002_widen_leave_sessions`;
- `2026_11_10_100001_add_item_key_to_tds_employee_investments`.

They are listed so that a `php artisan migrate` run is not mistaken for running only the compliance set: `migrate` runs
**every** pending migration.

### 1.2 Permission sync

`php artisan peopleos:sync-permissions` (`app/Console/Commands/SyncPermissions.php`):
1. **Creates or updates** every permission in `config/peopleos.php`. The compliance work adds four:
   - `compliance.reports.view`;
   - `compliance.reports.generate`;
   - `compliance.reports.review`;
   - `compliance.profile_corrections.approve`.
2. **Deletes** every permission row whose key is no longer in the config ("pruned"). This is **not additive**: any
   role grant on a pruned key goes with it.
3. **Tops up system roles in every tenant** (`seedSystemRoles`). For example, HR Admin's `compliance.*` pattern picks
   up the new keys. `--no-roles` skips this step.

## 2. Pre-checks for each environment

1. **Approval** for this environment, in writing, naming the steps.
2. **Code:** the commit that contains M1–M5 and the config is deployed. Never run from a working tree.
3. **Pending list:** `php artisan migrate:status`. Record exactly which migrations are pending and confirm the list
   matches the approval. On the development database (2026-10-28), M4 and M5 are pending; M1–M3 are applied.
4. **Pruning preview** for the permission sync. List the permission keys in the database that are not in the config,
   and confirm none is still needed. For example, compare `SELECT key FROM permissions` with the config keys.
5. **Layouts:** run `php artisan peopleos:compliance:layout-checksums` before M4 and keep the output. It is
   read-only.
6. **Backups:**
   - a full database backup (or snapshot) taken and verified restorable before any step;
   - at minimum, `permissions`, `permission_role`, `roles`, `role_user`, `establishments` and
     `statutory_export_layouts`.
7. **Integrations (2026-10-28 change, no migration):** an API key that runs shared reports now needs the data scopes
   listed for the report's dataset in `peopleos.analytics.unattended_datasets`, besides `reports.run`. Exits reports no
   longer run through the API. List the keys holding `reports.run`, and agree any scope changes with their owners
   before the deploy.
8. **Window:** M2 and M3 alter `establishments`. On MySQL that rebuilds the table; on a large tenant base, run it
   outside payroll processing.

## 3. Order of execution

Development, then staging (or a production-like copy), then production, each only after the one before it passes
section 4.

1. Put the application in maintenance mode, or pause queue workers, so packs and payroll do not run during the
   migration.
2. `php artisan migrate --force` (`--force` is needed only outside local). This applies whatever is pending: M1–M5
   plus any approved migrations from other work.
3. `php artisan peopleos:sync-permissions`. Run it only after confirming the pruning preview.
4. `php artisan peopleos:compliance:layout-checksums`. Expect `intact` or `intact_against_pack` for every layout, and
   exit 0.
5. Leave maintenance mode, and resume workers.

## 4. Verification

| Step | Check |
|---|---|
| Migrations | `php artisan migrate:status` shows M1–M5 as Ran. `SHOW COLUMNS FROM statutory_export_layouts LIKE 'checksum_algorithm'`; `SHOW TABLES LIKE 'statutory_profile_corrections'`; `SHOW INDEX FROM statutory_profile_corrections WHERE Key_name = 'stat_profile_corrections_lookup_idx'` |
| Existing layouts | The checksum command's output equals the pre-check output apart from the new column: every row `intact` or `intact_against_pack`; none `mismatch` |
| Permissions | The four keys exist (`SELECT key FROM permissions WHERE key IN (...)`). The pruned count matches the preview. HR Admin holds them in a sample tenant |
| Application | A compliance pack builds for one test company and month. A correction request can be created and reviewed in a non-production tenant |
| Tests (before deploying) | The final-run suites on the deployed commit, as in `compliance-report-test-matrix.md` §12 |

## 5. Rollback and recovery

- **Preferred: restore the backup** taken in pre-check 6. All five `down()` methods drop what their `up()` added, so a
  rollback after use loses data (packs, corrections, establishment answers).
- **Rollback by migration** (`php artisan migrate:rollback --step=N`) only right after a failed or unwanted
  migration, before anyone has used the new columns or tables.
- **Permission sync:** pruned permissions and grants cannot be re-created by re-running it. Restore the permission
  tables from the backup.
- **M4:** rolling back makes layouts sealed canonically after M4 unverifiable (they fail closed). It never alters a
  stored checksum.
