<?php

namespace App\Domain\Exit\Services;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Compensation\Contracts\CompensationOutput;
use App\Domain\Exit\Events\ExitEvent;
use App\Domain\Exit\Models\ExitCase;
use App\Domain\Exit\Models\FinalSettlement;
use App\Domain\Exit\Models\FinalSettlementLine;
use App\Domain\Identity\Models\User;
use App\Domain\Leave\Models\LeaveType;
use App\Domain\Leave\Services\LeaveBalances;
use App\Domain\Leave\Services\LeaveEntitlements;
use App\Domain\Leave\Services\LeaveYear;
use App\Domain\Payroll\Models\PayrollEntry;
use App\Domain\Payroll\Models\PayrollPeriod;
use App\Domain\Payroll\Services\PayrollCalculator;
use App\Domain\Platform\Services\SettingsRepository;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Full & final (§60). Earnings: unpaid salary for the last month (through the payroll calculator, so
 * LOP and statutory deductions are identical to a normal run), leave encashment, manual items.
 * Deductions: statutory on that salary, notice shortfall recovery, asset recovery, manual items.
 * Every auto line carries its basis; manual lines survive recalculation.
 */
final class FinalSettlements
{
    public function __construct(
        private readonly PayrollCalculator $calculator,
        private readonly CompensationOutput $compensation,
        private readonly LeaveEntitlements $entitlements,
        private readonly LeaveBalances $balances,
        private readonly LeaveYear $leaveYear,
        private readonly SettingsRepository $settings,
        private readonly AuditRecorder $audit,
    ) {}

    public function ensure(ExitCase $case): FinalSettlement
    {
        return FinalSettlement::query()->firstOrCreate(['exit_case_id' => $case->id], ['employee_id' => $case->employee_id, 'status' => 'draft']);
    }

    public function calculate(ExitCase $case, ?User $actor = null): FinalSettlement
    {
        $settlement = $this->ensure($case);
        if (! $settlement->isEditable()) {
            throw new RuntimeException('An approved settlement cannot be recalculated.');
        }

        $employee = $case->employee()->with(['person', 'currentPosition', 'statutoryDetail', 'bankAccounts'])->firstOrFail();
        $lwd = $case->last_working_day;
        $inputs = ['last_working_day' => $lwd->toDateString(), 'exit_type' => $case->type];

        return DB::transaction(function () use ($settlement, $case, $employee, $lwd, &$inputs, $actor) {
            $settlement->lines()->where('source', 'auto')->delete();
            $order = 0;
            $add = function (string $type, string $code, string $name, float $amount, array $basis = []) use ($settlement, &$order) {
                if (round($amount, 2) == 0.0) {
                    return;
                }
                FinalSettlementLine::create(['final_settlement_id' => $settlement->id, 'type' => $type, 'code' => $code, 'name' => $name, 'amount' => round($amount, 2), 'basis' => $basis, 'source' => 'auto', 'sort_order' => ++$order * 10]);
            };

            $companyId = $employee->currentPosition?->company_id;
            $assignment = $this->compensation->on($employee, $lwd);
            $monthlyBasic = 0.0;
            $monthlyGross = 0.0;

            if ($companyId && $assignment) {
                $period = PayrollPeriod::for($employee->currentPosition->company, (int) $lwd->year, (int) $lwd->month);
                $alreadyPaid = PayrollEntry::query()->where('employee_id', $employee->id)->whereHas('run', fn ($q) => $q->where('payroll_period_id', $period->id)->whereIn('status', ['finalized', 'paid']))->exists();
                $inputs['salary_period'] = $period->label();
                $inputs['salary_already_paid'] = $alreadyPaid;

                $c = $this->calculator->calculate($employee, $period);
                foreach ($c->lines as $line) {
                    $full = (float) ($line['basis']['full_month'] ?? $line['amount']);
                    if ($line['type'] === 'earning' && $line['classification'] === 'basic') {
                        $monthlyBasic += $full;
                    }
                    if ($line['type'] === 'earning' && $line['include_in_gross']) {
                        $monthlyGross += $full;
                    }
                }

                if (! $alreadyPaid) {
                    foreach ($c->lines as $line) {
                        if (in_array($line['type'], ['earning', 'reimbursement'], true)) {
                            $add('earning', 'SAL_'.$line['code'], $line['name'].' ('.$period->label().')', (float) $line['amount'], ['paid_days' => $c->paidDays, 'days_in_period' => $c->daysInPeriod] + $line['basis']);
                        } elseif ($line['type'] === 'deduction') {
                            $add('deduction', 'SAL_'.$line['code'], $line['name'].' ('.$period->label().')', (float) $line['amount'], $line['basis']);
                        }
                    }
                    $inputs['paid_days'] = $c->paidDays;
                    $inputs['lop_days'] = $c->lopDays;
                }
            } else {
                $inputs['salary_skipped'] = 'no salary assignment or company';
            }

            $basis = $this->settings->get('exit.encashment_basis', 'basic') === 'gross' ? $monthlyGross : $monthlyBasic;
            $perDay = round($basis / 30, 2);
            $inputs['encashment_per_day'] = $perDay;

            $period = $this->leaveYear->periodFor($lwd);
            foreach (array_keys($this->entitlements->for($employee, $lwd)) as $code) {
                $type = LeaveType::query()->where('code', $code)->first();
                if (! $type || ! $type->is_encashable || ! $type->is_paid) {
                    continue;
                }
                $days = $this->balances->balance($employee, $type, $period)->available();
                if ($days > 0 && $perDay > 0) {
                    $add('earning', 'ENCASH_'.$type->code, "{$type->name} encashment", $days * $perDay, ['days' => $days, 'per_day' => $perDay, 'basis' => $this->settings->get('exit.encashment_basis', 'basic'), 'leave_type_id' => $type->id, 'period' => $period]);
                }
            }

            if ($case->type === 'resignation' && (bool) $this->settings->get('exit.notice_recovery', true) && $case->resignation_date) {
                $served = (int) $case->resignation_date->diffInDays($lwd);
                $shortfall = max(0, $case->notice_days - $served);
                if ($shortfall > 0 && $monthlyGross > 0) {
                    $add('deduction', 'NOTICE_RECOVERY', "Notice shortfall ({$shortfall} days)", $shortfall * round($monthlyGross / 30, 2), ['notice_days' => $case->notice_days, 'served_days' => $served, 'per_day_gross' => round($monthlyGross / 30, 2)]);
                }
            }

            $recoverable = (float) $case->clearances()->sum('recoverable_amount');
            $add('deduction', 'ASSET_RECOVERY', 'Asset recovery', $recoverable, ['from_clearances' => true]);

            $this->total($settlement);
            $settlement->update(['status' => 'calculated', 'inputs' => $inputs, 'calculated_at' => now()]);
            $this->audit->record(AuditAction::PayrollCalculated, 'exit', $settlement, [], null, actor: $actor, metadata: ['net' => (float) $settlement->net_amount, 'exit_case' => $case->number]);
            ExitEvent::dispatch('exit.settlement.calculated', $employee, $settlement, ['number' => $case->number, 'net' => (float) $settlement->net_amount]);

            return $settlement->refresh();
        });
    }

    public function addLine(FinalSettlement $settlement, string $type, string $name, float $amount, ?string $note = null): FinalSettlementLine
    {
        if (! $settlement->isEditable()) {
            throw new RuntimeException('An approved settlement cannot be changed.');
        }
        if (! in_array($type, ['earning', 'deduction'], true)) {
            throw new RuntimeException('A line is an earning or a deduction.');
        }
        $line = FinalSettlementLine::create(['final_settlement_id' => $settlement->id, 'type' => $type, 'code' => 'MANUAL', 'name' => $name, 'amount' => round($amount, 2), 'basis' => ['note' => $note, 'by' => auth()->user()?->name], 'source' => 'manual', 'sort_order' => 1000 + $settlement->lines()->count()]);
        $this->total($settlement);

        return $line;
    }

    public function removeLine(FinalSettlementLine $line): void
    {
        $settlement = $line->settlement()->firstOrFail();
        if (! $settlement->isEditable() || $line->source !== 'manual') {
            throw new RuntimeException('Only manual lines of an unapproved settlement can be removed.');
        }
        $line->delete();
        $this->total($settlement);
    }

    private function total(FinalSettlement $settlement): void
    {
        $earnings = (float) $settlement->lines()->where('type', 'earning')->sum('amount');
        $deductions = (float) $settlement->lines()->where('type', 'deduction')->sum('amount');
        $settlement->update(['total_earnings' => round($earnings, 2), 'total_deductions' => round($deductions, 2), 'net_amount' => round($earnings - $deductions, 2)]);
    }

    /** Approval freezes the lines and posts the leave encashment to the ledger. */
    public function approve(FinalSettlement $settlement, User $approver, ?string $note = null): FinalSettlement
    {
        if ($settlement->status !== 'calculated') {
            throw new RuntimeException('Calculate the settlement before approving it.');
        }

        return DB::transaction(function () use ($settlement, $approver, $note) {
            $employee = $settlement->employee()->firstOrFail();
            foreach ($settlement->lines()->where('code', 'like', 'ENCASH_%')->get() as $line) {
                $type = LeaveType::query()->find($line->basis['leave_type_id'] ?? 0);
                if ($type) {
                    $this->balances->post($employee, $type, (int) $line->basis['period'], 'encashment', -(float) $line->basis['days'], $settlement, 'Full & final encashment');
                }
            }
            $settlement->update(['status' => 'approved', 'approved_by' => $approver->id, 'approved_at' => now(), 'notes' => $note ?? $settlement->notes]);
            $this->audit->record(AuditAction::PayrollApproved, 'exit', $settlement, [['field' => 'status', 'before' => 'calculated', 'after' => 'approved']], $note, actor: $approver, metadata: ['net' => (float) $settlement->net_amount]);
            ExitEvent::dispatch('exit.settlement.approved', $employee, $settlement, ['net' => (float) $settlement->net_amount], array_filter([$employee->user_id]));

            return $settlement->refresh();
        });
    }

    public function markPaid(FinalSettlement $settlement, string $reference, ?User $actor = null): FinalSettlement
    {
        if ($settlement->status !== 'approved') {
            throw new RuntimeException('Only an approved settlement can be paid.');
        }
        $settlement->update(['status' => 'paid', 'paid_at' => now(), 'payment_reference' => $reference]);
        $this->audit->record(AuditAction::PayrollFinalized, 'exit', $settlement, [['field' => 'status', 'before' => 'approved', 'after' => 'paid']], $reference, actor: $actor);
        ExitEvent::dispatch('exit.settlement.paid', $settlement->employee()->first(), $settlement, ['net' => (float) $settlement->net_amount, 'reference' => $reference], array_filter([$settlement->employee()->value('user_id')]));

        return $settlement;
    }
}
