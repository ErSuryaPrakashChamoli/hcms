<?php

namespace App\Domain\Notifications\Services;

use App\Domain\Audit\Services\ChangeIntelligence;
use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Model;

/** Builds the variable tree templates and audiences work from. */
final class NotificationContext
{
    public function __construct(private readonly TenantContext $tenants) {}

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    public function build(?Model $subject = null, array $extra = [], ?User $initiator = null): array
    {
        $employee = $this->employeeOf($subject);

        return array_replace_recursive([
            'tenant' => ['name' => $this->tenants->current()?->name],
            'app' => ['name' => config('peopleos.name'), 'url' => url('/admin')],
            'initiator' => $initiator ? ['id' => $initiator->id, 'name' => $initiator->name, 'email' => $initiator->email] : null,
            'employee' => $employee ? $this->employeeVars($employee) : null,
            'subject' => $subject ? $this->subjectVars($subject) : null,
        ], $extra);
    }

    public function employeeOf(?Model $subject): ?Employee
    {
        return match (true) {
            $subject instanceof Employee => $subject,
            $subject === null => null,
            method_exists($subject, 'employee') => $subject->employee()->first(),
            method_exists($subject, 'subject') && $subject->subject()->first() instanceof Employee => $subject->subject()->first(),
            default => null,
        };
    }

    /** @return array<string, mixed> */
    private function employeeVars(Employee $employee): array
    {
        $employee->loadMissing(['person', 'currentPosition.designation', 'currentPosition.department', 'currentPosition.company', 'currentManager.manager.person']);

        return [
            'id' => $employee->id,
            'code' => $employee->employee_code,
            'name' => $employee->person?->display_name,
            'first_name' => $employee->person?->first_name,
            'email' => $employee->work_email,
            'joining_date' => $employee->joining_date,
            'state' => $employee->lifecycle_state->getLabel(),
            'designation' => $employee->currentPosition?->designation?->name,
            'department' => $employee->currentPosition?->department?->name,
            'company' => $employee->currentPosition?->company?->name,
            'manager' => $employee->currentManager?->manager?->person?->display_name,
            'user_id' => $employee->user_id,
            'manager_user_id' => $employee->currentManager?->manager?->user_id,
        ];
    }

    /**
     * Phase 14: a classified subject (financial, statutory, highly sensitive or confidential record)
     * exposes only its identity, label and status to templates, and highly sensitive attributes are
     * never exposed. A template can no longer print an account number, an amount or a confidential note.
     *
     * @return array<string, mixed>
     */
    private function subjectVars(Model $subject): array
    {
        $vars = ['type' => class_basename($subject), 'id' => $subject->getKey()];
        $classified = app(ChangeIntelligence::class)->classified($subject::class);
        $sensitive = (array) (config('peopleos.data_classification.highly_sensitive')[$subject::class] ?? []);

        foreach ($subject->getAttributes() as $key => $value) {
            if (in_array($key, $subject->getHidden(), true) || in_array($key, $sensitive, true)
                || ($classified && ! in_array($key, ['status', 'number', 'code', 'type', 'effective_from', 'effective_to'], true))) {
                continue;
            }

            $vars[$key] = $subject->getAttribute($key) instanceof \BackedEnum ? $subject->getAttribute($key)->value : $subject->getAttribute($key);
        }

        if (method_exists($subject, 'auditLabel')) {
            $vars['label'] = $subject->auditLabel();
        }

        return $vars;
    }
}
