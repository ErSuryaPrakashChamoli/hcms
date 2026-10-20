# PeopleOS Phase 10 — Discovery

- **Date:** 1 October 2026.
- **Baseline:** `feature/oct_1_phase_1` at `de0d4b1` (Phase 9 end). The tree is clean, nothing is
  pushed, and there are 93 migrations, all run.
- **Scope:** Workforce Planning, Position Management & Headcount Planning foundation.

## 1. What "position" means today

| Term in the code | What it really is | Owner |
|---|---|---|
| `employee_positions` / `EmployeePosition` | **The employee's effective-dated assignment**: company, location, business unit, division, department, team, designation, level, grade, employment type, category, work mode, cost centre and a `change_type` (hire, transfer, promotion, demotion, reassignment, correction, rehire). Written only by `AssignPositionAction`, which opens a new row and closes the previous one the day before. | Employment |
| `Designation` | The canonical **role / job** (job family, level, grade, department, effective dates) | Organisation |
| `CriticalPosition` (Phase 9) | A **role, plus an optional organisation unit**, designated critical for succession | Succession |
| `RoleRequirementVersion` (Phase 9) | Effective-dated requirements per role (plus unit) | Career |
| `CareerPath` steps | Ordered designations | Career (Phase 7 model) |

**There is no seat, headcount or capacity entity, and nothing models a vacancy.**
- `WorkforceMetrics::headcount()` counts employed employees.
- Grep for headcount / vacancy / FTE / budget / manpower / seat finds only employee counts, payroll
  cost and the Phase 9 succession wording.
- No `Position` model exists to reuse.

## 2. Related facts that shape the design

- **Organisation:**
  - `OrganisationNode` is a tree (`path` `/1/5/9/`) over company, business unit, division,
    department, team and location.
  - Legal entities and establishments exist (Phase 6). `Location` carries `establishment_id`.
  - Cost centres, grades, levels, job families and employment types are canonical tables.
- **Exits:**
  - Exits set `employees.exit_date` and the lifecycle state. **They do not close
    `employee_positions` rows**.
  - Rehire clears `exit_date` and opens a new row.
  - Historical occupancy must therefore use the lifecycle transition history
    (`employee_lifecycle_transitions`), not just the assignment rows.
- **Organisation scope:** `AccessScope` constrains employees through their current position and
  organisation units through company plus their own dimension. A record with several organisation
  dimensions (like a position) needs its own constraint.
- **Workflow:**
  - The engine pins instances to the published version, and `WorkflowCompleted` bridges apply
    outcomes.
  - The engine has no built-in "initiator may not approve". Phases 5–9 enforce separation of
    duties in the domain service (payroll preparer / approver, catalogue approval, rule
    verification).
- **Payroll cost:** `payroll_entries.employer_cost` per employee per run, in finalized / paid runs
  of a `payroll_period`. There is no read contract for it yet; `WorkforceMetrics` reads payroll
  models directly.

## 3. Decisions

| Brief | Decision |
|---|---|
| §4, §7 Position | **New canonical `Position`** (`app/Domain/Workforce`): a seat / capacity owned by a company, never an employee.<br>Its definition lives in **`position_versions`**, each effective-dated and immutable once the position leaves draft. A version references the designation (job / role), job family, career track, organisation node (from which business unit / division / department / team are derived), location, establishment, employment type, worker type, grade, cost centre and parent position. It also carries FTE per seat, standard hours, seat capacity, FTE capacity, occupancy mode and status.<br>Nothing it references is duplicated. |
| §8 Lifecycle | `draft → proposed → approved → planned / open`, plus `frozen`, `on_hold`, `abolished` and `closed`, from a configured transition map (`peopleos.workforce.position_transitions`).<br>**"Occupied" is not a typed status.** It is derived from assignments (an open position whose capacity is filled), so occupancy always reconciles with employment.<br>Approval is by a second person (`workforce.approve`), or through a configured workflow. |
| §9, §51–52 History | Every change after draft is a new version from an effective date, closing the previous one. A same-day change leaves a zero-length version (kept as history, never effective).<br>Snapshots reconstruct positions, capacity, FTE, organisation and occupants on any date from versions, assignments and lifecycle history. There are no snapshot rows. |
| §10–11 Hierarchy | `parent_position_id` per version. Refused: self or cycle (as of the date), another company, another tenant.<br>Position hierarchy never drives reporting relationships. |
| §12–17 Headcount, FTE, occupancy, vacancy | Seats and FTE capacity come from the version. Occupancy is the `employee_positions` rows carrying a `position_id` that are effective on the date, for employees not exited on that date. The FTE is the assignment's own, else the position's.<br>Approved / planned / occupied / vacant / frozen seats and FTE come from the effective versions.<br>Single occupancy is one seat; multiple occupancy is N seats with an FTE cap.<br>A vacancy is unfilled capacity of an open position: a fact, **never a requisition**. |
| §15, §42 Assignment | **Employment stays authoritative.**<br>• `employee_positions` gains `position_id` and `fte` (additive).<br>• `AssignPositionAction` calls a `PositionAssignmentGuard` contract (implemented by Workforce) inside its transaction. The guard locks the position row, validates it, checks capacity with locking reads, and supplies the position's dimensions.<br>• The position carries forward on unrelated changes; vacating is explicit. No second history. |
| §19–20 Freeze, abolish | Freeze, unfreeze and abolish need permission and a reason, are audited, and run under the position row lock. Abolishing or closing an occupied position is refused; nobody is moved or terminated. |
| §21–24 Integrations | **Read only:**<br>• requirements through `CareerArchitecture::requirementsFor(designation, node)`;<br>• succession through the Phase 9 critical position for the same role (unit-specific first, then role-wide), its open plan, successors and readiness;<br>• career paths through their steps (next designations).<br>Nothing in Career, Talent, Succession, Learning or Performance is written. |
| §25–30 Plans, scenarios | `workforce_scenarios` are configurable, with explicit planning assumptions labelled as such; draft → approved → archived.<br>`workforce_plans` (scope, owner) → `workforce_plan_versions` (period type, period, scenario, currency; draft → submitted → under review → approved → active → superseded / archived; a single active version per plan; approved versions are locked, and corrections are new versions) → `workforce_plan_lines` (movement type, intended dimensions, planned headcount / FTE / cost, cost basis, effective date).<br>Scenarios and plans never touch live positions. The only bridge is an explicit "propose position from line" action, which creates a **proposed** position that still needs approval. |
| §31–33 Budget | `workforce_budgets` (scope, period, currency, cost basis, amount; draft → approved, locked).<br>Actual cost comes from a new **Payroll read contract** (`WorkforceCostReader`: finalized / paid employer cost, aggregated in the database).<br>Costs need `workforce.costs`. No compensation, salary revision or payroll write. |
| §34–36 Forecast | Facts plus explicit assumptions: current occupancy, planned line movements by date, recorded exits (open exit cases with a last working day) and the scenario's attrition rate as a **planning assumption**, shown separately. No per-employee inference and no `AttritionRisk`. |
| §39–41 Approvals | **Domain service with separation of duties:** submitter ≠ reviewer ≠ approver for plans; proposer ≠ approver for positions; requester ≠ approver for changes.<br>**Optional workflows** (pinned versions) for position approval and plan approval. The bridge refuses an approval completed by the submitter.<br>**Configurable change approval:** `peopleos.workforce.change_approval` names the attributes that need approval; those changes become `position_change_requests`. |
| §46 API | Read only: `/api/v1/positions` (`positions.read`) and `/api/v1/workforce` (`workforce.read`, costs with `workforce.costs`). Employees appear by code only; foreign ids return 404. |
| §6 Recruitment | None: no candidate, application, requisition, interview or offer objects. A vacancy is a capacity fact; a webhook event may announce it. |

## 4. Boundaries

- No payroll, compensation or statutory writes.
- No automatic promotion, transfer or termination.
- No AI.
- No RMS.
- Scenarios cannot change live data.
- Statutory status is frozen: 24 versions, 0 verified, 5 open notices, NOT DECLARED.
