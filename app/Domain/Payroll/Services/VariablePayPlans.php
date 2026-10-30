<?php

namespace App\Domain\Payroll\Services;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Scopes\AccessScope;
use App\Domain\Identity\Services\AccessScopes;
use App\Domain\Payroll\Events\PayrollEvent;
use App\Domain\Payroll\Models\SalaryComponent;
use App\Domain\Payroll\Models\VariablePayPlan;
use App\Domain\Payroll\Models\VariablePayRequest;
use App\Support\Tenancy\TenantRunner;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * HROne setup parity BP-9 (variable payment schedule): payroll plans an employee's variable pay on an earning
 * component — the months it falls due and each month's target. On `payroll.variable.task_day` (0 = never) the
 * employee's manager is asked for each due month's payout (`payroll.variable_pay_due`, once per plan and month); the
 * payout they enter becomes an ordinary variable pay request, approved and paid as before (VariablePay, VariablePayInput).
 */
final class VariablePayPlans
{
    /** Request statuses that hold a plan month (a rejected or cancelled payout can be entered again). */
    private const LIVE = ['pending', 'approved', 'paid'];

    public function __construct(private readonly AuditRecorder $audit, private readonly VariablePay $variablePay, private readonly AccessScopes $scopes) {}

    /** @param  list<array{month?: string, target?: float|int|string}>  $schedule */
    public function create(Employee $employee, SalaryComponent $component, array $schedule, ?string $name, User $by, ?string $note = null): VariablePayPlan
    {
        $this->assertPayroll($by, $employee);
        if ($component->type !== 'earning' || $component->is_statutory) {
            throw new RuntimeException("{$component->name} is not an earning that can be paid as variable pay.");
        }
        if ($component->pay_nature !== 'variable' && SalaryComponent::query()->where('pay_nature', 'variable')->where('status', 'active')->exists()) {
            throw new RuntimeException("{$component->name} is a fixed component; choose a variable one.");
        }
        $plan = new VariablePayPlan(['employee_id' => $employee->id, 'salary_component_id' => $component->id, 'name' => filled($name) ? mb_substr(trim((string) $name), 0, 120) : null,
            'schedule' => $this->schedule($schedule), 'status' => 'active', 'note' => filled($note) ? mb_substr(trim((string) $note), 0, 500) : null, 'created_by' => $by->id]);
        $plan->withAuditReason($note)->save();

        return $plan;
    }

    /** @param  list<array{month?: string, target?: float|int|string}>  $schedule */
    public function update(VariablePayPlan $plan, array $schedule, ?string $name, User $by, string $reason): VariablePayPlan
    {
        $this->assertPayroll($by, $this->employeeOf($plan));
        if ($plan->status !== 'active') {
            throw new RuntimeException('A closed plan is not changed.');
        }
        if (trim($reason) === '') {
            throw new RuntimeException('Give the reason for the change.');
        }
        $clean = $this->schedule($schedule);
        $kept = array_column($clean, 'month');
        $entered = $plan->requests()->whereIn('status', self::LIVE)->pluck('plan_month')->filter()->unique()->values();
        if (($lost = $entered->diff($kept))->isNotEmpty()) {
            throw new RuntimeException('A payout was already entered for '.$lost->implode(', ').'; keep those months.');
        }
        $plan->withAuditReason($reason)->update(['schedule' => $clean, 'name' => filled($name) ? mb_substr(trim((string) $name), 0, 120) : $plan->name]);

        return $plan;
    }

    public function close(VariablePayPlan $plan, string $reason, User $by): VariablePayPlan
    {
        $this->assertPayroll($by, $this->employeeOf($plan));
        if (trim($reason) === '') {
            throw new RuntimeException('Give the reason for closing the plan.');
        }
        $plan->withAuditReason($reason)->update(['status' => 'closed', 'closed_at' => now(), 'closed_by' => $by->id]);

        return $plan;
    }

    /**
     * The month's payout, entered by the employee's manager or payroll (never for oneself): a pending variable pay
     * request that names the plan and month.
     */
    public function enterPayout(VariablePayPlan $plan, string $month, float $amount, string $note, User $by): VariablePayRequest
    {
        $employee = $this->employeeOf($plan);
        if (! $this->mayEnter($by, $employee)) {
            throw new RuntimeException('Only the employee\'s manager or payroll enters the payout.');
        }
        if (trim($note) === '') {
            throw new RuntimeException('Say what the payout is for.');
        }

        return DB::transaction(function () use ($plan, $employee, $month, $amount, $note, $by) {
            $locked = VariablePayPlan::query()->withoutGlobalScope(AccessScope::class)->whereKey($plan->getKey())->lockForUpdate()->firstOrFail();
            if ($locked->status !== 'active') {
                throw new RuntimeException('This plan is closed.');
            }
            if (! array_key_exists($month, $locked->targets())) {
                throw new RuntimeException("{$month} is not a month of this plan.");
            }
            if (VariablePayRequest::query()->withoutGlobalScope(AccessScope::class)->where('variable_pay_plan_id', $locked->id)->where('plan_month', $month)->whereIn('status', self::LIVE)->exists()) {
                throw new RuntimeException("The payout for {$month} has already been entered.");
            }
            $component = SalaryComponent::query()->findOrFail($locked->salary_component_id);

            return $this->variablePay->request($employee, $component, $amount, $month, $note, $by, $locked, $month);
        });
    }

    /** Whether the user may enter a payout for the employee: payroll in scope, or their current manager — never for themselves. */
    public function mayEnter(User $user, Employee $employee): bool
    {
        if ($employee->user_id !== null && (int) $employee->user_id === (int) $user->id) {
            return false;
        }

        return ($user->hasPermission('payroll.manage') && $this->scopes->allows($user, $employee)) || (int) ($this->managerUserId($employee) ?? 0) === (int) $user->id;
    }

    /**
     * Active plans with a month due and no payout entered for it.
     *
     * @return Collection<int, VariablePayPlan>
     */
    public function due(string $month): Collection
    {
        return VariablePayPlan::query()->withoutGlobalScope(AccessScope::class)->with(['component', 'employee.person'])->where('status', 'active')->orderBy('id')->get()
            ->filter(fn (VariablePayPlan $p) => array_key_exists($month, $p->targets())
                && ! VariablePayRequest::query()->withoutGlobalScope(AccessScope::class)->where('variable_pay_plan_id', $p->id)->where('plan_month', $month)->whereIn('status', self::LIVE)->exists())
            ->values();
    }

    /**
     * Ask the manager of every plan due this month for its payout, once per plan and month (scheduler claims), on the
     * organisation's task day.
     *
     * @return int plans asked about
     */
    public function askManagers(Carbon $today): int
    {
        $month = $today->format('Y-m');
        $asked = 0;
        foreach ($this->due($month) as $plan) {
            if (! TenantRunner::claim("variable_pay_plan:{$plan->id}", $month)) {
                continue;
            }
            $employee = $plan->employee;
            PayrollEvent::dispatch('payroll.variable_pay_due', $plan, [
                'plan' => $plan->name ?: $plan->component?->name, 'month' => $month, 'target' => $plan->targets()[$month], 'employee' => $employee?->person?->full_name,
                'employee_id' => $plan->employee_id, 'recipients' => array_values(array_filter([$employee ? $this->managerUserId($employee) : null])),
            ]);
            $this->audit->record(AuditAction::StatusChange, 'payroll', $plan, [], null, metadata: ['asked_for' => $month]);
            $asked++;
        }

        return $asked;
    }

    /** @return list<array{month: string, target: float}> */
    private function schedule(array $rows): array
    {
        $clean = [];
        foreach ($rows as $row) {
            $month = trim((string) ($row['month'] ?? ''));
            $target = $row['target'] ?? null;
            if ($month === '' && blank($target)) {
                continue;
            }
            if (! preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)) {
                throw new RuntimeException('Give each month as YYYY-MM.');
            }
            if (! is_numeric($target) || (float) $target < 0) {
                throw new RuntimeException("Give a target of zero or more for {$month}.");
            }
            if (isset($clean[$month])) {
                throw new RuntimeException("{$month} appears twice.");
            }
            $clean[$month] = ['month' => $month, 'target' => round((float) $target, 2)];
        }
        if ($clean === []) {
            throw new RuntimeException('Add at least one month with its target.');
        }
        ksort($clean);

        return array_values($clean);
    }

    private function assertPayroll(User $by, Employee $employee): void
    {
        if (! $by->hasPermission('payroll.manage') || ! $this->scopes->allows($by, $employee)) {
            throw new RuntimeException('Variable pay plans are kept by payroll.');
        }
        if ($employee->user_id !== null && (int) $employee->user_id === (int) $by->id) {
            throw new RuntimeException('No one plans their own variable pay.');
        }
    }

    private function employeeOf(VariablePayPlan $plan): Employee
    {
        return Employee::query()->withoutGlobalScope(AccessScope::class)->findOrFail($plan->employee_id);
    }

    private function managerUserId(Employee $employee): ?int
    {
        $id = $employee->currentManager()->with('manager')->first()?->manager?->user_id;

        return $id !== null ? (int) $id : null;
    }
}
