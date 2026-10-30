<?php

namespace App\Console\Commands;

use App\Domain\Lifecycle\Services\ConfirmationReviews;
use App\Domain\Platform\Models\Tenant;
use App\Support\Tenancy\TenantContext;
use App\Support\Tenancy\TenantRunner;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/** HROne setup parity (Workforce → Confirmation policy): open the confirmation reviews coming due. Idempotent. */
class OpenConfirmationReviews extends Command
{
    protected $signature = 'peopleos:lifecycle:confirmation-reviews {--date=} {--tenant=}';

    protected $description = 'Open confirmation reviews ahead of probation ends';

    public function handle(ConfirmationReviews $reviews, TenantContext $tenants): int
    {
        $runner = TenantRunner::for($this);
        $today = Carbon::parse($this->option('date') ?? now())->startOfDay();

        Tenant::query()
            ->when($this->option('tenant'), fn ($q, $t) => $q->where('slug', $t)->orWhere('id', $t))
            ->orderBy('id')
            ->each($runner->isolate(fn (Tenant $tenant) => $tenants->runAs($tenant, function () use ($reviews, $tenant, $today) {
                $this->info("{$tenant->slug}: ".$reviews->openDue($today).' review(s) opened');
            })));

        return $runner->exitCode();
    }
}
