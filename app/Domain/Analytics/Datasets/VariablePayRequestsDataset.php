<?php

namespace App\Domain\Analytics\Datasets;

use App\Domain\Payroll\Models\VariablePayRequest;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * HROne parity AN-26 (HROne reports "Variable request", "Variable paid"; Payroll dashboard "Variables"): every variable
 * pay request — incentive, bonus or a payout entered for a variable pay plan month (with that month's target) — with its
 * pay month and status; "paid" requests were paid by the payroll of their pay month. For payroll (payroll.manage) in the
 * reader's access scope; amounts are sensitive fields of that right.
 */
class VariablePayRequestsDataset extends Dataset
{
    public function key(): string
    {
        return 'variable_pay_requests';
    }

    public function label(): string
    {
        return 'Variable pay requests and payouts';
    }

    public function category(): string
    {
        return 'Payroll & compensation';
    }

    public function permissions(): array
    {
        return ['payroll.manage'];
    }

    public function sensitivePermission(): ?string
    {
        return 'payroll.manage';
    }

    public function fields(): array
    {
        $money = fn (string $label, \Closure $value) => ['label' => $label, 'type' => 'number', 'sensitive' => true, 'value' => $value];
        $target = fn (VariablePayRequest $r) => $r->plan !== null && $r->plan_month !== null ? ($r->plan->targets()[$r->plan_month] ?? null) : null;

        return [
            'pay_month' => ['label' => 'Pay month', 'type' => 'string', 'value' => fn (VariablePayRequest $r) => $r->pay_month],
            'pay_month_start' => ['label' => 'Pay month (date)', 'type' => 'date', 'value' => fn (VariablePayRequest $r) => preg_match('/^\d{4}-\d{2}$/', (string) $r->pay_month) ? Carbon::parse($r->pay_month.'-01') : null],
            ...$this->employeeFields(fn (VariablePayRequest $r) => $r->employee),
            'component' => ['label' => 'Pay component', 'type' => 'string', 'value' => fn (VariablePayRequest $r) => $r->component?->name],
            'plan' => ['label' => 'Variable pay plan', 'type' => 'string', 'value' => fn (VariablePayRequest $r) => $r->plan ? ($r->plan->name ?: 'Plan #'.$r->plan->id) : null],
            'plan_month' => ['label' => 'Plan month', 'type' => 'string', 'value' => fn (VariablePayRequest $r) => $r->plan_month],
            'plan_target' => $money('Plan target for the month', $target),
            'amount' => $money('Amount', fn (VariablePayRequest $r) => round((float) $r->amount, 2)),
            'variance' => $money('Amount less target', fn (VariablePayRequest $r) => ($t = $target($r)) === null ? null : round((float) $r->amount - $t, 2)),
            'reason' => ['label' => 'Reason', 'type' => 'string', 'value' => fn (VariablePayRequest $r) => $r->reason],
            'status' => ['label' => 'Status (code)', 'type' => 'string', 'value' => fn (VariablePayRequest $r) => $r->status],
            'status_label' => ['label' => 'Status', 'type' => 'string', 'value' => fn (VariablePayRequest $r) => VariablePayRequest::STATUSES[$r->status] ?? $r->status],
            'requested_on' => ['label' => 'Requested on', 'type' => 'date', 'value' => fn (VariablePayRequest $r) => $r->created_at],
            'requested_by' => ['label' => 'Requested by', 'type' => 'string', 'value' => fn (VariablePayRequest $r) => $r->requester?->name],
            'reviewed_on' => ['label' => 'Decided on', 'type' => 'date', 'value' => fn (VariablePayRequest $r) => $r->reviewed_at],
            'reviewed_by' => ['label' => 'Decided by', 'type' => 'string', 'value' => fn (VariablePayRequest $r) => $r->reviewer?->name],
            'count' => ['label' => 'Requests (1 per row)', 'type' => 'number', 'value' => fn (VariablePayRequest $r) => 1],
        ];
    }

    public function query(): Builder
    {
        return VariablePayRequest::query()->with([...self::employeeWith('employee'), 'component', 'plan', 'requester', 'reviewer'])->orderByDesc('pay_month')->orderByDesc('id');
    }
}
