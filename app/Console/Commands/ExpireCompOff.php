<?php

namespace App\Console\Commands;

use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Leave\Models\LeaveLedgerEntry;
use App\Domain\Leave\Services\CompOffCredits;
use App\Domain\Platform\Models\Tenant;
use App\Support\Tenancy\TenantContext;
use App\Support\Tenancy\TenantRunner;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/** HROne parity HP-1: lapse comp-off credits whose validity ended (first in, first out). Idempotent per claim. */
class ExpireCompOff extends Command
{
    protected $signature = 'peopleos:leave:expire-comp-off {--date= : Expire as of this date (default today)} {--tenant=}';

    protected $description = 'Lapse unused comp-off credits past their validity';

    public function handle(CompOffCredits $credits, TenantContext $tenants): int
    {
        $runner = TenantRunner::for($this);
        $today = Carbon::parse($this->option('date') ?? now())->startOfDay();

        Tenant::query()
            ->when($this->option('tenant'), fn ($q, $t) => $q->where('slug', $t)->orWhere('id', $t))
            ->orderBy('id')
            ->each($runner->isolate(function (Tenant $tenant) use ($credits, $tenants, $today) {
                $tenants->runAs($tenant, function () use ($credits, $tenant, $today) {
                    $lapsed = 0;
                    app(AuditRecorder::class)->operation('leave', 'Comp-off expiry '.$today->toDateString(), function () use ($credits, $today, &$lapsed) {
                        $lapsed = $credits->expire($today);

                        return ['succeeded' => $lapsed];
                    }, entityType: LeaveLedgerEntry::class);

                    $this->info("{$tenant->slug}: {$lapsed} comp-off credit(s) lapsed");
                });
            }));

        return $runner->exitCode();
    }
}
