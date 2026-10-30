<?php

namespace App\Console\Commands;

use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Expense\Models\ExpenseReceipt;
use App\Domain\Expense\Services\ExpenseReceipts;
use App\Domain\Platform\Models\Tenant;
use App\Support\Tenancy\TenantContext;
use App\Support\Tenancy\TenantRunner;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * HROne setup parity EX-C (Expense → Settings → expense expiry period): unreported expenses older than the tenant's
 * expiry period become expired and can no longer be reported. Idempotent: an expired expense is never touched again;
 * tenants with no expiry period are skipped.
 */
class ExpireExpenseReceipts extends Command
{
    protected $signature = 'peopleos:expense:expire {--date= : Expire as of this date (default today)} {--tenant=}';

    protected $description = 'Expire unreported expense receipts past the expense expiry period';

    public function handle(ExpenseReceipts $receipts, TenantContext $tenants): int
    {
        $runner = TenantRunner::for($this);
        $today = Carbon::parse($this->option('date') ?? now())->startOfDay();

        Tenant::query()
            ->when($this->option('tenant'), fn ($q, $t) => $q->where('slug', $t)->orWhere('id', $t))
            ->orderBy('id')
            ->each($runner->isolate(function (Tenant $tenant) use ($receipts, $tenants, $today) {
                $tenants->runAs($tenant, function () use ($receipts, $tenant, $today) {
                    $expired = 0;
                    app(AuditRecorder::class)->operation('expense', 'Expense expiry '.$today->toDateString(), function () use ($receipts, $today, &$expired) {
                        $expired = $receipts->expireDue($today);

                        return ['succeeded' => $expired];
                    }, entityType: ExpenseReceipt::class);

                    $this->info("{$tenant->slug}: {$expired} expense(s) expired");
                });
            }));

        return $runner->exitCode();
    }
}
