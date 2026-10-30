<?php

namespace App\Console\Commands;

use App\Domain\Platform\Models\Tenant;
use App\Domain\Workflow\Services\InboxArchives;
use App\Support\Tenancy\TenantContext;
use App\Support\Tenancy\TenantRunner;
use Illuminate\Console\Command;

/**
 * HROne setup parity P4 (Inbox → Archive inbox, automatic): for tenants that archive automatically
 * (inbox.archive.enabled, inbox.archive.mode = auto), archive each person's Approval Center items waiting longer than
 * inbox.archive.after_days, every request type except inbox.archive.excluded_types. Archiving decides nothing and an
 * item the person restored is never archived again. Tenants without the setting are skipped.
 */
class ArchiveInboxItems extends Command
{
    protected $signature = 'peopleos:inbox:archive {--tenant=}';

    protected $description = 'Archive Approval Center items waiting longer than each tenant\'s archive period (automatic archiving)';

    public function handle(TenantContext $tenants): int
    {
        $runner = TenantRunner::for($this);
        Tenant::query()
            ->when($this->option('tenant'), fn ($q, $t) => $q->where('slug', $t)->orWhere('id', $t))
            ->orderBy('id')
            ->each($runner->isolate(fn (Tenant $tenant) => $tenants->runAs($tenant, function () use ($tenant) {
                $count = app(InboxArchives::class)->autoArchive();
                $this->info("{$tenant->slug}: {$count} item(s) archived");
            })));

        return $runner->exitCode();
    }
}
