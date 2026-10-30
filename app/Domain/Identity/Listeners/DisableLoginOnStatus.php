<?php

namespace App\Domain\Identity\Listeners;

use App\Domain\Employment\Models\Employee;
use App\Domain\Enterprise\Services\SecurityPolicy;
use App\Domain\Identity\Enums\UserStatus;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Scopes\AccessScope;
use App\Domain\Lifecycle\Enums\LifecycleState;
use App\Domain\Lifecycle\Events\EmployeeLifecycleChanged;

/**
 * HROne setup parity (Security → Disable login): an employee in one of the tenant's listed lifecycle states
 * (security.disable_login_states) cannot sign in.
 *
 * - Entering a listed state suspends the employee's active account, audited with the reason "Login disabled by status
 *   …", and marks it `disabled_by_rule`. The suspension ends the person's sessions at their next request.
 * - Leaving the listed states reactivates the account only when the rule suspended it and nothing changed its status
 *   since (any other change of status clears the mark, User). An account suspended by an administrator, an exit or
 *   SCIM is never reactivated here, and exits keep their own behaviour.
 * - reconcile() applies the same rule to everyone (daily, peopleos:security:login-status), so a state listed after
 *   employees entered it, or a change of the list, takes effect without a new transition.
 *
 * With no state listed (the default) nothing changes.
 */
final class DisableLoginOnStatus
{
    public function __construct(private readonly SecurityPolicy $policy) {}

    public function handle(EmployeeLifecycleChanged $event): void
    {
        $this->apply($event->employee, $event->to);
    }

    /** @return array{disabled: int, enabled: int} */
    public function reconcile(): array
    {
        $counts = ['disabled' => 0, 'enabled' => 0];
        $states = $this->policy->loginDisabledStates();
        $employees = Employee::query()->withoutGlobalScope(AccessScope::class)->whereNotNull('user_id')
            ->where(fn ($q) => $q->whereIn('lifecycle_state', $states === [] ? ['-'] : $states)
                ->orWhereIn('user_id', User::forCurrentTenant()->where('disabled_by_rule', true)->select('id')))
            ->orderBy('id');
        foreach ($employees->cursor() as $employee) {
            $outcome = $this->apply($employee, $employee->lifecycle_state);
            if ($outcome !== null) {
                $counts[$outcome]++;
            }
        }

        return $counts;
    }

    /** @return 'disabled'|'enabled'|null what was done to the employee's account */
    public function apply(Employee $employee, LifecycleState $state): ?string
    {
        $user = $employee->user_id === null ? null : User::forCurrentTenant()->find($employee->user_id);
        if ($user === null || $user->isPlatformAdmin()) {
            return null;
        }
        $listed = in_array($state->value, $this->policy->loginDisabledStates(), true);

        if ($listed && $user->status === UserStatus::Active) {
            $user->forceFill(['status' => UserStatus::Suspended, 'disabled_by_rule' => true])
                ->withAuditReason('Login disabled by status '.$state->getLabel().' (security policy)')->save();

            return 'disabled';
        }
        if (! $listed && $user->status === UserStatus::Suspended && ($user->getAttributes()['disabled_by_rule'] ?? false)) {
            $user->forceFill(['status' => UserStatus::Active, 'disabled_by_rule' => false])
                ->withAuditReason('Login enabled: status '.$state->getLabel().' no longer disables it (security policy)')->save();

            return 'enabled';
        }

        return null;
    }
}
