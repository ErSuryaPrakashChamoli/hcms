<?php

namespace App\Domain\Employment\Actions;

use App\Domain\Employment\Models\Employee;
use App\Domain\Employment\Services\EmployeeCodeGenerator;
use App\Domain\Lifecycle\Enums\LifecycleState;
use App\Domain\Lifecycle\Services\LifecycleEngine;
use App\Domain\Organisation\Models\Company;
use App\Domain\Organisation\Models\Department;
use App\Domain\Organisation\Models\Designation;
use App\Domain\Organisation\Models\EmploymentType;
use App\Domain\Organisation\Models\Grade;
use App\Domain\Organisation\Models\Level;
use App\Domain\Organisation\Models\Location;
use App\Domain\People\Models\Person;
use App\Domain\Platform\Services\SettingsRepository;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * RMS hand-over (§89): offer accepted -> pre-employee -> preboarding. Positions are given by
 * codes so the recruitment system never needs PeopleOS ids. Idempotent on external_reference.
 */
final class CreatePreEmployeeAction
{
    public function __construct(
        private readonly EmployeeCodeGenerator $codes,
        private readonly AssignPositionAction $positions,
        private readonly ChangeManagerAction $managers,
        private readonly LifecycleEngine $lifecycle,
        private readonly SettingsRepository $settings,
    ) {}

    /**
     * @param  array<string, mixed>  $payload  person{first_name,last_name,...}, offer{external_reference, expected_joining_date, accepted_at}, position{company_code, ...}, manager_code?
     */
    public function handle(array $payload, ?string $reason = null): Employee
    {
        $reference = $payload['offer']['external_reference'] ?? null;

        if ($reference && $existing = Employee::query()->where('external_reference', $reference)->first()) {
            return $existing;
        }

        return DB::transaction(function () use ($payload, $reason, $reference) {
            $person = new Person(array_intersect_key($payload['person'] ?? [], array_flip(['first_name', 'middle_name', 'last_name', 'preferred_name', 'date_of_birth', 'gender', 'nationality', 'personal_email', 'personal_phone'])));
            $person->withAuditReason($reason)->save();

            $joining = Carbon::parse($payload['offer']['expected_joining_date'] ?? now()->addWeeks(2))->startOfDay();

            $employee = new Employee([
                'person_id' => $person->id,
                'employee_code' => $payload['employee_code'] ?? $this->codes->next(),
                'lifecycle_state' => LifecycleState::PreEmployee,
                'source' => $payload['source'] ?? 'rms',
                'external_reference' => $reference,
                'expected_joining_date' => $joining,
                'offer_accepted_at' => isset($payload['offer']['accepted_at']) ? Carbon::parse($payload['offer']['accepted_at']) : now(),
                'probation_end_date' => $joining->copy()->addMonths((int) $this->settings->get('employee.probation.default_months', 6)),
                'work_email' => $payload['work_email'] ?? null,
            ]);
            $employee->withAuditReason($reason)->save();

            $this->positions->handle($employee, $this->resolvePosition($payload['position'] ?? []), 'hire', $joining, $reason);

            if (! empty($payload['manager_code'])) {
                $manager = Employee::query()->where('employee_code', $payload['manager_code'])->first() ?? throw new InvalidArgumentException("Unknown manager code [{$payload['manager_code']}].");
                $this->managers->handle($employee, $manager, 'line', $joining, $reason);
            }

            $this->lifecycle->transition($employee, LifecycleState::Preboarding, now(), $reason);

            return $employee->refresh();
        });
    }

    /** @return array<string, int> */
    private function resolvePosition(array $codes): array
    {
        $map = [
            'company_code' => ['company_id', Company::class],
            'location_code' => ['location_id', Location::class],
            'department_code' => ['department_id', Department::class],
            'designation_code' => ['designation_id', Designation::class],
            'level_code' => ['level_id', Level::class],
            'grade_code' => ['grade_id', Grade::class],
            'employment_type_code' => ['employment_type_id', EmploymentType::class],
        ];
        $position = [];

        foreach ($map as $codeKey => [$column, $model]) {
            if (! empty($codes[$codeKey])) {
                $position[$column] = $model::query()->where('code', $codes[$codeKey])->value('id')
                    ?? throw new InvalidArgumentException("Unknown {$codeKey} [{$codes[$codeKey]}].");
            }
        }

        if (empty($position['company_id'])) {
            throw new InvalidArgumentException('position.company_code is required.');
        }

        return $position;
    }
}
