<?php

namespace App\Console\Commands;

use App\Domain\Learning\Jobs\SendLearningReminders;
use App\Domain\Learning\Services\LearningReminders;
use App\Domain\Platform\Models\Tenant;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;

class LearningRemindersCommand extends Command
{
    protected $signature = 'peopleos:learning:send-reminders {--tenant=} {--queue : Dispatch one tenant-bound job per tenant instead of running inline}';

    protected $description = 'Send learning reminders (due soon, mandatory overdue, skill assessments in draft, development milestones) with throttling';

    public function handle(LearningReminders $reminders, TenantContext $tenants): int
    {
        Tenant::query()
            ->when($this->option('tenant'), fn ($q, $t) => $q->where('slug', $t)->orWhere('id', $t))
            ->orderBy('id')
            ->each(function (Tenant $tenant) use ($reminders, $tenants) {
                $tenants->runAs($tenant, function () use ($reminders, $tenant) {
                    if ($this->option('queue')) {
                        SendLearningReminders::dispatch();
                        $this->info("{$tenant->slug}: queued");

                        return;
                    }
                    $r = $reminders->tick();
                    $this->info("{$tenant->slug}: {$r['due']} due, {$r['overdue_mandatory']} mandatory overdue, {$r['assessments']} assessment, {$r['milestones']} milestone reminder(s)");
                });
            });

        return self::SUCCESS;
    }
}
