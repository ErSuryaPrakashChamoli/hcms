<?php

namespace App\Console\Commands;

use App\Domain\Performance\Services\PeriodicReviews;
use App\Domain\Platform\Models\Tenant;
use App\Support\Tenancy\TenantContext;
use App\Support\Tenancy\TenantRunner;
use Illuminate\Console\Command;

/**
 * HROne setup parity HP-8i: once a day, open the periodic reviews (performance policy review cycle) whose window has
 * started — unless the policy asks for manual initiation — and mark those whose window passed unrated as missed.
 */
class PeriodicReviewsSchedule extends Command
{
    protected $signature = 'peopleos:performance:periodic-reviews {--tenant=}';

    protected $description = 'Open periodic reviews whose window has started and mark missed ones (performance policy)';

    public function handle(TenantContext $tenants, PeriodicReviews $reviews): int
    {
        $runner = TenantRunner::for($this);
        Tenant::query()
            ->when($this->option('tenant'), fn ($q, $t) => $q->where('slug', $t)->orWhere('id', $t))
            ->orderBy('id')
            ->each($runner->isolate(function (Tenant $tenant) use ($tenants, $reviews) {
                $tenants->runAs($tenant, function () use ($tenant, $reviews) {
                    $done = $reviews->runSchedule();
                    $this->line(sprintf('%s: periodic reviews opened %d, missed %d', $tenant->slug, $done['opened'], $done['missed']));
                });
            }));

        return $runner->exitCode();
    }
}
