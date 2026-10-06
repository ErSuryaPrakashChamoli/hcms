# SaaS.6 — Commercial subscriptions and trials (shadow mode): the developer's map

Full report: `docs/saas/SaaS-6-Commercial-Subscription-Lifecycle-Report.md`. Design: `docs/saas/SaaS-6-Baseline.md`. What this
feeds: `saas-4-plans.md` (plan assignments) and `saas-3-entitlements.md` (the engine).

## The shape

```
Tenant ── TenantSubscription (reason, reference)                  BelongsToTenant; never edited or deleted
            └─ SubscriptionPeriod × n                             effective-dated timeline (inclusive UTC dates)
                 status: trial | active | grace | expired | cancelled
                 plan_version_id (pinned)                         ends earlier or is voided; never rewritten
                         │
                         │ projection (same transaction, same tenant lock)
                         ▼
Tenant ── TenantPlanAssignment (subscription_id, commercial_status) ──► PlanVersion     the engine reads only this
```

- **Two sources, two questions.** The subscription answers "what is the commercial state?". The plan assignment answers "which plan is in force?". The subscription is the only writer of a subscription-managed tenant's assignments.
- **Entitled states project, others don't.** `trial`, `active` and `grace` periods become assignments. `expired` and `cancelled` periods have none, so the engine answers UNKNOWN / `NO_PLAN_IN_FORCE` (never DENY).

## Rules for code

| Rule | Why |
|---|---|
| Change a subscription only through `CommercialSubscriptions`: `startTrial`, `start`, `extend`, `convert`, `enterGrace`, `reactivate`, `expire`, `cancel`, `changePlan`, `settle` | Operator guard, reason, the tenant lock, the projection, both audit chains, the domain event. Model guards refuse edits and deletes |
| Never call `EntitlementConfiguration::assignPlan` / `endPlanAssignment` for a subscription-managed tenant | They refuse it ("managed by its subscription"): one writer. Only `projectSubscription()` writes those rows |
| Read the commercial state through `SubscriptionTimeline` (`stateOn`, `lapse`, `cancelledFrom`, `tailFrom`). Never read `subscription_periods` rows and guess | The state on a day may be *derived* (expired after a lapse that the scheduler has not recorded yet) |
| The entitlement domain never references `App\Domain\Subscriptions`. Nothing that authorises references either domain | Architecture test `keeps the entitlement engine independent of subscriptions` |
| Never change `tenants.status` from commercial code, and never change a subscription from technical lifecycle code | ADR-0040 |
| No price, payment, invoice or billing term in `Domain/Subscriptions` | Architecture test (SaaS.5 price boundary, extended) |
| Notify through the `CommercialStatusChanged` event (dispatched after commit). There are no listeners yet, and no mail | ADR-0041: the notification boundary |

## Locks

| Change | Serialised on |
|---|---|
| Every subscription change, the settlement, and manual plan assignment | The tenant's `tenant_entitlement_profiles` row (`FOR UPDATE`), via `TenantCommercialLock::run()`; the tenant's entitlement cache is forgotten after commit |
| Plan version used by a new or changed period | Shared lock on the version (as in SaaS.4) |
| Backstop | Unique `(subscription_id, live_from)`; `live_from` is a generated column, null for voided rows |

## Transitions

A transition is legal according to the state in force on its effective date (today or later):

| From | To | Operation |
|---|---|---|
| nothing (or after a cancellation) | trial / active | `startTrial` / `start` |
| trial | active | `convert` |
| active | grace | `enterGrace` |
| grace, expired (recorded or derived) | active | `reactivate` |
| trial, active, grace | expired | `expire` (operator) or `settle` (scheduler; effective the day after the recorded end) |
| anything but cancelled | cancelled | `cancel` (terminal) |
| trial, active, grace | same, later end | `extend` |
| trial, active, grace | same, another version | `changePlan` |

**Expired is derived from dates.** It applies from the day after an entitled period's explicit end, until the next period starts or for good. The settlement records only the trailing lapse; a gap closed by a later change stays derived. **Painting.** A change ends the period in force on the day before its date, and voids the periods that would have started on or after it. **Idempotency.** A change whose resulting tail equals the existing one writes nothing and audits nothing.

## Tests

| Kind | Where |
|---|---|
| Feature | `tests/Feature/Subscriptions/`: lifecycle and transitions, trials and settlement, entitlement projection and legacy safety, security and isolation |
| MySQL races | `tests/MySql/SubscriptionConcurrencyTest.php` (7 races). After each race it checks that live periods don't overlap, projections equal entitled periods, and both chains verify |
| Helpers | `publishedPlan()` from `tests/Feature/Entitlements/PlanTestHelpers.php`; plans must be published on or before the trial start (travel back first) |

Test gotchas:
- `provisionTenant()` takes no attributes. Set legacy fields with `forceFill([...])->save()`.
- A subscription audit event exists on both chains: query `AuditEvent::withoutTenancy()` and tell them apart by `tenant_id` (null = platform chain, with `metadata.subject_tenant_id`).
- In browser checks, Filament date pickers are set through Livewire, with `$wire.set('mountedActions.0.data.<field>', 'YYYY-MM-DD')` on the page component.
