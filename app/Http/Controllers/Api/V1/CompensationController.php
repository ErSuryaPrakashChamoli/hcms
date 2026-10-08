<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Compensation\Contracts\CompensationOutput;
use App\Domain\Compensation\Models\CompensationCycle;
use App\Domain\Compensation\Models\CompensationRange;
use App\Domain\Compensation\Models\SalaryStructure;
use App\Domain\Compensation\Models\SalaryStructureVersion;
use App\Domain\Compensation\Support\CompensationSnapshot;
use App\Domain\Employment\Models\Employee;
use App\Domain\Organisation\Models\Grade;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Phase 11 compensation API (`/api/v1/compensation/*`, scope compensation.read). Read-only: every
 * change goes through the approval chain in PeopleOS (no API writes until they can meet the same
 * separation of duties; see docs/architecture/compensation.md).
 *
 * Definitions (structures, grades, ranges, cycles) need compensation.read. Employee compensation
 * amounts additionally need compensation.sensitive and every such read is audited. Employees and other
 * records are addressed by code; a code from another tenant returns 404. Draft, submitted, rejected
 * and cancelled compensation is never returned (the contract has only approved compensation).
 */
class CompensationController extends Controller
{
    use PaginatesApi;

    public function employee(Request $request, string $code, CompensationOutput $output): JsonResponse
    {
        $employee = $this->employeeByCode($request, $code);
        $on = $this->on($request);
        $current = $output->on($employee, $on);

        return response()->json(['data' => ['employee_code' => $employee->employee_code, 'as_of' => $on, 'compensation' => $current ? $this->snapshot($current) : null]]);
    }

    public function history(Request $request, string $code, CompensationOutput $output): JsonResponse
    {
        $employee = $this->employeeByCode($request, $code);

        return response()->json(['data' => ['employee_code' => $employee->employee_code, 'history' => $output->history($employee)->map(fn (CompensationSnapshot $s) => $this->snapshot($s))->values()->all()]]);
    }

    public function structures(Request $request): JsonResponse
    {
        return $this->page(SalaryStructure::query()->orderBy('code'), $request, function (SalaryStructure $s) {
            $versions = SalaryStructureVersion::query()->where('salary_structure_id', $s->id)->whereIn('status', SalaryStructureVersion::APPROVED)->with('components.component:id,code,name,type')->orderBy('version')->get();

            return ['code' => $s->code, 'name' => $s->name, 'status' => $s->status?->value, 'versions' => $versions->map(fn (SalaryStructureVersion $v) => [
                'version' => $v->version, 'status' => $v->status, 'effective_from' => $v->effective_from->toDateString(), 'effective_to' => $v->effective_to?->toDateString(),
                'currency' => $v->currency, 'pay_frequency' => $v->pay_frequency,
                'components' => $v->components->map(fn ($c) => ['code' => $c->component?->code, 'name' => $c->component?->name, 'type' => $c->component?->type, 'pay_nature' => $c->pay_nature, 'frequency' => $c->frequency, 'sort_order' => $c->sort_order])->values()->all(),
            ])->values()->all()];
        });
    }

    public function grades(Request $request): JsonResponse
    {
        return $this->page(Grade::query()->orderBy('code'), $request, fn (Grade $g) => ['code' => $g->code, 'name' => $g->name]);
    }

    public function ranges(Request $request): JsonResponse
    {
        $on = $request->query('on');
        $query = CompensationRange::query()->whereIn('status', ['approved', 'superseded'])->with(['grade:id,code', 'structure:id,code', 'company:id,code', 'jobFamily:id,code', 'designation:id,code'])
            ->when($on, fn ($q) => $q->effectiveOn($on))->orderBy('grade_id')->orderBy('effective_from');

        return $this->page($query, $request, fn (CompensationRange $r) => [
            'grade_code' => $r->grade?->code, 'structure_code' => $r->structure?->code, 'company_code' => $r->company?->code, 'job_family_code' => $r->jobFamily?->code, 'designation_code' => $r->designation?->code,
            'version' => $r->version, 'status' => $r->status, 'currency' => $r->currency, 'frequency' => $r->frequency,
            'minimum' => (float) $r->minimum, 'midpoint' => $r->midpoint !== null ? (float) $r->midpoint : null, 'maximum' => (float) $r->maximum,
            'effective_from' => $r->effective_from->toDateString(), 'effective_to' => $r->effective_to?->toDateString(),
        ]);
    }

    public function cycles(Request $request): JsonResponse
    {
        return $this->page(CompensationCycle::query()->with('company:id,code')->orderByDesc('effective_from'), $request, fn (CompensationCycle $c) => [
            'code' => $c->code, 'name' => $c->name, 'cycle_type' => $c->cycle_type, 'company_code' => $c->company?->code, 'status' => $c->status,
            'effective_from' => $c->effective_from->toDateString(), 'employees' => $c->employee_count, 'executed_at' => $c->executed_at?->toIso8601String(),
        ]);
    }

    private function employeeByCode(Request $request, string $code): Employee
    {
        abort_unless((bool) $request->attributes->get('api_key')?->hasScope('compensation.sensitive'), 403, 'This key needs the compensation.sensitive scope for employee compensation.');
        $employee = Employee::query()->where('employee_code', $code)->firstOrFail();
        app(AuditRecorder::class)->record(AuditAction::View, 'compensation', $employee, [], null, metadata: ['scope' => 'compensation', 'source' => 'api']);

        return $employee;
    }

    private function on(Request $request): string
    {
        try {
            return Carbon::parse((string) $request->query('on', now()->toDateString()))->toDateString();
        } catch (\Throwable) {
            abort(422, 'on must be a date (YYYY-MM-DD).');
        }
    }

    /** @return array<string, mixed> */
    private function snapshot(CompensationSnapshot $s): array
    {
        return [
            'effective_from' => $s->effectiveFrom->toDateString(), 'effective_to' => $s->effectiveTo?->toDateString(),
            'structure_code' => $s->structureCode, 'currency' => $s->currency, 'pay_frequency' => $s->payFrequency,
            'ctc_annual' => $s->ctcAnnual, 'ctc_monthly' => $s->monthlyCtc(), 'variable_target_annual' => $s->variableTargetAnnual,
            'components_monthly' => $s->componentValues, 'change_type' => $s->changeType,
        ];
    }
}
