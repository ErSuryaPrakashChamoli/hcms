<?php

use App\Domain\Employment\Models\Employee;
use App\Domain\Experience\Services\ApprovalCenter;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Models\UserAccessScope;
use App\Domain\Identity\Scopes\AccessScope;
use App\Domain\Identity\Services\AccessScopes;
use App\Domain\Leave\Models\LeaveRequest;
use App\Domain\Leave\Models\LeaveType;
use App\Domain\Organisation\Models\Company;
use App\Domain\Organisation\Models\Team;
use App\Domain\Platform\Models\Tenant;
use App\Filament\Pages\MyHr;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\UxShowcaseSeeder;

/*
| UX.15 closure P1-05: the demo personas carry realistic organisation scope, set by the showcase seeder through
| the existing access-scope service (audited scope rows; no demo bypass; no rule changed). The manager reaches the
| Platform team and their reporting line, the HR business partner and the payroll lead reach Demo Technologies,
| the HR admin and the executive stay tenant-wide by the documented rule, and employees are self-service.
*/

it('scopes the demo personas realistically, through the access-scope architecture', function () {
    $this->seed(UxShowcaseSeeder::class);
    $tenants = app(TenantContext::class);
    $tenant = $tenants->bypass(fn () => Tenant::query()->where('slug', 'demo')->firstOrFail());
    actAsTenant($tenant);
    $user = fn (string $email) => User::query()->where('email', $email)->firstOrFail();
    $emp = fn (string $email) => Employee::query()->withoutGlobalScopes([AccessScope::class])->where('work_email', $email)->firstOrFail();
    $tech = Company::query()->where('code', 'DEMO-TECH')->firstOrFail();
    $platform = Team::query()->where('code', 'PLAT')->firstOrFail();
    $scopes = app(AccessScopes::class);
    $visible = function (User $viewer) {
        $this->actingAs($viewer);
        app(AccessScopes::class)->forget();

        return Employee::query()->pluck('work_email')->all();
    };

    // Stored as ordinary, audited scope rows (no special-casing anywhere).
    expect($scopes->for($user('amit.verma@demo.local')))->toBe(['team' => [$platform->id]])
        ->and($scopes->for($user('neha.kapoor@demo.local')))->toBe(['company' => [$tech->id]])
        ->and($scopes->for($user('arjun.bose@demo.local')))->toBe(['company' => [$tech->id]])
        ->and($scopes->for($user('kavya.menon@demo.local')))->toBeNull()
        ->and($scopes->for($user('meera.iyer@demo.local')))->toBeNull()
        ->and(UserAccessScope::query()->count())->toBe(3);

    // Manager: their Platform team and reporting line (any reporting type), nobody else.
    $amitSees = $visible($user('amit.verma@demo.local'));
    expect($amitSees)->toContain('priya.nair@demo.local', 'rahul.sharma@demo.local', 'rohan.pillai@demo.local', 'leela.chandran@demo.local', 'karan.mehta@demo.local')
        ->not->toContain('fatima.sheikh@demo.local')->not->toContain('sara.thomas@demo.local')->not->toContain('omar.farooq@demo.local')->not->toContain('vikram.singh@demo.local');

    // HR business partner: Demo Technologies, not Demo Services (the consultants).
    $nehaSees = $visible($user('neha.kapoor@demo.local'));
    expect($nehaSees)->toContain('fatima.sheikh@demo.local', 'sara.thomas@demo.local', 'vikram.singh@demo.local')
        ->not->toContain('omar.farooq@demo.local')->not->toContain('nisha.reddy@demo.local');

    // HR admin and executive: tenant-wide by design.
    expect($visible($user('kavya.menon@demo.local')))->toContain('omar.farooq@demo.local', 'fatima.sheikh@demo.local')
        ->and($visible($user('meera.iyer@demo.local')))->toContain('nisha.reddy@demo.local');

    // Approvals stay within scope: the manager may approve their line, not another team or company.
    $type = LeaveType::query()->where('code', 'EL')->firstOrFail();
    $file = fn (Employee $e) => LeaveRequest::query()->create(['employee_id' => $e->id, 'leave_type_id' => $type->id, 'from_date' => now()->addDays(30)->toDateString(), 'to_date' => now()->addDays(30)->toDateString(),
        'from_session' => 'full', 'to_session' => 'full', 'days' => 1, 'reason' => 'Demo scope', 'status' => 'pending']);
    $mobile = $file($emp('fatima.sheikh@demo.local'));
    $services = $file($emp('omar.farooq@demo.local'));
    $amit = $user('amit.verma@demo.local');
    $this->actingAs($amit);
    $scopes->forget();
    $pendingForAmit = (new ApprovalCenter($tenants))->pending($amit)->pluck('subjectEmployeeId')->unique()->all();
    expect($amit->can('approve', LeaveRequest::query()->withoutGlobalScopes([AccessScope::class])->find($mobile->id)))->toBeFalse()
        ->and($amit->can('approve', LeaveRequest::query()->withoutGlobalScopes([AccessScope::class])->find($services->id)))->toBeFalse()
        ->and($pendingForAmit)->not->toContain($emp('fatima.sheikh@demo.local')->id)->not->toContain($emp('omar.farooq@demo.local')->id)
        ->and(collect($pendingForAmit)->every(fn ($id) => in_array($id, Employee::query()->whereIn('work_email', $amitSees)->pluck('id')->all(), false)))->toBeTrue();

    // Employee: self-service by permission (their own requests and My HR), never other people's records.
    $priya = $user('priya.nair@demo.local');
    $own = $file($emp('priya.nair@demo.local'));
    $this->actingAs($priya);
    expect($priya->can('view', $own))->toBeTrue()
        ->and($priya->can('view', LeaveRequest::query()->withoutGlobalScopes([AccessScope::class])->find($mobile->id)))->toBeFalse()
        ->and($priya->can('view', $emp('fatima.sheikh@demo.local')))->toBeFalse()
        ->and(MyHr::canAccess())->toBeTrue();
});
