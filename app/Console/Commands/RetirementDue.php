<?php

namespace App\Console\Commands;

use App\Domain\Configuration\Services\PolicyResolver;
use App\Domain\Employment\Events\EmploymentEvent;
use App\Domain\Employment\Models\Employee;
use App\Domain\Exit\Models\ExitCase;
use App\Domain\Exit\Services\RetirementDates;
use App\Domain\Platform\Models\Tenant;
use App\Support\Tenancy\TenantContext;
use App\Support\Tenancy\TenantRunner;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * HROne setup parity (Separation policy → retirement age): raise `employee.retirement_due` once for each employee whose
 * retirement date (the birthday at the policy's age, or the end of that month) falls within the next N days and who
 * has no open exit. HR then starts the retirement exit; nothing is exited automatically.
 */
class RetirementDue extends Command
{
    protected $signature = 'peopleos:lifecycle:retirement-due {--days=60 : How far ahead to look} {--date=} {--tenant=}';

    protected $description = 'Raise retirement-due events from the separation policy retirement age';

    public function handle(TenantContext $tenants, PolicyResolver $policies): int
    {
        $runner = TenantRunner::for($this);
        $today = Carbon::parse($this->option('date') ?? now())->startOfDay();
        $horizon = $today->copy()->addDays((int) $this->option('days'));

        Tenant::query()
            ->when($this->option('tenant'), fn ($q, $t) => $q->where('slug', $t)->orWhere('id', $t))
            ->orderBy('id')
            ->each($runner->isolate(fn (Tenant $tenant) => $tenants->runAs($tenant, function () use ($tenant, $today, $horizon) {
                $raised = 0;
                Employee::query()->employed()->with('person')->whereHas('person', fn ($q) => $q->whereNotNull('date_of_birth'))
                    ->each(function (Employee $employee) use ($today, $horizon, &$raised) {
                        // The retirement date (birthday at the policy's age, or the end of that month): RetirementDates.
                        $retirement = app(RetirementDates::class)->for($employee, $today);
                        if ($retirement === null) {
                            return;
                        }
                        ['date' => $retires, 'age' => $age] = $retirement;
                        if ($retires->gt($horizon) || ($employee->metadata['retirement_alerted_for'] ?? null) === $retires->toDateString()
                            || ExitCase::query()->where('employee_id', $employee->id)->whereIn('status', ExitCase::OPEN)->exists()) {
                            return;
                        }
                        EmploymentEvent::dispatch('employee.retirement_due', $employee, $employee, ['retires_on' => $retires->toDateString(), 'age' => $age]);
                        $employee->withoutAuditing()->update(['metadata' => ['retirement_alerted_for' => $retires->toDateString()] + (array) ($employee->metadata ?? [])]);
                        $raised++;
                    });
                $this->info("{$tenant->slug}: {$raised} retirement(s) due");
            })));

        return $runner->exitCode();
    }
}
