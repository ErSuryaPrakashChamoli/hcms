<?php

namespace App\Domain\Expense\Settlement;

use App\Domain\Exit\Contracts\SettlementSource;
use App\Domain\Exit\Models\ExitCase;
use App\Domain\Exit\Models\FinalSettlement;
use App\Domain\Exit\Models\FinalSettlementLine;
use App\Domain\Expense\Contracts\PayrollCommitments;
use App\Domain\Expense\Models\ExpenseAdvance;
use App\Domain\Expense\Models\ExpenseReport;
use App\Domain\Expense\Services\ExpenseAdvances;
use App\Domain\Expense\Services\ExpenseReports;
use App\Domain\Identity\Scopes\AccessScope;
use Carbon\CarbonImmutable;

/**
 * HROne setup parity EX-E: expense money at exit. The full and final settlement pays the employee's approved, unpaid
 * expense reports (earning EXPENSE_PAYABLE) and recovers what is outstanding on dispensed advances (deduction
 * EXP_ADVANCE_BALANCE; not for advances whose policy says they are not recovered automatically). Records the settlement's
 * final salary already carries (its SAL_ lines from the payroll inputs) and records a payroll run not yet finalized or
 * another open exit's settlement carries are left to them, so nothing is paid or recovered twice. When the settlement is
 * paid, every report it carried is paid (channel `settlement`) and every advance recovered (ledger keyed fnf-{id}).
 */
final class ExpenseSettlementSource implements SettlementSource
{
    public const PAYABLE = 'EXPENSE_PAYABLE';

    public const BALANCE = 'EXP_ADVANCE_BALANCE';

    public function __construct(private readonly PayrollCommitments $payroll) {}

    public function linesFor(ExitCase $case, FinalSettlement $settlement): array
    {
        $employeeId = (int) $case->employee_id;
        $own = $settlement->exists ? self::carried([(int) $settlement->id]) : [];
        $elsewhere = fn (string $key) => ($own[$key] ?? []) + $this->payroll->pending($key, $employeeId) + self::pending($key, $employeeId, $settlement->exists ? (int) $settlement->id : null);
        $lines = [];

        $skip = $elsewhere(PayrollCommitments::REPORT);
        $reports = ExpenseReport::query()->withoutGlobalScope(AccessScope::class)->where('employee_id', $employeeId)->whereIn('status', ['approved', 'partly_approved'])
            ->whereNull('paid_on')->where('net_payable', '>', 0)->orderBy('decided_at')->orderBy('id')->get()
            ->reject(fn (ExpenseReport $r) => isset($skip[$r->id]));
        if ($reports->isNotEmpty()) {
            $lines[] = ['type' => 'earning', 'code' => self::PAYABLE, 'name' => 'Expense reimbursement ('.$reports->count().' report'.($reports->count() === 1 ? '' : 's').')',
                'amount' => round((float) $reports->sum(fn (ExpenseReport $r) => (float) $r->net_payable), 2),
                'basis' => ['reports' => $reports->map(fn (ExpenseReport $r) => ['id' => $r->id, 'number' => $r->number, 'amount' => (float) $r->net_payable])->values()->all()]];
        }

        $skip = $elsewhere(PayrollCommitments::RECOVERY);
        $advances = ExpenseAdvance::query()->withoutGlobalScope(AccessScope::class)->where('employee_id', $employeeId)->where('status', 'disbursed')
            ->where('outstanding', '>', 0)->orderBy('disbursed_on')->orderBy('id')->get()
            ->reject(fn (ExpenseAdvance $a) => isset($skip[$a->id]) || app(ExpenseAdvances::class)->rulesFor($a)->recovery === 'none');
        if ($advances->isNotEmpty()) {
            $lines[] = ['type' => 'deduction', 'code' => self::BALANCE, 'name' => 'Expense advance balance ('.$advances->pluck('number')->implode(', ').')',
                'amount' => round((float) $advances->sum(fn (ExpenseAdvance $a) => (float) $a->outstanding), 2),
                'basis' => ['advances' => $advances->map(fn (ExpenseAdvance $a) => ['id' => $a->id, 'number' => $a->number, 'amount' => (float) $a->outstanding, 'settle_by' => $a->settle_by?->toDateString()])->values()->all()]];
        }

        return $lines;
    }

    public function settled(ExitCase $case, FinalSettlement $settlement): void
    {
        if (! $settlement->exists) {
            return;
        }
        $carried = self::carried([(int) $settlement->id]);
        $on = CarbonImmutable::parse($settlement->paid_at ?? now())->toDateString();
        $reference = trim((string) $settlement->payment_reference) ?: (string) $case->number;
        $note = 'Full and final settlement '.$case->number;

        if ($ids = array_keys($carried[PayrollCommitments::PAYOUT] ?? [])) {
            app(ExpenseAdvances::class)->paidOut($ids, 'settlement', $on, $reference, $note, null, (int) $settlement->id);
        }
        if ($ids = array_keys($carried[PayrollCommitments::REPORT] ?? [])) {
            app(ExpenseReports::class)->paidThrough($ids, 'settlement', $on, $reference, null, (int) $settlement->id);
        }
        if ($amounts = array_map(fn (array $c) => $c['amount'], $carried[PayrollCommitments::RECOVERY] ?? [])) {
            app(ExpenseAdvances::class)->recovered($amounts, 'fnf-'.$settlement->id, $on, $reference, $note, null, (int) $settlement->id);
        }
    }

    /**
     * Records of this key carried by another open exit's settlement that is calculated or approved but not paid yet.
     *
     * @return array<int, string> record id => where ("the full and final settlement of EXIT-…")
     */
    public static function pending(string $key, int $employeeId, ?int $exceptSettlementId = null): array
    {
        $settlements = FinalSettlement::query()->withoutGlobalScope(AccessScope::class)->where('employee_id', $employeeId)->whereIn('status', ['calculated', 'approved'])
            ->when($exceptSettlementId !== null, fn ($q) => $q->whereKeyNot($exceptSettlementId))
            ->whereHas('exitCase', fn ($q) => $q->withoutGlobalScope(AccessScope::class)->whereIn('status', ExitCase::OPEN))
            ->with(['exitCase' => fn ($q) => $q->withoutGlobalScope(AccessScope::class)])->get(['id', 'exit_case_id']);
        if ($settlements->isEmpty()) {
            return [];
        }
        $numbers = $settlements->mapWithKeys(fn (FinalSettlement $s) => [$s->id => $s->exitCase?->number]);

        return array_map(fn (array $c) => 'the full and final settlement of '.($numbers[$c['settlement']] ?? 'an exit'), self::carried($settlements->modelKeys())[$key] ?? []);
    }

    /**
     * What the settlements' lines carry, per payroll-input key: this source's own lines and the final salary's lines
     * (SAL_…, whose basis keeps the payroll input's source ids).
     *
     * @param  list<int>  $settlementIds
     * @return array<string, array<int, array{settlement: int, amount: float}>>
     */
    private static function carried(array $settlementIds): array
    {
        $carried = [];
        $add = function (string $key, int $id, int $settlement, float $amount) use (&$carried) {
            $carried[$key][$id] = ['settlement' => $settlement, 'amount' => round(($carried[$key][$id]['amount'] ?? 0) + $amount, 2)];
        };
        FinalSettlementLine::query()->whereIn('final_settlement_id', $settlementIds)->whereNotNull('basis')->get(['id', 'final_settlement_id', 'code', 'amount', 'basis'])
            ->each(function (FinalSettlementLine $line) use ($add) {
                $basis = (array) $line->basis;
                $settlement = (int) $line->final_settlement_id;
                match (true) {
                    $line->code === self::PAYABLE => array_map(fn ($r) => $add(PayrollCommitments::REPORT, (int) $r['id'], $settlement, (float) $r['amount']), (array) ($basis['reports'] ?? [])),
                    $line->code === self::BALANCE => array_map(fn ($a) => $add(PayrollCommitments::RECOVERY, (int) $a['id'], $settlement, (float) $a['amount']), (array) ($basis['advances'] ?? [])),
                    in_array($basis['source']['key'] ?? null, [PayrollCommitments::REPORT, PayrollCommitments::PAYOUT, PayrollCommitments::RECOVERY], true) => array_map(fn ($id) => $add($basis['source']['key'], (int) $id, $settlement, count((array) $basis['source']['ids']) === 1 ? (float) $line->amount : 0.0), (array) ($basis['source']['ids'] ?? [])),
                    default => null,
                };
            });

        return $carried;
    }
}
