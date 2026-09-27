# Queue, scheduler and worker requirements

Verified in Phase 0.2 (27 September 2026).

## What is queued

| Work | How | Notes |
|---|---|---|
| Filament in-app notifications (`Notification::make()->sendToDatabase()`) | `Filament\Notifications\DatabaseNotification` queued job | Every in-app notice from the notification engine's `InAppChannel` and from action feedback. **Without a running worker the bell never fills.** Ten such jobs from 26 September were found pending in the development database, which is how this requirement was discovered. |
| Workflow webhook node | `App\Domain\Workflow\Jobs\SendWebhook` (`tries = 3`) | Captures `tenantId` at dispatch and re-binds it in the worker through `BindTenantContext` (fixed in Phase 0.2: before, the action log written by the worker would have thrown `MissingTenantException`). |
| Everything else | synchronous, inside the request or the scheduled command | Attendance processing, leave accrual, payroll calculation/finalisation, report runs and exports, warehouse export, learning tick, SCIM operations, notification fan-out (email through the mailer). Moving these to jobs is infrastructure roadmap work, not a Phase 0.2 change. |

Failed jobs land in `failed_jobs` (0 at verification). Retries: `SendWebhook` 3 tries; notification jobs use the default (1). Outbound enterprise webhooks (`peopleos:webhooks:deliver`) are not queue jobs: deliveries are rows with exponential backoff, attempted by the scheduler every minute.

## Configuration

| Setting | Development | Tests | Production requirement |
|---|---|---|---|
| `QUEUE_CONNECTION` | `database` | `sync` (phpunit.xml) | `redis` (Horizon) or `database` with supervised workers |
| `CACHE_STORE` | `database` | `array` | `redis` |
| `SESSION_DRIVER` | `database` | `array` | `redis` or `database` |

Business code depends only on the `ShouldQueue` contract and job middleware; nothing is coupled to the database driver.

## Operational requirements

1. **At least one queue worker must run in every environment where in-app notifications or workflow webhooks are expected**, including development:
   ```bash
   php artisan queue:work --tries=3 --timeout=120
   ```
   In production run workers under a supervisor (systemd or Supervisor), one process per CPU-bound unit, restarted on deploy (`php artisan queue:restart`). With Redis, install Horizon and run `php artisan horizon` instead.
2. **The scheduler must run every minute** on exactly one node:
   ```
   * * * * * cd /path/to/peopleos && php artisan schedule:run >> /dev/null 2>&1
   ```
   Thirteen `peopleos:*` commands depend on it (see `routes/console.php`). Before running on more than one node, add `withoutOverlapping()` and `onOneServer()` to the schedule entries (recorded as TD-08 in the Phase 0.1 report).
3. **Failed jobs**: monitor `php artisan queue:failed`; retry with `queue:retry`. Alerting is part of the observability roadmap.
4. **Tenant safety in jobs**: any new job that reads or writes tenant-owned models must follow the `SendWebhook` pattern (`public ?int $tenantId`, `middleware(): [new BindTenantContext]`). The architecture test suite does not yet enforce this mechanically; reviewers must.

## Production topology (target)

```
Web / API (php-fpm)  ──dispatch──►  Redis queue  ──►  Horizon workers  ──►  Job (tenant re-bound)  ──►  Audit / result
Scheduler (cron, one node) ──► peopleos:* commands (iterate tenants with runAs)
```
