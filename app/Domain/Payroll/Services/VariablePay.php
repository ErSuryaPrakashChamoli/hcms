<?php

namespace App\Domain\Payroll\Services;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Employment\Models\Employee;
use App\Domain\Experience\Services\SelfServiceLock;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Scopes\AccessScope;
use App\Domain\Payroll\Events\PayrollEvent;
use App\Domain\Payroll\Models\SalaryComponent;
use App\Domain\Payroll\Models\VariablePayPlan;
use App\Domain\Payroll\Models\VariablePayRequest;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Variable pay requests (HROne parity PY-05): an incentive, bonus or other one-off earning asked for an employee and
 * a pay month, by the employee, their manager or HR. Approved requests are paid by that month's payroll (or the
 * next run if it has passed) through VariablePayInput, with the component's own tax and statutory treatment.
 */
final class VariablePay
{
    public function __construct(private readonly AuditRecorder $audit) {}

    /** @param  ?VariablePayPlan  $plan  HROne setup parity BP-9: the plan (and its $planMonth) this payout is for */
    public function request(Employee $employee, SalaryComponent $component, float $amount, string $payMonth, string $reason, ?User $requester = null, ?VariablePayPlan $plan = null, ?string $planMonth = null): VariablePayRequest
    {
        // HROne setup parity P4: the employee request lock refuses the employee's own request while it applies.
        app(SelfServiceLock::class)->assertOpen($employee, 'variable_pay', $requester);
        if ($component->type !== 'earning' || $component->is_statutory) {
            throw new RuntimeException("{$component->name} is not an earning that can be paid as variable pay.");
        }
        // HROne setup parity (PS-1): once variable components are marked, variable pay uses only them.
        if ($component->pay_nature !== 'variable' && SalaryComponent::query()->where('pay_nature', 'variable')->where('status', 'active')->exists()) {
            throw new RuntimeException("{$component->name} is a fixed component; choose a variable one.");
        }
        if ($amount <= 0) {
            throw new RuntimeException('The amount must be more than zero.');
        }
        if (! preg_match('/^\d{4}-\d{2}$/', $payMonth) || $payMonth < now()->subMonth()->format('Y-m')) {
            throw new RuntimeException('Pay it this month or later (YYYY-MM).');
        }
        if (trim($reason) === '') {
            throw new RuntimeException('Give a reason.');
        }

        $request = new VariablePayRequest(['employee_id' => $employee->id, 'salary_component_id' => $component->id, 'amount' => round($amount, 2), 'pay_month' => $payMonth, 'reason' => $reason, 'status' => 'pending', 'requested_by' => ($requester ?? auth()->user())?->id]
            + ($plan !== null ? ['variable_pay_plan_id' => $plan->id, 'plan_month' => $planMonth] : []));
        $request->withAuditReason($reason)->save();
        $this->audit->record(AuditAction::Submitted, 'payroll', $request, reason: $reason, metadata: ['component' => $component->code, 'pay_month' => $payMonth]);
        $this->event('payroll.variable_pay_requested', $request);

        return $request;
    }

    public function approve(VariablePayRequest $request, ?string $note = null, ?User $actor = null): VariablePayRequest
    {
        return $this->decide($request, 'approved', $note, $actor);
    }

    public function reject(VariablePayRequest $request, string $note, ?User $actor = null): VariablePayRequest
    {
        if (trim($note) === '') {
            throw new RuntimeException('Give the reason for rejecting.');
        }

        return $this->decide($request, 'rejected', $note, $actor);
    }

    public function cancel(VariablePayRequest $request, string $reason): VariablePayRequest
    {
        if (! in_array($request->status, ['pending', 'approved'], true)) {
            throw new RuntimeException('Only a request not yet paid can be cancelled.');
        }
        $request->withAuditReason($reason)->update(['status' => 'cancelled']);
        $this->audit->record(AuditAction::Cancelled, 'payroll', $request, reason: $reason);

        return $request;
    }

    private function decide(VariablePayRequest $request, string $status, ?string $note, ?User $actor): VariablePayRequest
    {
        $actor ??= auth()->user();
        if ($actor === null) {
            throw new RuntimeException('Variable pay is decided by a person.');
        }

        return DB::transaction(function () use ($request, $status, $note, $actor) {
            $locked = VariablePayRequest::query()->whereKey($request->getKey())->lockForUpdate()->firstOrFail();
            if ($locked->status !== 'pending') {
                throw new RuntimeException('This request has already been decided.');
            }
            if (Employee::query()->withoutGlobalScope(AccessScope::class)->whereKey($locked->employee_id)->value('user_id') === $actor->id) {
                throw new RuntimeException('You cannot decide your own variable pay.');
            }
            $locked->withAuditReason($note)->update(['status' => $status, 'reviewed_by' => $actor->id, 'reviewed_at' => now(), 'review_note' => $note]);
            $this->audit->record($status === 'approved' ? AuditAction::Approved : AuditAction::Rejected, 'payroll', $locked, reason: $note, actor: $actor);
            $this->event($status === 'approved' ? 'payroll.variable_pay_approved' : 'payroll.variable_pay_rejected', $locked);

            return $locked;
        });
    }

    /** HROne setup parity P5: variable_pay.* as payroll events — references only (the request, its pay month and the employee). */
    private function event(string $name, VariablePayRequest $request): void
    {
        PayrollEvent::dispatch($name, $request, ['request_id' => (int) $request->id, 'pay_month' => $request->pay_month, 'status' => $request->status, 'employee_id' => (int) $request->employee_id]);
    }
}
