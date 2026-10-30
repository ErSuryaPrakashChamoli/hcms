<?php

namespace App\Console\Commands;

use App\Domain\Claims\Services\ReimbursementClaims;
use App\Domain\Platform\Models\Tenant;
use App\Support\Tenancy\TenantContext;
use App\Support\Tenancy\TenantRunner;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * HROne setup parity BP-9 ("assign a task to employee to claim reimbursement"): in each reminder month of
 * `payroll.claims.reminder_task` (every month, the last month of each quarter or of the financial year; off by default),
 * remind employees with a pay-on-claim balance left to claim, once per tenant and month (scheduler claims).
 */
class PayrollClaimReminders extends Command
{
    protected $signature = 'peopleos:payroll:claim-reminders {--date=} {--tenant=}';

    protected $description = 'Remind employees to claim their reimbursement balance (payroll.claims.reminder_task)';

    public function handle(TenantContext $tenants): int
    {
        $runner = TenantRunner::for($this);
        $today = Carbon::parse($this->option('date') ?? now())->startOfDay();

        Tenant::query()
            ->when($this->option('tenant'), fn ($q, $t) => $q->where('slug', $t)->orWhere('id', $t))
            ->orderBy('id')
            ->each($runner->isolate(fn (Tenant $tenant) => $tenants->runAs($tenant, function () use ($tenant, $today) {
                $claims = app(ReimbursementClaims::class);
                $period = $claims->reminderPeriod($today);
                if ($period === null || ! TenantRunner::claim('payroll.claim_reminders', $period)) {
                    $this->info("{$tenant->slug}: no claim reminders due");

                    return;
                }
                $this->info("{$tenant->slug}: ".$claims->remind($today).' employee(s) reminded to claim');
            })));

        return $runner->exitCode();
    }
}
