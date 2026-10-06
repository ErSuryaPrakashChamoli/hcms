# SaaS.4 — Commercial plans (shadow mode): the developer's map

Full report: `docs/saas/SaaS-4-Commercial-Plan-Report.md`. Model: `docs/saas/SaaS-4-Plan-Model.md`. The engine this extends: `saas-3-entitlements.md`.

## The shape

```
Plan (code, name)                      platform catalogue, no tenant
  └─ PlanVersion v1, v2 …              draft → published → retired; published = immutable; sale window
       └─ PlanEntitlement × capability  included / excluded / limit / unlimited; absent = not in the plan
Tenant ── TenantPlanAssignment ──► PlanVersion   effective-dated, one per day, history kept (BelongsToTenant)
```

The engine answers in this precedence order:
1. catalogue;
2. override;
3. the tenant's own configuration row;
4. **plan**;
5. configured without a row;
6. UNKNOWN.

## Rules for code

| Rule | Why |
|---|---|
| HCM code never touches a plan model. It calls `Entitlements::observe()` as before | Architecture test (`keeps commercial plans out of HCM code…`) |
| Plans change only through `PlanCatalog` (`create`, `update`, `draft`, `define`, `set`, `remove`, `publish`, `retire`). Assignments change only through `EntitlementConfiguration::assignPlan` / `endPlanAssignment` | Operator guard, reason, audit, locks. Mass updates would skip the immutability model events |
| Never edit a published version. Create a draft (`draft()` copies the latest version) and publish it | `PlanVersion` / `PlanEntitlement` model guards throw |
| Never add a plan value for a capability that is not commercial. Never switch a protected one off | `PlanCatalog::value()` refuses both |
| Read a plan through the tenant's state (`EntitlementState::assignmentOn`, `plan`, `planEntitlement`). Use `EntitlementDiagnostics::explain` for "why" | One cached read; published versions never change, so no catalogue invalidation |

## Locks

| Change | Serialised on |
|---|---|
| Plan catalogue (draft, define / set / remove, publish, retire, update) | The `plans` row (`FOR UPDATE`); one-draft unique index as a backstop. `set` and `remove` read the draft's content under that lock (read-modify-write), so concurrent edits of one draft never lose each other (MySQL race 7) |
| Assignment | The tenant's `tenant_entitlement_profiles` row (as in SaaS.3), plus a shared lock on the plan version, so a concurrent retirement is either seen or waits |

## Cost

| Tenant | Cold cache | Warm |
|---|---|---|
| Never had a plan | As SaaS.3: 3 reads (configured), 1 (unconfigured) | 0 |
| Has or had a plan | 5 reads: the 3 above + assignments + pinned versions with their entitlements, joined | 0 |

## Tests

| Kind | Where |
|---|---|
| Feature | `tests/Feature/Entitlements/Plan*Test.php`: catalogue, assignment and resolution, security and isolation, payroll safety, performance |
| MySQL races | `tests/MySql/PlanConcurrencyTest.php` |
| Helper | `tests/Feature/Entitlements/PlanTestHelpers.php` → `publishedPlan($operator, $code, $values, $from)` (fictional plans only) |

Test gotchas:
- Lazy loading is disabled outside production. A service that receives a `PlanVersion` loaded in a collection must load its plan by id, not through `$version->plan`.
- SQLite stores `date` casts as `Y-m-d H:i:s`. Filter dates with `whereDate()` or `DATE(...)`, never by string equality.
- Browser checks: Filament shows the operator's MFA challenge inline on the login page (an `autocomplete="one-time-code"` field), not at a separate URL. Filament inputs carry native `required` / `min` attributes, so the browser refuses before the server answers; test both layers.
