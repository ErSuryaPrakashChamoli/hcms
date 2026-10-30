<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Attendance\Models\AttendanceRecord;
use App\Domain\Attendance\Models\AttendanceRegularisation;
use App\Domain\Attendance\Models\LocationPing;
use App\Domain\Attendance\Models\Shift;
use App\Domain\Attendance\Models\WorkSchedule;
use App\Domain\Attendance\Services\PunchIngestion;
use App\Domain\Attendance\Services\Regularisations;
use App\Domain\Attendance\Support\AttendanceDay;
use App\Domain\Configuration\Services\PolicyResolver;
use App\Domain\Employment\Models\Employee;
use App\Http\Controllers\Controller;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
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
            'device_identifier' => ['nullable', 'string', 'max:128'], 'device_platform' => ['nullable', 'string', 'max:32'],
            // HROne setup parity (check-in policy): photos, app-side face verification and the WiFi network.
            'photos' => ['nullable', 'array', 'max:5'], 'photos.*' => ['file', 'mimes:jpg,jpeg,png', 'max:4096'],
            'photos_base64' => ['nullable', 'array', 'max:5'], 'photos_base64.*' => ['string', 'max:6000000'],
            'face_verified' => ['nullable', 'boolean'],
            'wifi_ssid' => ['nullable', 'string', 'max:64'], 'wifi_bssid' => ['nullable', 'string', 'max:32'],
        ]);

        $employee = Employee::query()->where('employee_code', $data['employee_code'])->first();
        $photos = $employee ? $this->storePhotos($request, $employee) : [];
        $punch = $ingestion->record($employee, $data['punched_at'], $data['direction'] ?? 'auto', 'api', null, $data['external_id'] ?? null, [
            'source_timezone' => $data['timezone'] ?? null, 'source_type' => $data['source_type'] ?? 'api', 'employee_code' => $data['employee_code'],
            'latitude' => $data['latitude'] ?? null, 'longitude' => $data['longitude'] ?? null, 'correlation_id' => $data['correlation_id'] ?? null,
            'device_identifier' => $data['device_identifier'] ?? null, 'device_platform' => $data['device_platform'] ?? null,
            'photos' => $photos, 'face_verified' => $data['face_verified'] ?? null, 'wifi_ssid' => $data['wifi_ssid'] ?? null, 'wifi_bssid' => $data['wifi_bssid'] ?? null,
            'payload' => ['employee_code' => $data['employee_code'], 'external_id' => $data['external_id'] ?? null, 'key' => $request->attributes->get('api_key')?->name],
        ]);

        if ($punch === null) {
            return response()->json(['data' => ['status' => 'duplicate']], 200);
        }

        $punch->refresh();

        return response()->json(['data' => ['id' => $punch->id, 'status' => $punch->processing_status, 'punched_at' => $punch->punched_at->toIso8601String(), 'error' => $punch->processing_error, 'geo_status' => $punch->geo_status]], match ($punch->processing_status) {
            'failed' => 202, 'rejected' => 422, default => 201,
        });
    }

    /**
     * POST attendance/locations — location pings from the mobile app during the working day, accepted only for
     * employees whose check-in policy turns continuous location tracking on.
     */
    public function locations(Request $request): JsonResponse
    {
        $data = $request->validate([
            'employee_code' => ['required', 'string', 'max:32'],
            'device_identifier' => ['nullable', 'string', 'max:128'],
            'pings' => ['required', 'array', 'min:1', 'max:500'],
            'pings.*.recorded_at' => ['required', 'date'],
            'pings.*.latitude' => ['required', 'numeric', 'between:-90,90'], 'pings.*.longitude' => ['required', 'numeric', 'between:-180,180'],
            'pings.*.accuracy_m' => ['nullable', 'integer', 'min:0', 'max:100000'],
        ]);
        $employee = Employee::query()->where('employee_code', $data['employee_code'])->firstOrFail();
        $policy = app(PolicyResolver::class)->resolve('checkin', $employee, now());
        if (! in_array($policy?->setting('continuous_tracking'), [true, 1, '1'], true)) {
            return response()->json(['message' => 'Continuous location tracking is off for this employee.'], 422);
        }
        foreach ($data['pings'] as $ping) {
            LocationPing::create(['employee_id' => $employee->id, 'recorded_at' => Carbon::parse($ping['recorded_at'])->setTimezone(config('app.timezone')), 'latitude' => $ping['latitude'], 'longitude' => $ping['longitude'],
                'accuracy_m' => $ping['accuracy_m'] ?? null, 'device_identifier' => $data['device_identifier'] ?? null]);
        }

        return response()->json(['data' => ['stored' => count($data['pings'])]], 201);
    }

    /** Photos sent with a punch (files or base64 data), stored privately; the paths go on the punch. @return list<string> */
    private function storePhotos(Request $request, Employee $employee): array
    {
        $disk = Storage::disk(config('peopleos.documents.disk', 'local'));
        $directory = 'attendance-photos/'.$employee->id.'/'.now()->format('Y-m');
        $paths = [];
        foreach ((array) $request->file('photos', []) as $file) {
            $paths[] = $file->store($directory, ['disk' => config('peopleos.documents.disk', 'local'), 'visibility' => 'private']);
        }
        foreach ((array) $request->input('photos_base64', []) as $encoded) {
            $binary = base64_decode((string) preg_replace('#^data:image/[a-z]+;base64,#i', '', (string) $encoded), true);
            $type = $binary !== false ? (new \finfo(FILEINFO_MIME_TYPE))->buffer($binary) : null;
            if (! in_array($type, ['image/jpeg', 'image/png'], true)) {
                continue; // not an image: ignored, so the policy's photo count is not met
            }
            $path = $directory.'/'.Str::uuid().($type === 'image/png' ? '.png' : '.jpg');
            $disk->put($path, $binary, 'private');
            $paths[] = $path;
        }

        return $paths;
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
