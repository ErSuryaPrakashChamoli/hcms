<?php

use App\Domain\Configuration\Services\SetupCatalogue;
use App\Domain\Employment\Models\Employee;
use App\Domain\Platform\Services\SettingsRepository;
use App\Filament\Pages\SetupCentre;
use App\Filament\Resources\AttendanceArrears\AttendanceArrearResource;
use App\Filament\Resources\AttendanceOverrides\AttendanceOverrideResource;
use App\Filament\Resources\AttendancePenalties\AttendancePenaltyResource;
use App\Filament\Resources\EmployeeDevices\EmployeeDeviceResource;
use App\Filament\Resources\GeoFences\GeoFenceResource;
use App\Filament\Resources\LeaveDonations\LeaveDonationResource;
use App\Filament\Resources\LeaveEncashments\LeaveEncashmentResource;
use App\Filament\Resources\LocationRequests\LocationRequestResource;
use App\Filament\Resources\MyLeaveEncashments\MyLeaveEncashmentResource;
use App\Filament\Resources\ShiftOverrides\ShiftOverrideResource;
use App\Filament\Resources\TimeOfficeImports\TimeOfficeImportResource;
use App\Filament\Resources\TimeOfficeRequests\TimeOfficeRequestResource;
use Livewire\Livewire;

/* HROne parity: the Setup centre — every module's typed settings, policies and configuration screens. */

beforeEach(function () {
    $this->tenant = provisionTenant();
    actAsTenant($this->tenant);
    $this->admin = tenantUser($this->tenant, ['*']);
    $this->actingAs($this->admin);
});

it('declares only settings that exist, with a known type, and links that resolve to classes', function () {
    $defaults = config('peopleos.settings');
    foreach (config('peopleos_setup.modules') as $key => $module) {
        foreach ($module['settings'] as $setting) {
            expect(array_key_exists($setting['key'], $defaults))->toBeTrue("{$key}: {$setting['key']} has no default")
                ->and($setting['type'])->toBeIn(['boolean', 'number', 'text', 'textarea', 'select', 'multiselect', 'roles', 'date', 'matrix', 'file']);
        }
        foreach ($module['policy_types'] as $type) {
            expect(config("peopleos.policies.types.{$type}"))->not->toBeNull("{$key}: policy type {$type}");
        }
        foreach ($module['links'] as $link) {
            expect(class_exists($link['target']))->toBeTrue("{$key}: {$link['target']}");
        }
    }
});

it('saves only changed settings, validates them, and keeps every change audited', function () {
    $catalogue = app(SetupCatalogue::class);
    $field = fn (string $key) => SetupCatalogue::field($key);

    $changed = $catalogue->save('time_office', [$field('attendance.punch_window_hours') => '6', $field('attendance.process_on_punch') => true], 'Night shifts', $this->admin);
    expect($changed)->toBe(['attendance.punch_window_hours'])
        ->and(app(SettingsRepository::class)->get('attendance.punch_window_hours'))->toBe(6);

    expect(fn () => $catalogue->save('time_office', [$field('attendance.punch_window_hours') => '40'], 'Too many', $this->admin))->toThrow(RuntimeException::class, 'between 1 and 12');
    expect(fn () => $catalogue->save('payroll', [$field('payroll.proration_basis') => 'lunar'], 'x', $this->admin))->toThrow(RuntimeException::class, 'Choose a value');
    expect(fn () => $catalogue->save('time_office', [], '', $this->admin))->toThrow(RuntimeException::class, 'reason');

    $viewer = tenantUser($this->tenant, ['settings.view']);
    expect(fn () => $catalogue->save('time_office', [$field('attendance.punch_window_hours') => '5'], 'No', $viewer))->toThrow(RuntimeException::class, 'cannot change');
});

it('renders every module of the Setup centre and its new configuration screens', function () {
    $page = Livewire::test(SetupCentre::class)->assertOk()->assertSee('Core HR')->assertSee('Time office');
    foreach (array_keys(config('peopleos_setup.modules')) as $module) {
        $page->call('selectModule', $module)->assertOk()->assertSet('module', $module);
    }
    $page->call('selectModule', 'time_office')->assertSee('Short leave policy')->assertSee('Check-in policy')->assertSee('Shift roster');

    foreach ([TimeOfficeRequestResource::class, ShiftOverrideResource::class, AttendanceOverrideResource::class, AttendancePenaltyResource::class, AttendanceArrearResource::class, GeoFenceResource::class, LocationRequestResource::class, EmployeeDeviceResource::class, LeaveDonationResource::class, LeaveEncashmentResource::class, TimeOfficeImportResource::class] as $resource) {
        $this->get($resource::getUrl('index'))->assertOk();
    }
});

it('gives employees their own encashments without opening the HR register', function () {
    $user = tenantUser($this->tenant, ['leave.apply']);
    Employee::factory()->create(['user_id' => $user->id]);
    $this->actingAs($user);
    $this->get(MyLeaveEncashmentResource::getUrl('index'))->assertOk();
    $this->get(LeaveEncashmentResource::getUrl('index'))->assertForbidden();
});

it('is closed to people without settings or policy access', function () {
    $this->actingAs(tenantUser($this->tenant, ['attendance.regularise']));
    expect(SetupCentre::canAccess())->toBeFalse();
    $this->get(SetupCentre::getUrl())->assertForbidden();
});
