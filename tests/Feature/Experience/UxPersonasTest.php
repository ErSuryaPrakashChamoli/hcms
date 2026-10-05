<?php

use App\Domain\Employment\Actions\HireEmployeeAction;
use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Models\User;
use App\Domain\Lifecycle\Enums\LifecycleState;
use App\Domain\Lifecycle\Services\LifecycleEngine;
use App\Domain\Organisation\Models\Company;
use App\Filament\Pages\AdminCentre;
use App\Filament\Pages\Approvals;
use App\Filament\Pages\Home;
use App\Filament\Pages\MyWork;
use App\Filament\Pages\NotificationCenter;
use App\Filament\Pages\People;
use App\Filament\Pages\WorkforceCommandCentre;
use App\Filament\Resources\Employees\EmployeeResource;
use App\Support\Tenancy\TenantContext;
use Livewire\Livewire;

/*
| Experience Transformation §55: every persona, with the real system roles, against representative
| screens. The redesigned surfaces must answer exactly as the permissions say, for the URL itself (not
| only for what the navigation shows).
*/

function persona(string $role, ?Employee $employee = null): User
{
    return app(TenantContext::class)->runAs(test()->tenant, function () use ($role, $employee) {
        $user = User::factory()->forTenant(test()->tenant)->create();
        $user->roles()->attach(Role::query()->where('slug', $role)->firstOrFail());
        if ($employee !== null) {
            LifecycleEngine::unguarded(fn () => $employee->forceFill(['user_id' => $user->id])->save());
        }

        return $user;
    });
}

beforeEach(function () {
    $this->tenant = provisionTenant();
    actAsTenant($this->tenant);
    $company = Company::factory()->create();
    $hire = fn (string $first, ?Employee $manager = null) => tap(app(HireEmployeeAction::class)->handle(
        ['first_name' => $first, 'last_name' => 'Persona'], ['joining_date' => '2024-01-01'], ['company_id' => $company->id], $manager?->id,
    ), fn (Employee $e) => forceLifecycle($e, LifecycleState::Active));
    $this->managerEmployee = $hire('Maya');
    $this->staffEmployee = $hire('Ravi', $this->managerEmployee);

    $this->personas = [
        'employee' => persona('employee', $this->staffEmployee),
        'manager' => persona('manager', $this->managerEmployee),
        'hr' => persona('hr-manager'),
        'hr_admin' => persona('tenant-hr-admin'),
        'executive' => persona('executive'),
        'payroll' => persona('payroll-admin'),
        'admin' => persona('tenant-super-admin'),
    ];
    actAsTenant(null);
});

it('answers every representative screen exactly as each persona\'s permissions say', function () {
    $staff360 = EmployeeResource::getUrl('view', ['record' => $this->staffEmployee]);
    // persona => [Home, My work, Approvals, People, Employee 360, Workforce Command Centre, Admin Centre, Notifications]
    // UX.19 (G12): the staff 360 is the employee persona's own record, which employee.self now opens (read-only); it was
    // 403 before G12 was decided. Another employee's 360 stays refused (checked below).
    $expected = [
        'employee' => [200, 200, 200, 200, 200, 403, 403, 200],
        'manager' => [200, 200, 200, 200, 200, 403, 403, 200],
        'hr' => [200, 200, 200, 200, 200, 403, 200, 200],
        'hr_admin' => [200, 200, 200, 200, 200, 200, 200, 200],
        'executive' => [200, 200, 403, 403, 403, 200, 403, 200],
        'payroll' => [200, 200, 200, 200, 200, 403, 403, 200],
        'admin' => [200, 200, 200, 200, 200, 200, 200, 200],
    ];
    $urls = [Home::getUrl(), MyWork::getUrl(), Approvals::getUrl(), People::getUrl(), $staff360, WorkforceCommandCentre::getUrl(), AdminCentre::getUrl(), NotificationCenter::getUrl()];

    foreach ($expected as $persona => $codes) {
        foreach ($urls as $i => $url) {
            $status = $this->actingAs($this->personas[$persona])->get($url)->getStatusCode();
            expect($status)->toBe($codes[$i], "{$persona} → {$url}");
        }
    }
    $this->actingAs($this->personas['employee'])->get(EmployeeResource::getUrl('view', ['record' => $this->managerEmployee]))->assertForbidden();
});

it('gives each persona the Home that fits their role', function () {
    $sees = [
        'employee' => ['Request leave', 'Your day'],
        'manager' => ['Team pulse', 'Approvals pending'],
        'hr' => ['People operations'],
        'hr_admin' => ['People operations'],
        'executive' => ['People today', 'Workforce pulse'],
        'payroll' => ['No payroll run yet'],
        // UX.16 (G1): a tenant super-admin's Home opens on administration, no longer on the executive figures.
        'admin' => ['Governance', 'Open Admin Centre'],
    ];
    foreach ($sees as $persona => $texts) {
        $response = $this->actingAs($this->personas[$persona])->get(Home::getUrl())->assertOk();
        foreach ($texts as $text) {
            $response->assertSee($text);
        }
    }
    $this->actingAs($this->personas['employee'])->get(Home::getUrl())->assertDontSee('People operations')->assertDontSee('Workforce pulse');

    // The executive view the super-admin used to land on is still one switch away, with the same figures.
    $this->actingAs($this->personas['admin']);
    Livewire::test(Home::class)->call('switchLens', 'executive')->assertSee('People today')->assertSee('Workforce pulse');
});
