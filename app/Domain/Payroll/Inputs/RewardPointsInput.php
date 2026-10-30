<?php

namespace App\Domain\Payroll\Inputs;

use App\Domain\Identity\Scopes\AccessScope;
use App\Domain\Payroll\Contracts\PayrollInputSource;
use App\Domain\Payroll\Models\PayrollRun;
use App\Domain\Payroll\Services\PayrollComputation;
use App\Domain\Recognition\Models\RewardPointEntry;
use App\Domain\Recognition\Services\RewardPoints;
use Illuminate\Database\Eloquent\Collection;

/**
 * HROne setup parity HP-9d (Engage → Reward points, redeem through payroll): reward points redeemed by the end of the
 * period and not yet paid are paid as one taxable earning (REWARD, outside gross, no PF or ESI), at the amount fixed when
 * they were redeemed. Each line carries the ledger rows; finalizing the run records the run on them, so a later run
 * never pays them again, and a redemption another run not yet finalized carries is left to that run. Points are redeemed
 * only when the organisation allows it (engage.points.redeem_modes, default none), so by default nothing is added.
 */
final class RewardPointsInput implements PayrollInputSource
{
    public function key(): string
    {
        return 'reward_points';
    }

    public function inputsFor(PayrollComputation $c): array
    {
        /** @var Collection<int, RewardPointEntry> $rows */
        $rows = app(RewardPoints::class)->unpaidRedemptions((int) $c->employee->id, $c->period->end_date->copy()->endOfDay())->orderBy('id')->sharedLock()->get();
        if ($rows->isEmpty()) {
            return [];
        }
        $elsewhere = app(PendingPayrollInputs::class)->pending($this->key(), (int) $c->employee->id);
        $rows = $rows->reject(fn (RewardPointEntry $r) => isset($elsewhere[$r->id]))->values();
        if ($rows->isEmpty()) {
            return [];
        }
        $points = -(int) $rows->sum('points');

        return [[
            'code' => 'REWARD', 'name' => "Reward points redeemed ({$points} points)", 'type' => 'earning', 'amount' => round((float) $rows->sum(fn (RewardPointEntry $r) => (float) $r->amount), 2),
            'ids' => $rows->pluck('id')->all(), 'taxable' => true, 'classification' => 'reward_points',
            'basis' => ['points' => $points, 'redemptions' => $rows->pluck('id')->all()],
        ]];
    }

    public function settle(PayrollRun $run, array $idsByEmployee): void
    {
        $run->loadMissing('period');
        foreach ($idsByEmployee as $employeeId => $ids) {
            RewardPointEntry::query()->withoutGlobalScope(AccessScope::class)->where('employee_id', $employeeId)->whereIn('id', $ids)
                ->where('kind', 'redeem')->whereNull('payroll_run_id')->get()
                ->each(fn (RewardPointEntry $r) => $r->withAuditReason('Reward points paid in payroll '.$run->period?->label())->update(['payroll_run_id' => $run->id, 'settled_at' => now()]));
        }
    }
}
