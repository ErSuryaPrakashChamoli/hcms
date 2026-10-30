<?php

namespace App\Console\Commands;

use App\Domain\People\Services\Anniversaries;
use App\Domain\People\Services\Birthdays;
use App\Domain\Platform\Models\Tenant;
use App\Support\Tenancy\TenantContext;
use App\Support\Tenancy\TenantRunner;
use Illuminate\Console\Command;

/**
 * Birthdays, run hourly: each tenant is handled once its own clock passes 06:00 (its timezone), once a day. On the day:
 * the employee's greeting, the nudge to the people around them, the day's list for celebration managers and the
 * employee.birthday event. Once a month: the month's list, digest_lead_days before the month starts. HROne setup
 * parity HP-9a: work and wedding anniversaries the organisation celebrates get their day's notices in the same run
 * (claimed separately, so switching one on during the day still sends that day's).
 */
class BirthdayNotices extends Command
{
    protected $signature = 'peopleos:birthdays:send {--tenant=} {--force : Send again even if today\'s notices already went out}';

    protected $description = 'Send birthday greetings, nudges and the daily and monthly birthday lists (each tenant on its own date)';

    private const FROM_HOUR = 6;

    public function handle(TenantContext $tenants): int
    {
        $runner = TenantRunner::for($this);
        Tenant::query()
            ->when($this->option('tenant'), fn ($q, $t) => $q->where('slug', $t)->orWhere('id', $t))
            ->orderBy('id')
            ->each($runner->isolate(function (Tenant $tenant) use ($tenants) {
                $tenants->runAs($tenant, function () use ($tenant) {
                    $birthdays = app(Birthdays::class);
                    $force = (bool) $this->option('force');
                    if (! $force && $birthdays->now()->hour < self::FROM_HOUR) {
                        $this->line("{$tenant->slug}: before ".self::FROM_HOUR.':00 on the tenant\'s clock');

                        return;
                    }
                    $today = $birthdays->today();

                    $month = $birthdays->setting('digest_enabled') ? $birthdays->digestMonthDue($today) : null;
                    $lists = $month !== null && ($force || TenantRunner::claim('birthdays.month', $month->format('Y-m')))
                        ? $birthdays->sendMonthlyList($month) : 0;

                    $anniversaries = app(Anniversaries::class);
                    if ($anniversaries->enabledKinds() !== [] && ($force || TenantRunner::claim('anniversaries.day', $today->toDateString()))) {
                        foreach ($anniversaries->sendDayNotices($today) as $kind => $counts) {
                            $this->line(sprintf('%s: %s %d, greetings %d, nudges %d, day lists %d', $tenant->slug, str_replace('_', ' ', $kind), ...array_values($counts)));
                        }
                    }

                    if (! $force && ! TenantRunner::claim('birthdays.day', $today->toDateString())) {
                        $this->line("{$tenant->slug}: already sent today; monthly lists {$lists}");

                        return;
                    }
                    $counts = $birthdays->sendDayNotices($today);
                    $this->line(sprintf('%s: birthdays %d, greetings %d, nudges %d, day lists %d, monthly lists %d', $tenant->slug, ...[...array_values($counts), $lists]));
                });
            }));

        return $runner->exitCode();
    }
}
