# PeopleOS Phase 0.3 Report — Architecture Contract & Final Foundation Decisions

Performed 27 September 2026 on branch `main` from HEAD 52e6aa9 (Phase 0.2). This phase froze the architecture; it added no HR module, no RMS integration and no schema redesign. Two additive code changes were made because the contract required them to be enforceable (relationship scope; queue/scheduler invariants) and three small contract-driven fixes (tenancy error rendering, scheduler guards, data-classification map).

## 1. Baseline

| Item | Start | End |
|---|---|---|
| HEAD | 52e6aa9 | the commit that adds this report (see `git log --oneline -2`) |
| Working tree | clean | clean |
| Tests | 315 passed, 2,668 assertions | 319 passed, 0 failed, 2,706 assertions, 411 s |
| Pint | passed | passed |
| Migrations | 70 ran | 70 ran (no new migration) |
| Pushes | 0 | 0 |
| Ahead of origin/main | 4 commits | 6 commits |

## 2. Deliverables

| Deliverable | Path |
|---|---|
| Architecture contract (incl. discovery matrix, canonical domain map, answers to the 28 review questions) | `docs/architecture/peopleos-architecture-contract.md` |
| Decision register ADR-0002 … ADR-0015 (ADR-0001 unchanged in its own file) | `docs/architecture/decision-register.md` |
| Integration contract (external references, inbound events, mappings, recruitment boundary, security, API and webhook conventions) | `docs/architecture/integration-contract.md` |
| Security and architecture invariants (extended) | `docs/architecture/security-invariants.md` |
| This report | `docs/PeopleOS-Phase-0.3-Report.md` |

## 3. Decisions frozen (summary)

- **Identity (ADR-0002):** Person 1 : 1 Employee per tenant, enforced by the existing unique constraint; employment = effective-dated positions, reporting, salary and lifecycle transitions; employment spells derived; rehire = `alumni → active` on the same Employee; `employments` table deferred and additive.
- **Organisation & reporting (ADR-0003):** units classified as management / legal / physical / reporting / financial / job architecture; typed, effective-dated reporting relationships with one primary line at a time; no single `manager_id`.
- **Legal Entity / Establishment (ADR-0001):** preserved as deferred; current `Company = legal entity = establishment`; target `Tenant › Company › Legal Entity › Establishment › Location`.
- **Authorisation (ADR-0004):** tenant → role → permission → organisation scope → relationship scope → field security → record. Manager visibility resolved: managers reach direct reports of any reporting type regardless of organisation scope (implemented). Visibility matrix for employee, manager, HR, HRBP, business head, tenant administrator, executive, platform admin recorded.
- **Field-level security (ADR-0005):** data-classification map in config (highly sensitive, financial, statutory, confidential); view/edit via `*.sensitive.*`, export via dataset sensitive permission + audit, search never on sensitive columns; matrix UI deferred.
- **API keys (ADR-0014):** per-key organisation scope data contract (`api_keys.access_scope` JSON, same semantics as user scopes) defined; enforcement deferred until the Integration Hub.
- **Effective dating (ADR-0006), configuration and versioning (ADR-0009), workflow versioning (ADR-0010):** vocabulary and status mapping fixed; running workflows stay on their version (confirmed in code: instances pinned to `workflow_version_id`).
- **Lifecycle & events (ADR-0007):** 12 canonical states; reserved event names for position/salary changes to be emitted in Phase 1.
- **Audit vs domain events (ADR-0008):** distinct; audit append-only and hash-chained; change history = audit + effective-dated rows.
- **External references, integration events, mappings (ADR-0011/0012):** table contracts and state machine fixed; implementation deferred.
- **Queue (ADR-0013):** `TenantAwareJob` interface + `BindTenantContext`, enforced mechanically; scheduler guarded.
- **AI (ADR-0015):** boundary documented; no new AI functionality.
- **RMS:** PeopleOS operates without RMS; boundary enforced by an architecture test; future integration only through the hub with external references and mappings.

## 4. Implementation changes (all contract-driven)

| Change | Why |
|---|---|
| `AccessScopes::employeeKeys()` adds reports-to relationship scope | ADR-0004 manager visibility decision |
| `TenantAwareJob` interface; `BindTenantContext` reads `tenantId()`; `SendWebhook` and the test double implement it | ADR-0013 mechanically enforceable invariant |
| `routes/console.php`: every entry `withoutOverlapping()->onOneServer()` | Scheduler contract §34 |
| `bootstrap/app.php`: `MissingTenantException` / `TenantMismatchException` render as plain 403 | Error contract §38 |
| `config/peopleos.php`: `data_classification` map | Data classification contract §17 |
| Architecture tests: tenant-aware queued jobs, RMS boundary, data-classification masking; access-scope test for manager visibility | §52, §53 |

No migration. No Filament or API change.

## 5. Verification

- Full suite: 319 passed, 0 failed, 2,706 assertions, 411 s. Pint: passed. Architecture suite: 9 tests passed. Security suites (Tenancy, Identity/AccessScope, Experience/TicketAttachment, Audit) pass.
- No RMS dependency; no push; no history rewrite.

## 6. Deferred items

1. Legal Entity / Establishment implementation (ADR-0001).
2. `employments` table and rehire UI (ADR-0002) if a tenant needs per-spell contracts.
3. External references, inbound events, mappings, inbound signed webhooks, `Idempotency-Key` header, OpenAPI (Integration Hub phase).
4. Per-API-key organisation scope enforcement.
5. Field-permission matrix UI.
6. Reserved lifecycle events for position/salary changes to be emitted by the actions (Phase 1).
7. Generic workflow node retry; amount-based and dynamic approvers.
8. Redis/Horizon, queued heavy operations, observability.
9. Retention periods per jurisdiction (compliance phase).

## 7. Known limitations

- Scoped queries carry nested sub-selects (accepted characteristic, contract §16).
- Filament table search on `people.*` relation columns cannot be exercised on SQLite in tests.
- Field-level security is expressed through permission keys and the classification map, not per-field configuration.

## 8. Git

Commits: a5c5a48 (code) and the docs commit that adds this report. Working tree clean. Not pushed.
