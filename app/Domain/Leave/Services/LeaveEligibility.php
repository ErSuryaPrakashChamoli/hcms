<?php

namespace App\Domain\Leave\Services;

use App\Domain\Employment\Models\Employee;
use App\Domain\Leave\Models\LeaveType;
use App\Domain\Leave\Support\Eligibility;
use App\Domain\Lifecycle\Enums\LifecycleState;
use Illuminate\Support\Carbon;

/**
 * Deterministic leave eligibility (Phase 3 §11–§12): type active and effective, gender applicability,
 * lifecycle state, a policy entitlement for the type, probation, and service period
 * (immediately / after N days / after N months / after confirmation). Used by requests, the API,
 * accrual and the UI — never re-implemented elsewhere.
 */
final class LeaveEligibility
{
    public function __construct(private readonly LeaveEntitlements $entitlements) {}

    public function check(Employee $employee, LeaveType $type, Carbon|string|null $on = null): Eligibility
    {
        $on = Carbon::parse($on ?? now())->startOfDay();

        if ($type->status->value !== 'active') {
            return Eligibility::no("{$type->name} is not active.");
        }
        if (($type->effective_from && $type->effective_from->gt($on)) || ($type->effective_to && $type->effective_to->lt($on))) {
            return Eligibility::no("{$type->name} is not in effect on {$on->toDateString()}.");
        }
        if ($type->applicable_gender && $employee->person?->gender && $type->applicable_gender !== $employee->person->gender) {
            return Eligibility::no("{$type->name} does not apply to this employee.");
        }

        $rule = $this->entitlements->forType($employee, $type, $on)
            ?? ($type->category === 'unpaid' ? LeaveEntitlements::DEFAULTS : null);
        if ($rule === null) {
            return Eligibility::no("No leave policy grants {$type->name} to this employee.");
        }

        $states = $rule['eligible_states'] ?? config('peopleos.leave.eligible_states');
        if (! in_array($employee->lifecycle_state->value, (array) $states, true)) {
            return Eligibility::no("{$type->name} is not available while the employee is {$employee->lifecycle_state->getLabel()}.", $rule);
        }
        if (! $rule['probation_eligible'] && $employee->lifecycle_state === LifecycleState::Probation) {
            return Eligibility::no("{$type->name} is not available during probation.", $rule);
        }

        $after = $rule['eligible_after'] ?? 'immediate';
        $value = (int) ($rule['eligible_after_value'] ?? 0);
        $joined = $employee->joining_date?->copy()->startOfDay();

        if ($after === 'confirmation' && $employee->confirmation_date === null) {
            return Eligibility::no("{$type->name} is available after confirmation.", $rule);
        }
        if (in_array($after, ['days', 'months'], true) && $value > 0) {
            if ($joined === null) {
                return Eligibility::no('The joining date is not recorded.', $rule);
            }
            $from = $after === 'days' ? $joined->copy()->addDays($value) : $joined->copy()->addMonths($value);
            if ($on->lt($from)) {
                return Eligibility::no("{$type->name} is available from {$from->toDateString()} ({$value} {$after} of service).", $rule);
            }
        }

        return Eligibility::yes($rule);
    }
}
