<?php

namespace App\Domain\Payroll\Inputs;

use App\Domain\Engagement\Models\FundraiserPledge;
use App\Domain\Engagement\Services\Fundraisers;
use App\Domain\Identity\Scopes\AccessScope;
use App\Domain\Payroll\Contracts\PayrollInputSource;
use App\Domain\Payroll\Models\PayrollRun;
use App\Domain\Payroll\Services\PayrollComputation;
use App\Domain\Platform\Services\SettingsRepository;
use RuntimeException;

/**
 * HROne parity GO-18 (owner decision D-GO3: payroll deduction of fundraiser pledges, off by default). While the
 * organisation allows it (engagement.fundraisers.payroll_deduction), each pledge the employee made to be deducted from
 * their salary in this payroll month — with their consent recorded on it, still to be collected — is deducted once, at
 * exactly the amount pledged, as its own deduction line (FUNDRAISER, outside gross, no tax effect). A pledge another run
 * not yet finalized already carries is left to it. Finalizing the run marks the pledges it carried deducted (the run and
 * the time); a pledge cancelled after the run was calculated stops the finalization until the run is recalculated, so a
 * withdrawn consent is never deducted. With the setting off, or no pledge, nothing is added.
 */
final class FundraiserDeductionInput implements PayrollInputSource
{
    public const KEY = 'fundraiser_pledge';

    public function key(): string
    {
        return self::KEY;
    }

    public function inputsFor(PayrollComputation $c): array
    {
        if (! (bool) app(SettingsRepository::class)->get(Fundraisers::SETTING, false)) {
            return [];
        }
        $month = sprintf('%04d-%02d', (int) $c->period->year, (int) $c->period->month);
        $pledges = FundraiserPledge::query()->withoutGlobalScope(AccessScope::class)->where('employee_id', $c->employee->id)->where('mode', 'one_time_payroll')
            ->where('status', 'pledged')->where('payroll_month', $month)->whereNotNull('consent_given_at')
            ->with('campaign:id,name,status')->orderBy('id')->sharedLock()->get();
        if ($pledges->isEmpty()) {
            return [];
        }
        $elsewhere = app(PendingPayrollInputs::class)->pending(self::KEY, (int) $c->employee->id);

        return $pledges->reject(fn (FundraiserPledge $p) => isset($elsewhere[$p->id]) || $p->campaign === null || $p->campaign->status === 'cancelled')
            ->map(fn (FundraiserPledge $p) => [
                'code' => 'FUNDRAISER', 'name' => 'Fundraiser: '.$p->campaign->name, 'type' => 'deduction', 'amount' => round((float) $p->amount, 2),
                'ids' => [$p->id], 'classification' => self::KEY,
                'basis' => ['fundraiser' => $p->campaign->name, 'pledge' => $p->id, 'month' => $month, 'consent_given_at' => $p->consent_given_at?->toIso8601String()],
            ])->values()->all();
    }

    public function settle(PayrollRun $run, array $idsByEmployee): void
    {
        $run->loadMissing('period');
        $ids = array_values(array_unique(array_map('intval', array_merge(...array_values($idsByEmployee)))));
        if ($ids === []) {
            return;
        }
        $pledges = FundraiserPledge::query()->withoutGlobalScope(AccessScope::class)->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get();
        $changed = $pledges->filter(fn (FundraiserPledge $p) => $p->status !== 'pledged');
        if ($changed->isNotEmpty()) {
            throw new RuntimeException($changed->count().' fundraiser pledge(s) were cancelled or paid after this payroll was calculated; recalculate the run before finalizing.');
        }
        $pledges->each(fn (FundraiserPledge $p) => $p->withAuditReason('Deducted in payroll '.$run->period?->label())
            ->update(['status' => 'deducted', 'payroll_run_id' => $run->id, 'deducted_at' => now()]));
        app(Fundraisers::class)->forget();
    }
}
