<?php

namespace App\Console\Commands;

use App\Domain\Payroll\Services\PayrollRunCockpit;
use App\Domain\Platform\Models\Tenant;
use App\Domain\Platform\Services\SettingsRepository;
use App\Support\Tenancy\TenantContext;
use App\Support\Tenancy\TenantRunner;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * HROne parity PO-4 (matrix 25 §25.6.1 #6, §25.6.10 #1, "Schedule payroll … on"): on `payroll.auto_calculate_day` (0 = never,
 * the default) recalculate, in the background, every payroll run that is still open (draft, calculated or validated) —
 * never an approved, finalized or paid one; nothing is validated, approved or finalized and no run is opened. Once per
 * tenant and month (scheduler claims), so a second run of the command on the same day does nothing.
 */
class PayrollAutoCalculate extends Command
{
    protected $signature = 'peopleos:payroll:auto-calculate {--date=} {--tenant=}';

    protected $description = 'Recalculate open payroll runs on the day of the month set in payroll.auto_calculate_day';

    public function handle(TenantContext $tenants): int
    {
        $runner = TenantRunner::for($this);
        $today = Carbon::parse($this->option('date') ?? now())->startOfDay();

        Tenant::query()
            ->when($this->option('tenant'), fn ($q, $t) => $q->where('slug', $t)->orWhere('id', $t))
            ->orderBy('id')
            ->each($runner->isolate(fn (Tenant $tenant) => $tenants->runAs($tenant, function () use ($tenant, $today) {
                $day = (int) app(SettingsRepository::class)->get('payroll.auto_calculate_day', 0);
                if (! PayrollRunCockpit::isAutoCalculateDay($day, $today) || ! TenantRunner::claim('payroll.auto_calculate', $today->format('Y-m'))) {
                    $this->info("{$tenant->slug}: not the payroll calculation day");

                    return;
                }
                $this->info("{$tenant->slug}: ".app(PayrollRunCockpit::class)->autoCalculate($today).' open payroll run(s) queued for recalculation');
            })));

        return $runner->exitCode();
    }
}
