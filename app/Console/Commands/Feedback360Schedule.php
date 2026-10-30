<?php

namespace App\Console\Commands;

use App\Domain\Performance\Services\FeedbackRounds;
use App\Domain\Platform\Models\Tenant;
use App\Support\Tenancy\TenantContext;
use App\Support\Tenancy\TenantRunner;
use Illuminate\Console\Command;

/**
 * HROne setup parity HP-8h: once a day, close 360 feedback rounds whose window has passed and launch the current period's
 * round of every 360 policy that asks for automatic launch (once per policy and period).
 */
class Feedback360Schedule extends Command
{
    protected $signature = 'peopleos:performance:feedback-rounds {--tenant=}';

    protected $description = 'Close 360 feedback rounds whose window has passed and launch automatic rounds (360 policy)';

    public function handle(TenantContext $tenants, FeedbackRounds $rounds): int
    {
        $runner = TenantRunner::for($this);
        Tenant::query()
            ->when($this->option('tenant'), fn ($q, $t) => $q->where('slug', $t)->orWhere('id', $t))
            ->orderBy('id')
            ->each($runner->isolate(function (Tenant $tenant) use ($tenants, $rounds) {
                $tenants->runAs($tenant, function () use ($tenant, $rounds) {
                    $done = $rounds->runSchedule();
                    $this->line(sprintf('%s: 360 rounds closed %d, launched %d', $tenant->slug, $done['closed'], $done['launched']));
                });
            }));

        return $runner->exitCode();
    }
}
