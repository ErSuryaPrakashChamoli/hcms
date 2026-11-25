<?php

namespace App\Domain\Employment\Jobs;

use App\Domain\Employment\Requirements\EmploymentRequirementPreflight;
use App\Domain\Employment\Requirements\EmploymentRequirementsPreflight;
use App\Domain\Identity\Models\User;
use App\Domain\Platform\Models\Tenant;
use App\Support\Tenancy\Jobs\BindTenantContext;
use App\Support\Tenancy\Jobs\TenantAwareJob;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Auth;
use Throwable;

/**
 * Remediation I.13: a large organisation's employment data requirements pre-flight, outside any web request, in the
 * tenant it was asked in and as the person who asked (examples name only people they may see; counts read everyone).
 * EmploymentRequirementsPreflight::execute() claims the run, so a duplicate delivery or a retry of a finished run does
 * nothing; a failed attempt goes back to pending and is retried, the last one marks the run failed.
 */
class RunEmploymentRequirementsPreflight implements ShouldQueue, TenantAwareJob
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 3600;

    /** @var list<int> */
    public array $backoff = [30, 120];

    public ?int $tenantId;

    public function __construct(public readonly int $runId)
    {
        $this->tenantId = app(TenantContext::class)->id();
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

    public function handle(EmploymentRequirementsPreflight $preflight): void
    {
        $run = EmploymentRequirementPreflight::query()->find($this->runId);
        if ($run === null || ! $run->isOpen()) {
            return;
        }
        $requester = User::query()->find($run->requested_by);
        if ($requester === null || ! $requester->can('policy.view')) {
            $run->update(['status' => EmploymentRequirementPreflight::CANCELLED, 'finished_at' => now(), 'error' => 'The person who asked for it may no longer view policies.']);

            return;
        }
        $previous = Auth::user();
        Auth::setUser($requester);
        try {
            $preflight->execute($run, final: $this->attempts() >= $this->tries);
        } finally {
            $previous !== null ? Auth::setUser($previous) : Auth::forgetUser();
        }
    }

    public function failed(?Throwable $e): void
    {
        $tenant = $this->tenantId === null ? null : Tenant::query()->find($this->tenantId);
        if ($tenant === null) {
            return;
        }
        app(TenantContext::class)->runAs($tenant, fn () => EmploymentRequirementPreflight::query()->whereKey($this->runId)
            ->whereIn('status', EmploymentRequirementPreflight::OPEN)
            ->update(['status' => EmploymentRequirementPreflight::FAILED, 'finished_at' => now(), 'error' => mb_substr('The pre-flight stopped: '.($e?->getMessage() ?? 'unknown error'), 0, 1000)]));
    }
}
