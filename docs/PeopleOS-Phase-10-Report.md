# PHASE 10 — WORKFORCE PLANNING, POSITION MANAGEMENT & HEADCOUNT PLANNING FOUNDATION

## Status

```text
Phase 10: COMPLETE
Statutory production readiness: NOT DECLARED
```

Stopped for owner review. Phase 11 has not been started.

## Git

| | |
|---|---|
| Starting HEAD | `de0d4b1` on `feature/oct_1_phase_1` (Phase 9 end; baseline verified, clean tree, no discrepancy) |
| Ending HEAD | the phase-10.5 documentation commit (the commit that adds this report) |
| Branch | `feature/oct_1_phase_1` |
| Commits | 5 on top of `de0d4b1`:<br>• `7e4bd15` 10.1 discovery, schema, position foundation<br>• `e3b0d7b` 10.2 headcount, snapshots, plans, scenarios, budgets, forecast<br>• `55fa7fb` 10.3 screens, Employee 360, API<br>• `34b4bdb` 10.4 security, integration, architecture, scale, MySQL tests (with the security fixes they found)<br>• 10.5 documentation<br>The grouping is coarser than the suggested 12 (the prompt allows this). |
| Pushes | 0 |
| Working tree | clean after the final commit |

## Discovery

See `docs/PeopleOS-Phase-10-Discovery.md`.

**What "position" meant before this phase:**
- `employee_positions` is the employee's effective-dated **assignment**, written only by
  `AssignPositionAction`.
- `Designation` is the role.
- Phase 9 critical positions are role plus unit.
- **No seat, capacity, headcount, vacancy or FTE entity existed**; `WorkforceMetrics` counts
  employees.

**Exits do not close assignment rows, and rehire clears `exit_date`.** Historical occupancy therefore
also reads the lifecycle history.

No second position model existed to reuse, so one canonical `Position` was introduced.

## Position Management

| | |
|---|---|
| Position model | `positions` holds the code, company, latest status and a mirror of the latest version used for listing and scoping.<br>`position_versions` holds the definition: role (designation), job family, career track, organisation unit with derived business unit / division / department / team, location, establishment, legal entity, employment and worker type, grade, cost centre, parent position, occupancy mode, seats, FTE per seat, FTE capacity, standard hours and status.<br>Everything is referenced, not duplicated. **A position holds no employee.** |
| Lifecycle | Configured map: draft → proposed → approved → planned / open; open ⇄ frozen / on hold; → abolished / closed.<br>Approval is by a second person (`workforce.approve`; never the proposer).<br>Reasons are required for freeze, hold, abolish, close and unfreeze.<br>**"Occupied" is derived from assignments, never typed.** |
| Hierarchy | Parent per version. Refused: self (also a MySQL CHECK), cycles (on the date and in the latest definitions), another company, an abolished or closed parent, another tenant.<br>Independent of reporting relationships. |
| Effective dating | A draft is edited in place. Every later change is a new immutable version from a date; the previous one closes the day before.<br>Same-day changes leave zero-length versions (history, never in force). Any date reconstructs the definition in force. |
| Occupancy | Assignment rows effective on the date, for employees not yet left (exit date plus lifecycle history, so rehire gaps are right).<br>FTE is the assignment's own, else the per-seat FTE. |
| Capacity | Single occupancy is one seat. Multiple occupancy is N seats with FTE capacity ≤ seats × FTE.<br>A new assignment needs a free seat **and** FTE from its start date onward.<br>Capacity can never be reduced below occupancy. |
| Vacancy | Unfilled capacity of open positions: a fact. **No requisition, candidate or recruitment object exists.** Webhooks may announce opened or vacated positions. |
| Employee assignment | Through the authoritative employment history.<br>• `AssignPositionAction` calls the `PositionAssignmentGuard` contract (implemented by Workforce) in its transaction: position row lock, scope check, status and capacity checks with locking reads, dimensions from the position.<br>• Vacating is explicit.<br>• Unrelated changes carry the seat forward.<br>• Abolishing or closing an occupied position is refused; nobody is moved. |

## Workforce Planning

| | |
|---|---|
| Headcount | Reported as three never-interchanged measures: positions, seats / FTE, and employees.<br>Seat categories: approved / planned / open / occupied / vacant / frozen / on hold. |
| FTE | Fractional (e.g. 0.25–1.00), validated as > 0; never assumed to be 1 per person. |
| Plans | Plan (scope, owner) → versions:<br>• draft → submitted → under review → approved → active → superseded / archived (rejected → archived);<br>• one active version per plan;<br>• approved content is checksummed and locked; corrections are new versions.<br>Lines carry a signed movement (baseline, new position, expansion, transfer in / out, reduction, closure, retirement, known exit), dimensions, headcount, FTE, planned cost with its cost basis, and an effective date.<br>Planning periods: monthly, quarterly, half-year, annual or custom. |
| Scenarios | Tenant-named, never hard-coded. Explicit labelled assumptions (attrition %, growth %, notes). A second person approves and locks them. They never touch live data. |
| Budget | Scope, period, currency, cost basis and amount; draft → approved (locked) → superseded.<br>Planned cost comes from lines on the same basis. Actual cost is finalized / paid employer cost through the new **Payroll read contract** `WorkforceCostReader`, compared only on the same basis and currency and suppressed below 5 employees.<br>No accounting, compensation or payroll write. |
| Forecast assumptions | Facts:<br>• capacity and occupancy at the period start;<br>• dated plan movements;<br>• exits already recorded.<br>The scenario's attrition % is shown separately and labelled "Planning assumption — not a prediction about any employee". No individual inference. |
| Approvals | Domain service with separation of duties:<br>• plans: the submitter ≠ reviewer / approver / rejecter;<br>• positions: proposer ≠ approver;<br>• changes: requester ≠ decider;<br>• scenarios and budgets: preparer ≠ approver.<br>An optional plan workflow (pinned version) has a bridge that refuses the submitter's own approval.<br>Configurable change approval (`peopleos.workforce.change_approval`: headcount, FTE, organisation and grade by default) creates change requests. |
| Historical snapshots | Reconstructed for any date from effective-dated records, in database aggregates with a constant query count; no snapshot rows. "What existed on 31 Mar 2026, who occupied it, what capacity and FTE, which organisation" are all answered. |
| Live-data isolation | Plans and scenarios never write positions or employment. The one explicit bridge, "propose position from line", creates a **proposed** position that still needs approval. |

## Integrations

| | |
|---|---|
| Career | Career paths through the position's role, next designations and open / planned positions for them (read only; nobody is moved) |
| Succession | The Phase 9 critical position for the role (unit first, then role-wide): plan status, active successors, current ready-now labels. Shown only with `succession.view`. |
| Skills / Learning | Requirements through Phase 9 `CareerArchitecture::requirementsFor` (skills on pinned Phase 8 scale versions, certifications, learning, competencies, experience) |
| Employee 360 | The employment tab shows each assignment's position (code and the title in force then) and FTE. "Transfer / promote" can occupy a seat (validated) or vacate it. No scenario, budget or approval discussion is exposed. |
| Untouched | A fingerprint test shows workforce actions leave career, talent, succession, learning, performance and skills tables unchanged |

## Security

| Layer | Result |
|---|---|
| Tenant | `BelongsToTenant` on every model; `tenant_id` on all 9 tables; foreign administrators see nothing; foreign codes return 404 in the API (tests) |
| Organisation | `ScopedByOrganisationDimensions` checks every scoped dimension and is fail-closed (a record without that dimension is hidden). It applies to positions, versions, plans and budgets at query and policy level.<br>**Found and fixed during testing:** the services now also re-check scope for every write and for seat assignment. Before the fix only the screens' policies did. |
| Relationship | Managers (`workforce.team`) see their position subtree and the positions of employees they manage (configured relationship types). **Mentor, buddy and project lead grant nothing** (test). |
| Employees | No workforce planning by default, not even their own seat's record (test) |
| Field security | Costs need `workforce.costs`. Budget amounts and planned costs are masked in the audit trail (test). Budgets are classified financial; plans, lines and scenarios confidential. |
| API | Separate scopes `positions.read` / `workforce.read` / `workforce.costs`. Codes only (no internal ids, names or pay). Read only. |
| IDOR | Foreign position codes return 404 (show and occupancy) |
| Audit | Every model is `Auditable` except the reminder log. Lifecycle moves are recorded with actor, roles, reason, effective date, before / after and event; plan decisions likewise. Bulk paths carry operation ids. |

## Concurrency

| | |
|---|---|
| MySQL concurrency tests | 6 Phase 10 races on real MySQL (forked processes):<br>• last seat;<br>• last FTE capacity;<br>• freeze vs assignment and closure vs assignment;<br>• concurrent plan approval and publication;<br>• scenario approval;<br>• audit chain afterwards.<br>Run together with the Phase 8 and 9 races: 16 PASS. |
| Race conditions discovered | **REPEATABLE READ:** a plain read of occupancy after taking the position lock would answer from an older snapshot, so all capacity reads are locking reads.<br>**Mutation check:** without the seat locks the last-seat race fails (MySQL aborts one writer with a deadlock instead of giving a clean single winner). |
| Locks used | Position row `lockForUpdate` for assignment, transitions, changes, abolish and close; locking reads of versions and assignment rows; plan-version and plan row locks with `lock_version` and a unique `active_key`; scenario and budget row locks |

## Statutory

Current status only; nothing statutory was changed in Phase 10:

- 24 rule versions;
- 0 verified;
- 5 open notices.

**EPFO blockers carried forward:**
- the EDLI ceiling conflict;
- the EPS rate wording (8.33% vs 8⅓%);
- the administrative charges;
- the September 2026 split month / ECR;
- second-person verification.

**Income tax blockers carried forward:**
- new-regime salary TDS rates;
- 2025-Act deduction references;
- the 25% surcharge cap;
- second-person verification.

No payroll engine change, no rule edit, no gate change. Workforce never writes statutory or payroll
data (architecture test).

**Statutory production readiness: NOT DECLARED.**

## Tests

| | |
|---|---|
| Tests | 643 (627 passed + 16 skipped: the MySQL-only suites, run separately below); Phase 9 end: 600 |
| Assertions | 6,626 |
| Failures | 0 |
| Architecture | 51 PASS (43 existing + 8 workforce invariants) |
| Security | PASS. Covers:<br>• employee exclusion;<br>• manager relationship scope (mentor / buddy / project);<br>• organisation scope at policy, query and service level;<br>• tenant isolation;<br>• cost field security and audit masking;<br>• API scopes, codes and IDOR;<br>• lifecycle audit. |
| Workforce domain | Position foundation 8, planning 7, security / API 6, integration 2, scale 3, screens and actions 3 |
| Pint | PASS |
| Migration replay | PASS. On a temporary MySQL database: fresh + seed (263 tables, 94 migrations, 962 foreign keys); columns, indexes, unique constraints, foreign keys and the 4 MySQL CHECK constraints identical to dev apart from `tenants.base_currency`; the Phase 10 migration rolled back (all 9 tables and the `employee_positions` columns removed) and re-applied twice with an identical schema. The seeded statutory state was still 24 / 0 / 5. Temporary databases dropped. |
| Audit chain | PASS on all three databases:<br>• **dev:** platform 40, demo 587 events;<br>• **replay:** platform 2, demo 548;<br>• **MySQL concurrency:** platform 16 plus 16 race tenants (168–236 events each), written by concurrent processes.<br>0 problems. |
| MySQL concurrency | 16 PASS (5 Phase 8 + 5 Phase 9 + 6 Phase 10), in two consecutive full runs.<br>An earlier combined run had two failures:<br>• an intermittent deadlock on the audit-chain insert in a Phase 8 race (pre-existing; see Known limitations);<br>• a test-factory role slug longer than MySQL's 64 characters (fixed). |

Existing tests changed on purpose:
- **Architecture test:** documents the Phase 10 reminder log as a derived de-duplication row, like
  the Phase 7–9 reminder logs.
- **`RoleFactory`:** bounds the generated role slug to the 64-character column. Long Faker job
  titles overflowed it on MySQL; SQLite does not enforce lengths. No assertion changed.

## Known limitations

- **Effective dating and scope:**
  - Position changes cannot be backdated before the latest version.
  - Organisation scope follows the latest definition, so a future-dated move counts already.
- **Assignments:**
  - Abolishing or closing an occupied position is always refused; no controlled automatic process
    exists (deliberately).
  - A transfer that names no position keeps the employee's seat; HR vacates or reassigns explicitly.
  - Historical occupancy for rehired employees depends on recorded lifecycle transitions (forced
    state changes without a transition fall back to `exit_date`).
- **Planning:**
  - Plan transfers are two lines (out / in), not one linked movement.
  - Budget actuals attribute employer cost by assignment overlap with the period (no pro rata for
    movers) and are suppressed below 5 employees.
- **Approvals:** position approval is direct (second person); only plan approval has a workflow
  option.
- **API:** read only (no idempotent writes yet).
- **Audit chain under concurrency (pre-existing):** concurrent transactions append to the
  per-tenant audit hash chain with locking reads. MySQL occasionally resolves the contention with a
  deadlock that aborts one writer. This was seen once in a Phase 8 race; the writer can retry.
  Automatic retry is not implemented.
- **Statutory:** the blockers above are unchanged; nothing is verified.

## Deferred work

- **Workforce:**
  - API writes with idempotency keys;
  - a position-approval workflow option;
  - linked transfer movements;
  - pro-rata actual cost;
  - scheduled snapshot materialisation if volumes require it;
  - organisation-chart visualisation.
- **Statutory:** everything listed under Statutory, for a later payroll remediation and
  verification phase.
- **Excluded by the Phase 10 prompt (§71):**
  - Compensation, salary reviews, bonus and increments;
  - payroll compensation integration;
  - AI workforce decisions;
  - RMS / recruitment integration (no candidates, requisitions, interviews or offers);
  - production deployment.
