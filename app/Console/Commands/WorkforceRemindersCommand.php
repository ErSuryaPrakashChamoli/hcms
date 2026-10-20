<?php

namespace App\Console\Commands;

use App\Domain\Platform\Models\Tenant;
use App\Domain\Workforce\Jobs\SendWorkforceReminders;
use App\Domain\Workforce\Services\WorkforceReminders;
use App\Support\Tenancy\TenantContext;
use App\Support\Tenancy\TenantRunner;
use Illuminate\Console\Command;

class WorkforceRemindersCommand extends Command
{
    protected $signature = 'peopleos:workforce:send-reminders {--tenant=} {--queue : Dispatch one tenant-bound job per tenant instead of running inline}';

    protected $description = 'Send workforce reminders (plans awaiting approval, plans ending, long vacancies) with throttling';

    public function handle(WorkforceReminders $reminders, TenantContext $tenants): int
    {
        $runner = TenantRunner::for($this);
        Tenant::query()
            ->when($this->option('tenant'), fn ($q, $t) => $q->where('slug', $t)->orWhere('id', $t))
            ->orderBy('id')
            ->each($runner->isolate(function (Tenant $tenant) use ($reminders, $tenants) {
                $tenants->runAs($tenant, function () use ($reminders, $tenant) {
                    if ($this->option('queue')) {
                        SendWorkforceReminders::dispatch();
                        $this->info("{$tenant->slug}: queued");

                        return;
                    }
                    $r = $reminders->tick();
                    $this->info("{$tenant->slug}: {$r['pending_approval']} approval, {$r['plan_expiry']} plan expiry, {$r['vacancies']} vacancy reminder(s)");
                });
            }));

        return $runner->exitCode();
    }
}
