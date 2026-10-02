<?php

namespace App\Console\Commands;

use App\Domain\Engagement\Jobs\ProcessEngagement;
use App\Domain\Engagement\Services\EngagementProcessor;
use App\Domain\Platform\Models\Tenant;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;

class ProcessEngagementCommand extends Command
{
    protected $signature = 'peopleos:engagement:process {--tenant=} {--queue : Dispatch one tenant-bound job per tenant instead of running inline}';

    protected $description = 'Engagement: open and close surveys on their dates (audience snapshot), send invitations and reminders, launch and complete campaigns (idempotent)';

    public function handle(EngagementProcessor $processor, TenantContext $tenants): int
    {
        Tenant::query()
            ->when($this->option('tenant'), fn ($q, $t) => $q->where('slug', $t)->orWhere('id', $t))
            ->orderBy('id')
            ->each(function (Tenant $tenant) use ($processor, $tenants) {
                $tenants->runAs($tenant, function () use ($processor, $tenant) {
                    if ($this->option('queue')) {
                        ProcessEngagement::dispatch();
                        $this->info("{$tenant->slug}: queued");

                        return;
                    }
                    $r = $processor->run();
                    $this->info("{$tenant->slug}: ".collect($r)->map(fn ($n, $k) => "{$k} {$n}")->implode(', '));
                });
            });

        return self::SUCCESS;
    }
}
