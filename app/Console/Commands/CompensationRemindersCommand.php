<?php

namespace App\Console\Commands;

use App\Domain\Compensation\Jobs\SendCompensationReminders;
use App\Domain\Compensation\Services\CompensationReminders;
use App\Domain\Platform\Models\Tenant;
use App\Support\Tenancy\TenantContext;
use App\Support\Tenancy\TenantRunner;
use Illuminate\Console\Command;

class CompensationRemindersCommand extends Command
{
    protected $signature = 'peopleos:compensation:send-reminders {--tenant=} {--queue : Dispatch one tenant-bound job per tenant instead of running inline}';

    protected $description = 'Remind reviewers, approvers and executors of compensation changes and cycles waiting for them (throttled)';

    public function handle(CompensationReminders $reminders, TenantContext $tenants): int
    {
        $runner = TenantRunner::for($this);
        Tenant::query()
            ->when($this->option('tenant'), fn ($q, $t) => $q->where('slug', $t)->orWhere('id', $t))
            ->orderBy('id')
            ->each($runner->isolate(function (Tenant $tenant) use ($reminders, $tenants) {
                $tenants->runAs($tenant, function () use ($reminders, $tenant) {
                    if ($this->option('queue')) {
                        SendCompensationReminders::dispatch();
                        $this->info("{$tenant->slug}: queued");

                        return;
                    }
                    $r = $reminders->tick();
                    $this->info("{$tenant->slug}: {$r['changes']} change and {$r['cycles']} cycle reminder(s)");
                });
            }));

        return $runner->exitCode();
    }
}
