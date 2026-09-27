<?php

use App\Domain\Attendance\Jobs\ProcessAttendanceDay;
use App\Domain\Attendance\Models\AttendancePunch;
use App\Domain\Attendance\Models\AttendanceRecord;
use App\Domain\Attendance\Models\AttendanceRegularisation;
use App\Domain\Attendance\Services\AttendanceProcessor;
use App\Domain\Attendance\Services\PunchIngestion;
use App\Domain\Attendance\Services\Regularisations;
use App\Domain\Audit\Models\AuditEvent;
use App\Domain\Employment\Actions\HireEmployeeAction;
use App\Domain\Identity\Services\AccessScopes;
use App\Domain\Integration\Services\ApiKeys;
use App\Domain\Organisation\Models\Company;
use App\Domain\Organisation\Models\Location;
use App\Filament\Resources\AttendanceRecords\AttendanceRecordResource;
use App\Filament\Resources\AttendanceRegularisations\AttendanceRegularisationResource;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Bus;

require_once __DIR__.'/AttendanceTestHelpers.php';

/* Phase 2: ingestion idempotency/retry, regularisation lifecycle with evidence preserved, overtime review, queue tenant context, security across tenant/scope/relationship, API. */

beforeEach(function () {
    $this->travelTo('2026-09-24 10:00:00');
    $this->tenant = provisionTenant();
    $this->tenantB = provisionTenant('B');
    actAsTenant($this->tenant);
    $this->hr = tenantUser($this->tenant, ['*']);
    $this->actingAs($this->hr);
    $this->company = Company::factory()->create(['code' => 'ALPHA']);
    $this->delhi = Location::factory()->create(['company_id' => $this->company->id, 'name' => 'Delhi', 'code' => 'DEL']);
    $this->mumbai = Location::factory()->create(['company_id' => $this->company->id, 'name' => 'Mumbai', 'code' => 'MUM']);
    $this->schedule = weeklySchedule(generalShift());
    $this->managerUser = tenantUser($this->tenant, ['attendance.view', 'attendance.approve', 'employee.view']);
    $this->manager = app(HireEmployeeAction::class)->handle(['first_name' => 'Mgr', 'last_name' => 'One'], ['joining_date' => '2025-01-01', 'user_id' => $this->managerUser->id, 'employee_code' => 'MGR1'], ['company_id' => $this->company->id, 'location_id' => $this->delhi->id]);
    $this->empUser = tenantUser($this->tenant, ['attendance.regularise']);
    $this->employee = app(HireEmployeeAction::class)->handle(['first_name' => 'Asha', 'last_name' => 'Rao'], ['joining_date' => '2025-01-01', 'user_id' => $this->empUser->id, 'employee_code' => 'EMP1'], ['company_id' => $this->company->id, 'location_id' => $this->mumbai->id], $this->manager->id);
    $this->stranger = app(HireEmployeeAction::class)->handle(['first_name' => 'Ravi', 'last_name' => 'Nair'], ['joining_date' => '2025-01-01', 'employee_code' => 'EMP2'], ['company_id' => $this->company->id, 'location_id' => $this->mumbai->id]);
    foreach ([$this->manager, $this->employee, $this->stranger] as $e) {
        assignSchedule($e, $this->schedule);
    }
    $this->processor = app(AttendanceProcessor::class);
    $this->wed = '2026-09-23';
});

it('stores a punch once whatever the source retries, retains unknown-employee punches as failed and lets them be retried', function () {
    $ingestion = app(PunchIngestion::class);
    $first = $ingestion->record($this->employee, "{$this->wed} 09:00:00", 'in', 'api', null, 'EXT-1', ['employee_code' => 'EMP1', 'source_type' => 'api']);
    expect($first)->not->toBeNull()->and($first->fingerprint)->toHaveLength(64)->and($first->fresh()->processing_status)->toBe('processed')->and($first->received_at)->not->toBeNull();
    expect($ingestion->record($this->employee, "{$this->wed} 09:00:30", 'in', 'api'))->toBeNull(); // same minute + direction
    expect($ingestion->record($this->employee, "{$this->wed} 09:00:00", 'in', 'import'))->toBeNull();
    expect(AttendancePunch::query()->where('employee_id', $this->employee->id)->count())->toBe(1);
    expect(AuditEvent::query()->where('action', 'CREATE')->where('entity_type', AttendancePunch::class)->count())->toBe(0); // evidence, not an audited edit

    $failed = $ingestion->record(null, "{$this->wed} 18:00:00", 'out', 'api', null, null, ['employee_code' => 'GHOST', 'payload' => ['employee_code' => 'GHOST']]);
    expect($failed->processing_status)->toBe('failed')->and($failed->employee_id)->toBeNull()->and($failed->processing_error)->toContain('GHOST');
    expect($ingestion->retry($failed)->processing_status)->toBe('failed');

    $this->actingAs($this->hr);
    $ghost = app(HireEmployeeAction::class)->handle(['first_name' => 'Ghost', 'last_name' => 'Now'], ['joining_date' => '2025-01-01', 'employee_code' => 'GHOST'], ['company_id' => $this->company->id]);
    assignSchedule($ghost, $this->schedule);
    $retried = $ingestion->retry($failed->fresh());
    expect($retried->employee_id)->toBe($ghost->id)->and($retried->processing_status)->toBe('processed');
});

it('runs the processing job with tenant context and marks the day failed with the error when processing throws', function () {
    $job = new ProcessAttendanceDay($this->employee->id, $this->wed);
    expect($job->tenantId())->toBe($this->tenant->id);
    punch($this->employee, "{$this->wed} 09:00:00");
    actAsTenant(null);
    auth()->logout();
    Bus::dispatchSync(unserialize(serialize($job)));
    actAsTenant($this->tenant);
    expect(AttendanceRecord::query()->where('employee_id', $this->employee->id)->whereDate('date', $this->wed)->value('status'))->toBe('incomplete');
});

it('keeps the original evidence and both snapshots through request, approval, rejection and cancellation, with audit actions', function () {
    punch($this->employee, "{$this->wed} 09:00:00", 'in');
    $before = $this->processor->process($this->employee, $this->wed);
    expect($before->status)->toBe('incomplete');

    $this->actingAs($this->empUser);
    $regs = app(Regularisations::class);
    $req = $regs->request($this->employee, $this->wed, 'missed_punch', 'Forgot to punch out', null, "{$this->wed} 18:05:00");
    expect(AuditEvent::query()->where('action', 'REGULARISATION_REQUESTED')->where('entity_id', (string) $req->id)->exists())->toBeTrue();

    $cancelMe = $regs->request($this->employee, '2026-09-22', 'wfh', 'Working from home');
    expect($regs->cancel($cancelMe, 'Not needed')->status)->toBe('cancelled');
    expect(fn () => $regs->cancel($cancelMe->fresh()))->toThrow(RuntimeException::class, 'Only pending');

    $this->actingAs($this->managerUser);
    $approved = $regs->approve($req, 'Confirmed by CCTV');
    $after = AttendanceRecord::query()->where('employee_id', $this->employee->id)->whereDate('date', $this->wed)->first();
    expect($approved->status)->toBe('approved')
        ->and($approved->original_snapshot['status'])->toBe('incomplete')
        ->and($approved->resulting_snapshot['status'])->toBe('present')
        ->and($after->is_regularised)->toBeTrue()->and($after->last_out->format('H:i'))->toBe('18:05')
        ->and(AttendancePunch::query()->where('employee_id', $this->employee->id)->count())->toBe(1) // evidence untouched
        ->and(AuditEvent::query()->where('action', 'REGULARISATION_APPROVED')->where('entity_id', (string) $req->id)->exists())->toBeTrue();
    expect(fn () => $regs->approve($req->fresh()))->toThrow(RuntimeException::class, 'already been reviewed');

    $this->actingAs($this->hr);
    $rej = $regs->request($this->employee, '2026-09-21', 'late', 'Traffic', '2026-09-21 09:00:00');
    $regs->reject($rej, 'No evidence');
    expect($rej->fresh()->status)->toBe('rejected')->and(AuditEvent::query()->where('action', 'REGULARISATION_REJECTED')->exists())->toBeTrue();
});

it('records WFH, on duty and field duty as controlled attendance states', function () {
    $regs = app(Regularisations::class);
    foreach (['2026-09-21' => 'wfh', '2026-09-22' => 'on_duty', '2026-09-23' => 'field_duty'] as $date => $type) {
        $regs->approve($regs->request($this->employee, $date, $type, ucfirst($type)));
        expect(AttendanceRecord::query()->where('employee_id', $this->employee->id)->whereDate('date', $date)->value('status'))->toBe($type);
    }
});

it('reviews overtime: below minimum is none, pending needs approval, approve caps at calculated, reject zeroes and both are audited and payroll-visible', function () {
    punch($this->employee, "{$this->wed} 09:00:00");
    punch($this->employee, "{$this->wed} 18:20:00"); // 20 extra minutes < minimum 30
    $r = $this->processor->process($this->employee, $this->wed);
    expect($r->overtime_minutes)->toBe(0)->and($r->overtime_status)->toBe('none');

    punch($this->employee, '2026-09-22 09:00:00');
    punch($this->employee, '2026-09-22 20:00:00'); // 120 extra
    $r = $this->processor->process($this->employee, '2026-09-22');
    expect($r->overtime_minutes)->toBe(120)->and($r->overtime_status)->toBe('pending')->and($r->exceptions)->toContain('overtime');

    $this->actingAs($this->managerUser);
    $regs = app(Regularisations::class);
    $r = $regs->approveOvertime($r, 500, 'Deployment night');
    expect($r->overtime_approved_minutes)->toBe(120)->and($r->overtime_status)->toBe('approved')->and($r->overtime_reviewed_by)->toBe($this->managerUser->id)->and($r->exceptions)->toBeNull();
    expect(AuditEvent::query()->where('action', 'OVERTIME_APPROVED')->where('entity_id', (string) $r->id)->exists())->toBeTrue();

    // Reprocessing the same evidence keeps the decision; rejecting zeroes it.
    $r = $this->processor->process($this->employee, '2026-09-22');
    expect($r->overtime_status)->toBe('approved')->and($r->overtime_approved_minutes)->toBe(120);
    $r = $regs->rejectOvertime($r, 'Not authorised');
    expect($r->overtime_approved_minutes)->toBe(0)->and($r->overtime_status)->toBe('rejected')
        ->and(AuditEvent::query()->where('action', 'OVERTIME_REJECTED')->exists())->toBeTrue();
    expect(fn () => $regs->approveOvertime($this->processor->process($this->employee, $this->wed), 10))->toThrow(RuntimeException::class, 'no calculated overtime');
});

it('enforces tenant, scope and relationship boundaries on attendance records, regularisations and the panel', function () {
    punch($this->employee, "{$this->wed} 09:00:00");
    punch($this->stranger, "{$this->wed} 09:00:00");
    $mine = $this->processor->process($this->employee, $this->wed);
    $theirs = $this->processor->process($this->stranger, $this->wed);

    // Manager in Delhi scope still reaches the Mumbai direct report, never the unrelated Mumbai employee.
    app(AccessScopes::class)->assign($this->managerUser, ['location' => [$this->delhi->id]]);
    $this->actingAs($this->managerUser);
    expect(AttendanceRecord::query()->pluck('employee_id')->all())->toEqualCanonicalizing([$this->employee->id])
        ->and($this->managerUser->can('approve', $mine))->toBeTrue()
        ->and($this->managerUser->can('approve', $theirs))->toBeFalse();
    $this->get(AttendanceRecordResource::getUrl('index'))->assertOk();
    $req = app(Regularisations::class)->request($this->stranger, $this->wed, 'late', 'x', "{$this->wed} 09:00:00", requester: $this->hr);
    // Even the service path fails closed: the out-of-scope request is invisible to the manager's queries.
    expect(fn () => app(Regularisations::class)->approve($req))->toThrow(ModelNotFoundException::class);
    expect(AttendanceRegularisation::query()->find($req->id))->toBeNull(); // out of scope

    // Employee sees only their own record.
    $this->actingAs($this->empUser);
    expect(AttendanceRecordResource::getEloquentQuery()->pluck('employee_id')->all())->toBe([$this->employee->id])
        ->and(AttendanceRegularisationResource::getEloquentQuery()->count())->toBe(0)
        ->and($this->empUser->can('view', $mine))->toBeTrue()->and($this->empUser->can('view', $theirs))->toBeFalse();

    // Another tenant: nothing.
    $other = tenantUser($this->tenantB, ['*']);
    actAsTenant($this->tenantB);
    $this->actingAs($other);
    expect(AttendanceRecord::query()->count())->toBe(0)->and(AttendanceRecord::query()->find($mine->id))->toBeNull();
    $this->get(AttendanceRegularisationResource::getUrl('index'))->assertOk();
});

it('serves the attendance API inside the key tenant and never exposes source payloads', function () {
    punch($this->employee, "{$this->wed} 09:00:00");
    punch($this->employee, "{$this->wed} 18:00:00");
    $this->processor->process($this->employee, $this->wed);
    $key = app(ApiKeys::class)->issue('att', ['attendance.read', 'attendance.write'])['plaintext'];
    actAsTenant($this->tenantB);
    $keyB = app(ApiKeys::class)->issue('B', ['attendance.read', 'attendance.write'])['plaintext'];
    actAsTenant(null);
    auth()->logout();

    $this->withHeader('X-Api-Key', $key)->getJson("/api/v1/attendance/records/EMP1/{$this->wed}")->assertOk()->assertJsonPath('data.status', 'present')->assertJsonPath('data.worked_minutes', 480)->assertJsonMissingPath('data.payload');
    $this->withHeader('X-Api-Key', $key)->getJson('/api/v1/attendance/records?from=2026-09-01')->assertOk()->assertJsonPath('meta.total', 1)->assertJsonMissing(['payload' => []]);
    $this->withHeader('X-Api-Key', $keyB)->getJson("/api/v1/attendance/records/EMP1/{$this->wed}")->assertNotFound();

    $this->withHeader('X-Api-Key', $key)->postJson('/api/v1/attendance/punches', ['employee_code' => 'EMP2', 'punched_at' => '2026-09-23 03:30:00', 'timezone' => 'Asia/Kolkata', 'direction' => 'in', 'external_id' => 'X-1', 'source_type' => 'mobile'])
        ->assertCreated()->assertJsonPath('data.status', 'processed');
    $this->withHeader('X-Api-Key', $key)->postJson('/api/v1/attendance/punches', ['employee_code' => 'EMP2', 'punched_at' => '2026-09-23 03:30:00', 'timezone' => 'Asia/Kolkata', 'direction' => 'in', 'external_id' => 'X-1'])
        ->assertOk()->assertJsonPath('data.status', 'duplicate');
    $this->withHeader('X-Api-Key', $key)->postJson('/api/v1/attendance/punches', ['employee_code' => 'NOBODY', 'punched_at' => '2026-09-23 09:00:00'])->assertStatus(202)->assertJsonPath('data.status', 'failed');
    $this->withHeader('X-Api-Key', $key)->postJson('/api/v1/attendance/punches', ['employee_code' => 'EMP2'])->assertStatus(422);

    $this->withHeader('X-Api-Key', $key)->postJson('/api/v1/attendance/regularisations', ['employee_code' => 'EMP2', 'date' => $this->wed, 'type' => 'missed_punch', 'reason' => 'Left early', 'requested_out' => "{$this->wed} 17:00:00"])->assertCreated()->assertJsonPath('data.status', 'pending');
    $this->withHeader('X-Api-Key', $key)->getJson('/api/v1/attendance/regularisations?status=pending')->assertOk()->assertJsonPath('meta.total', 1);
    $this->withHeader('X-Api-Key', $key)->getJson('/api/v1/attendance/exceptions')->assertOk();
    $this->withHeader('X-Api-Key', $key)->getJson('/api/v1/attendance/shifts')->assertOk()->assertJsonPath('data.0.code', 'GEN')->assertJsonPath('data.0.unpaid_break_minutes', 60);
    $this->withHeader('X-Api-Key', $key)->getJson('/api/v1/attendance/schedules')->assertOk()->assertJsonPath('data.0.code', 'MF');

    actAsTenant($this->tenant);
    expect(AttendancePunch::query()->where('external_id', 'X-1')->value('punched_at')->toIso8601String())->toBe('2026-09-22T22:00:00+00:00');
});
