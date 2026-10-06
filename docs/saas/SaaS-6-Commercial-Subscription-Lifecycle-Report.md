# SaaS.6 — FINAL REPORT

**Phase:** Tenant Commercial Subscription and Trial Lifecycle (no money, shadow mode) · **Date:** 7 October 2026

Supporting documents:

| Document | Content |
|---|---|
| [SaaS-6 Baseline](SaaS-6-Baseline.md) | Discovery at `a7dc823`: the architecture as found, open decisions and how SaaS.6 proceeds without inventing them, the design, the test strategy, implementation notes |
| [Decision register](../architecture/decision-register.md#saas6-decisions) | ADR-0038 to ADR-0041. The register has always lived in `docs/architecture/`; there is no `docs/saas/decision-register.md`, and a second copy would be a second source of truth |
| [Security invariants](../architecture/security-invariants.md) | 49–52 |
| [Architecture note](../architecture/saas-6-subscriptions.md) | The developer's map: shape, rules for code, locks, transitions, test gotchas |
| [Browser evidence](evidence/SaaS-6-browser-validation.json) | Browser checks and axe results |

## 1. Executive summary

**SaaS.6 is COMPLETE**, within its scope (no money, shadow mode).

PeopleOS now has a first-class commercial lifecycle per tenant:
- a **subscription** (a commercial agreement's identity);
- its **effective-dated timeline** of periods, each with one **commercial state** (`trial`, `active`, `grace`, `expired`, `cancelled`) and one pinned, immutable **plan version**.

What it guarantees:
- **Every operator action is legal, reasoned and audited.** Transitions are legal only from the state in force on their effective date (today or later). Every change is operator-only, reasoned, idempotent and serialised per tenant, and audited on both chains with before, after and effective date.
- **History is append-only and reconstructable on any date.** A future-dated change is a row that starts later. A later decision voids it visibly, never silently.
- **Trials have explicit dates.** There is no global length, extensions need a reason, and nothing converts on its own.
- **The scheduler only records what the dates decided.** A daily, idempotent command records the expiries effective the day after the recorded end, however late it runs.

**One source of truth per question:**
- The subscription answers "what is the commercial state?".
- The plan assignment (SaaS.4) still answers "which plan is in force?". The engine reads nothing else.
- The subscription **projects** its entitled periods (trial, active, grace) onto assignments in the same transaction, under the same tenant lock. For a subscribed tenant it is the only writer.
- A lapsed or cancelled subscription leaves **no plan in force**: the engine answers UNKNOWN (`NO_PLAN_IN_FORCE`), never DENY.
- Shadow mode, protected capabilities, payroll and authorisation are untouched (proved by test, mutation and architecture rules).

**Nothing else changes:**
- Legacy tenants receive nothing: no subscription, no plan, no flag.
- The technical tenant lifecycle and the commercial lifecycle stay apart.
- There is no payment, price, invoice, signup, enforcement or RMS code.

## 2. Starting commit

`a7dc823` (docs: SaaS.5 commercial packaging, limits and pricing architecture report), branch `feature/oct_1_phase_1`, clean tree.

## 3. Ending commit

The documentation commit that adds this report, directly after the SaaS.6 code commit `5f81c12`. Its hash cannot be written inside itself; see `git log`. Nothing is pushed, merged or deployed, and production is untouched.

## 4. Discovery findings

Full record: [SaaS-6 Baseline](SaaS-6-Baseline.md) §1–§3.

- **No commercial lifecycle existed.** A SaaS.4 plan assignment simply started and optionally ended. The words "trial", "grace", "expired" and "cancelled" had no implementation.
- **The technical tenant status already had a `trial` value** (with `tenants.trial_ends_at`). It was metadata that no code read commercially: provisioning sets it only when the creator chooses it, and nothing expires it. It is not a subscription and SaaS.6 does not treat it as one.
- **The engine was already shaped for this.** ADR-0032 had decided that a subscription would later write plan assignments without changing the engine, and that assignments are effective-dated, pinned and serialised on the tenant's profile row.
- **The open business decisions do not block a no-money lifecycle:** trial policy (D-4), renewal and migration notice (D-5), access mode (D-6, D-7), retention (D-8), operator roles (D-15), grace and dunning (D-16), reminders, tenant-local dates. SaaS.6 takes every date explicitly from an operator, so no policy is invented. **No blocker.**

## 5. Architecture

```
Platform operator ──► Platform › Subscriptions (Filament)           page: visibility of legal actions, confirmation
                         │
                         ▼
               CommercialSubscriptions (Domain/Subscriptions)        operator guard, reason, legality on the date,
                         │                                           painting, idempotency, audit (both chains), event
                         │  TenantCommercialLock::run(tenant)        the tenant's profile row FOR UPDATE
                         ├──► subscription_periods                   commercial truth (timeline)
                         └──► EntitlementConfiguration::projectSubscription
                                   └──► tenant_plan_assignments      plan truth (what the engine reads)
                                              │
Scheduler 00:15 ──► peopleos:subscriptions:settle (TenantRunner, suspended included) ──► settle(tenant) ──┘
                                              ▼
                         EntitlementEvaluator (unchanged precedence) → Decision (+ commercialStatus, context only)
```

- **Module.** `app/Domain/Subscriptions` (Enums, Models, Services, Support, Events). The Entitlements domain never references it (architecture test).
- **Shared lock.** `TenantCommercialLock` lives in Entitlements, because manual assignment uses the same lock. It is the single serialisation point for everything that writes a tenant's plan or commercial state.
- **Read models.** `SubscriptionDirectory` serves the operators' overview and the platform-chain audit trail. It is the one new cross-tenant read, allow-listed.

## 6. Data model

All additive (§29). No price, money, invoice or payment column.

| Table / column | Owner | Content |
|---|---|---|
| `tenant_subscriptions` | Tenant (`BelongsToTenant`) | `reason`, `reference` (contract, order or ticket), `created_by`, timestamps. Never edited or deleted (model guards) |
| `subscription_periods` | Tenant (`BelongsToTenant`) | `subscription_id`, `status`, `plan_version_id`, `starts_on`, `ends_on` (inclusive; null = open-ended), `trigger` (`operator` / `scheduler`), `reason`, `reference`, `created_by`, `closed_by` / `closed_at` / `close_reason`, `voided_at`, `superseded_by`. Generated `live_from` (`starts_on` unless voided) with unique `(subscription_id, live_from)`; indexes for the tenant, the timeline and due ends |
| `tenant_plan_assignments.subscription_id`, `.commercial_status` | Tenant | The assignments a subscription projected, and the state each represents |
| `tenant_entitlement_profiles.subscription_managed` | Tenant | Set by the first projection: manual assignment is refused from then on |
| `entitlement_shadow_observations.last_commercial_status` | Tenant | Shadow context only |

The model guards allow a period to change in only two ways:
- its `ends_on` may move earlier (closing it, with who, when and why);
- it may be voided once.

Its state, version, start, reason and creator never change.

## 7. State machine

Stored states: `trial`, `active`, `grace`, `expired`, `cancelled`. Entitled (they project a plan): trial, active, grace. Terminal: cancelled.

| From (in force on the date) | To | Operation | Notes |
|---|---|---|---|
| nothing / after the previous cancellation | trial | `startTrial` | Explicit end date required; a published version on sale on the start date |
| nothing / after the previous cancellation | active | `start` | End optional (fixed or open-ended term) |
| trial | active | `convert` | Never automatic |
| active | grace | `enterGrace` | Explicit grace end required; never automatic |
| grace, expired (recorded or derived) | active | `reactivate` | Voids a recorded expiry that would start on or after the date |
| trial, active, grace | expired | `expire` (operator) / `settle` (scheduler) | Scheduler: effective the day after the recorded end |
| trial, active, grace, expired | cancelled | `cancel` | Terminal; same date twice is a no-op; a different date after a cancellation is refused |
| trial, active, grace | same state, later end | `extend` | A continuation row from the day after the current end; refused once lapsed (reactivate instead) |
| trial, active, grace | same state, other version | `changePlan` | The tail moves to the new version; the past keeps its version |
| cancelled | anything | — | Refused. A returning customer gets a new subscription |

**Rules:**
- **Dates decide.** The state on a day is derived from the rows. After an entitled period's explicit end, until the next period starts (or for good, when none follows), the state is *expired*, derived until a row records it. So:
  - a trial that ended yesterday cannot be converted today, whoever acts first (MySQL race 7); it can be reactivated;
  - every order of events gives the same state on every day. A settlement before a late reactivation records the expiry and the reactivation closes it. A reactivation before the settlement leaves those days derived as expired. Either way the state on each day is the same (feature test; MySQL race 5);
  - a cancellation recorded in advance, dated after a trial's planned end, leaves the days between expired.
- **Painting.** A change effective on D ends the period in force on D−1 and voids the live periods starting on or after D. Then it writes the new tail.
- **Idempotency.** If the resulting tail equals the existing one, nothing is written, audited or emitted.

## 8. Trial lifecycle

| Step | Behaviour |
|---|---|
| Start | `startTrial(tenant, version, from, until, reason, actor, reference)`. Both dates are explicit; there is no global trial length or eligibility rule; the trial uses the chosen version's content (no trial-specific limits) |
| Extend | `extend(subscription, until, reason, …)`: a continuation row from the day after the current end to the new end, action `TRIAL_EXTENDED`, audited with the reason and the new `until`. Refused once lapsed |
| Convert | `convert(subscription, from, until?, …)` → active, from a date within the trial. A future-dated extension that the conversion overtakes is voided and stays visible as "voided (never took effect)" |
| Lapse | Nothing converts it. From the day after its end the trial is expired (derived); the scheduler records the `expired` row effective that day, trigger `scheduler`, with the reason "The trial ended on {date} with no successor" |
| After lapse | `reactivate` (explicit, audited) or a new decision; `convert` is refused |
| Legacy trial fields | `tenants.status = trial` and `trial_ends_at` are shown as "Legacy tenant fields (metadata, never read as commercial state)", never converted |

## 9. Subscription lifecycle

- **Start:** `start` (active, open-ended or fixed term).
- **Renew:** `extend` an active term (`SUBSCRIPTION_RENEWED`). A fixed term can become open-ended.
- **Grace:** `enterGrace` with an explicit end (`GRACE_ENTERED`); `extend` it (`GRACE_EXTENDED`); `reactivate` it (`SUBSCRIPTION_REACTIVATED`); or let it lapse.
- **Expire:** early, by an operator (`expire`), or recorded by the scheduler after a dated end.
- **Cancel:**
  - Terminal, and future-dating is allowed: the current state continues until the day before.
  - The same cancellation twice is idempotent. A different date after a cancellation is refused ("already cancelled from …").
  - A new subscription may start on or after the cancellation date.
- **One live subscription per tenant** (not cancelled, or cancelled only in the future), checked under the tenant lock (MySQL race 2).

## 10. Plan-version relationship

- **A period pins one published version.** It must be on sale on the period's start (shared lock on the version; same rule as SaaS.4 assignment).
- **Publishing never moves a subscription.** A new version of the same plan changes nothing for existing subscriptions, which stay on their pinned version (test "pins the plan version"). Retiring a version only stops new sales of it.
- **Only `changePlan` changes the version.** It is explicit, reasoned and audited (`SUBSCRIPTION_PLAN_CHANGED`, `plan_version` before and after), and it applies from its date onwards. Earlier periods keep theirs.

## 11. Entitlement relationship

| Aspect | Behaviour |
|---|---|
| Source of truth | Assignments stay the engine's only plan source (ADR-0032, ADR-0033 unchanged). The subscription projects each entitled period as an assignment of its version for exactly its dates. The projection always equals the entitled periods: asserted after every MySQL race and in the feature tests |
| One writer | Once projected, the tenant is `subscription_managed`: `assignPlan` and `endPlanAssignment` refuse it. An older SaaS.4 manual assignment is painted over from the subscription's start, and its history is kept. An architecture test restricts `projectSubscription()` to the guarded subscription service |
| Lapse | An expired or cancelled period projects nothing: UNKNOWN / `NO_PLAN_IN_FORCE`, never DENY, never an authorisation failure (payroll runs to payslips under a lapsed subscription; test) |
| Context | `Decision::$commercialStatus` (trial, active, grace; null without a subscription assignment); `toArray()['commercial_status']`; observations store `last_commercial_status`; `peopleos:entitlements:explain` and Platform › Entitlements show it. It never changes an outcome |
| Shadow | `enforced()` is still false; `observe()` still never throws |
| Legacy | Tenants without a subscription behave exactly as in SaaS.5 (UNKNOWN or their manual plan) |

## 12. Tenant lifecycle relationship

| Aspect | Behaviour |
|---|---|
| Separation | No SaaS.6 code writes `tenants.status` (ADR-0040). A cancellation never suspends a tenant; a suspension never changes a subscription (test) |
| Suspended tenants | Keep their commercial history. Operators can still manage their subscription (a conversion during an investigation; test). The scheduler settles them too (`includeSuspended: true`), without touching their status |
| Display | The page shows the technical status and the commercial state side by side, never merged |

## 13. Security model

| Boundary | Enforcement |
|---|---|
| Operator-only | Every mutation calls `guard()`: `isPlatformAdmin()` (flag and no tenant) in the **service**, not just the page. Refused for employees, managers, tenant administrators, a tenant user wrongly flagged as an operator, and a tenantless non-operator, for every operation and any tenant (test, mutation S03) |
| Reason | At least 5 characters and at most 500; the reference (optional) at most 100. Both checked in the service (test, mutation S04). The browser and the server both refuse an empty reason (browser evidence) |
| Tenant isolation | Both new models are `BelongsToTenant`, so fail-closed without a tenant (test, mutation S11). Operators reach them through `runAs`. The cross-tenant overview (`SubscriptionDirectory`) is allow-listed and reads tenant names and commercial states only |
| Page | `canAccess()` = operator. 403 for every tenant user (feature and browser tests); no Platform menu for tenants |
| Authorisation separation | Nothing that authorises references the subscription or entitlement domain; the engine never references subscriptions (architecture test, mutations S15, S16) |
| Immutability | Model guards (test, mutation S12); audit rows immutable (`ImmutableAuditRecordException`) |
| Sessions | Unchanged. Commercial changes never log anyone out or change a session epoch; operator MFA and SaaS.2 session epochs untouched |

Invariants 49–52 are recorded in [security-invariants.md](../architecture/security-invariants.md).

## 14. Audit model

- **Two chains.** Every change records one event on the **tenant chain** and one on the **platform chain** (`tenant_id` null, `metadata.subject_tenant_id`), module `subscriptions`. Actions: `TRIAL_STARTED`, `TRIAL_EXTENDED`, `TRIAL_CONVERTED`, `SUBSCRIPTION_ACTIVATED`, `SUBSCRIPTION_RENEWED`, `GRACE_ENTERED`, `GRACE_EXTENDED`, `SUBSCRIPTION_REACTIVATED`, `SUBSCRIPTION_EXPIRED`, `SUBSCRIPTION_CANCELLED`, `SUBSCRIPTION_PLAN_CHANGED`.
- **Each event carries:**
  - the actor (null for the scheduler);
  - the reason;
  - the effective date;
  - field changes `commercial_status` and `plan_version` (before → after);
  - metadata: `subscription_id`, `trigger`, `effective_from`, `until`, `reference`, the period ids written and voided, and the assignment ids ended or created.
- **Reconstruction.** The timeline (append-only, voided rows kept) and the audit chain each reconstruct the state on any date, independently.
- **Idempotent changes are not audited twice** (test, mutation S08). Both chains verify after every feature scenario and every MySQL race.

## 15. Concurrency model

- **One lock per tenant.** Every subscription change, the settlement and manual plan assignment run inside `TenantCommercialLock::run()`: the tenant's `tenant_entitlement_profiles` row `FOR UPDATE` (created if missing with `insertOrIgnore`). The legality check, painting, projection and audit all happen inside that one transaction.
- **Plan versions** are read with a shared lock.
- **Backstop:** unique `(subscription_id, live_from)`.
- **Cache.** The tenant's entitlement cache is forgotten after commit.
- **Proof:** 7 MySQL races (§22). Removing the lock makes race 2 deadlock or duplicate (mutation S13, run on MySQL).

## 16. Scheduler/idempotency model

| Property | How |
|---|---|
| Schedule | `peopleos:subscriptions:settle` daily at 00:15, `withoutOverlapping()->onOneServer()`; `--tenant=` for one tenant. Listed in the operations runbook's scheduler inventory (`docs/operations/queue-and-scheduler.md`, enforced by `OperationsHardeningTest`), as the second command that also runs for suspended tenants |
| Scope | Every tenant through `TenantRunner` (failure isolation: one tenant's error never stops another), suspended tenants included |
| What it does | Reads the timeline under the tenant (no lock unless due). If a lapse is due (the last entitled period ended before today with no successor), it applies an expiry under the lock, **effective the day after the end** (mutation S10), trigger `scheduler`, actor null |
| Idempotent | A second run finds the expiry and writes nothing (test; MySQL race 4: two settlements at once record one expiry) |
| Late runs | Ten days late still records the expiry on the day after the end (test) |
| Races | A reactivation racing the settlement of the same lapse: active today and expired yesterday, whichever wins, never a duplicate expiry (race 5). An extension racing the lapse is refused as lapsed (race 7) |
| Notifications | Every change dispatches `CommercialStatusChanged` after commit. There are no listeners, no mail and no reminders (test asserts nothing is mailed) |

## 17. Migration strategy

- **One additive migration** (§29): two tables and four nullable or defaulted columns. No data is written.
- **MySQL 8.4.11 chain** on a throwaway database:
  - full `migrate` passes;
  - `migrate:rollback --step=3` (SaaS.6, SaaS.4, SaaS.3) removes every SaaS.6 table and column;
  - re-`migrate` passes, and `mysqldump --no-data` is identical before and after;
  - the database is dropped.
- **Showcase** (`hcm_ux_showcase`):
  - backed up first (mysqldump, mode 600, scratchpad);
  - migrated with `migrate --force`;
  - every table's row count is unchanged, apart from one more `migrations` row and the two new empty tables;
  - no profile became subscription-managed and no assignment received a subscription.
- **Rollback** removes SaaS.6 only. A SaaS.4 manual assignment is never rewritten, so a rollback leaves SaaS.5 behaviour intact.

## 18. Legacy tenant strategy

- **No subscription is created automatically:** not for existing tenants, demo tenants or the showcase.
- **No default plan** and no flag.
- **Tenants whose technical status is `trial` keep it**, as metadata. Converting them into subscriptions is an operator decision, made per tenant through the page.
- **A tenant with a SaaS.4 manual plan keeps it** until an operator starts a subscription, which then takes over from its start date (history kept).
- **Proved** by the test "leaves tenants without a subscription exactly as they were" and by the showcase migration (§17).

## 19. UI changes

**Platform › Subscriptions** (new; operators only; navigation group Platform, after Plans and Entitlements):
- **Overview:** every tenant with its technical status and its commercial state today (state, since, until, plan version), in a keyboard-scrollable table region.
- **Tenant detail:**
  - a summary that keeps the technical status, the commercial state and the legacy fields apart;
  - the full timeline (voided rows struck through and labelled "voided (never took effect)");
  - the projected plan assignments;
  - the platform audit trail (action, before → after, effective date, reason, reference, actor).
- **Actions** (`startTrial`, `startSubscription`, `convert`, `extend`, `enterGrace`, `reactivate`, `changePlan`, `expire`, `cancel`):
  - each is visible only when legal today;
  - each confirms with "Now: {state} → {result}" and needs a reason (optional reference and dates);
  - errors from the service are shown as notifications.

**Existing pages:** Platform › Entitlements and `peopleos:entitlements:explain` append "· {commercial status} · subscription #{id}" to the plan in force.

## 20. API/service changes

There is no public API change and no new tenant API; `routes/api.php` is untouched.

| Symbol | Change |
|---|---|
| `CommercialSubscriptions` | New. `startTrial`, `start`, `extend`, `convert`, `enterGrace`, `reactivate`, `expire`, `cancel`, `changePlan` (all take `reason`, `actor`, optional `reference`; return the subscription); `settle(Tenant)` |
| `SubscriptionTimeline` | New pure read model: `stateOn`, `lapse`, `last`, `first`, `cancelledFrom`, `tailFrom` |
| `SubscriptionDirectory` | New read model: `overview(day)`, `auditTrail(tenantId)` |
| `CommercialStatusChanged` | New event: `tenantId`, `subscriptionId`, `action`, `from`, `to`, `effectiveOn`, `trigger` |
| `TenantCommercialLock` | New: `run(Tenant, Closure)` |
| `EntitlementConfiguration` | New `projectSubscription(…)`; `assignPlan` / `endPlanAssignment` refuse subscription-managed tenants; locking delegated to `TenantCommercialLock` |
| `Decision` | `commercialStatus`, `withCommercialStatus()`, `toArray()['commercial_status']` |
| `EntitlementEvaluator`, `ShadowRecorder` | Attach / store the commercial status |
| `AuditAction` | 11 cases (§14) |
| Console | `peopleos:subscriptions:settle {--tenant=}`; scheduled daily 00:15 |
| Route | `GET /admin/platform-subscriptions` (`filament.admin.pages.platform-subscriptions`) |

## 21. Tests

| Suite | Result |
|---|---|
| Full suite (`php artisan test --parallel --processes=2`, SQLite) | **1299 tests: 1210 passed, 0 failed, 89 skipped** (the opt-in MySQL suite, run separately in §22), 15,211 assertions. SaaS.5 ended at 1270 / 1188 / 82. The first SaaS.6 run failed one test: the scheduler inventory in the operations runbook did not list the new command (fixed; §16) |
| New feature tests `tests/Feature/Subscriptions/` | 21 tests, 302 assertions: lifecycle 6, trials and settlement 5, entitlement 5, security 5 |
| Architecture | 132 (one new SaaS.6 test; four allow-lists and the price boundary extended to Subscriptions; `projectSubscription()` restricted to the subscription service) |
| Entitlements (SaaS.3–5) | 71 pass unchanged, except `ShadowModeTest`'s exact column list, which now includes `last_commercial_status` |
| Mutation | 20 mutants of the commercial invariants, each run on a private copy of the tree (S13 on MySQL). The first run (S01–S18) killed 17. S18 (back-dating allowed) survived because the test refused a back-dated change for the wrong reason (no state before the start). The test now back-dates inside the timeline and asserts "today or later", and the re-run kills it. S19 (no expiry between periods) and S20 (length limits removed) came with the last fixes, and S02 was re-targeted at the new timeline code. **20 of 20 killed**, each by the intended test |

Mutants: S01 illegal transition, S02 derived expiry ignored, S03 operator check, S04 reason, S05 second writer, S06 projection skipped, S07 lapse keeps the plan, S08 idempotency, S09 two live subscriptions, S10 late settlement dated today, S11 tenant scope, S12 history rewritable, S13 tenant lock, S14 suspended tenants skipped, S15 policy reads subscriptions, S16 engine depends on subscriptions, S17 cancellation not terminal, S18 back-dating, S19 no expiry between periods, S20 length limits removed.

## 22. MySQL validation

| Check | Result |
|---|---|
| Server | MySQL 8.4.11 |
| Migration chain | migrate, rollback (3 steps), reapply: clean; schema identical after reapply (§17) |
| SaaS.6 races (`tests/MySql/SubscriptionConcurrencyTest.php`, forked processes) | 7 pass: 1. convert vs cancel (cancel always lands; the end state is cancelled). 2. Two subscriptions at once (one). 3. The same conversion twice (one row, one audit). 4. Two settlements (one expiry, dated the day after the end). 5. Reactivation vs settlement (the same state on every day whichever wins: expired yesterday, active today; at most one expiry, closed the day before). 6. Cancel vs reactivate from grace (cancelled). 7. Extension vs lapse (refused as lapsed). After each race: no overlapping live periods, projections equal the entitled periods, both audit chains valid |
| Full MySQL suite (`PEOPLEOS_MYSQL_CONCURRENCY_DB=hcm_saas6_concurrency php artisan test tests/MySql`) | **89 / 89 pass**, 411 assertions: every earlier race (tenancy, audit chain, payroll, entitlements, plans) plus the 7 subscription races. An earlier run of this suite exposed the race 5 finding (§7, §25), which was fixed before the final run |

## 23. Browser/accessibility evidence

[evidence/SaaS-6-browser-validation.json](evidence/SaaS-6-browser-validation.json). Disposable `hcm_saas6_ui_showcase` (showcase seeder plus fictional plans and subscriptions) on 8094, dropped afterwards. Chromium; 1440×900, light and dark, and 390×844.

- **19 of 19 checks pass:**
  - TOTP sign-in;
  - the overview with technical and commercial state;
  - the summary keeps the legacy fields apart;
  - timeline and audit trail;
  - only legal actions offered;
  - before → after in the modal;
  - an empty reason refused by the browser and by the server;
  - a conversion through the UI, recorded and audited;
  - an unsubscribed tenant offered only "start";
  - a trial without an end refused, and a trial started with explicit dates through the UI;
  - a lapsed trial shown as expired by its dates, with reactivation offered and conversion not;
  - the Entitlements page shows the commercial context;
  - no horizontal scroll on a phone;
  - a tenant administrator gets 403 and no Platform menu.
- **axe-core (WCAG 2 A/AA, 2.1 A/AA):** 0 violations in 5 states (overview, trial detail, convert modal, phone, dark). No console errors.
- **Regression suites on 8092** (frozen-clock showcase rebuilt from scratch, including the SaaS.6 migration): visual regression **120 passed**, 198 skipped by design, no baseline changed; browser suite (Chromium and WebKit) **74 passed**, 6 skipped by design.
- **Showcase on 8090** (`hcm_ux_showcase`, after the additive migration):
  - all 6 personas (employee, manager, HR, tenant administrator, payroll, executive) sign in, and their everyday pages answer 200;
  - Platform › Plans, Entitlements and Subscriptions answer 403 to all of them, with no Platform menu;
  - the only console errors are those expected 403s;
  - the existing operator renders the Subscriptions overview and the demo tenant ("No subscription", only the start actions offered) inside a rolled-back transaction: users, audit events, subscriptions, profiles and observations have the same counts before and after.

## 24. Performance

Same probe, same data, at `a7dc823` and at HEAD. Three interleaved rounds; array and database cache; tenants with no plan, with a plan, and (HEAD only) with a subscription; 7 timed runs per surface.

| Surface | Queries `a7dc823` → HEAD | Median ms (array, no plan / plan) |
|---|---|---|
| payroll calculate (15 employees) | 790 → 790 | 300 → 301 / 307 → 311 |
| leave request | 55 → 55 | 21.7 → 22.7 / 21.5 → 22.9 |
| API employees | 9 → 9 (11 → 11 database cache) | 12.2 → 13.2 / 12.4 → 13.5 |
| home | 51 → 51 (62 → 62) | 163 → 172 / 167 → 182 |
| entitlement cold load + 1 decision | 1 → 1 / 5 → 5 (4 / 8 database cache) | 0.3 → 0.3 / 1.3 → 1.4 |
| 31 capabilities × 10, warm | 0 → 0 | 6.9 → 7.3 / 7.2 → 8.1 |
| Entitlements page | 29 → 29 / 36 → 36 | 82 → 86 / 85 → 88 |
| Subscriptions page (new) | 31 without a subscription; 41 with one, **21** after the fix below | 69 / 76 (measured before the fix) |

- **Query counts are identical on every shared surface.** A tenant on a subscription costs the same as one on a manual plan (5 cold reads, 0 warm).
- **Timings are within run-to-run noise.** The database-cache variant moves −8 % to +9 % on the same surfaces in both directions. Warm decisions are about 2 µs slower each, from attaching the commercial status.
- **Subscriptions page.** It is constant in the number of tenants (overview: 9 queries for 1, 5 or 20 subscribed tenants). The selected tenant was re-read for every action-visibility check; memoising the tenant and version labels per request cut a tenant's page from 41 to 21 queries.

## 25. Known limitations

1. **Shadow only.** A lapsed subscription changes no access: there is no access mode, restriction or enforcement (by scope).
2. **No notification is delivered.** `CommercialStatusChanged` has no listener: no reminders before a trial ends and no owner e-mail. There is no owner contact.
3. **Any platform operator can perform every commercial operation.** There is no operator role catalogue and no maker-checker (G-PLAT-1 / D-15).
4. **`subscription_managed` is sticky.** After a cancellation the tenant's plan can only come from a new subscription; there is no way back to manual assignment (deliberate: one writer; see deferred decisions).
5. **Business dates are UTC.** Tenant-local dates are an open decision; the scheduler runs at 00:15 server time.
6. **Overview at scale.** The overview loads every live period in one read. This is fine for hundreds of tenants; thousands will need pagination or a summary column. The platform audit trail filters on a JSON metadata path (bounded to 50 rows, module `subscriptions`).
7. **A lapse that a later change closed stays derived.** If an operator reactivates or cancels from a date after a lapse that the settlement has not recorded yet (or after a planned end, in advance), the expired days in between are derived from the dates and have no row of their own and no separate `CommercialStatusChanged` event. The state on every day is the same as if the settlement had run first, and the change's audit shows the state it ended ("expired (lapsed)" → …). Only the trailing lapse is recorded.
8. **`changePlan` moves the whole future tail** to the new version. There is no per-period or "at next renewal" change (renewal-boundary migrations are D-5).
9. **Operational: the leaked demo credential is still active.** It was checked again on 7 October 2026 (booleans only, nothing printed): the old demo tenant-admin credential still works in `hcm` and `hcm_ux_showcase`. Rotation is the owner's action and was not performed.

## 26. Deferred decisions

| Decision | Status after SaaS.6 |
|---|---|
| D-4 trial policy (length, eligibility, limits, card) | Open. Trials take explicit operator dates |
| D-5 renewal notice and plan migrations at renewal (ADR-0021 remainder) | Open. Changes are explicit and dated |
| D-6 / D-7 access mode and what a lapsed tenant may do | Open. Lapse = no plan in force, nothing restricted |
| D-8 retention and tenant closing after the end | Open. No clock, no closing |
| D-15 operator roles; maker-checker for commercial changes | Open |
| D-16 grace policy and dunning | Open. Grace is explicit and operator-driven |
| Reminders and owner contact (`owner_user_id`) | Open. Event boundary only |
| Tenant-local business dates | Open. UTC |
| ADR-0017 ownership of billing records | Open. SaaS.6 made subscriptions tenant-owned (ADR-0038); billing records are a separate question |
| Releasing a tenant from subscription management | Open. Not possible today |
| Migrating legacy `tenants.status = trial` tenants into subscriptions | Business decision, per tenant, by operators |

## 27. Production-readiness implications

- **PeopleOS remains NOT production-ready.** SaaS.6 neither improves nor worsens the existing blockers: statutory rules 0 of 24 verified, the leaked demo credential, and the production gates from earlier phases.
- **Deploying SaaS.6 would be low-risk** (additive migration, no data written, shadow only, no change for tenants without a subscription). Before any production use of subscriptions, Markedge must still decide:
  - who may operate them (D-15);
  - whether reminders are needed;
  - the dates' time zone.
- **Billing (SaaS.7 and later) must build on ADR-0038 to ADR-0041:**
  - it reads the subscription timeline;
  - it never writes plan assignments directly;
  - it never turns a lapse into a denial without an explicit access-mode decision.

## 28. Exact files changed

**Code commit `5f81c12`**: 32 files.

| Kind | Files |
|---|---|
| New: domain | `app/Domain/Subscriptions/Enums/CommercialStatus.php`, `Events/CommercialStatusChanged.php`, `Models/TenantSubscription.php`, `Models/SubscriptionPeriod.php`, `Services/CommercialSubscriptions.php`, `Services/SubscriptionDirectory.php`, `Support/SubscriptionTimeline.php`; `app/Domain/Entitlements/Services/TenantCommercialLock.php` |
| New: console, UI | `app/Console/Commands/SettleSubscriptions.php`, `app/Filament/Pages/PlatformSubscriptionsPage.php`, `resources/views/filament/pages/platform-subscriptions.blade.php` |
| New: migration | `database/migrations/2026_10_24_100001_create_subscription_tables.php` |
| New: tests | `tests/Feature/Subscriptions/SubscriptionLifecycleTest.php`, `TrialAndSettlementTest.php`, `SubscriptionEntitlementTest.php`, `SubscriptionSecurityTest.php`; `tests/MySql/SubscriptionConcurrencyTest.php` |
| Modified | `app/Console/Commands/ExplainEntitlements.php`, `app/Domain/Audit/Enums/AuditAction.php`, `app/Domain/Entitlements/Models/EntitlementShadowObservation.php`, `TenantEntitlementProfile.php`, `TenantPlanAssignment.php`, `app/Domain/Entitlements/Services/EntitlementConfiguration.php`, `EntitlementEvaluator.php`, `ShadowRecorder.php`, `app/Domain/Entitlements/Support/Decision.php`, `resources/views/filament/pages/platform-entitlements.blade.php`, `routes/console.php`, `tests/Feature/Architecture/ArchitectureTest.php`, `tests/Feature/Entitlements/ShadowModeTest.php` |
| Operations runbook | `docs/operations/queue-and-scheduler.md` (scheduler inventory; enforced by a test) |
| Evidence | `docs/saas/evidence/SaaS-6-browser-validation.json` |

**Documentation commit:**
- `docs/saas/SaaS-6-Baseline.md` (new);
- this report (new);
- `docs/architecture/saas-6-subscriptions.md` (new);
- `docs/architecture/decision-register.md`;
- `docs/architecture/security-invariants.md`.

## 29. Exact migrations

| Migration | Up | Down |
|---|---|---|
| `2026_10_24_100001_create_subscription_tables` | Creates `tenant_subscriptions` and `subscription_periods` (with generated `live_from`, unique `sub_periods_live_unique`, indexes `sub_periods_tenant_index`, `sub_periods_timeline_index`, `sub_periods_due_index`). Adds `tenant_plan_assignments.subscription_id` (FK, null on delete) and `.commercial_status`, `tenant_entitlement_profiles.subscription_managed` (default false), `entitlement_shadow_observations.last_commercial_status` | Drops the four columns (FK first) and both tables |

## 30. Exact commits

1. `5f81c12` feat: tenant commercial subscriptions and trial lifecycle in shadow mode (SaaS.6): 32 files, +2293 / −42
2. The documentation commit: `docs: SaaS.6 commercial subscription and trial lifecycle report` (this file)

Branch `feature/oct_1_phase_1`. **Not pushed. Not merged. Not deployed.**
