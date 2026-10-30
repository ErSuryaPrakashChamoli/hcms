<?php

namespace App\Domain\Onboarding\Jobs;

use App\Domain\Employment\Models\Employee;
use App\Domain\Onboarding\Models\OnboardingTemplate;
use App\Domain\Onboarding\Services\Onboarding;
use App\Support\Tenancy\Jobs\BindTenantContext;
use App\Support\Tenancy\Jobs\TenantAwareJob;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * HROne setup parity P13 ("apply this change to all employees"): issue onboarding template items to the current
 * employees chosen when the job was queued (Onboarding::issueItems; items they already have since the last change are
 * left out, so a retry issues nothing twice).
 */
class IssueOnboardingItems implements ShouldQueue, TenantAwareJob
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 600;

    /** @var list<int> retry with backoff instead of hammering a failing dependency. */
    public array $backoff = [60, 300];

    public ?int $tenantId;

    /**
     * @param  list<int>  $itemIds
     * @param  list<int>  $employeeIds
     */
    public function __construct(public readonly int $templateId, public readonly array $itemIds, public readonly array $employeeIds, public readonly ?string $reason = null)
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

    public function handle(Onboarding $onboarding): void
    {
        $template = OnboardingTemplate::query()->find($this->templateId);
        if ($template === null) {
            return;
        }
        $onboarding->issueItems($template, $this->itemIds, Employee::query()->with('person')->whereIn('id', $this->employeeIds)->orderBy('id')->lazy(), $this->reason);
    }
}
