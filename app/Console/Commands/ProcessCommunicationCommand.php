<?php

namespace App\Console\Commands;

use App\Domain\Communication\Jobs\ProcessCommunication;
use App\Domain\Communication\Services\CommunicationProcessor;
use App\Domain\Platform\Models\Tenant;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;

class ProcessCommunicationCommand extends Command
{
    protected $signature = 'peopleos:communication:process {--tenant=} {--queue : Dispatch one tenant-bound job per tenant instead of running inline}';

    protected $description = 'Communication: publish scheduled announcements on their date (audience snapshot) and deliver due recipients through the Notifier (idempotent)';

    public function handle(CommunicationProcessor $processor, TenantContext $tenants): int
    {
        Tenant::query()
            ->when($this->option('tenant'), fn ($q, $t) => $q->where('slug', $t)->orWhere('id', $t))
            ->orderBy('id')
            ->each(function (Tenant $tenant) use ($processor, $tenants) {
                $tenants->runAs($tenant, function () use ($processor, $tenant) {
                    if ($this->option('queue')) {
                        ProcessCommunication::dispatch();
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
