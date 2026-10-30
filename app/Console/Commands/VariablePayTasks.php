<?php

namespace App\Console\Commands;

use App\Domain\Payroll\Services\VariablePayPlans;
use App\Domain\Platform\Models\Tenant;
use App\Domain\Platform\Services\SettingsRepository;
use App\Support\Tenancy\TenantContext;
use App\Support\Tenancy\TenantRunner;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * HROne setup parity BP-9 ("On what day of the month do you want to assign the variable payment task"): on
 * `payroll.variable.task_day` (0 = never, the default), ask the managers of employees with a variable pay plan due this
 * month for the payout, once per plan and month (scheduler claims). The payout they enter becomes a variable pay request.
 */
class VariablePayTasks extends Command
{
    protected $signature = 'peopleos:payroll:variable-pay-tasks {--date=} {--tenant=}';

    protected $description = 'Ask managers for planned variable pay on the configured day of the month';

    public function handle(TenantContext $tenants): int
    {
        $runner = TenantRunner::for($this);
        $today = Carbon::parse($this->option('date') ?? now())->startOfDay();

        Tenant::query()
            ->when($this->option('tenant'), fn ($q, $t) => $q->where('slug', $t)->orWhere('id', $t))
            ->orderBy('id')
            ->each($runner->isolate(fn (Tenant $tenant) => $tenants->runAs($tenant, function () use ($tenant, $today) {
                $day = (int) app(SettingsRepository::class)->get('payroll.variable.task_day', 0);
                if ($day <= 0 || (int) $today->day !== $day) {
                    $this->info("{$tenant->slug}: not the variable pay task day");

                    return;
                }
                $this->info("{$tenant->slug}: ".app(VariablePayPlans::class)->askManagers($today).' variable pay payout(s) asked for');
            })));

        return $runner->exitCode();
    }
}
