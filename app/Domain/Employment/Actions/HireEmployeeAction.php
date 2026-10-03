<?php

namespace App\Domain\Employment\Actions;

use App\Domain\Employment\Events\EmploymentEvent;
use App\Domain\Employment\Exceptions\DuplicatePersonException;
use App\Domain\Employment\Models\Employee;
use App\Domain\Employment\Services\EmployeeCodeGenerator;
use App\Domain\Lifecycle\Enums\LifecycleState;
use App\Domain\Lifecycle\Services\LifecycleEngine;
use App\Domain\People\Models\Person;
use App\Domain\People\Services\PersonMatcher;
use App\Domain\Platform\Services\SettingsRepository;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Person + Employee + first position + line manager + lifecycle "joined/probation" in one
 * transaction. Everything it touches is audited by the models and services it calls.
 */
final class HireEmployeeAction
{
    public function __construct(
        private readonly EmployeeCodeGenerator $codes,
        private readonly AssignPositionAction $positions,
        private readonly ChangeManagerAction $managers,
        private readonly LifecycleEngine $lifecycle,
        private readonly SettingsRepository $settings,
        private readonly PersonMatcher $matcher,
    ) {}

    /**
     * @param  array<string, mixed>  $person  Person attributes, or ['id' => existing person id]
     * @param  array<string, mixed>  $employee  joining_date, probation_end_date?, work_email?, work_phone?, employee_code?
     * @param  array<string, mixed>  $position  dimension ids
     */
    public function handle(array $person, array $employee, array $position, ?int $managerId = null, ?string $reason = null): Employee
    {
        return DB::transaction(function () use ($person, $employee, $position, $managerId, $reason) {
            if (isset($person['id'])) {
                $personModel = Person::query()->findOrFail($person['id']);
                if ($personModel->employee()->exists()) {
                    throw new DuplicatePersonException(collect([['person_id' => $personModel->getKey(), 'employee_id' => $personModel->employee->getKey(), 'employee_code' => $personModel->employee->employee_code, 'name' => $personModel->display_name, 'matched_on' => ['person'], 'definite' => true]]));
                }
            } else {
                $allowDuplicate = (bool) ($person['allow_duplicate'] ?? false);
                unset($person['allow_duplicate']);
                $definite = $this->matcher->definite($person, $employee);
                // Phase 14: a definite match outside the caller's scope can never be overridden by them.
                if ($definite->isNotEmpty() && (! $allowDuplicate || $definite->contains(fn (array $c) => $c['outside_scope'] ?? false))) {
                    throw new DuplicatePersonException($definite);
                }
                $personModel = new Person($person);
                $personModel->withAuditReason($reason)->save();
            }

            $joiningDate = Carbon::parse($employee['joining_date'] ?? now())->startOfDay();
            $probationEnd = isset($employee['probation_end_date'])
                ? Carbon::parse($employee['probation_end_date'])
                : $joiningDate->copy()->addMonths((int) $this->settings->get('employee.probation.default_months', 6));

            $record = new Employee([
                'person_id' => $personModel->getKey(),
                'employee_code' => $employee['employee_code'] ?? $this->codes->next(),
                'lifecycle_state' => LifecycleState::PreEmployee,
                'joining_date' => $joiningDate,
                'probation_end_date' => $probationEnd,
                'work_email' => $employee['work_email'] ?? null,
                'work_phone' => $employee['work_phone'] ?? null,
                'user_id' => $employee['user_id'] ?? null,
            ]);
            $record->withAuditReason($reason)->save();

            $this->positions->handle($record, $position, 'hire', $joiningDate, $reason);

            if ($managerId !== null) {
                $this->managers->handle($record, Employee::query()->findOrFail($managerId), 'line', $joiningDate, $reason);
            }

            if ($joiningDate->isFuture()) {
                $this->lifecycle->transition($record, LifecycleState::Preboarding, now(), $reason);
            } else {
                $this->lifecycle->transition($record, LifecycleState::Joined, $joiningDate, $reason);
                $this->lifecycle->transition($record, LifecycleState::Probation, $joiningDate, $reason);
            }

            EmploymentEvent::dispatch('employee.created', $record, $record, ['employee_code' => $record->employee_code, 'joining_date' => $joiningDate->toDateString(), 'source' => $record->source]);

            return $record->refresh();
        });
    }
}
