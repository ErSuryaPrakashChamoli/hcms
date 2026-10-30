<?php

namespace App\Console\Commands;

use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Scopes\AccessScope;
use App\Domain\People\Services\Birthdays;
use App\Domain\Platform\Models\Tenant;
use App\Domain\Recognition\Services\RewardPoints;
use App\Support\Tenancy\TenantContext;
use App\Support\Tenancy\TenantRunner;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * HROne setup parity HP-9d (Engage → Reward points policy, credit cycle): credits each employee of a reward_points
 * policy their points for the cycle (once per cycle: dedupe keys in the ledger, so a run repeated the same day, or after
 * HR deposited points into a short budget, credits only who is still due), lapses unused points of earlier cycles
 * without carry forward, and expires points received longer ago than the policy allows. Nothing happens while
 * engage.points.enabled is off (the default).
 */
class CreditRewardPoints extends Command
{
    protected $signature = 'peopleos:engage:credit-points {--date=} {--tenant=}';

    protected $description = 'Credit reward points per the reward points policies (cycle credits, lapses and expiry)';

    public function handle(TenantContext $tenants): int
    {
        $runner = TenantRunner::for($this);

        Tenant::query()
            ->when($this->option('tenant'), fn ($q, $t) => $q->where('slug', $t)->orWhere('id', $t))
            ->orderBy('id')
            ->each($runner->isolate(fn (Tenant $tenant) => $tenants->runAs($tenant, function () use ($tenant) {
                $points = app(RewardPoints::class);
                if (! $points->enabled()) {
                    $this->info("{$tenant->slug}: reward points are off");

                    return;
                }
                $on = $this->option('date') ? Carbon::parse($this->option('date'), $tenant->timezone ?: config('app.timezone')) : $points->now();
                $counts = ['credited' => 0, 'points' => 0, 'budget' => 0, 'lapsed' => 0, 'expired' => 0];
                // A closure (not an arrow function): the counts are taken by reference.
                AccessScope::withoutScoping(function () use ($points, $on, &$counts) {
                    Employee::query()->whereIn('lifecycle_state', Birthdays::STATES)->select('id')->orderBy('id')->chunkById(200, function ($ids) use ($points, $on, &$counts) {
                        foreach ($ids as $row) {
                            // One employee at a time (the policy resolver reads their relations; strict mode refuses lazy loads on a collection).
                            $employee = Employee::query()->find($row->id);
                            if ($employee === null) {
                                continue;
                            }
                            $result = $points->credit($employee, $on);
                            $counts['credited'] += $result['status'] === 'credited' ? 1 : 0;
                            $counts['budget'] += $result['status'] === 'budget' ? 1 : 0;
                            $counts['points'] += $result['points'];
                            $counts['lapsed'] += $result['lapsed'];
                            $counts['expired'] += $result['expired'];
                        }
                    });
                });
                $this->info("{$tenant->slug}: {$counts['credited']} employee(s) credited {$counts['points']} point(s); {$counts['budget']} waiting for budget; {$counts['lapsed']} lapsed; {$counts['expired']} expired");
            })));

        return $runner->exitCode();
    }
}
