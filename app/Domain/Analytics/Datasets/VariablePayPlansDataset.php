<?php

namespace App\Domain\Analytics\Datasets;

use App\Domain\Payroll\Models\VariablePayPlan;
use App\Domain\Payroll\Models\VariablePayRequest;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * HROne parity AN-26 (HROne report "Variable pay plan": the month-wise variable schedule per plan): one row per plan with
 * its schedule (month and target for each month due), the totals, the payouts entered for it and what is due next. For
 * payroll (payroll.manage, as Payroll → Variable pay plans) in the reader's access scope; targets and payouts are
 * sensitive fields of that right. Month by month, target against payout, is in variable_pay_requests (plan_target).
 */
class VariablePayPlansDataset extends Dataset
{
    public function key(): string
    {
        return 'variable_pay_plans';
    }

    public function label(): string
    {
        return 'Variable pay plans (schedule and payouts)';
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
        $current = now()->format('Y-m');

        return [
            'plan' => ['label' => 'Plan', 'type' => 'string', 'value' => fn (VariablePayPlan $p) => $p->name ?: 'Plan #'.$p->id],
            ...$this->employeeFields(fn (VariablePayPlan $p) => $p->employee),
            'component' => ['label' => 'Pay component', 'type' => 'string', 'value' => fn (VariablePayPlan $p) => $p->component?->name],
            'status' => ['label' => 'Status', 'type' => 'string', 'value' => fn (VariablePayPlan $p) => $p->status],
            'months' => ['label' => 'Months in the schedule', 'type' => 'number', 'value' => fn (VariablePayPlan $p) => count($p->targets())],
            'first_month' => ['label' => 'First month', 'type' => 'string', 'value' => fn (VariablePayPlan $p) => array_key_first($p->targets())],
            'last_month' => ['label' => 'Last month', 'type' => 'string', 'value' => fn (VariablePayPlan $p) => array_key_last($p->targets())],
            'schedule' => ['label' => 'Schedule (month: target)', 'type' => 'string', 'sensitive' => true,
                'value' => fn (VariablePayPlan $p) => collect($p->targets())->map(fn (float $target, string $month) => $month.': '.number_format($target, 2))->implode(' · ') ?: null],
            'total_target' => $money('Total target', fn (VariablePayPlan $p) => round(array_sum($p->targets()), 2)),
            'payouts_entered' => ['label' => 'Payouts entered', 'type' => 'number', 'value' => fn (VariablePayPlan $p) => self::live($p)->count()],
            'paid_total' => $money('Paid so far', fn (VariablePayPlan $p) => round((float) $p->requests->where('status', 'paid')->sum('amount'), 2)),
            'awaiting_total' => $money('Entered, not yet paid', fn (VariablePayPlan $p) => round((float) $p->requests->whereIn('status', ['pending', 'approved'])->sum('amount'), 2)),
            // Months due up to this month for which no payout is entered (a rejected or cancelled one does not count).
            'months_without_payout' => ['label' => 'Months due without a payout', 'type' => 'number', 'value' => fn (VariablePayPlan $p) => collect(array_keys($p->targets()))
                ->filter(fn (string $month) => $month <= $current)->diff(self::live($p)->pluck('plan_month'))->count()],
            'next_month' => ['label' => 'Next month due', 'type' => 'string', 'value' => fn (VariablePayPlan $p) => self::next($p, $current)],
            'next_target' => $money('Next target', fn (VariablePayPlan $p) => ($month = self::next($p, $current)) === null ? null : $p->targets()[$month]),
            'created_on' => ['label' => 'Created on', 'type' => 'date', 'value' => fn (VariablePayPlan $p) => $p->created_at],
            'closed_on' => ['label' => 'Closed on', 'type' => 'date', 'value' => fn (VariablePayPlan $p) => $p->closed_at],
            'count' => ['label' => 'Plans (1 per row)', 'type' => 'number', 'value' => fn (VariablePayPlan $p) => 1],
        ];
    }

    public function query(): Builder
    {
        return VariablePayPlan::query()->with([...self::employeeWith('employee'), 'component', 'requests'])->orderBy('employee_id')->orderBy('id');
    }

    /** @return Collection<int, VariablePayRequest> payouts entered for the plan and still standing */
    private static function live(VariablePayPlan $plan): Collection
    {
        return $plan->requests->whereNotIn('status', ['rejected', 'cancelled'])->values();
    }

    /** The first scheduled month from this month on without a payout entered (null for a closed plan). */
    private static function next(VariablePayPlan $plan, string $current): ?string
    {
        if ($plan->status !== 'active') {
            return null;
        }
        $entered = self::live($plan)->pluck('plan_month')->all();

        return collect(array_keys($plan->targets()))->first(fn (string $month) => $month >= $current && ! in_array($month, $entered, true));
    }
}
