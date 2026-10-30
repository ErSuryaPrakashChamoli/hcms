<?php

namespace App\Console\Commands;

use App\Domain\Performance\Services\PerformanceReminders;
use App\Domain\Platform\Models\Tenant;
use App\Domain\Platform\Services\SettingsRepository;
use App\Support\Tenancy\TenantContext;
use App\Support\Tenancy\TenantRunner;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * HROne setup parity HP-8b: scheduled goal check-in prompts (company, team, employee), run hourly. Each tenant is
 * handled once a day, once its own clock (timezone) reaches performance.checkin.send_at; the prompts themselves are
 * logged per person and day, so a repeated run never sends twice.
 */
class PerformanceCheckInPrompts extends Command
{
    protected $signature = 'peopleos:performance:check-in-prompts {--tenant=} {--force : Run even before the scheduled hour or if today\'s run is done}';

    protected $description = 'Prompt goal owners to check in on their goals on the days set in Setup → Performance (each tenant at its scheduled hour)';

    public function handle(TenantContext $tenants, PerformanceReminders $reminders): int
    {
        $runner = TenantRunner::for($this);
        Tenant::query()
            ->when($this->option('tenant'), fn ($q, $t) => $q->where('slug', $t)->orWhere('id', $t))
            ->orderBy('id')
            ->each($runner->isolate(function (Tenant $tenant) use ($tenants, $reminders) {
                $tenants->runAs($tenant, function () use ($tenant, $reminders) {
                    $now = Carbon::now($tenant->timezone ?: config('app.timezone'));
                    $force = (bool) $this->option('force');
                    $hour = (int) app(SettingsRepository::class)->get('performance.checkin.send_at', 9);
                    if (! $force && $now->hour < $hour) {
                        $this->line("{$tenant->slug}: before {$hour}:00 on the tenant's clock");

                        return;
                    }
                    if (! $force && ! TenantRunner::claim('performance.check_in_prompts', $now->toDateString())) {
                        $this->line("{$tenant->slug}: already run today");

                        return;
                    }
                    $sent = $reminders->checkInPrompts($now);
                    $this->line(sprintf('%s: check-in prompts company %d, team %d, employee %d', $tenant->slug, $sent['company'], $sent['team'], $sent['employee']));
                });
            }));

        return $runner->exitCode();
    }
}
