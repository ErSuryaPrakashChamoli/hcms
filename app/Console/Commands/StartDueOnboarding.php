<?php

namespace App\Console\Commands;

use App\Domain\Documents\Services\EmployeeDocumentDownloads;
use App\Domain\Onboarding\Services\Onboarding;
use App\Domain\Platform\Models\Tenant;
use App\Support\Tenancy\TenantContext;
use App\Support\Tenancy\TenantRunner;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * HROne setup parity 08.2 (Induction policy → start N days before joining): start the onboarding checklists and induction
 * programmes whose template starts a number of days before joining, once the joiner is within those days. Idempotent.
 * HROne parity WO-4: also removes expired "Download all documents" zips (EmployeeDocumentDownloads::prune).
 */
class StartDueOnboarding extends Command
{
    protected $signature = 'peopleos:onboarding:start-due {--date=} {--tenant=}';

    protected $description = 'Start onboarding and induction plans that begin a set number of days before joining';

    public function handle(Onboarding $onboarding, TenantContext $tenants): int
    {
        $runner = TenantRunner::for($this);
        $today = Carbon::parse($this->option('date') ?? now())->startOfDay();

        Tenant::query()
            ->when($this->option('tenant'), fn ($q, $t) => $q->where('slug', $t)->orWhere('id', $t))
            ->orderBy('id')
            ->each($runner->isolate(fn (Tenant $tenant) => $tenants->runAs($tenant, function () use ($onboarding, $tenant, $today) {
                $this->info("{$tenant->slug}: ".$onboarding->startDue($today).' plan(s) started');
                // HROne parity WO-4: expired "Download all documents" zips of upcoming joiners are removed (the row stays).
                $expired = app(EmployeeDocumentDownloads::class)->prune();
                if ($expired > 0) {
                    $this->info("{$tenant->slug}: {$expired} expired document download(s) removed");
                }
            })));

        return $runner->exitCode();
    }
}
