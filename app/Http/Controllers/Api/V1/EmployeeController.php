<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Employment\Actions\HireEmployeeAction;
use App\Domain\Employment\Exceptions\DuplicatePersonException;
use App\Domain\Employment\Models\Employee;
use App\Domain\Employment\Services\SensitiveAccessAuditor;
use App\Domain\Lifecycle\Enums\LifecycleState;
use App\Domain\Lifecycle\Exceptions\InvalidLifecycleTransitionException;
use App\Domain\Lifecycle\Services\LifecycleEngine;
use App\Domain\Organisation\Services\OrganisationCodes;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\EmployeeApiResource;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

/**
 * Employee API foundation (Phase 1 §42–§44): the same domain actions as the UI, tenant bound by
 * the key, explicit field security through EmployeeApiResource. Records are resolved after the
 * tenant is bound (ids from other tenants are 404).
 */
class EmployeeController extends Controller
{
    private const RELATIONS = ['person', 'currentPosition.company', 'currentPosition.location', 'currentPosition.businessUnit', 'currentPosition.division', 'currentPosition.department', 'currentPosition.team', 'currentPosition.designation', 'currentPosition.level', 'currentPosition.grade', 'currentPosition.employmentType', 'currentPosition.employeeCategory', 'currentPosition.workMode', 'currentManager.manager.person'];

    public function index(Request $request): JsonResponse
    {
        $query = Employee::query()->with(self::RELATIONS)
            ->when($request->query('state'), fn (Builder $q, $s) => $q->where('lifecycle_state', $s))
            ->when($request->query('employed') === '1', fn (Builder $q) => $q->employed())
            ->when($request->query('employee_code'), fn (Builder $q, $c) => $q->where('employee_code', $c))
            ->when($request->query('external_reference'), fn (Builder $q, $r) => $q->where('external_reference', $r))
            ->when($request->query('updated_since'), fn (Builder $q, $d) => $q->where('updated_at', '>=', $d))
            ->when($request->query('department_code'), fn (Builder $q, $c) => $q->whereHas('currentPosition.department', fn (Builder $d) => $d->where('code', $c)))
            ->when($request->query('location_code'), fn (Builder $q, $c) => $q->whereHas('currentPosition.location', fn (Builder $d) => $d->where('code', $c)))
            ->orderBy('employee_code');

        $this->prepareSensitive($request, null);
        $perPage = min(max((int) $request->query('per_page', 50), 1), 200);
        $paginator = $query->paginate($perPage)->appends($request->query());

        return response()->json([
            'data' => EmployeeApiResource::collection($paginator->items())->resolve($request),
            'meta' => ['page' => $paginator->currentPage(), 'per_page' => $paginator->perPage(), 'total' => $paginator->total(), 'last_page' => $paginator->lastPage()],
        ]);
    }

    public function show(Request $request, int $employee): JsonResponse
    {
        $employee = Employee::query()->with(self::RELATIONS)->findOrFail($employee);
        $this->prepareSensitive($request, $employee);

        return response()->json(['data' => (new EmployeeApiResource($employee))->resolve($request)]);
    }

    /** Create: validate -> tenant (key) -> authorise (scope) -> resolve person -> employee -> position -> lifecycle -> audit -> event, all inside HireEmployeeAction. */
    public function store(Request $request, HireEmployeeAction $hire, OrganisationCodes $codes): JsonResponse
    {
        $data = $request->validate([
            'person.id' => ['nullable', 'integer'],
            'person.first_name' => ['required_without:person.id', 'string', 'max:255'], 'person.middle_name' => ['nullable', 'string', 'max:255'], 'person.last_name' => ['nullable', 'string', 'max:255'],
            'person.preferred_name' => ['nullable', 'string', 'max:255'], 'person.date_of_birth' => ['nullable', 'date'], 'person.gender' => ['nullable', Rule::in(array_keys(config('peopleos.people.genders', [])))],
            'person.nationality' => ['nullable', 'string', 'max:64'], 'person.personal_email' => ['nullable', 'email', 'max:255'], 'person.personal_phone' => ['nullable', 'string', 'max:32'],
            'person.allow_duplicate' => ['nullable', 'boolean'],
            'employee.joining_date' => ['required', 'date'], 'employee.probation_end_date' => ['nullable', 'date'], 'employee.employee_code' => ['nullable', 'string', 'max:32', 'alpha_dash'],
            'employee.work_email' => ['nullable', 'email', 'max:255'], 'employee.work_phone' => ['nullable', 'string', 'max:32'], 'employee.external_reference' => ['nullable', 'string', 'max:128'],
            'position.company_code' => ['required', 'string', 'max:32'], 'position.location_code' => ['nullable', 'string', 'max:32'], 'position.business_unit_code' => ['nullable', 'string', 'max:32'],
            'position.division_code' => ['nullable', 'string', 'max:32'], 'position.department_code' => ['nullable', 'string', 'max:32'], 'position.team_code' => ['nullable', 'string', 'max:32'],
            'position.designation_code' => ['nullable', 'string', 'max:64'], 'position.level_code' => ['nullable', 'string', 'max:32'], 'position.grade_code' => ['nullable', 'string', 'max:32'],
            'position.employment_type_code' => ['nullable', 'string', 'max:32'], 'position.employee_category_code' => ['nullable', 'string', 'max:32'], 'position.work_mode_code' => ['nullable', 'string', 'max:32'],
            'manager_code' => ['nullable', 'string', 'max:32'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        try {
            $position = $codes->resolvePosition($data['position']);
            $managerId = isset($data['manager_code']) ? Employee::query()->where('employee_code', $data['manager_code'])->value('id') ?? throw new InvalidArgumentException("Manager '{$data['manager_code']}' does not exist.") : null;
            $person = isset($data['person']['id']) ? ['id' => (int) $data['person']['id']] : array_filter($data['person'], fn ($v) => $v !== null && $v !== '');
            $employeeData = array_filter(array_intersect_key($data['employee'], array_flip(['joining_date', 'probation_end_date', 'employee_code', 'work_email', 'work_phone'])), fn ($v) => $v !== null && $v !== '');

            $employee = $hire->handle($person, $employeeData, $position, $managerId, $data['reason'] ?? 'Created through the API ('.$request->attributes->get('api_key')?->name.')');
            if (! empty($data['employee']['external_reference'])) {
                $employee->withAuditReason('API')->update(['source' => 'api', 'external_reference' => $data['employee']['external_reference']]);
            } else {
                $employee->withAuditReason('API')->update(['source' => 'api']);
            }
        } catch (DuplicatePersonException $e) {
            return response()->json(['message' => $e->getMessage(), 'errors' => ['person' => [$e->getMessage()]], 'candidates' => $e->candidates->map(fn ($c) => ['person_id' => $c['person_id'], 'employee_code' => $c['employee_code'], 'matched_on' => $c['matched_on']])->values()], 422);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $employee->loadMissing(self::RELATIONS);
        EmployeeApiResource::$includeSensitive = false;

        return response()->json(['data' => (new EmployeeApiResource($employee))->resolve($request)], 201);
    }

    /** Lifecycle transition through the engine (validated, audited, effective-dated, event-emitting). */
    public function lifecycle(Request $request, int $employee, LifecycleEngine $engine): JsonResponse
    {
        $data = $request->validate([
            'state' => ['required', Rule::in(array_map(fn (LifecycleState $s) => $s->value, LifecycleState::cases()))],
            'effective_date' => ['nullable', 'date'],
            'reason' => ['required', 'string', 'max:500'],
        ]);
        $employee = Employee::query()->findOrFail($employee);

        try {
            $engine->transition($employee, LifecycleState::from($data['state']), $data['effective_date'] ?? now(), $data['reason'], ['source' => 'api']);
        } catch (InvalidLifecycleTransitionException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $employee->refresh()->loadMissing(self::RELATIONS);
        EmployeeApiResource::$includeSensitive = false;

        return response()->json(['data' => (new EmployeeApiResource($employee))->resolve($request)]);
    }

    private function prepareSensitive(Request $request, ?Employee $employee): void
    {
        $wanted = $request->query('include') === 'sensitive';
        $key = $request->attributes->get('api_key');
        EmployeeApiResource::$includeSensitive = $wanted && $key?->hasScope('employees.sensitive.read');

        if ($wanted && ! EmployeeApiResource::$includeSensitive) {
            abort(403, 'This key lacks the [employees.sensitive.read] scope.');
        }

        if (EmployeeApiResource::$includeSensitive && $employee !== null) {
            $employee->loadMissing(['statutoryDetail', 'bankAccounts']);
            app(SensitiveAccessAuditor::class)->recordView($employee, 'api', 'API read with sensitive scope ('.$key?->name.')');
        }

        if (EmployeeApiResource::$includeSensitive && $employee === null) {
            abort(422, 'Sensitive fields are available on single employee reads only.');
        }
    }
}
