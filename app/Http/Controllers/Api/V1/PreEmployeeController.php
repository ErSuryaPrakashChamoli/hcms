<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Employment\Actions\CreatePreEmployeeAction;
use App\Domain\Employment\Models\Employee;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

/** POST /api/v1/pre-employees — the RMS hand-over endpoint (§87, §89). */
class PreEmployeeController extends Controller
{
    public function store(Request $request, CreatePreEmployeeAction $action): JsonResponse
    {
        $data = $request->validate([
            'person.first_name' => ['required', 'string', 'max:255'],
            'person.last_name' => ['nullable', 'string', 'max:255'],
            'person.middle_name' => ['nullable', 'string', 'max:255'],
            'person.date_of_birth' => ['nullable', 'date'],
            'person.gender' => ['nullable', 'string', 'max:32'],
            'person.personal_email' => ['nullable', 'email', 'max:255'],
            'person.personal_phone' => ['nullable', 'string', 'max:32'],
            'offer.external_reference' => ['required', 'string', 'max:128'],
            'offer.expected_joining_date' => ['required', 'date'],
            'offer.accepted_at' => ['nullable', 'date'],
            'position.company_code' => ['required', 'string', 'max:32'],
            'position.location_code' => ['nullable', 'string', 'max:32'],
            'position.department_code' => ['nullable', 'string', 'max:32'],
            'position.designation_code' => ['nullable', 'string', 'max:64'],
            'position.level_code' => ['nullable', 'string', 'max:32'],
            'position.grade_code' => ['nullable', 'string', 'max:32'],
            'position.employment_type_code' => ['nullable', 'string', 'max:32'],
            'manager_code' => ['nullable', 'string', 'max:32'],
            'work_email' => ['nullable', 'email', 'max:255'],
        ]);

        try {
            $employee = $action->handle($data, 'Received from recruitment ('.$request->attributes->get('api_key')?->name.')');
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => $this->present($employee)], $employee->wasRecentlyCreated ? 201 : 200);
    }

    public function show(string $reference): JsonResponse
    {
        $employee = Employee::query()->with('person')
            ->where('external_reference', $reference)
            ->orWhere('employee_code', $reference)
            ->firstOrFail();

        return response()->json(['data' => $this->present($employee)]);
    }

    /** @return array<string, mixed> */
    private function present(Employee $employee): array
    {
        $employee->loadMissing(['person', 'onboardingPlan']);

        return [
            'id' => $employee->id,
            'employee_code' => $employee->employee_code,
            'external_reference' => $employee->external_reference,
            'name' => $employee->person->display_name,
            'lifecycle_state' => $employee->lifecycle_state->value,
            'expected_joining_date' => $employee->expected_joining_date?->toDateString(),
            'joining_date' => $employee->joining_date?->toDateString(),
            'onboarding_progress' => $employee->onboardingPlan?->progress,
        ];
    }
}
