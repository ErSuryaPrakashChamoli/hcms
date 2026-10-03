<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Attendance\Models\AttendanceRecord;
use App\Domain\Attendance\Models\AttendanceRegularisation;
use App\Domain\Attendance\Models\Shift;
use App\Domain\Attendance\Models\WorkSchedule;
use App\Domain\Attendance\Services\PunchIngestion;
use App\Domain\Attendance\Services\Regularisations;
use App\Domain\Attendance\Support\AttendanceDay;
use App\Domain\Employment\Models\Employee;
use App\Http\Controllers\Controller;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use RuntimeException;

/**
 * Attendance API (Phase 2 §33–§34): tenant bound by the key, employees addressed by code, raw
 * punches as the only write boundary for time evidence, records exposed as the payroll-ready
 * quantities. Source payloads are never returned.
 */
class AttendanceController extends Controller
{
    use PaginatesApi;

    /** GET attendance/records — records for a period, optionally one employee; quantities only. */
    public function records(Request $request): JsonResponse
    {
        $query = AttendanceRecord::query()->with('employee:id,employee_code')
            ->when($request->query('from'), fn (Builder $q, $d) => $q->whereDate('date', '>=', $d))
            ->when($request->query('to'), fn (Builder $q, $d) => $q->whereDate('date', '<=', $d))
            ->when($request->query('status'), fn (Builder $q, $s) => $q->where('status', $s))
            ->when($request->query('employee_code'), fn (Builder $q, $c) => $q->whereHas('employee', fn ($e) => $e->where('employee_code', $c)))
            ->orderByDesc('date')->orderBy('employee_id');

        return $this->page($query, $request, fn (AttendanceRecord $r) => ['employee_code' => $r->employee?->employee_code] + AttendanceDay::fromRecord($r)->toArray());
    }

    /** GET attendance/records/{employee_code}/{date} */
    public function record(string $employee, string $date): JsonResponse
    {
        $model = Employee::query()->where('employee_code', $employee)->firstOrFail();
        $record = AttendanceRecord::query()->with(['shift:id,code,name'])->where('employee_id', $model->id)->whereDate('date', $date)->firstOrFail();

        return response()->json(['data' => ['employee_code' => $model->employee_code, 'shift' => $record->shift?->only(['code', 'name']), 'first_in' => $record->first_in?->toIso8601String(), 'last_out' => $record->last_out?->toIso8601String(), 'exceptions' => $record->exceptions ?? [], 'timezone' => $record->timezone] + AttendanceDay::fromRecord($record)->toArray()]);
    }

    /** GET attendance/exceptions — unresolved exceptions for review. */
    public function exceptions(Request $request): JsonResponse
    {
        $query = AttendanceRecord::query()->with('employee:id,employee_code')->whereNotNull('exceptions')->where('is_locked', false)
            ->when($request->query('from'), fn (Builder $q, $d) => $q->whereDate('date', '>=', $d))
            ->when($request->query('to'), fn (Builder $q, $d) => $q->whereDate('date', '<=', $d))
            ->orderByDesc('date');

        return $this->page($query, $request, fn (AttendanceRecord $r) => ['employee_code' => $r->employee?->employee_code, 'date' => $r->date->toDateString(), 'status' => $r->status, 'exceptions' => $r->exceptions, 'overtime_status' => $r->overtime_status]);
    }

    /** POST attendance/punches — a punch from any external source (no device registration needed). */
    public function punch(Request $request, PunchIngestion $ingestion): JsonResponse
    {
        $data = $request->validate([
            'employee_code' => ['required', 'string', 'max:32'],
            'punched_at' => ['required', 'date'],
            'timezone' => ['nullable', 'timezone:all'],
            'direction' => ['nullable', Rule::in(['in', 'out', 'auto'])],
            'source_type' => ['nullable', Rule::in(PunchIngestion::SOURCE_TYPES)],
            'external_id' => ['nullable', 'string', 'max:128'],
            'latitude' => ['nullable', 'numeric'], 'longitude' => ['nullable', 'numeric'],
            'correlation_id' => ['nullable', 'string', 'max:64'],
        ]);

        $employee = Employee::query()->where('employee_code', $data['employee_code'])->first();
        $punch = $ingestion->record($employee, $data['punched_at'], $data['direction'] ?? 'auto', 'api', null, $data['external_id'] ?? null, [
            'source_timezone' => $data['timezone'] ?? null, 'source_type' => $data['source_type'] ?? 'api', 'employee_code' => $data['employee_code'],
            'latitude' => $data['latitude'] ?? null, 'longitude' => $data['longitude'] ?? null, 'correlation_id' => $data['correlation_id'] ?? null,
            'payload' => ['employee_code' => $data['employee_code'], 'external_id' => $data['external_id'] ?? null, 'key' => $request->attributes->get('api_key')?->name],
        ]);

        if ($punch === null) {
            return response()->json(['data' => ['status' => 'duplicate']], 200);
        }

        $punch->refresh();

        return response()->json(['data' => ['id' => $punch->id, 'status' => $punch->processing_status, 'punched_at' => $punch->punched_at->toIso8601String(), 'error' => $punch->processing_error]], $punch->processing_status === 'failed' ? 202 : 201);
    }

    /** GET attendance/regularisations */
    public function regularisations(Request $request): JsonResponse
    {
        $query = AttendanceRegularisation::query()->with('employee:id,employee_code')
            ->when($request->query('status'), fn (Builder $q, $s) => $q->where('status', $s))
            ->when($request->query('employee_code'), fn (Builder $q, $c) => $q->whereHas('employee', fn ($e) => $e->where('employee_code', $c)))
            ->orderByDesc('id');

        return $this->page($query, $request, fn (AttendanceRegularisation $r) => ['id' => $r->id, 'employee_code' => $r->employee?->employee_code, 'date' => $r->date->toDateString(), 'type' => $r->type, 'status' => $r->status, 'requested_in' => $r->requested_in?->toIso8601String(), 'requested_out' => $r->requested_out?->toIso8601String(), 'reason' => $r->reason, 'reviewed_at' => $r->reviewed_at?->toIso8601String()]);
    }

    /** POST attendance/regularisations — raise a request on behalf of an employee (external self-service). */
    public function requestRegularisation(Request $request, Regularisations $regularisations): JsonResponse
    {
        $data = $request->validate([
            'employee_code' => ['required', 'string', 'max:32'],
            'date' => ['required', 'date'],
            'type' => ['required', Rule::in(array_keys(config('peopleos.attendance.regularisation_types', [])))],
            'reason' => ['required', 'string', 'max:500'],
            'requested_in' => ['nullable', 'date'], 'requested_out' => ['nullable', 'date', 'after:requested_in'],
        ]);
        $employee = Employee::query()->where('employee_code', $data['employee_code'])->firstOrFail();

        try {
            $reg = $regularisations->request($employee, $data['date'], $data['type'], $data['reason'], $data['requested_in'] ?? null, $data['requested_out'] ?? null);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => ['id' => $reg->id, 'status' => $reg->status, 'date' => $reg->date->toDateString(), 'type' => $reg->type]], 201);
    }

    /** GET attendance/shifts */
    public function shifts(Request $request): JsonResponse
    {
        $query = Shift::query()->with('breaks')->where('status', 'active')->orderBy('code');

        return $this->page($query, $request, fn (Shift $s) => ['code' => $s->code, 'name' => $s->name, 'type' => $s->type, 'start_time' => $s->start_time, 'end_time' => $s->end_time, 'crosses_midnight' => $s->crosses_midnight, 'timezone' => $s->timezone, 'full_day_minutes' => $s->full_day_minutes, 'half_day_minutes' => $s->half_day_minutes, 'grace_in_minutes' => $s->grace_in_minutes, 'grace_out_minutes' => $s->grace_out_minutes, 'unpaid_break_minutes' => $s->unpaidBreakMinutes(), 'breaks' => $s->breaks->map(fn ($b) => $b->only(['name', 'duration_minutes', 'is_paid']))->all(), 'effective_from' => $s->effective_from?->toDateString(), 'effective_to' => $s->effective_to?->toDateString()]);
    }

    /** GET attendance/schedules */
    public function schedules(Request $request): JsonResponse
    {
        $query = WorkSchedule::query()->where('status', 'active')->orderBy('code');

        return $this->page($query, $request, fn (WorkSchedule $s) => ['code' => $s->code, 'name' => $s->name, 'weeks' => count($s->pattern ?? []), 'pattern' => $s->pattern, 'effective_from' => $s->effective_from?->toDateString()]);
    }
}
