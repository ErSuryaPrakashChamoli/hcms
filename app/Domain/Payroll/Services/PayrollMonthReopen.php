<?php

namespace App\Domain\Payroll\Services;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Claims\Services\ReimbursementClaims;
use App\Domain\Compliance\Models\StatutoryChallan;
use App\Domain\Compliance\Models\StatutoryReturn;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Scopes\AccessScope;
use App\Domain\Payroll\Events\PayrollEvent;
use App\Domain\Payroll\Inputs\PayrollInputs;
use App\Domain\Payroll\Models\PayrollEntry;
use App\Domain\Payroll\Models\PayrollPeriod;
use App\Domain\Payroll\Models\PayrollReopening;
use App\Domain\Payroll\Models\PayrollRun;
use App\Domain\Payroll\Models\Payslip;
use App\Support\Settlements\SettlementReversal;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Streamline S2 (decision ST-1, owner 2026-11-05): reopen the latest closed payroll month of a company to change its
 * details. Allowed while the month is not marked paid and no statutory return or challan has been filed from it; a
 * reason is required, and `payroll.reopen_closed` (checked by the screens) is a right of its own.
 *
 * Reopening gives back everything closing did, in one transaction: the run returns to draft (a fresh calculation,
 * validation, independent approval and close follow), the period opens, the month's attendance days unlock, the
 * payslips are withdrawn (kept on the PayrollReopening record as issued) and every input the run settled is due
 * again (PayrollInputSource::unsettle). Later months that were calculated return to draft too, because their
 * year-to-date figures came from this one. The next close re-issues payslips with a revision number.
 */
final class PayrollMonthReopen
{
    /** Return statuses that mean the figures have left PeopleOS (exported to a portal, filed, acknowledged …). */
    private const FILED_RETURNS = [StatutoryReturn::EXPORTED, StatutoryReturn::SUBMITTED, StatutoryReturn::ACKNOWLEDGED, StatutoryReturn::RECONCILIATION_REQUIRED, StatutoryReturn::RECONCILED, StatutoryReturn::REVISED];

    public function __construct(private readonly AuditRecorder $audit) {}

    /**
     * Why this run cannot be reopened now, or null when it can.
     */
    public function blocker(PayrollRun $run): ?string
    {
        $run->loadMissing('period');
        $period = $run->period;

        return match (true) {
            $run->status === 'paid' => "{$period->label()} is marked as paid: the bank already has these figures. Correct it with an arrear or a recovery in an open month.",
            $run->status !== 'finalized' => 'Only a closed payroll month can be reopened.',
            $this->laterClosed($run) !== null => 'Only the latest closed month can be reopened: '.$this->laterClosed($run).' was closed after it.',
            $this->laterApproved($run) !== null => $this->laterApproved($run).' is approved: send it back to draft before reopening '.$period->label().'.',
            ($filed = $this->filed($run)) !== null => $filed,
            default => null,
        };
    }

    /** What reopening will give back, for the confirmation (counts only). */
    public function preview(PayrollRun $run): array
    {
        $carried = PayrollInputs::carried($run);

        return [
            'payslips' => Payslip::query()->withoutGlobalScope(AccessScope::class)->whereIn('payroll_entry_id', $run->entries()->withoutGlobalScope(AccessScope::class)->select('id'))->count(),
            'published' => $run->payslips_published_at !== null,
            'inputs' => array_map(fn (array $byEmployee) => count(array_merge(...array_values($byEmployee))), $carried),
            'later_runs' => $this->laterEditable($run)->map(fn (PayrollRun $r) => $r->period->label())->values()->all(),
        ];
    }

    public function reopen(PayrollRun $run, string $reason, User $actor): PayrollRun
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw new RuntimeException('A reason is required to reopen a closed payroll month.');
        }

        $reopened = DB::transaction(function () use ($run, $reason, $actor) {
            // Decided on the locked row: a payment or another reopening that committed first is seen here.
            $run = PayrollRun::query()->whereKey($run->getKey())->lockForUpdate()->firstOrFail()->loadMissing('period');
            if (($blocker = $this->blocker($run)) !== null) {
                throw new RuntimeException($blocker);
            }

            return SettlementReversal::during(function () use ($run, $reason, $actor) {
                $period = $run->period;
                $note = 'Payroll '.$period->label().' reopened: '.mb_substr($reason, 0, 200);
                $entryIds = $run->entries()->withoutGlobalScope(AccessScope::class)->pluck('id');
                $payslips = Payslip::query()->withoutGlobalScope(AccessScope::class)->whereIn('payroll_entry_id', $entryIds)->orderBy('id')->get();
                $before = ['status' => $run->status, 'finalized_at' => $run->finalized_at?->toIso8601String(), 'payslips_published_at' => $run->payslips_published_at?->toIso8601String(), 'totals' => $run->totals];

                // Inputs first: their give-back reads the run's lines, which the next calculation replaces.
                $givenBack = PayrollInputs::unsettle($run);
                app(ReimbursementClaims::class)->undoAccruals($run);

                $generation = (int) $run->reopen_count + 1;
                PayrollReopening::query()->create([
                    'payroll_run_id' => $run->id, 'payroll_period_id' => $period->id, 'generation' => $generation, 'previous_status' => $run->status,
                    'reason' => mb_substr($reason, 0, 500), 'reopened_by' => $actor->id,
                    'summary' => $before + ['employees' => $entryIds->count()],
                    'payslips' => $payslips->map(fn (Payslip $p) => ['number' => $p->number, 'employee_id' => (int) $p->employee_id, 'generated_at' => $p->generated_at?->toIso8601String(), 'viewed_at' => $p->viewed_at?->toIso8601String(), 'snapshot' => $p->snapshot])->all(),
                    'given_back' => $givenBack,
                ]);
                $payslips->each(fn (Payslip $p) => $p->delete());

                app(PayrollRuns::class)->unlockAttendance($run, PayrollEntry::query()->withoutGlobalScope(AccessScope::class)->whereIn('id', $entryIds)->pluck('employee_id'));

                $run->update(['status' => 'draft', 'approved_by' => null, 'approved_at' => null, 'finalized_by' => null, 'finalized_at' => null,
                    'payslips_published_at' => null, 'payslips_published_by' => null, 'reopen_count' => $generation]);
                $period->update(['status' => 'open']);

                $later = $this->laterEditable($run);
                $later->each(fn (PayrollRun $r) => $r->update(['status' => 'draft']));

                $this->audit->record(AuditAction::PayrollReopened, 'payroll', $run, [
                    ['field' => 'status', 'before' => $before['status'], 'after' => 'draft'],
                    ['field' => 'period_status', 'before' => 'closed', 'after' => 'open'],
                ], $reason, actor: $actor, metadata: ['period' => $period->label(), 'generation' => $generation, 'payslips_withdrawn' => $payslips->count(),
                    'given_back' => $givenBack, 'later_runs_to_draft' => $later->pluck('id')->all(), 'closed_month' => true]);

                return $run->refresh();
            });
        });

        PayrollEvent::dispatch('payroll.reopened', $reopened, ['period' => $reopened->period->label(), 'generation' => (int) $reopened->reopen_count]);

        return $reopened;
    }

    private function laterClosed(PayrollRun $run): ?string
    {
        $later = PayrollPeriod::query()->where('company_id', $run->company_id)->where('status', 'closed')
            ->where(fn ($q) => $q->where('year', '>', $run->period->year)->orWhere(fn ($q) => $q->where('year', $run->period->year)->where('month', '>', $run->period->month)))
            ->orderBy('year')->orderBy('month')->first();

        return $later?->label();
    }

    private function laterApproved(PayrollRun $run): ?string
    {
        return $this->laterRuns($run)->first(fn (PayrollRun $r) => $r->status === 'approved')?->period->label();
    }

    private function laterEditable(PayrollRun $run)
    {
        return $this->laterRuns($run)->filter(fn (PayrollRun $r) => in_array($r->status, ['calculated', 'validated'], true))->values();
    }

    private function laterRuns(PayrollRun $run)
    {
        return PayrollRun::query()->with('period')->where('company_id', $run->company_id)->whereKeyNot($run->getKey())
            ->whereHas('period', fn ($q) => $q->where(fn ($q) => $q->where('year', '>', $run->period->year)->orWhere(fn ($q) => $q->where('year', $run->period->year)->where('month', '>', $run->period->month))))
            ->get();
    }

    /** A statutory challan or a return covering the month that has left PeopleOS, described for the refusal. */
    private function filed(PayrollRun $run): ?string
    {
        $period = $run->period;
        $month = sprintf('%04d-%02d-01', $period->year, $period->month);
        $challan = StatutoryChallan::query()->withoutGlobalScope(AccessScope::class)->where('company_id', $run->company_id)->whereDate('period_month', $month)
            ->where('status', '!=', StatutoryChallan::CANCELLED)->first(['id', 'statute', 'challan_number']);
        if ($challan !== null) {
            return "A {$challan->statute} challan ({$challan->challan_number}) is recorded for {$period->label()}: the authority already has these figures. Correct it with an arrear or a recovery in an open month.";
        }
        $start = $period->start_date->toDateString();
        $end = $period->end_date->toDateString();
        $return = StatutoryReturn::query()->withoutGlobalScope(AccessScope::class)->where('company_id', $run->company_id)->whereIn('status', self::FILED_RETURNS)
            ->whereDate('period_start', '<=', $end)->whereDate('period_end', '>=', $start)->first(['id', 'form_code', 'period_key']);

        return $return !== null
            ? "The {$return->form_code} return for {$return->period_key} was exported or filed from this month: the authority already has these figures. Correct it with an arrear or a recovery in an open month."
            : null;
    }
}
