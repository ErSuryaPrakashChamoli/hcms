<?php

use App\Domain\Attendance\Models\AttendancePunch;
use App\Domain\Attendance\Models\GeoFence;
use App\Domain\Attendance\Models\LocationPing;
use App\Domain\Attendance\Services\CheckInGuard;
use App\Domain\Attendance\Services\GeoLocations;
use App\Domain\Attendance\Services\PunchIngestion;
use App\Domain\Configuration\Models\Policy;
use App\Domain\Configuration\Models\PolicyAssignmentRule;
use App\Domain\Configuration\Services\Policies;
use App\Domain\Employment\Models\Employee;
use App\Domain\Integration\Services\ApiKeys;
use App\Filament\Pages\MyDay;
use App\Filament\Resources\Policies\PolicyResource;
use App\Filament\Support\PolicySettingsSchema;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

require_once __DIR__.'/../Attendance/AttendanceTestHelpers.php';

/* HROne setup parity — Time office → Check in policy: web and mobile controls, polygon and temporary locations, the app's punch payload. */

beforeEach(function () {
    $this->travelTo('2026-09-25 10:00:00');
    Storage::fake('local');
    config(['peopleos.documents.disk' => 'local']);
    $this->tenant = provisionTenant();
    actAsTenant($this->tenant);
    $this->hr = tenantUser($this->tenant, ['*']);
    $this->actingAs($this->hr);
    $this->employee = Employee::factory()->create(['joining_date' => '2025-01-01', 'employee_code' => 'EMP7']);
    assignSchedule($this->employee, weeklySchedule(generalShift()));
    $this->punch = fn (string $at, string $source, array $meta = []) => app(PunchIngestion::class)->record($this->employee, $at, 'auto', $source, null, null, $meta)->refresh();
});

function checkinPolicy(array $settings): Policy
{
    $policy = Policy::create(['type' => 'checkin', 'name' => 'Check-in', 'code' => 'CHK']);
    app(Policies::class)->draft($policy, $settings);
    app(Policies::class)->publish($policy, '2020-01-01');
    PolicyAssignmentRule::create(['policy_type' => 'checkin', 'policy_id' => $policy->id, 'name' => 'All', 'conditions' => []]);

    return $policy;
}

it('keeps web check-ins to the window, the daily punch limit and the office IP ranges', function () {
    checkinPolicy(['web_window_enabled' => true, 'web_window_start' => '08:00', 'web_window_end' => '20:00', 'web_max_punches_enabled' => true, 'web_max_punches' => 2,
        'web_ip_validation' => true, 'web_allowed_ips' => ['10.0.0.0/8', '203.0.113.7']]);

    expect(($this->punch)('2026-09-25 07:30:00', 'web', ['ip_address' => '10.1.2.3'])->processing_error)->toBe('Attendance can be marked only between 08:00 and 20:00.')
        ->and(($this->punch)('2026-09-25 09:00:00', 'web', ['ip_address' => '192.168.1.5'])->processing_error)->toBe('Web check-in is allowed only from your office network.')
        ->and(($this->punch)('2026-09-25 09:01:00', 'web', ['ip_address' => '10.1.2.3'])->processing_status)->not->toBe('rejected')
        ->and(($this->punch)('2026-09-25 13:00:00', 'web', ['ip_address' => '203.0.113.7'])->processing_status)->not->toBe('rejected')
        ->and(($this->punch)('2026-09-25 14:00:00', 'web', ['ip_address' => '10.1.2.3'])->processing_error)->toContain('at most 2 time(s)')
        ->and(($this->punch)('2026-09-25 14:05:00', 'mobile')->processing_status)->not->toBe('rejected') // the web limits do not apply to the app
        ->and(AttendancePunch::query()->where('processing_status', '!=', 'rejected')->orderBy('id')->value('ip_address'))->toBe('10.1.2.3');
});

it('asks the app for photos, a verified face and an approved WiFi network', function () {
    checkinPolicy(['photo_capture' => 'facial', 'photo_count' => 2, 'wifi_attendance' => true, 'wifi_networks' => ['Office-WiFi', 'AA:BB:CC:DD:EE:FF']]);

    expect(($this->punch)('2026-09-25 09:00:00', 'mobile', ['photos' => ['p/1.jpg']])->processing_error)->toBe('Take 2 photos to check in.')
        ->and(($this->punch)('2026-09-25 09:01:00', 'mobile', ['photos' => ['p/1.jpg', 'p/2.jpg']])->processing_error)->toBe('Your face could not be verified; try again.')
        ->and(($this->punch)('2026-09-25 09:02:00', 'mobile', ['photos' => ['p/1.jpg', 'p/2.jpg'], 'face_verified' => true, 'wifi_ssid' => 'Guest'])->processing_error)->toBe('Connect to an approved office WiFi network to check in.');

    $ok = ($this->punch)('2026-09-25 09:03:00', 'mobile', ['photos' => ['p/1.jpg', 'p/2.jpg'], 'face_verified' => '1', 'wifi_ssid' => 'office-wifi']);
    expect($ok->processing_status)->not->toBe('rejected')->and($ok->photos)->toBe(['p/1.jpg', 'p/2.jpg'])->and($ok->face_verified)->toBeTrue()->and($ok->wifi_ssid)->toBe('office-wifi')
        ->and(($this->punch)('2026-09-25 18:00:00', 'web')->processing_status)->not->toBe('rejected'); // photos and WiFi are for the app
});

it('checks polygon geo locations and keeps temporary locations to their approved period', function () {
    checkinPolicy(['geo_fence_required' => true, 'outside_fence_action' => 'reject', 'temporary_locations' => true, 'temporary_location_max_days' => 10]);
    GeoFence::create(['name' => 'Campus', 'latitude' => 28.605, 'longitude' => 77.205, 'shape' => 'polygon',
        'polygon' => [['latitude' => 28.60, 'longitude' => 77.20], ['latitude' => 28.60, 'longitude' => 77.21], ['latitude' => 28.61, 'longitude' => 77.21], ['latitude' => 28.61, 'longitude' => 77.20]]]);

    $inside = ($this->punch)('2026-09-25 09:00:00', 'mobile', ['latitude' => 28.605, 'longitude' => 77.205]);
    $outside = ($this->punch)('2026-09-25 09:05:00', 'mobile', ['latitude' => 28.62, 'longitude' => 77.205]);
    expect($inside->geo_status)->toBe('inside')->and($inside->processing_status)->not->toBe('rejected')
        ->and($outside->geo_status)->toBe('outside')->and($outside->geo_distance_m)->toBeGreaterThan(1000)->and($outside->processing_status)->toBe('rejected');

    $geo = app(GeoLocations::class);
    $site = ['name' => 'Client site', 'latitude' => 19.07, 'longitude' => 72.87, 'radius_m' => 300];
    expect(fn () => $geo->request($this->employee, $site, 'Audit'))->toThrow(RuntimeException::class, 'up to 10 days')
        ->and(fn () => $geo->request($this->employee, $site + ['valid_until' => '2026-10-20'], 'Audit'))->toThrow(RuntimeException::class, 'at most 10 days');
    $request = $geo->request($this->employee, $site + ['valid_until' => '2026-09-29'], 'Audit');
    $geo->approve($request, 'ok', $this->hr);
    $fence = GeoFence::query()->where('source', 'request')->firstOrFail();
    expect($fence->valid_from->toDateString())->toBe('2026-09-25')->and($fence->valid_until->toDateString())->toBe('2026-09-29')
        ->and(($this->punch)('2026-09-28 09:00:00', 'mobile', ['latitude' => 19.0701, 'longitude' => 72.8701])->geo_status)->toBe('inside');

    $this->travelTo('2026-10-01 09:00:00');
    expect(($this->punch)('2026-10-01 09:00:00', 'mobile', ['latitude' => 19.0701, 'longitude' => 72.8701])->processing_status)->toBe('rejected');
});

it('takes photos, face verification and WiFi from the app, and location pings only with continuous tracking', function () {
    checkinPolicy(['photo_capture' => 'photo', 'photo_count' => 1]);
    $key = app(ApiKeys::class)->issue('app', ['attendance.read', 'attendance.write'])['plaintext'];
    $jpeg = 'data:image/jpeg;base64,'.base64_encode(base64_decode('/9j/4AAQSkZJRgABAQAAAQABAAD/2wBDAAgGBgcGBQgHBwcJCQgKDBQNDAsLDBkSEw8UHRofHh0aHBwgJC4nICIsIxwcKDcpLDAxNDQ0Hyc5PTgyPC4zNDL/wAALCAABAAEBAREA/8QAFAABAAAAAAAAAAAAAAAAAAAACf/EABQQAQAAAAAAAAAAAAAAAAAAAAD/2gAIAQEAAD8AKp//2Q=='));
    actAsTenant(null);
    auth()->logout();

    $this->withHeader('X-Api-Key', $key)->postJson('/api/v1/attendance/punches', ['employee_code' => 'EMP7', 'punched_at' => '2026-09-25 09:00:00', 'source_type' => 'mobile'])
        ->assertStatus(422)->assertJsonPath('data.error', 'Take a photo to check in.');
    $this->withHeader('X-Api-Key', $key)->postJson('/api/v1/attendance/punches', ['employee_code' => 'EMP7', 'punched_at' => '2026-09-25 09:01:00', 'source_type' => 'mobile',
        'photos_base64' => [$jpeg], 'face_verified' => true, 'wifi_ssid' => 'Office'])->assertCreated();
    $this->withHeader('X-Api-Key', $key)->postJson('/api/v1/attendance/locations', ['employee_code' => 'EMP7', 'pings' => [['recorded_at' => '2026-09-25 09:30:00', 'latitude' => 28.6, 'longitude' => 77.2]]])
        ->assertStatus(422);

    actAsTenant($this->tenant);
    $punch = AttendancePunch::query()->where('processing_status', '!=', 'rejected')->firstOrFail();
    expect($punch->photos)->toHaveCount(1)->and(Storage::disk('local')->exists($punch->photos[0]))->toBeTrue()->and($punch->wifi_ssid)->toBe('Office');

    Policy::query()->firstOrFail()->delete();
    checkinPolicy(['continuous_tracking' => true]);
    actAsTenant(null);
    $this->withHeader('X-Api-Key', $key)->postJson('/api/v1/attendance/locations', ['employee_code' => 'EMP7', 'device_identifier' => 'phone-1',
        'pings' => [['recorded_at' => '2026-09-25 09:30:00', 'latitude' => 28.6, 'longitude' => 77.2, 'accuracy_m' => 15], ['recorded_at' => '2026-09-25 10:00:00', 'latitude' => 28.61, 'longitude' => 77.21]]])
        ->assertCreated()->assertJsonPath('data.stored', 2);
    actAsTenant($this->tenant);
    expect(LocationPing::query()->count())->toBe(2);
});

it('reports a refused web check-in as not accepted and does not show the employee as checked in', function () {
    $user = tenantUser($this->tenant, ['attendance.regularise', 'leave.apply', 'payroll.payslip']);
    $this->employee->update(['user_id' => $user->id]);
    checkinPolicy(['require_location' => true]);
    $this->actingAs($user);

    expect(app(CheckInGuard::class)->needsLocation($this->employee))->toBeTrue();
    Livewire::test(MyDay::class)->call('punch', 'in', ['device_identifier' => 'web-abc'])->assertNotified();
    expect(AttendancePunch::query()->value('processing_status'))->toBe('rejected')
        ->and(Livewire::test(MyDay::class)->instance()->getToday()['checked_in'])->toBeFalse();

    $this->travel(1)->minutes(); // the same second and direction would be the same punch
    Livewire::test(MyDay::class)->call('punch', 'in', ['device_identifier' => 'web-abc', 'latitude' => 28.6, 'longitude' => 77.2])->assertNotified();
    expect(Livewire::test(MyDay::class)->instance()->getToday()['checked_in'])->toBeTrue();
    expect(AttendancePunch::query()->where('processing_status', '!=', 'rejected')->value('device_identifier'))->toBe('web-abc');
});

it('lays the check-in policy out like HROne with follow-up questions, shows it labelled, and flags a policy that applies to nobody', function () {
    $keys = array_column(PolicySettingsSchema::flatFields('checkin'), 'key');
    expect($keys)->toContain('web_max_punches', 'web_window_start', 'web_allowed_ips', 'photo_capture', 'photo_count', 'temporary_location_max_days', 'wifi_networks', 'continuous_tracking')
        ->and(PolicySettingsSchema::display(['type' => 'radio', 'options' => ['photo' => 'Photo']], 'photo'))->toBe('Photo')
        ->and(PolicySettingsSchema::display(['type' => 'yes_no'], '0'))->toBe('No')
        ->and(PolicySettingsSchema::display(['type' => 'tags'], ['10.0.0.0/8', '1.2.3.4']))->toBe('10.0.0.0/8, 1.2.3.4');

    $empty = Policy::create(['type' => 'checkin', 'name' => 'Empty', 'code' => 'EMPTY']);
    $this->get(PolicyResource::getUrl('edit', ['record' => $empty]))->assertOk()->assertSee('applies to nobody yet');
    $used = checkinPolicy(['web_window_enabled' => true, 'web_window_start' => '08:00', 'web_window_end' => '20:00']);
    $this->get(PolicyResource::getUrl('edit', ['record' => $used]))->assertOk()->assertDontSee('applies to nobody yet');
});
