<?php

namespace App\Console\Commands;

use App\Domain\Platform\Models\Tenant;
use App\Domain\Talent\Jobs\SendTalentReminders;
use App\Domain\Talent\Services\TalentReminders;
use App\Support\Tenancy\TenantContext;
use App\Support\Tenancy\TenantRunner;
use Illuminate\Console\Command;

class TalentRemindersCommand extends Command
{
    protected $signature = 'peopleos:talent:send-reminders {--tenant=} {--queue : Dispatch one tenant-bound job per tenant instead of running inline}';

    protected $description = 'Send talent and succession reminders (critical-position and plan reviews, expiring readiness, upcoming talent reviews) with throttling';

    public function handle(TalentReminders $reminders, TenantContext $tenants): int
    {
        $runner = TenantRunner::for($this);
        Tenant::query()
            ->when($this->option('tenant'), fn ($q, $t) => $q->where('slug', $t)->orWhere('id', $t))
            ->orderBy('id')
            ->each($runner->isolate(function (Tenant $tenant) use ($reminders, $tenants) {
                $tenants->runAs($tenant, function () use ($reminders, $tenant) {
                    if ($this->option('queue')) {
                        SendTalentReminders::dispatch();
                        $this->info("{$tenant->slug}: queued");

                        return;
                    }
                    $r = $reminders->tick();
                    $this->info("{$tenant->slug}: {$r['position_reviews']} position review, {$r['plan_reviews']} plan review, {$r['readiness_expiring']} readiness, {$r['talent_reviews']} talent review reminder(s)");
                });
            }));

        return $runner->exitCode();
    }
}
