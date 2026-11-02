<?php

namespace App\Domain\Employment\Actions;

use App\Domain\Configuration\Services\PolicyResolver;
use App\Domain\Employment\Events\EmploymentEvent;
use App\Domain\Employment\Exceptions\DuplicatePersonException;
use App\Domain\Employment\Exceptions\EmployeeCodeTakenException;
use App\Domain\Employment\Models\Employee;
use App\Domain\Employment\Services\EmployeeCodeGenerator;
use App\Domain\Lifecycle\Enums\LifecycleState;
use App\Domain\Lifecycle\Services\LifecycleEngine;
use App\Domain\People\Models\Person;
use App\Domain\People\Services\PersonMatcher;
use App\Domain\Platform\Services\SettingsRepository;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Person + Employee + first position + line manager + lifecycle "joined/probation" in one
 * transaction. Everything it touches is audited by the models and services it calls.
 * Without an employee_code the code comes from the hire's company series; a given code must be free in the tenant.
 */
final class HireEmployeeAction
{
    /**
     * HROne parity WO-3 (§23.2.3 #12): the states a historic load may start in instead of the default (a future joining date
     * → pre-boarding, otherwise joined → probation). Each is reached through the lifecycle engine from joined, exactly as
     * "Change lifecycle state" would: confirmed = joined → probation → confirmed on the confirmation date; active = joined →
     * active (no probation). Other states (notice, exited, …) have their own records and are refused here.
     */
    public const HISTORIC_START_STATES = [LifecycleState::Confirmed, LifecycleState::Active];

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
     * @param  array<string, mixed>  $employee  joining_date, probation_end_date?, work_email?, work_phone?, employee_code?,
     *                                          work_phone_country_code?, device_user_id? (HROne parity MS-4)
     * @param  array<string, mixed>  $position  dimension ids
     * @param  LifecycleState|null  $startState  HROne parity WO-3: null = as before; or one of HISTORIC_START_STATES
     * @param  CarbonInterface|string|null  $confirmationDate  with $startState confirmed: the day they were confirmed
     */
    public function handle(array $person, array $employee, array $position, ?int $managerId = null, ?string $reason = null, ?LifecycleState $startState = null, CarbonInterface|string|null $confirmationDate = null): Employee
    {
        $confirmedOn = $this->assertStartState($startState, $employee['joining_date'] ?? null, $confirmationDate);

        return DB::transaction(function () use ($person, $employee, $position, $managerId, $reason, $startState, $confirmedOn) {
            if (isset($employee['employee_code']) && $this->codes->isTaken($employee['employee_code'])) {
                throw new EmployeeCodeTakenException($employee['employee_code']);
            }

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
            $probationGiven = isset($employee['probation_end_date']);
            // HROne parity WO-3: someone loaded as already confirmed ended their probation on the confirmation date; someone
            // active without probation has none (unless a probation end date was given).
            if ($startState !== null && ! $probationGiven) {
                $probationEnd = $startState === LifecycleState::Confirmed ? $confirmedOn : null;
                $probationGiven = true;
            }

            $record = new Employee([
                'person_id' => $personModel->getKey(),
                'employee_code' => $employee['employee_code'] ?? $this->codes->next(isset($position['company_id']) ? (int) $position['company_id'] : null, isset($position['employment_type_id']) ? (int) $position['employment_type_id'] : null),
                'lifecycle_state' => LifecycleState::PreEmployee,
                'joining_date' => $joiningDate,
                'probation_end_date' => $probationEnd,
                'work_email' => $employee['work_email'] ?? null,
                'work_phone' => $employee['work_phone'] ?? null,
                // HROne parity MS-4: the work phone's country code (E.164 when given) and the attendance device user id.
                'work_phone_country_code' => $employee['work_phone_country_code'] ?? null,
                'device_user_id' => $employee['device_user_id'] ?? null,
                'user_id' => $employee['user_id'] ?? null,
            ]);
            $record->withAuditReason($reason)->save();

            $this->positions->handle($record, $position, 'hire', $joiningDate, $reason);

            if ($managerId !== null) {
                $this->managers->handle($record, Employee::query()->findOrFail($managerId), 'line', $joiningDate, $reason);
            }

            // HROne setup parity: the confirmation policy that applies once the position is known may set the probation.
            $months = app(PolicyResolver::class)->resolve('confirmation', $record, $joiningDate)?->setting('probation_months');
            if (! $probationGiven && is_numeric($months)) {
                $record->withAuditReason($reason)->update(['probation_end_date' => $joiningDate->copy()->addMonths((int) $months)]);
            }

            if ($joiningDate->isFuture()) {
                $this->lifecycle->transition($record, LifecycleState::Preboarding, now(), $reason);
            } elseif ($startState === LifecycleState::Active) {
                // HROne parity WO-3: a historic load without probation (joined → active, as "Change lifecycle state" allows).
                $this->lifecycle->transition($record, LifecycleState::Joined, $joiningDate, $reason);
                $this->lifecycle->transition($record, LifecycleState::Active, $joiningDate, $reason, ['start_state' => 'active']);
            } else {
                $this->lifecycle->transition($record, LifecycleState::Joined, $joiningDate, $reason);
                $this->lifecycle->transition($record, LifecycleState::Probation, $joiningDate, $reason);
                if ($startState === LifecycleState::Confirmed) {
                    // HROne parity WO-3: a historic load of someone already confirmed (probation → confirmed on that day).
                    $this->lifecycle->transition($record, LifecycleState::Confirmed, $confirmedOn, $reason, ['start_state' => 'confirmed']);
                }
            }

            EmploymentEvent::dispatch('employee.created', $record, $record, ['employee_code' => $record->employee_code, 'joining_date' => $joiningDate->toDateString(), 'source' => $record->source]);

            return $record->refresh();
        });
    }

    /**
     * HROne parity WO-3: a starting state other than the default only for someone who has already joined; confirmed needs
     * the confirmation date, on or after joining and not in the future. Returns the confirmation day (confirmed only).
     */
    private function assertStartState(?LifecycleState $startState, mixed $joining, CarbonInterface|string|null $confirmationDate): ?Carbon
    {
        if ($startState === null) {
            return null;
        }
        if (! in_array($startState, self::HISTORIC_START_STATES, true)) {
            throw new InvalidArgumentException('A hire can start as confirmed or as active without probation; '.$startState->getLabel().' has its own steps.');
        }
        $joiningDate = Carbon::parse($joining ?? now())->startOfDay();
        if ($joiningDate->isFuture()) {
            throw new InvalidArgumentException('Only someone who has already joined can start as '.mb_strtolower($startState->getLabel()).': a future joining date starts in pre-boarding.');
        }
        if ($startState !== LifecycleState::Confirmed) {
            return null;
        }
        if (blank($confirmationDate)) {
            throw new InvalidArgumentException('Give the date the employee was confirmed.');
        }
        $confirmedOn = Carbon::parse($confirmationDate)->startOfDay();
        if ($confirmedOn->lt($joiningDate) || $confirmedOn->isFuture()) {
            throw new InvalidArgumentException('The confirmation date must be on or after the joining date and not in the future.');
        }

        return $confirmedOn;
    }
}
