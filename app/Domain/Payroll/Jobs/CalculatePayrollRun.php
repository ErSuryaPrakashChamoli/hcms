<?php

namespace App\Domain\Payroll\Jobs;

use App\Domain\Identity\Models\User;
use App\Domain\Notifications\Services\Notifier;
use App\Domain\Payroll\Models\PayrollRun;
use App\Domain\Payroll\Services\PayrollRuns;
use App\Domain\Platform\Models\Tenant;
use App\Support\Tenancy\Jobs\BindTenantContext;
use App\Support\Tenancy\Jobs\TenantAwareJob;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Queued calculation for large runs (Phase 4 §45). Unique per run, tenant-bound, retry-safe: a recalculation replaces the
 * run's entries inside one transaction under the run lock. A run that can no longer be calculated (approved, finalized or
 * paid by the time the job runs) is left as it is.
 *
 * HROne parity PO-4 (§25.6.1 #3, "Process payroll in background"): with `notify` (the run cockpit's "Calculate in
 * background") the person who asked is told in the app and by e-mail when it is done, when the run could no longer be
 * calculated, and when the calculation failed after its retries (notification event payroll.calculated). Without it the
 * job behaves exactly as before.
 */
class CalculatePayrollRun implements ShouldBeUnique, ShouldQueue, TenantAwareJob
{
    use Queueable;

    public int $tries = 2;

    /** @var list<int> Phase 14: retry with backoff instead of hammering a failing dependency. */
    public array $backoff = [60];

    public int $timeout = 3600;

    public ?int $tenantId;

    public function __construct(public readonly int $runId, public readonly ?int $actorId = null, public readonly bool $notify = false)
    {
        $this->tenantId = app(TenantContext::class)->id();
    }

    public function uniqueId(): string
    {
        return "payroll-run-{$this->tenantId}-{$this->runId}";
    }

    public function tenantId(): ?int
    {
        return $this->tenantId;
    }

    /** @return list<object> */
    public function middleware(): array
    {
        return [new BindTenantContext];
    }

    public function handle(PayrollRuns $runs): void
    {
        $run = PayrollRun::query()->find($this->runId);
        if ($run !== null && $run->isEditable()) {
            $run = $runs->calculate($run, $this->actorId ? User::query()->find($this->actorId) : null);
            $this->tell($run, 'Payroll calculated: ', $run->total('employees').' employee(s) calculated; '.$run->exception_count.' with exceptions.');
        } elseif ($run !== null) {
            $this->tell($run, 'Payroll not calculated: ', "The run is {$run->status} now, so it was not recalculated.");
        }
    }

    /** Retries used up: tell the person who asked (the job runs outside its middleware here, so it binds the tenant itself). */
    public function failed(?Throwable $e): void
    {
        if (! $this->notify || $this->tenantId === null) {
            return;
        }
        $tenant = Tenant::query()->find($this->tenantId);
        if ($tenant === null) {
            return;
        }
        app(TenantContext::class)->runAs($tenant, function () {
            $run = PayrollRun::query()->find($this->runId);
            if ($run !== null) {
                $this->tell($run, 'Payroll calculation failed: ', 'The calculation in the background stopped with an error. Open the run and calculate it again, or ask support to look at the failed job.');
            }
        });
    }

    private function tell(PayrollRun $run, string $title, string $body): void
    {
        if (! $this->notify || $this->actorId === null) {
            return;
        }
        $actor = User::query()->find($this->actorId);
        if ($actor === null) {
            return;
        }
        $run->loadMissing(['period', 'company']);
        rescue(fn () => app(Notifier::class)->send([$actor], ['in_app', 'email'], $title.$run->period?->label().($run->company?->name ? ' · '.$run->company->name : ''), $body, 'payroll.calculated', $run), report: true);
    }
}
