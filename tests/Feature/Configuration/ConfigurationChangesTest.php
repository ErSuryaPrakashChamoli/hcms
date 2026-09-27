<?php

use App\Domain\Audit\Models\AuditEvent;
use App\Domain\Configuration\Enums\ChangeStatus;
use App\Domain\Configuration\Enums\RiskLevel;
use App\Domain\Configuration\Exceptions\ConfigurationException;
use App\Domain\Configuration\Models\ConfigurationChange;
use App\Domain\Configuration\Services\ConfigurationChanges;
use App\Domain\Employment\Actions\HireEmployeeAction;
use App\Domain\Identity\Models\Role;
use App\Domain\Organisation\Models\Company;
use App\Domain\Organisation\Models\Department;
use App\Domain\Organisation\Models\Designation;
use App\Domain\Platform\Services\FeatureFlags;
use App\Domain\Platform\Services\SettingsRepository;
use App\Filament\Resources\Departments\Pages\EditDepartment;
use Livewire\Livewire;

beforeEach(function () {
    $this->tenant = provisionTenant();
    actAsTenant($this->tenant);
    $this->admin = tenantUser($this->tenant, ['*']);
    $this->actingAs($this->admin);
    $this->changes = app(ConfigurationChanges::class);
    $this->department = Department::factory()->create(['name' => 'Finance']);
});

it('classifies risk from configuration', function () {
    expect($this->changes->riskFor($this->department))->toBe(RiskLevel::Low)
        ->and($this->changes->riskFor(Company::class))->toBe(RiskLevel::Medium)
        ->and($this->changes->riskFor(Role::class))->toBe(RiskLevel::High)
        ->and($this->changes->isGoverned(Designation::class))->toBeTrue()
        ->and($this->changes->requiresApproval($this->department))->toBeFalse();
});

it('applies changes immediately when approval is off, still leaving a published record', function () {
    $change = $this->changes->propose($this->department, ['name' => 'Finance & Accounts'], 'Rename');

    expect($change->status)->toBe(ChangeStatus::Published)
        ->and($this->department->fresh()->name)->toBe('Finance & Accounts')
        ->and($change->before['name'])->toBe('Finance')
        ->and($change->diff())->toBe(['name' => ['before' => 'Finance', 'after' => 'Finance & Accounts']])
        ->and($this->department->auditEvents()->where('action', 'UPDATE')->first()->approval_reference)->toBe("CHANGE-{$change->id}")
        ->and($this->department->auditEvents()->where('action', 'UPDATE')->first()->reason)->toBe('Rename');
});

it('parks medium and high risk changes for approval when the tenant turns it on', function () {
    app(FeatureFlags::class)->set('configuration.approval', true);
    $company = Company::factory()->create(['name' => 'Acme']);

    $low = $this->changes->propose($this->department, ['name' => 'Ops'], 'Low risk');
    $medium = $this->changes->propose($company, ['name' => 'Acme Global'], 'Rebrand');

    expect($low->status)->toBe(ChangeStatus::Published)
        ->and($medium->status)->toBe(ChangeStatus::PendingApproval)
        ->and($company->fresh()->name)->toBe('Acme')
        ->and($medium->requested_by)->toBe($this->admin->id);

    $this->changes->approve($medium, 'Looks right');

    expect($medium->fresh()->status)->toBe(ChangeStatus::Published)
        ->and($company->fresh()->name)->toBe('Acme Global')
        ->and(AuditEvent::query()->where('action', 'APPROVED')->where('entity_id', (string) $medium->id)->exists())->toBeTrue();
});

it('schedules approved changes with a future effective date and publishes them when due', function () {
    app(FeatureFlags::class)->set('configuration.approval', true);
    $company = Company::factory()->create(['name' => 'Acme']);

    $change = $this->changes->propose($company, ['name' => 'Acme 2027'], 'Rebrand', now()->addDays(3));
    $this->changes->approve($change);

    expect($change->fresh()->status)->toBe(ChangeStatus::Scheduled)
        ->and($company->fresh()->name)->toBe('Acme')
        ->and($this->changes->publishDue())->toBe(0);

    $this->travel(4)->days();
    $this->artisan('peopleos:configuration:publish-due')->assertSuccessful();

    expect($change->fresh()->status)->toBe(ChangeStatus::Published)
        ->and($company->fresh()->name)->toBe('Acme 2027');
});

it('rejects, discards and refuses illegal transitions', function () {
    app(FeatureFlags::class)->set('configuration.approval', true);
    $company = Company::factory()->create(['name' => 'Acme']);

    $rejected = $this->changes->propose($company, ['name' => 'Nope'], 'x');
    $this->changes->reject($rejected, 'Not agreed');
    expect($rejected->fresh()->status)->toBe(ChangeStatus::Rejected)->and($company->fresh()->name)->toBe('Acme');
    expect(fn () => $this->changes->approve($rejected))->toThrow(ConfigurationException::class);

    $discarded = $this->changes->propose($company, ['name' => 'Maybe'], 'y');
    $this->changes->discard($discarded);
    expect($discarded->fresh()->status)->toBe(ChangeStatus::Discarded);
    expect(fn () => $this->changes->discard($discarded))->toThrow(ConfigurationException::class);
});

it('rolls back a published change through a new governed change', function () {
    $change = $this->changes->propose($this->department, ['name' => 'Renamed'], 'Rename');
    $restore = $this->changes->rollback($change, 'Mistake');

    expect($this->department->fresh()->name)->toBe('Finance')
        ->and($change->fresh()->status)->toBe(ChangeStatus::RolledBack)
        ->and($change->fresh()->rolled_back_by_change_id)->toBe($restore->id)
        ->and($restore->change_type)->toBe('rollback')
        ->and($restore->status)->toBe(ChangeStatus::Published)
        ->and(ConfigurationChange::query()->count())->toBe(2);
});

it('previews impact by counting employees in the affected unit', function () {
    $company = Company::factory()->create();
    foreach (range(1, 3) as $i) {
        app(HireEmployeeAction::class)->handle(['first_name' => "E{$i}", 'last_name' => 'X'], ['joining_date' => '2025-01-01'], ['company_id' => $company->id, 'department_id' => $this->department->id]);
    }

    $change = $this->changes->propose($this->department, ['name' => 'Finance Ops']);

    expect($change->impact['employees_affected'])->toBe(3)
        ->and($change->impact['summary'])->toContain('3 employee(s)');
});

it('routes governed edit pages through the change centre only when approval applies', function () {
    Livewire::test(EditDepartment::class, ['record' => $this->department->getRouteKey()])
        ->fillForm(['name' => 'Direct edit', 'audit_reason' => 'Quick fix'])
        ->call('save')
        ->assertHasNoFormErrors();
    expect($this->department->fresh()->name)->toBe('Direct edit')
        ->and(ConfigurationChange::query()->count())->toBe(0);

    app(FeatureFlags::class)->set('configuration.approval', true);
    app(SettingsRepository::class)->set('configuration.approval.minimum_risk', 'low');

    Livewire::test(EditDepartment::class, ['record' => $this->department->getRouteKey()])
        ->fillForm(['name' => 'Needs approval', 'audit_reason' => 'Big change'])
        ->call('save')
        ->assertNotified('Submitted for approval');

    $change = ConfigurationChange::query()->first();
    expect($this->department->fresh()->name)->toBe('Direct edit')
        ->and($change->status)->toBe(ChangeStatus::PendingApproval)
        ->and($change->payload['name'])->toBe('Needs approval')
        ->and($change->reason)->toBe('Big change');
});
