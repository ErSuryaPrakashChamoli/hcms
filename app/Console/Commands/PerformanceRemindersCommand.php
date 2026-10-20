<?php

namespace App\Console\Commands;

use App\Domain\Performance\Jobs\SendPerformanceReminders;
use App\Domain\Performance\Services\PerformanceReminders;
use App\Domain\Platform\Models\Tenant;
use App\Support\Tenancy\TenantContext;
use App\Support\Tenancy\TenantRunner;
use Illuminate\Console\Command;

class PerformanceRemindersCommand extends Command
{
    protected $signature = 'peopleos:performance:reminders {--tenant=} {--queue : Dispatch one tenant-bound job per tenant instead of running inline}';

    protected $description = 'Send performance reminders (reviews due, check-ins awaiting a manager, PIP checkpoints) at most once per subject per day';

    public function handle(PerformanceReminders $reminders, TenantContext $tenants): int
    {
        $runner = TenantRunner::for($this);
        Tenant::query()
            ->when($this->option('tenant'), fn ($q, $t) => $q->where('slug', $t)->orWhere('id', $t))
            ->orderBy('id')
            ->each($runner->isolate(function (Tenant $tenant) use ($reminders, $tenants) {
                $tenants->runAs($tenant, function () use ($reminders, $tenant) {
                    if ($this->option('queue')) {
                        SendPerformanceReminders::dispatch();
                        $this->info("{$tenant->slug}: queued");

                        return;
                    }
                    $r = $reminders->tick();
                    $this->info("{$tenant->slug}: {$r['reviews']} review, {$r['check_ins']} check-in, {$r['checkpoints']} checkpoint reminder(s)");
                });
            }));

        return $runner->exitCode();
    }
}
