# Queue, scheduler and worker requirements

Phase 14 revision (3 October 2026). This replaces the Phase 0.2 note. The rules below are enforced by
`tests/Feature/Platform/OperationsHardeningTest.php` and the architecture tests. A new job or schedule
entry that breaks them fails the build.

## Rules every job follows

| Rule | Enforcement |
|---|---|
| Implements `TenantAwareJob`, captures the tenant at dispatch, and returns `[new BindTenantContext]` from `middleware()` | Architecture test (ADR-0013) |
| A tenant-aware job without a tenant **fails**; it never runs unscoped | `BindTenantContext` (Phase 14) |
| A job for a **suspended** tenant is skipped and logged (`Queued job skipped: tenant suspended`) | `BindTenantContext` |
| Per-user organisation-scope caches are cleared before and after each job (long-running workers) | `BindTenantContext` |
| Declares `$tries` and `$timeout`; jobs with more than one try declare `$backoff` | OperationsHardeningTest |
| `$timeout` is lower than every queue connection's `retry_after` (default **3900 s**, above the 3600 s payroll calculation), so a running job is never handed to a second worker | OperationsHardeningTest + `config/queue.php` |
| Work that must happen once is claimed (conditional update or unique key) inside the job, so a duplicate or retried job does nothing | Per domain (payroll run lock, inbound event lease, delivery claim, reminder logs) |
| Failed jobs are logged (`Queue job failed`: job, connection, queue, attempts, exception class) through the redacted log channels | `AppServiceProvider::registerObservability` |

## Job inventory

| Job | Tries / timeout / backoff | Purpose |
|---|---|---|
| `Payroll\Jobs\CalculatePayrollRun` | 2 / 3600 s / 60 | Calculate a payroll run (unique per run) |
| `Compliance\Jobs\StatutoryReturnJob` | 2 / 1800 s / 60 | Build a statutory return (unique) |
| `Notifications\Jobs\DeliverNotification` | 3 / 60 s / 60, 300 | **Phase 14.** One email / SMS / push delivery, dispatched after commit, claimed queued → sending |
| `Integration\Jobs\ProcessInboundEvent` | 1 / 120 s | Apply one inbound integration event (leased claim; the scheduler retries) |
| `Workflow\Jobs\SendWebhook` | 3 / 300 s / 60, 300 | Workflow webhook node |
| `Communication\Jobs\ProcessCommunication`, `DeliverCommunication` | 2–3 / 300 s | Announcement publishing and recipient delivery (idempotent recipients) |
| `Engagement\Jobs\ProcessEngagement`, `SendSurveyInvitations` | 2–3 / 300 s | Survey lifecycle and invitations (idempotent log) |
| `Attendance\Jobs\ProcessAttendanceDay` | 3 / 300 s | Process one attendance day |
| `Learning\Jobs\GenerateCertificateDocument`, `SendLearningReminders` | 2–3 / 300 s | Certificates and reminders |
| `Performance`, `Talent`, `Workforce`, `Compensation` reminder jobs, `EffectDueCompensation`, `ServiceDesk\Jobs\ProcessServiceDesk` | 2 / 300 s / 60 | Per-tenant `--queue` variants of the scheduled sweeps (unique per tenant) |

In-app notifications are **no longer queued**. Phase 14 stores them at once (`notifyNow`), so a
delivery marked `sent` is really in the bell. Email and the other external channels go through
`DeliverNotification` after the business transaction commits.

## Scheduler inventory

Every entry runs `withoutOverlapping()->onOneServer()`. Each command that iterates tenants goes through
`App\Support\Tenancy\TenantRunner`:

- **Suspended tenants are skipped.** There are two exceptions. Retention purge runs because purging is an obligation. Subscription settlement (SaaS.6) runs because commercial dates do not stop for a technical suspension, and it never changes the tenant's status.
- **One tenant's failure is isolated.** It is reported and logged with the tenant id and exception class, the
  next tenant still runs, and the command exits non-zero.
- **Every run gets a correlation id** (`request_id` in Context), carried by its logs, audit rows and notifications.

| Command | Frequency | Idempotency | Purpose |
|---|---|---|---|
| `peopleos:scheduler:heartbeat` | every minute | overwrite | Heartbeat read by `/health/ready` and `peopleos:readiness` |
| `peopleos:configuration:publish-due` | daily 00:05 | per change status | Publish approved configuration changes whose date arrived |
| `peopleos:subscriptions:settle` | daily 00:15 | tail comparison under the tenant's commercial lock (a second run writes nothing) | **SaaS.6.** Record the expiry of subscriptions whose explicit trial, term or grace end has passed with no successor, effective the day after the end however late it runs (runs for suspended tenants too) |
| `peopleos:compensation:effect` | daily 00:20 | per change lock | Make scheduled compensation changes / structure versions effective |
| `peopleos:leave:accrue` | daily 01:00 | ledger keys | Post leave accruals; close leave years |
| `peopleos:attendance:process` | daily 02:00 | recompute (deterministic) | Compute attendance records |
| `peopleos:learning:tick` | daily 03:00 | per enrolment state | Rule assignments, overdue, certificate expiry |
| `peopleos:retention:purge` | daily 03:30 | delete-by-age | Purge operational logs past retention (runs for suspended tenants too) |
| `peopleos:exit:tick` | daily 04:00 | per exit state | Start clearance inside the lead window |
| `peopleos:warehouse:export` | daily 05:00 | overwrite per day | Warehouse feed (non-sensitive fields unless enabled) |
| `peopleos:lifecycle:reminders` | daily 06:00 | **`scheduler_claims` per tenant per day (Phase 14)**; `--force` to re-run | Joining, probation and document-expiry reminder events |
| `peopleos:performance:reminders` | daily 07:00 | reminder log | Performance reminders |
| `peopleos:learning:send-reminders` | daily 07:30 | reminder log | Learning reminders |
| `peopleos:talent:send-reminders` | daily 07:45 | reminder log | Talent / succession reminders |
| `peopleos:workforce:send-reminders` | daily 08:00 | reminder log | Workforce reminders |
| `peopleos:compensation:send-reminders` | daily 08:15 | reminder log | Compensation reminders |
| `peopleos:compliance:sync` | weekly Mon 00:30 | upsert by rule key | Load statutory rule packs (rules stay unverified; see statutory readiness) |
| `peopleos:workflows:tick` | every 5 min | per instance state | Resume waiting workflows, escalate tasks |
| `peopleos:communication:process` | every 5 min | recipient rows | Publish / deliver announcements |
| `peopleos:engagement:process` | every 15 min | reminder log | Survey open / close, invitations, campaigns |
| `peopleos:service-desk:process` | hourly | reminder log | SLA warnings / escalations, auto-close, catalogue promotion |
| `peopleos:reports:run-due` | hourly | **conditional claim of `next_run_at` (Phase 14)**; runs as the report owner | Scheduled report exports |
| `peopleos:integrations:process` | every minute | leased claim | Apply due inbound integration events, retries, expired leases |
| `peopleos:webhooks:deliver` | every minute | leased claim | Outbound webhook deliveries, retries, dead letters |

## Configuration

| Setting | Development | Tests | Production requirement |
|---|---|---|---|
| `QUEUE_CONNECTION` | `database` | `sync` (phpunit.xml) | `redis` (Horizon) or `database` with supervised workers |
| `DB_QUEUE_RETRY_AFTER` / `REDIS_QUEUE_RETRY_AFTER` | 3900 | — | ≥ 3900 (longest job timeout + margin) |
| `CACHE_STORE` | `database` | `array` | `redis` or `database` (the scheduler heartbeat lives in the cache) |
| `SESSION_DRIVER` | `database` | `array` | `redis` or `database` |

## Operational requirements

1. **Workers.** At least one worker per environment where email, webhooks, integrations or queued sweeps are expected:
   ```bash
   php artisan queue:work --sleep=3 --tries=3 --timeout=3700 --memory=512 --max-time=3600
   ```
   Production readiness closure, the relationship that prevents a running job from being picked up
   by a second worker:

   | Value | Setting | Rule |
   |---|---|---|
   | Job timeout | `$timeout` per job (60–3600 s) | The worker kills a job that exceeds it |
   | Worker `--timeout` | 3700 s | Above the longest job timeout |
   | `retry_after` | 3900 s (`DB_QUEUE_RETRY_AFTER` / `REDIS_QUEUE_RETRY_AFTER`) | Above the worker timeout, so a job is re-delivered only after its worker has given up |
   | Backoff | `$backoff` per retried job (60 / 300 / 900 s) | Retries are spaced |
   | `--memory` / `--max-time` | 512 MB / 3600 s | The worker restarts cleanly (the supervisor brings it back) |

   Run workers under a supervisor (systemd / Supervisor, `autorestart=true`, `stopwaitsecs=3720`) and
   restart them on deploy (`php artisan queue:restart`). With Redis, use Horizon with the same limits.
   `peopleos:config:validate` refuses `retry_after` ≤ 3600; work that must happen once is claimed
   inside the job, so a duplicate delivery does nothing.
2. **Scheduler.** It runs every minute; `onOneServer()` requires a shared cache (database or Redis) when more than one node runs cron:
   ```
   * * * * * cd /path/to/peopleos && php artisan schedule:run >> /dev/null 2>&1
   ```
   `/health/ready` reports `scheduler: warn` when the heartbeat is older than `PEOPLEOS_HEARTBEAT_MAX_AGE` (180 s).
3. **Failed jobs.** `/health/ready` and `peopleos:readiness` warn on any failed job. Inspect them with `php artisan queue:failed` and retry with `queue:retry` once the cause is fixed.
4. **Dead letters.** Inbound integration events and webhook deliveries that exhaust their retries become `dead_letter` (audited). Reprocess them or replay from the Integration Hub screens.

## Production topology (target)

```
Web / API (php-fpm) ──dispatch (after commit)──► Redis / DB queue ──► supervised workers ──► Job (tenant re-bound, scopes reset) ──► Audit / result
Scheduler (cron, one node + shared cache lock) ──► peopleos:* commands ──► TenantRunner (active tenants, isolated failures)
Probes: /health/live (process)  /health/ready (database, cache, storage critical; queue, failed jobs, scheduler, dead letters warn)
```
