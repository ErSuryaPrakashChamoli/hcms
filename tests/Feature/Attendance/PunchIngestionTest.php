<?php

use App\Domain\Attendance\Models\AttendanceDevice;
use App\Domain\Attendance\Models\AttendancePunch;
use App\Domain\Attendance\Services\PunchIngestion;
use App\Domain\Employment\Models\Employee;
use App\Domain\Integration\Services\ApiKeys;

beforeEach(function () {
    $this->tenant = provisionTenant();
    actAsTenant($this->tenant);
    $this->actingAs(tenantUser($this->tenant, ['*']));
    $this->employee = Employee::factory()->create(['employee_code' => 'EMP00007']);
    $this->device = AttendanceDevice::create(['name' => 'Gate 1', 'code' => 'GATE1', 'adapter' => 'generic']);
    $this->ingestion = app(PunchIngestion::class);
});

it('normalises device payloads through adapters and ignores duplicates and unknown codes', function () {
    $result = $this->ingestion->ingest($this->device, ['punches' => [
        ['employee_code' => 'EMP00007', 'punched_at' => '2026-09-23 09:01:00', 'direction' => 'in', 'id' => 'A1'],
        ['employee_code' => 'EMP00007', 'punched_at' => '2026-09-23 09:01:00', 'direction' => 'in', 'id' => 'A1'],
        ['employee_code' => 'EMP00007', 'punched_at' => '2026-09-23 18:00:00', 'direction' => 'out', 'id' => 'A2'],
        ['employee_code' => 'NOBODY', 'punched_at' => '2026-09-23 18:00:00', 'direction' => 'out', 'id' => 'A3'],
    ]]);

    expect($result)->toMatchArray(['accepted' => 2, 'duplicates' => 1, 'failed' => 1, 'unknown' => ['NOBODY'], 'dates' => ['2026-09-23']])
        ->and(AttendancePunch::query()->count())->toBe(3) // the unknown-code punch is retained as failed evidence
        ->and(AttendancePunch::query()->where('processing_status', 'failed')->whereNull('employee_id')->count())->toBe(1)
        ->and(AttendancePunch::query()->first()->source)->toBe('biometric')
        ->and($this->device->fresh()->last_seen_at)->not->toBeNull();

    $essl = AttendanceDevice::create(['name' => 'Factory', 'code' => 'FAC', 'adapter' => 'essl']);
    $result = $this->ingestion->ingest($essl, ['logs' => [['EmployeeCode' => 'EMP00007', 'LogDate' => '2026-09-24 08:58:00', 'Direction' => 'in', 'SerialNumber' => 'SN1']]]);
    expect($result['accepted'])->toBe(1)->and(AttendancePunch::query()->latest('id')->first()->external_id)->toBe('SN1@2026-09-24 08:58:00');
});

it('accepts device pushes over the API with the attendance scope', function () {
    $key = app(ApiKeys::class)->issue('Gate', ['attendance.write']);
    $wrong = app(ApiKeys::class)->issue('RMS', ['rms.write']);
    auth()->logout();
    actAsTenant(null);

    $this->withHeader('X-Api-Key', $wrong['plaintext'])->postJson('/api/v1/attendance/devices/GATE1/punches', ['punches' => []])->assertStatus(403);
    $this->withHeader('X-Api-Key', $key['plaintext'])->postJson('/api/v1/attendance/devices/NOPE/punches', ['punches' => []])->assertNotFound();

    $this->withHeader('X-Api-Key', $key['plaintext'])
        ->postJson('/api/v1/attendance/devices/GATE1/punches', ['punches' => [['employee_code' => 'EMP00007', 'punched_at' => '2026-09-23T09:00:00+05:30', 'direction' => 'in', 'id' => 'X1']]])
        ->assertStatus(202)
        ->assertJsonPath('data.accepted', 1);

    actAsTenant($this->tenant);
    expect(AttendancePunch::query()->count())->toBe(1);
});

it('dedupes manual punches on the same minute and direction', function () {
    expect($this->ingestion->record($this->employee, '2026-09-23 09:00:10', 'in', 'manual', null, null, [], 'Forgot card'))->not->toBeNull()
        ->and($this->ingestion->record($this->employee, '2026-09-23 09:00:40', 'in', 'manual'))->toBeNull()
        ->and($this->ingestion->record($this->employee, '2026-09-23 09:00:40', 'out', 'manual'))->not->toBeNull();
});
