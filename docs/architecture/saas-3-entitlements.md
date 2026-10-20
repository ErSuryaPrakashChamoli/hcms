# SaaS.3 — Commercial entitlements (shadow mode): the developer's map

Full report: `docs/saas/SaaS-3-Entitlement-Architecture-and-Shadow-Mode-Report.md`. Catalogue: `docs/saas/SaaS-3-Capability-Catalog.md`. Model: `docs/saas/SaaS-3-Entitlement-Model.md`.

**SaaS.4** added commercial plans as a layer of this engine: tenant configuration → **plan** → configured default. See `saas-4-plans.md`.

## Observing a capability from HCM code

```php
use App\Domain\Entitlements\Enums\Capability;
use App\Domain\Entitlements\Services\Entitlements;

app(Entitlements::class)->observe(Capability::Payroll, 'payroll.run.calculate'); // first line of the business action
```

| Rule | Why |
|---|---|
| Use the `Capability` enum, never a string (tested) | Stable keys; the catalogue stays complete |
| One call per business action, not per UI element | Surfaces stay low-cardinality and meaningful |
| Never read the result to refuse anything | SaaS.3 is shadow mode; there is no enforcing path (`Decision::enforced()` is false) |
| Never put an entitlement into a permission, policy, gate, scope or navigation check | Authorisation and entitlement stay independent |
| A limit: `observeLimit(Capability::X, 'surface', fn () => $usage)` | The usage closure runs only when a finite limit applies today |
| A new capability | Add an enum case with its type, module, enforcement class, unit, and permission prefixes or scopes; `CapabilityCatalogTest` checks the mapping |

## Changing configuration (platform operators only)

`EntitlementConfiguration::configure | set | end | grantOverride | revokeOverride`:
- every method needs a platform operator and a reason;
- changes start today or later;
- every change is audited on both chains;
- the cache is forgotten after commit.

There is no other write path. Do not write the four tables directly.

## Reading

| Need | Use |
|---|---|
| The bound tenant's decision (HCM) | `Entitlements::evaluate($capability, $date = today, $usage = null)` |
| Any tenant (operators, console) | `Entitlements::evaluateFor($tenantId, …)`, `EntitlementDiagnostics::explain/catalog/shadowSummary`, `peopleos:entitlements:explain`, `peopleos:entitlements:shadow-report` |

## Bindings and lifetimes

| Service | Binding | Lifetime and notes |
|---|---|---|
| `EntitlementStateStore` | Request-scoped, also in `EXPERIENCE_SCOPED` | Forgotten after every handled request and per queued job. Resolve it at call time, never hold it |
| `ShadowRecorder` | Request-scoped | Its flush is deferred (`defer`, `always`); tests call `flush()` directly |

## Configuration (`config/peopleos.php` → `entitlements`)

| Key | Values |
|---|---|
| `mode` | `shadow` (default) or `off` |
| `cache_seconds` | 600 |
| `shadow.window_seconds` | 600 |
| `shadow.retention_days` | 90 |

## Tests

| Kind | Where |
|---|---|
| Feature | `tests/Feature/Entitlements/*` (engine, catalogue, shadow, payroll safety, security and isolation, performance, operations) |
| MySQL races | `tests/MySql/EntitlementConcurrencyTest.php`, using the database cache store so invalidation and the shadow gate are shared between processes |
| Queue isolation | `Tests\Support\EntitlementProbeJob` |

Test gotchas:
- In a non-HTTP test, deferred flushes do not run on their own; call `app(ShadowRecorder::class)->flush()`.
- A test's setup may itself be observed (a hire observes the employee limit); filter observations by surface.
