<?php

namespace App\Console\Commands;

use App\Domain\Learning\Services\Learning;
use App\Domain\Platform\Models\Tenant;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;

class TickLearning extends Command
{
    protected $signature = 'peopleos:learning:tick {--tenant=}';

    protected $description = 'Apply rule-based learning assignments, mark overdue enrolments, send due-soon reminders and expire certificates';

    public function handle(Learning $learning, TenantContext $tenants): int
    {
        Tenant::query()
            ->when($this->option('tenant'), fn ($q, $t) => $q->where('slug', $t)->orWhere('id', $t))
            ->orderBy('id')
            ->each(function (Tenant $tenant) use ($learning, $tenants) {
                $tenants->runAs($tenant, function () use ($learning, $tenant) {
                    $r = $learning->tick();
                    $this->info("{$tenant->slug}: {$r['assigned']} assigned, {$r['overdue']} overdue, {$r['due_soon']} due soon, {$r['expiring']} expiring, {$r['expired']} expired");
                });
            });

        return self::SUCCESS;
    }
}
