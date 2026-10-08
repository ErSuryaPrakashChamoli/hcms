# Workforce Planning & Position Management (Phase 10)

For: engineers extending PeopleOS workforce planning and positions.

Code lives in `app/Domain/Workforce`. Phase 10 adds organisational capacity on top of the existing
employment history; it does not replace it. Discovery: `docs/PeopleOS-Phase-10-Discovery.md`.

## 1. Position ≠ employee

```
Designation (job / role)          Organisation node · Location · Establishment · Grade · Cost centre …
        │                                   │
        └──────────────► Position ◄─────────┘        capacity owned by a company (code, status)
                            │
                            └── PositionVersion (effective-dated, immutable after draft):
                                title, role, job family, career track, organisation unit (+ derived
                                business unit / division / department / team), location, establishment,
                                legal entity, employment / worker type, grade, cost centre, parent
                                position, occupancy mode, seats, FTE per seat, FTE capacity,
                                standard hours, status
Employee ── employee_positions (authoritative employment history; + position_id, fte)
                            │
                            └── occupies a Position for the row's effective period
```

**The rule:**
- A position exists without anyone in it: planned, open, frozen, abolished.
- An employee can leave a position, and the position stays.
- The only link between them is the employee's own `employee_positions` row.
- `positions` and `position_versions` hold no `employee_id` (architecture test).

## 2. History

| Record | Rule |
|---|---|
| Position definition | **Draft** is edited in place.<br>**Every later change is a new version from an effective date**: the previous version is closed the day before. A same-day change leaves a zero-length version: kept as history, never in force.<br>Versions are never edited after draft (only `effective_to`, once) and never deleted. Changes cannot start before the latest version. |
| Lifecycle | `peopleos.workforce.position_transitions`:<br>• draft → proposed;<br>• proposed → approved / back to draft;<br>• approved → planned / open;<br>• open ⇄ frozen / on hold;<br>• → abolished or closed (terminal).<br>The transition is chosen by configuration, never typed. **"Occupied" is not a status**: an open position is filled, partially filled or vacant by its assignments. |
| Assignments | `employee_positions` rows, written only by `AssignPositionAction`. |
| Occupancy on a date | **Counts:** rows carrying `position_id` that are effective on the date, for employees who had not left by then.<br>**"Not left" requires both:**<br>• `exit_date` is empty or on / after the date;<br>• no exit transition between the row's start and the date.<br>The second condition matters because exits do not close employment rows and rehire clears `exit_date`.<br>**FTE:** the assignment's own, else the position's per-seat FTE. |
| Plans | **Draft:** the only editable state.<br>**From submission:** content is locked.<br>**At approval:** the content is checksummed.<br>**Corrections:** new versions (lines copied).<br>**Active:** one active version per plan (`active_key` unique plus the plan row lock); publishing supersedes the previous one. |
| Scenarios, budgets | **Draft:** editable.<br>**Approved:** locked (scenarios archived, budgets superseded). |

## 3. Headcount, FTE, vacancy

`WorkforceSnapshot` reconstructs any date from versions, assignments and lifecycle history. It stores
no snapshot rows, runs database aggregates and costs a constant number of queries.

| Measure | Meaning |
|---|---|
| Positions | Positions whose version in force has an effective status (approved, planned, open, frozen, on hold) |
| Approved seats / FTE | Seats / FTE capacity of those positions |
| Planned seats | Approved or planned, not yet open |
| Open seats | Seats of open positions |
| Occupied seats / FTE | Distinct occupants / their FTE |
| Vacant seats / FTE | Unfilled capacity of **open** positions only (frozen empty seats are not vacancies) |
| Frozen / on-hold seats | Seats of frozen / on-hold positions |
| Employees | People (`WorkforceMetrics::headcount`), never derived from seats |

**A vacancy is a capacity fact, never a requisition.** PeopleOS has no recruitment objects.
- Webhooks may announce `workforce.position.opened` / `vacated` to an outside system.
- **Single occupancy** means exactly one seat.
- **Multiple occupancy** means N seats with an FTE capacity no greater than seats × FTE per seat.
- A seat is assigned only when both a seat and the FTE are free.

## 4. Assignment boundary

`AssignPositionAction` (Employment) calls the `PositionAssignmentGuard` contract inside its
transaction. `PositionSeats` (Workforce) implements it.

**Naming a position:**
- The position row is locked with `lockForUpdate`.
- The scoped actor's organisation scope is checked.
- **Refused:**
  - the version on the start date is not open;
  - a later version abolishes or closes the position;
  - the employee has left;
  - the FTE is out of range;
  - no seat or FTE capacity is left from the start date (all later overlapping rows count).
- Assignment rows are read with a **locking read**, so REPEATABLE READ cannot hide a concurrent
  commit.
- The position's dimensions (company, units, location, designation, grade, employment type, cost
  centre) fill the new row; explicit dimensions still win.

**Vacating:** explicit (`vacate_position`).

**Any other employment change:** carries the seat forward.

**What Workforce never does:** it never moves, promotes, transfers or terminates anyone.
- Abolishing or closing an occupied position is refused (no controlled process is configured).
- Shrinking capacity below current occupancy is refused.

## 5. Hierarchy

`parent_position_id` per version.

**Refused:**
- self-parent (service check plus a MySQL CHECK);
- cycles, checked both on the date and in the latest definitions;
- a parent in another company;
- an abolished or closed parent;
- a foreign tenant (the tenant scope makes it unreachable).

**Never connected to reporting:** the position hierarchy never creates or changes
`reporting_relationships` (architecture test). It is used only for the manager's team view.

## 6. Planning, budgets, forecast

- **Scenarios:**
  - Tenants name them.
  - Assumptions are explicit and labelled: attrition % (0–100), growth %, notes.
  - Approval is a second person's act and locks the scenario.
- **Plan lines:**
  - A line has a movement (baseline, new position, expansion, transfer in / out, reduction,
    closure, retirement, known exit), with a configured sign.
  - It names an optional position and organisation dimensions.
  - It carries headcount, FTE and an effective date inside the period.
  - A planned cost always carries its **cost basis** (annualised salary, monthly salary, employer
    cost or position cost); bases are never mixed.
- **Separation of duties:**
  - The submitter never reviews, approves, rejects or returns the version.
  - The proposer of a position never approves it.
  - The requester of a change never decides it.
  - Preparers of scenarios and budgets never approve them.
- **Optional workflow** (`PEOPLEOS_WORKFORCE_PLAN_WORKFLOW`): the instance is pinned to the
  published version. `WorkforceWorkflowBridge` refuses an approval completed by the submitter.
- **Planning never writes live capacity.** The only bridge is `proposePositionFromLine`, an explicit
  action on an approved or active plan. It creates a **proposed** position that still needs approval.
- **Budgets:**
  - A budget has a scope, period, currency, basis and amount.
  - **Planned cost** comes from the plan lines on the same basis.
  - **Actual cost** comes from the Payroll read contract `WorkforceCostReader` (finalized / paid
    employer cost, aggregated in the database). It is compared only with an employer-cost budget
    in the same currency.
  - It is suppressed when fewer than `peopleos.workforce.analytics_min_group` (5) employees are
    behind it.
- **Forecast** (`WorkforceForecast`), month by month:
  - **Facts:** capacity and occupancy at the start, the plan's dated movements, exits already
    recorded (open exit cases with a last working day).
  - **Assumption:** the scenario's attrition %, shown separately and labelled "Planning assumption
    … not a prediction about any employee".
  - No individual inference and no `AttritionRisk`.

## 7. Access

| Who | Sees |
|---|---|
| `workforce.view` / `workforce.manage` | Positions, versions, plans and snapshots within organisation scope |
| `workforce.team` (managers) | **Positions:**<br>• those under the positions they hold (position hierarchy);<br>• those held by employees they manage through configured relationship types.<br>Mentors, buddies and project leads grant nothing.<br>No plans, scenarios or costs. |
| `workforce.plan` / `review` / `approve` | The planning workflow, with separation of duties |
| `workforce.costs` | Planned / budget / actual costs and budgets (masked in audit; financial classification) |
| Employees | Nothing by default |

**Organisation scope:**
- `ScopedByOrganisationDimensions` hooks into `AccessScope`. For every scoped dimension the record's
  own column must be one of the scoped ids; a missing value hides the record (fail-closed).
- Positions, position versions, plans and budgets carry the derived unit columns for this.
- The services re-check scope as well (`ChecksOrganisationScope`): an existing record must be
  visible, and a new record's dimensions must all fall inside the actor's scope.

## 8. Integrations (read only)

`WorkforceIntegrations`:
- **Requirements:** the Phase 9 `CareerArchitecture::requirementsFor(role, unit, date)`.
- **Succession:** the Phase 9 critical position for the same role (unit-specific first, then
  role-wide), its open plan, active successors and current, unexpired ready-now labels. This is
  shown only to `succession.view`.
- **Career paths:** steps through the role and the next designations, plus open or planned
  positions for them.

Nothing in Career, Talent, Succession, Learning, Performance or Skills is written (integration test
fingerprint).

## 9. API, events, automation

- **API (read only):**
  - `/api/v1/positions` (`positions.read`): list, by code, occupancy (employee codes only), vacancies,
    all as of `?on=`.
  - `/api/v1/workforce` (`workforce.read`): plans, plan version with lines, scenarios, headcount,
    snapshot by dimension. Planned costs need the `workforce.costs` scope.
  - Foreign codes return 404.
  - No write endpoints yet, so no idempotency keys are needed.
- **Events:** `WorkforceEvent` (subject plus explicit recipient users) covers:
  - **positions:** created, approved, opened, frozen, unfrozen, on hold, abolished, closed,
    occupied, vacated;
  - **changes:** change requested, changed;
  - **plans:** submitted, under review, approved, rejected, returned, published, superseded,
    archived;
  - **other:** scenario approved, budget approved, reminders.
- **Notifications:** in-app to the named users only.
- **Webhooks:** `workforce.position.approved / opened / vacated / frozen / abolished / closed`
  only. They carry no costs and no people.
- **Reminders:** `SendWorkforceReminders` (TenantAwareJob, unique) and
  `peopleos:workforce:send-reminders` at 08:00 (`withoutOverlapping()->onOneServer()`). They cover:
  - plans waiting for review or approval;
  - plans whose period ends;
  - long vacancies.

  They are throttled through `workforce_reminder_logs`, and a reminder never triggers another.

## 10. Concurrency

| Path | Protection |
|---|---|
| Last seat / last FTE | Position row `lockForUpdate`, plus locking reads of versions and assignment rows |
| Freeze / abolish / close vs assignment | Same position row lock; abolish and close also check occupancy with a locking read |
| Plan transitions | Version row lock + `lock_version` + state re-check |
| Publication | Plan row lock + unique `active_key` |
| Scenario / budget approval | Row lock + state re-check |

`tests/MySql/WorkforceConcurrencyTest.php` (6 races) uses the shared fork harness. Removing the seat
locks makes the last-seat race fail.

## 11. Known limitations

- **Effective dating:**
  - Changes cannot be backdated before a position's latest version; a correction of the past is a
    new position or a later version.
  - Organisation scope follows the position's **latest** definition (a future-dated move counts
    already).
- **Abolition:** abolishing an occupied position is always refused; there is no configurable
  controlled process.
- **Seat carry-forward:** a transfer that does not name a position keeps the employee's seat. HR
  vacates or reassigns explicitly.
- **Transfer lines:** plan lines are per unit; a transfer is two lines (out and in), not one linked
  movement.
- **Budget actuals:** actual cost is attributed to a budget scope by the employee's assignment
  overlapping the period, not split pro rata for movers.
- **API and approvals:** the API is read only. Position approval has no workflow option (direct,
  second person); plan approval does.
