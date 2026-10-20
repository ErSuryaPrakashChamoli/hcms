# SaaS.6 — Working Baseline (discovery and design)

Recorded on 7 October 2026, before any SaaS.6 code change. Every statement was checked against the repository at `a7dc823`.

| Item | Value |
|---|---|
| Branch | `feature/oct_1_phase_1` |
| HEAD | `a7dc823` (SaaS.5 report); clean tree |
| Roadmap position | SaaS.1 §23 **Workstream 3**: "Tenant Lifecycle, Subscriptions & Trials (no money yet)". SaaS.6 builds its subscription and trial part. Access mode, its enforcement and the platform-to-owner notices stay with later work (see "Not added") |

## 1. Current architecture (verified)

| Layer | What exists | Where |
|---|---|---|
| Technical tenant lifecycle | `tenants.status`: `trial`, `active`, `suspended` (`TenantStatus`; `allowsAccess()` is false only for `suspended`). Suspension and reactivation go through `TenantSuspensions` (reasoned, audited, session epochs). Provisioning defaults to `active`; `trial` is set only if the creator chooses it. `tenants.trial_ends_at`, `tier` and `region` are persisted metadata that no commercial code reads (SaaS.3, SaaS.4) | `Domain/Platform` |
| Plan catalogue | Plans with permanent codes. Versions `draft → published → retired`, immutable once published, with a sale window. A published version must be a consistent package | `Domain/Entitlements` (SaaS.4, SaaS.5) |
| Tenant → plan | `tenant_plan_assignments`: tenant-scoped, effective-dated (business dates, UTC, inclusive). One per day (painted), pinned to a published version, operator-only and reasoned, audited on both chains, no default. Serialised on the tenant's `tenant_entitlement_profiles` row (`FOR UPDATE`) | `EntitlementConfiguration::assignPlan` / `endPlanAssignment` |
| Entitlement engine | catalogue → override → tenant terms → **plan (the assignment covering the day)** → configured default → UNKNOWN. Shadow only: `observe()` never throws, `enforced()` is false. Pinned versions are cached in the tenant state (`tenant:{id}:entitlements`) and forgotten after commit | `EntitlementEvaluator`, `EntitlementStateStore` |
| Commercial lifecycle | **None.** No subscription, trial, grace, expiry or cancellation exists. A plan assignment simply starts and (optionally) ends | — |
| Audit | Hash-chained, append-only; tenant chain plus Markedge's platform chain (`platform: true`) | `AuditRecorder` |
| Scheduling | Daily commands `withoutOverlapping()->onOneServer()`, iterating tenants through `TenantRunner` (failure isolation; suspended tenants skipped unless the command opts in) | `routes/console.php` |
| Notifications | Tenant-scoped notification rules on domain events. There is no platform-to-owner channel (SaaS.1 G-PLAT-3) and no tenant "owner" (`owner_user_id` is proposed, not built) | `Domain/Notifications` |
| Operators | `User::isPlatformAdmin()` (flag and no tenant), MFA required, session epochs (SaaS.2). There is no platform role catalogue (G-PLAT-1) | `Domain/Identity` |

## 2. Decisions already resolved (not re-litigated)

| Topic | Resolution | Source |
|---|---|---|
| Subscriptions sit beside the engine, never inside it; HCM never knows subscriptions exist | Accepted shape | ADR-0018 (proposed), ADR-0033 |
| A tenant's plan is effective-dated tenant data pinned to an immutable version; **a subscription can later write assignments without changing the engine** | Accepted | ADR-0032 |
| Plan versions are immutable; tenants never move to a new version on their own | Accepted (pinning); migrations proposed | ADR-0021, ADR-0031 |
| Price is not part of a plan or of entitlement | Accepted | ADR-0037 |
| No default or Legacy plan; unconfigured tenants stay UNKNOWN | Accepted / proposal not adopted | ADR-0029, ADR-0032; ADR-0026 stays proposed |
| Entitlement configuration is tenant-owned (`BelongsToTenant`); billing records are a separate question | Accepted | ADR-0027 (ADR-0017 open for billing) |
| Tenant lifecycle and subscription are separate machines; trial belongs to the subscription, not the tenant | Proposed structure, adopted here | ADR-0020 (proposed), SaaS.1 State Machines §1, §3, §4 |
| Business dates in UTC | Convention (tenant-local dates still an open decision) | `HasEffectiveDates`, SaaS.3 §7 |

## 3. Decisions genuinely open and how SaaS.6 proceeds without inventing them

| Decision | Blocks SaaS.6? | Treatment |
|---|---|---|
| D-4: trial length, limits, eligibility, card | No | Every trial carries **explicit** start and end dates chosen by the operator. No global length, no eligibility rule, and no trial-specific limits (the trial uses the chosen plan version's content) |
| Grace policy (SaaS.1 tied grace to dunning, D-16) | No | Grace is entered **explicitly** by an operator, with an explicit end date. Nothing enters grace automatically |
| D-5: cancellation, renewal and migration notice | No | Cancellation and renewal take the date the operator gives (today or later). Moving a tenant to another version is an explicit, reasoned operation. Nothing is automatic |
| D-6, D-7: what a lapsed or restricted tenant may do (access mode) | No | Nothing is restricted. When a subscription lapses, its plan simply stops being in force: the engine answers UNKNOWN (`NO_PLAN_IN_FORCE`), never DENY. Access mode and its enforcement stay with later work |
| D-8: retention after the end (trial retention, `ended → closing`) | No | No retention clock and no tenant closing; the tenant's technical status never changes |
| D-15: operator roles | No | The existing operator definition |
| Reminders and notices (T-7/T-3/T-1, owner contact) | No | SaaS.6 emits a domain event on every commercial change (a tested boundary) and sends **no** e-mail: there is no owner contact and no reminder policy |
| Tenant-local dates | No | UTC business dates, as everywhere else |

**No blocker.** Every automatic transition SaaS.6 performs follows a date an operator recorded explicitly (a trial end, a term end, a grace end); none follows a policy.

## 4. What SaaS.6 adds

### 4.1 Data model (additive)

| Table | Owner | Purpose |
|---|---|---|
| `tenant_subscriptions` | Tenant (`BelongsToTenant`) | The commercial agreement's identity: reason, reference (contract or ticket), who created it, when |
| `subscription_periods` | Tenant (`BelongsToTenant`) | The subscription's **effective-dated timeline**. Each row is one span of time with one commercial state (`trial`, `active`, `grace`, `expired`, `cancelled`) and the plan version in force. Dates are inclusive business dates; the end is null when open-ended. History is append-only: a change ends the row in force (end brought earlier) and starts a new row; a not-yet-started row is voided, never deleted. A generated column with a unique index keeps one live row per start date per subscription (see §9) |
| `tenant_plan_assignments` + `subscription_id`, `commercial_status` | Tenant | Marks the assignments a subscription wrote, and the state each one represents |
| `tenant_entitlement_profiles` + `subscription_managed` | Tenant | Once a tenant has a subscription, its plan is managed by it: manual assignment is refused, so there is never a second writer |
| `entitlement_shadow_observations` + `last_commercial_status` | Tenant | Shadow context: the commercial state behind the last observed decision |

No money, price, invoice or payment column anywhere.

### 4.2 State machine (stored states: `trial`, `active`, `grace`, `expired`, `cancelled`)

Terminology follows the SaaS.1 proposal, adapted to a phase without payments:

| SaaS.1 proposal | SaaS.6 |
|---|---|
| `trialing` | `trial` |
| `past_due` (payment-driven grace) | `grace` (operator-driven, explicit end date) |
| `ended` | `cancelled` (terminal) |
| `pending` | Derived "scheduled": a subscription whose first period starts later. Not stored |
| `suspended` (commercial), `abandoned` | Not built: they are payment and checkout states |

| From → to | Operation | Entry condition | Effective date |
|---|---|---|---|
| (none) → trial | `startTrial` | No live subscription; a published version on sale on the start date; **explicit end date** | Start: today or later |
| (none) → active | `start` | As above; end date optional (an open-ended or fixed term) | Today or later |
| trial → trial, active → active, grace → grace | `extend` | The period has not lapsed (end ≥ today); the new end is later (active may become open-ended) | Day after the current end (a continuation row) |
| trial → active | `convert` | The trial is in force on the date | Within the trial |
| active → grace | `enterGrace` | Active on the date; **explicit grace end** | Today or later |
| grace / expired → active | `reactivate` | Grace or expired on the date | Today or later |
| trial / active / grace → expired | `expire` (operator, early end) **or automatic** when an explicit end date passes with no successor | — | Operator: today or later. Automatic: **the day after the recorded end**, however late the scheduler runs |
| any non-cancelled → cancelled | `cancel` | Not already cancelled | Today or later; future-dated cancellation allowed (the current state continues until the day before) |
| trial / active / grace → same state, new version | `changePlan` | The new version is published and on sale on the date | Today or later |
| cancelled → anything | — | **Terminal.** A returning customer gets a **new** subscription (SaaS.1) | — |

**Rules:**
- **Dates drive transitions.** A transition is legal according to the state in force on its effective date. Expiry is fixed by dates, not by who acts first: a trial that ended yesterday is expired today whether or not the scheduler has run, and so it cannot be converted (it can be reactivated).
- **Future-dated changes** are rows that start later. A later change painted over them voids them; a voided row never took effect and stays in history.
- **Idempotent.** The same change twice (same state, version and dates from the same date) changes nothing and is not audited again.
- **One live subscription per tenant** (one that is not cancelled). A new one may start only on or after the day the previous one is cancelled. The live periods of a subscription never overlap, and neither do a tenant's entitled periods (so neither do its projected assignments): the service paints them under a lock, and the unique index is a backstop (see §9).
- **Nothing happens on page view.** Readers derive the state on any date from the rows. The scheduler only materialises lapses that the dates already decided.

### 4.3 Plan assignment vs subscription: one source of truth

| Question | Answer |
|---|---|
| Who decides which plan version applies on a day? | **The plan assignment**, as before: the engine reads only assignments (ADR-0032, ADR-0033 unchanged) |
| Who decides the commercial state? | **The subscription timeline** |
| How do they stay consistent? | The subscription service **projects** its timeline onto assignments, in the same transaction and under the same tenant lock: each `trial`, `active` or `grace` period becomes an assignment of its plan version for exactly its dates (marked with `subscription_id` and `commercial_status`); `expired` and `cancelled` periods have none. It is the only writer for a subscription-managed tenant: `assignPlan` and `endPlanAssignment` refuse those tenants |
| A subscription ends | Its assignment ends with it. The engine answers UNKNOWN / `NO_PLAN_IN_FORCE`: no plan in force, **never DENY**, and never an authorisation failure |
| An assignment without a subscription | A SaaS.4 manual assignment stays valid and manual until an operator creates a subscription. That subscription then paints over from its start date: earlier history is kept, and future manual rows are voided |
| Legacy / unconfigured tenants | Untouched: no subscription is created, no default plan is assigned, UNKNOWN stays UNKNOWN |
| Future-dated assignments | Projected periods that start later become future assignments; a later change re-projects from its date |

### 4.4 Entitlement integration (shadow only)

- **The engine is unchanged.** Its plan layer reads the projected assignments exactly as SaaS.4 assignments.
- **Commercial context.** A decision carries `commercialStatus`: the state of the assignment in force (`trial`, `active` or `grace`; null without one). Observations record `last_commercial_status`. Nothing is denied because of it.
- **Failures stay UNKNOWN.** An engine failure still gives UNKNOWN / `EVALUATION_FAILED`. Payroll, the core, protected capabilities and authorisation are untouched.

### 4.5 Tenant lifecycle relationship

| Aspect | Behaviour |
|---|---|
| Separation | Technical (`tenants.status`) and commercial (`subscription_periods`) are separate. SaaS.6 never changes `tenants.status`, and suspension never changes the subscription |
| Suspended tenants | A suspended tenant keeps its commercial history; operators can still manage its subscription, and the scheduler settles its lapses too (commercial dates do not stop for a technical suspension) |
| Legacy trial fields | `tenants.status = trial` and `trial_ends_at` stay legacy metadata, shown read-only to operators and never converted into a subscription |

### 4.6 Scheduler

- **Command:** `peopleos:subscriptions:settle` runs daily (`withoutOverlapping()->onOneServer()`) through `TenantRunner`, including suspended tenants.
- **What it does:** for each tenant, under the tenant lock, it materialises an `expired` period starting the day after a lapsed end. It audits that (trigger `scheduler`) and emits the domain event.
- **Safety:** idempotent (a second run finds the row and does nothing), safe if delayed (the effective date comes from the recorded end), safe to retry (per-tenant isolation).

### 4.7 Operator UI

- **New page:** Platform › Subscriptions, for operators only.
  - All tenants with their technical and commercial state today.
  - For one tenant: current state, plan version, trial and term dates, the timeline (voided rows shown), the projected assignments and the audit trail.
- **Actions:** each is offered only when legal and needs confirmation, a reason and an effective date. Each shows "before → after".

## 5. What SaaS.6 does NOT add

- **Billing and money:** prices, payment, invoices, GST, refunds, reconciliation, webhooks, checkout.
- **Customer-facing pages:** public signup, a pricing page, billing pages.
- **Access mode** (full / grace / restricted / locked) and **any enforcement**, including on lapse.
- **Lifecycle extras:** trial eligibility, reminders and e-mails, trial retention, tenant closing or deletion.
- **Automatic behaviour:** grace, plan migration or renewal.
- **Tenant-owner self-service.**
- **Status changes:** any change to `tenants.status` or to SaaS.3–SaaS.5 behaviour for tenants without a subscription.

## 6. Security boundaries

| Boundary | How |
|---|---|
| Operator-only | Every mutation checks `isPlatformAdmin()` in the service (not just the page), needs a reason of at least 5 characters, accepts a reference, and is audited on the tenant chain and the platform chain with before and after, effective date and trigger |
| Tenant isolation | New models are fail-closed tenant-scoped. Operators reach them through `runAs`. The cross-tenant overview is one allow-listed read (names and states only) |
| Authorisation separation | Subscriptions live in a new `Domain/Subscriptions`. The entitlement engine never depends on it (architecture test), and nothing that authorises references either domain (SaaS.5 test extended) |
| UI | The page is refused (403) to every tenant user; the service refuses even if it is called directly |

## 7. Migration and backward compatibility

- **Additive only:** two tables and four nullable or defaulted columns. No data is written.
- **Existing tenants:**
  - with a manual assignment: unchanged;
  - without one: UNKNOWN as before;
  - with legacy trial metadata: untouched;
  - suspended: untouched;
  - showcase and demo: untouched.
- **SaaS.3–SaaS.5 behaviour** is unchanged for every tenant without a subscription. Engine query counts are unchanged.
- **Rollback** removes the SaaS.6 tables and columns only.

## 8. Test strategy

- **Feature tests:** state machine (every legal and illegal transition), effective dating and history reconstruction, future-dated changes, idempotency, trials (start, extend, convert, lapse), the projection, legacy safety, payroll under a lapsed subscription, security and isolation, scheduler idempotency and late runs, the page (Livewire).
- **MySQL races:** two operators; conversion versus settlement; extension versus settlement; cancellation versus reactivation; duplicate conversions; two subscriptions created at once.
- **Mutation testing** of the commercial invariants.
- **Regression:** the full suite, browser walkthroughs and axe, visual regression, the 8090 showcase, and performance against `a7dc823`.

## 9. Implementation notes (recorded after the build)

Two points of this design were refined during implementation. Neither changes a decision above.

- **Unique index per subscription, not per tenant.** A cancelled subscription keeps its open-ended `cancelled` row, and a returning customer's new subscription may start on the cancellation date. A per-tenant unique start date would refuse that legal case, so the backstop is `(subscription_id, live_from)`. The cross-subscription rule ("a new one starts only on or after the previous cancellation") is enforced by the service under the tenant lock, and proven by MySQL race 2.
- **Back-dating.** Every operator change takes effect today or later. Mutation testing showed that the first test refused a back-dated change for the wrong reason (no state in force before the start). The test now back-dates inside the timeline and asserts the "today or later" refusal.
- **Expired between periods.** The design derived "expired" only after the last period. The full MySQL suite then showed that a reactivation racing the settlement left the day between the trial's end and the reactivation as "expired" in one order and "no state" in the other. The timeline now derives "expired" after any entitled period's explicit end until the next period starts, so every order gives the same state on every day. The settlement still records only the trailing lapse.
