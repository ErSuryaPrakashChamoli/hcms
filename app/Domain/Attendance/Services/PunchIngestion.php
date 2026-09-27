<?php

namespace App\Domain\Attendance\Services;

use App\Domain\Attendance\Adapters\BiometricAdapter;
use App\Domain\Attendance\Events\AttendanceEvent;
use App\Domain\Attendance\Jobs\ProcessAttendanceDay;
use App\Domain\Attendance\Models\AttendanceDevice;
use App\Domain\Attendance\Models\AttendancePunch;
use App\Domain\Employment\Models\Employee;
use App\Domain\Platform\Services\SettingsRepository;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Context;
use InvalidArgumentException;

/**
 * Raw punch ingestion (Phase 2 §5–§8): every source (device adapter, API, web, mobile, manual,
 * import) ends here. A punch is stored once — the fingerprint is unique per tenant at the database
 * — and is never rewritten; processing state is tracked beside the evidence.
 */
final class PunchIngestion
{
    public const SOURCE_TYPES = ['biometric_device', 'mobile', 'web', 'api', 'manual', 'import'];

    public function __construct(private readonly TenantContext $tenants, private readonly SettingsRepository $settings) {}

    /**
     * Record one punch. Returns null when it is a duplicate (already stored). Unknown employees are
     * retained as failed punches when `$employee` is null and `$meta['employee_code']` is given.
     *
     * @param  array<string, mixed>  $meta  latitude, longitude, payload, source_timezone, source_type, employee_code, correlation_id
     */
    public function record(?Employee $employee, Carbon|string $punchedAt, string $direction = 'auto', string $source = 'manual', ?AttendanceDevice $device = null, ?string $externalId = null, array $meta = [], ?string $note = null): ?AttendancePunch
    {
        $sourceTimezone = $meta['source_timezone'] ?? null;
        $at = $punchedAt instanceof Carbon ? $punchedAt->copy() : Carbon::parse($punchedAt, $sourceTimezone ?: config('app.timezone'));
        $at = $at->setTimezone(config('app.timezone'));
        $direction = in_array($direction, ['in', 'out'], true) ? $direction : 'auto';
        $sourceType = $meta['source_type'] ?? ($device ? 'biometric_device' : match ($source) {
            'import' => 'import', 'api' => 'api', 'web' => 'web', 'mobile' => 'mobile', default => 'manual'
        });

        $fingerprint = $this->fingerprint($device, $externalId, $employee?->getKey(), $meta['employee_code'] ?? null, $at, $direction);

        if (AttendancePunch::query()->where('fingerprint', $fingerprint)->exists()) {
            return null;
        }

        try {
            $punch = AttendancePunch::create([
                'employee_id' => $employee?->getKey(),
                'punched_at' => $at,
                'direction' => $direction,
                'source' => $source,
                'source_type' => $sourceType,
                'source_timezone' => $sourceTimezone,
                'attendance_device_id' => $device?->id,
                'external_id' => $externalId,
                'fingerprint' => $fingerprint,
                'latitude' => $meta['latitude'] ?? null,
                'longitude' => $meta['longitude'] ?? null,
                'payload' => $meta['payload'] ?? null,
                'received_at' => now(),
                'processing_status' => $employee ? 'normalized' : 'failed',
                'processing_error' => $employee ? null : 'Unknown employee code ['.($meta['employee_code'] ?? '?').'].',
                'correlation_id' => $meta['correlation_id'] ?? Context::get('request_id'),
                'recorded_by' => auth()->id(),
                'note' => $note,
            ]);
        } catch (UniqueConstraintViolationException) {
            return null; // lost a race with an identical punch: idempotent by design
        }

        if ($employee !== null) {
            AttendanceEvent::dispatch('attendance.punch_received', $employee, $punch, ['punched_at' => $at->toIso8601String(), 'direction' => $direction, 'source_type' => $sourceType]);
            $this->queueProcessing($employee, $at);
        }

        return $punch;
    }

    /**
     * Ingest a device payload through its adapter.
     *
     * @return array{accepted: int, duplicates: int, failed: int, unknown: list<string>, dates: list<string>}
     */
    public function ingest(AttendanceDevice $device, array $payload): array
    {
        $adapter = $this->adapter($device->adapter);
        $result = ['accepted' => 0, 'duplicates' => 0, 'failed' => 0, 'unknown' => [], 'dates' => []];
        $timezone = $device->settings['timezone'] ?? null;

        foreach ($adapter->normalise($payload) as $punch) {
            $code = $punch['employee_code'] ?? '';
            $employee = $code !== '' ? Employee::query()->where('employee_code', $code)->first() : null;

            if (empty($punch['punched_at'])) {
                $result['failed']++;

                continue;
            }

            $stored = $this->record(
                $employee,
                $punch['punched_at'],
                $punch['direction'] ?? 'auto',
                'biometric',
                $device,
                $punch['external_id'] ?? null,
                ['latitude' => $punch['latitude'] ?? null, 'longitude' => $punch['longitude'] ?? null, 'payload' => $punch, 'source_timezone' => $timezone, 'source_type' => 'biometric_device', 'employee_code' => $code],
            );

            if ($employee === null) {
                $result['unknown'][] = $code;
                $stored ? $result['failed']++ : $result['duplicates']++;

                continue;
            }

            $stored ? $result['accepted']++ : $result['duplicates']++;
            if ($stored) {
                $result['dates'][] = $stored->punched_at->toDateString();
            }
        }

        $device->update(['last_seen_at' => now()]);
        $result['unknown'] = array_values(array_unique($result['unknown']));
        $result['dates'] = array_values(array_unique($result['dates']));

        return $result;
    }

    /** Retry a failed punch: re-resolve the employee from the retained payload and queue processing. */
    public function retry(AttendancePunch $punch): AttendancePunch
    {
        if ($punch->processing_status !== 'failed') {
            throw new InvalidArgumentException('Only failed punches can be retried.');
        }

        $employee = $punch->employee_id
            ? Employee::query()->find($punch->employee_id)
            : Employee::query()->where('employee_code', $punch->payload['employee_code'] ?? '')->first();

        if ($employee === null) {
            $punch->update(['processing_error' => 'Unknown employee code ['.($punch->payload['employee_code'] ?? '?').'] (retry).']);

            return $punch;
        }

        $punch->update(['employee_id' => $employee->getKey(), 'processing_status' => 'normalized', 'processing_error' => null]);
        $this->queueProcessing($employee, $punch->punched_at, force: true);

        return $punch->refresh();
    }

    public function punchesBetween(Employee $employee, Carbon $from, Carbon $to): Collection
    {
        return AttendancePunch::query()
            ->where('employee_id', $employee->id)
            ->whereBetween('punched_at', [$from, $to])
            ->orderBy('punched_at')->orderBy('id')
            ->get();
    }

    /** Deterministic identity of a punch: device+external id when the source gives one, else employee/code+minute+direction. */
    public function fingerprint(?AttendanceDevice $device, ?string $externalId, ?int $employeeId, ?string $employeeCode, Carbon $at, string $direction): string
    {
        $basis = $device && $externalId
            ? "device:{$device->id}:{$externalId}"
            : 'employee:'.($employeeId ?? 'code:'.$employeeCode).':'.$at->copy()->setTimezone(config('app.timezone'))->format('Y-m-d H:i').":{$direction}";

        return hash('sha256', $this->tenants->id().'|'.$basis);
    }

    private function queueProcessing(Employee $employee, Carbon $at, bool $force = false): void
    {
        if (! $force && ! $this->settings->get('attendance.process_on_punch', true)) {
            return;
        }

        // A punch belongs to the work date whose punch window contains it: the previous date when an
        // overnight shift is still open, otherwise its own date.
        $processor = app(AttendanceProcessor::class);
        $days = [];
        foreach ([$at->copy()->subDay()->startOfDay(), $at->copy()->startOfDay()] as $candidate) {
            [$from, $to] = $processor->windowFor($employee, $candidate);
            if ($at->betweenIncluded($from, $to)) {
                $days[] = $candidate;
                break;
            }
        }
        if ($days === []) {
            $days[] = $at->copy()->startOfDay();
        }
        foreach ($days as $day) {
            ProcessAttendanceDay::dispatch($employee->getKey(), $day->toDateString(), 'punch');
        }
    }

    private function adapter(string $key): BiometricAdapter
    {
        $driver = config("peopleos.attendance.adapters.{$key}.driver") ?? throw new InvalidArgumentException("Unknown attendance adapter [{$key}].");

        return app($driver);
    }
}
