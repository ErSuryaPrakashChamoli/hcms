<?php

use App\Domain\Audit\Models\AuditEvent;
use App\Domain\Configuration\Models\ConfigurationChange;
use App\Domain\Employment\Actions\AssignPositionAction;
use App\Domain\Employment\Models\Employee;
use App\Domain\Organisation\Models\Company;
use App\Domain\Organisation\Models\Department;
use App\Domain\Organisation\Models\Designation;
use App\Domain\Organisation\Models\Division;
use App\Domain\Organisation\Models\EmployeeEstablishmentAssignment;
use App\Domain\Organisation\Models\EmploymentType;
use App\Domain\Organisation\Models\Establishment;
use App\Domain\Organisation\Models\Grade;
use App\Domain\Organisation\Models\LegalEntity;
use App\Domain\Organisation\Models\Level;
use App\Domain\Organisation\Models\OrganisationNode;
use App\Domain\Organisation\Services\OrganisationTree;
use App\Domain\People\Models\PersonSkill;
use App\Domain\People\Models\Skill;
use App\Domain\Platform\Services\FeatureFlags;
use App\Domain\Platform\Services\SettingsRepository;
use App\Filament\Resources\Designations\Pages\EditDesignation;
use App\Filament\Resources\Levels\Pages\EditLevel;
use App\Filament\Resources\Skills\Pages\ManageSkills;
use App\Support\Masters\MasterInUseException;
use App\Support\Masters\MasterUsage;
use Filament\Actions\Testing\TestAction;
use Livewire\Livewire;

require_once __DIR__.'/../Workflow/WorkflowTestHelpers.php';
require_once __DIR__.'/../Performance/PerformanceTestHelpers.php';

/*
| Master-relationships R1: masters are created on their own (no other master needed), and a master that something
| uses is never deleted. The refusal names the counts (never the records), and the screen offers "Deactivate
| instead", which follows the edit rules: edit permission, and approval when the organisation governs the record.
*/

beforeEach(function () {
    $this->travelTo('2026-10-05 09:00:00');
    $this->tenant = provisionTenant();
    actAsTenant($this->tenant);
    $this->admin = tenantUser($this->tenant, ['*']);
    $this->actingAs($this->admin);
});

it('creates every master on its own: a designation without department, level or grade, a level, a grade without a level, a department without a division, a division without departments', function () {
    $designation = Designation::query()->create(['name' => 'Sourcing Manager', 'code' => 'SRC-MGR']);
    $level = Level::query()->create(['name' => 'R1 L5', 'code' => 'R1-L5']);
    $grade = Grade::query()->create(['name' => 'R1 T4', 'code' => 'R1-T4']);
    $department = Department::query()->create(['name' => 'Procurement', 'code' => 'PROC']);
    $division = Division::query()->create(['name' => 'Operations', 'code' => 'OPS']);

    expect($designation->refresh())->level_id->toBeNull()->grade_id->toBeNull()->department_id->toBeNull()->job_family_id->toBeNull()
        ->and($level->exists)->toBeTrue()
        ->and($grade->refresh()->level_id)->toBeNull()
        ->and($department->refresh()->company_id)->toBeNull()
        ->and($division->exists)->toBeTrue()
        ->and(Department::query()->count())->toBe(1);
});

it('deletes a master nothing uses, exactly as before', function () {
    $level = Level::query()->create(['name' => 'R1 L9', 'code' => 'R1-L9']);

    expect(app(MasterUsage::class)->of($level))->toBe([])
        ->and($level->delete())->toBeTrue()
        ->and(Level::query()->whereKey($level->id)->exists())->toBeFalse();
});

it('refuses to delete a master that current or past positions or other masters use, with counts only', function () {
    $employee = activeEmployee();
    $designation = Designation::query()->create(['name' => 'Sourcing Manager', 'code' => 'SRC-MGR']);
    $next = Designation::query()->create(['name' => 'Sourcing Lead', 'code' => 'SRC-LEAD']);
    app(AssignPositionAction::class)->handle($employee, ['designation_id' => $designation->id], 'transfer', '2026-10-01', 'Moved');
    // Moved on: only a past position still uses the first designation. History keeps it, so it is still in use.
    app(AssignPositionAction::class)->handle($employee, ['designation_id' => $next->id], 'promotion', '2026-11-01', 'Promoted');

    $level = Level::query()->create(['name' => 'R1 L5', 'code' => 'R1-L5']);
    Grade::query()->create(['name' => 'R1 T4', 'code' => 'R1-T4', 'level_id' => $level->id]);
    Designation::query()->create(['name' => 'Buyer', 'code' => 'BUY', 'default_reporting_level_id' => $level->id]);

    expect(fn () => $designation->delete())->toThrow(MasterInUseException::class, '“Sourcing Manager” is in use, so it cannot be deleted: 1 employee position (current or past) uses it. Deactivate it instead')
        ->and(Designation::query()->whereKey($designation->id)->exists())->toBeTrue()
        ->and(fn () => $level->delete())->toThrow(MasterInUseException::class, '1 designation (default reporting level) and 1 grade use it')
        ->and(Level::query()->whereKey($level->id)->exists())->toBeTrue();

    // Only counts leave the guard: never the person or the records behind them.
    try {
        $designation->delete();
    } catch (MasterInUseException $e) {
        expect($e->getMessage())->not->toContain((string) $employee->employee_code)->not->toContain((string) $employee->person?->full_name)
            ->and($e->uses)->toHaveCount(1)->and($e->uses[0])->toMatchArray(['table' => 'employee_positions', 'column' => 'designation_id', 'count' => 1]);
    }
});

it('counts a place in the organisation structure as a use, and deletes once the unit is taken out of it', function () {
    $tree = app(OrganisationTree::class);
    $company = Company::query()->orderBy('id')->first() ?? Company::factory()->create();
    $root = OrganisationNode::query()->where('nodeable_type', Company::class)->where('nodeable_id', $company->id)->first() ?? $tree->attach($company);
    $node = $tree->createUnit('department', ['name' => 'Procurement', 'code' => 'PROC'], $root);
    $department = Department::query()->findOrFail($node->nodeable_id);

    expect(fn () => $department->delete())->toThrow(MasterInUseException::class, 'it has a place in the organisation structure')
        ->and(fn () => $department->delete())->toThrow(MasterInUseException::class, 'remove it from the organisation structure in the Organisation Designer');

    $tree->detach($node->refresh(), 'Not needed');
    expect($department->refresh()->delete())->toBeTrue();
});

it('turns Delete on the screen into "Deactivate instead" for a master in use, and deletes an unused one', function () {
    $employee = activeEmployee();
    $designation = Designation::query()->create(['name' => 'Sourcing Manager', 'code' => 'SRC-MGR']);
    app(AssignPositionAction::class)->handle($employee, ['designation_id' => $designation->id], 'transfer', '2026-10-01', 'Moved');

    Livewire::test(EditDesignation::class, ['record' => $designation->getRouteKey()])
        ->mountAction('delete')
        ->assertMountedActionModalSee('In use: ')
        ->assertMountedActionModalSee('1 employee position (current or past) uses it')
        ->assertMountedActionModalSee('Deactivate instead')
        ->callMountedAction()
        ->assertHasNoErrors();

    expect($designation->refresh()->status->value)->toBe('inactive')
        ->and(AuditEvent::query()->where('entity_type', Designation::class)->where('entity_id', (string) $designation->id)->where('reason', 'Deactivated instead of deleted: it is in use.')->exists())->toBeTrue();

    $unused = Level::query()->create(['name' => 'R1 L9', 'code' => 'R1-L9']);
    Livewire::test(EditLevel::class, ['record' => $unused->getRouteKey()])->callAction('delete');
    expect(Level::query()->whereKey($unused->id)->exists())->toBeFalse();
});

it('offers no deactivation to someone who may delete but not edit, and leaves the record untouched', function () {
    // Skills are deleted from their list (a row action), so a delete-only user can reach Delete there.
    $employee = activeEmployee();
    $skill = Skill::query()->create(['name' => 'Negotiation', 'code' => 'NEGO']);
    PersonSkill::query()->create(['person_id' => $employee->person_id, 'skill_id' => $skill->id, 'proficiency' => 'expert']);
    $this->actingAs(tenantUser($this->tenant, ['people_setup.view', 'people_setup.delete']));

    Livewire::test(ManageSkills::class)
        ->mountAction(TestAction::make('delete')->table($skill))
        ->assertMountedActionModalSee('1 person skill uses it')
        ->assertMountedActionModalDontSee('Deactivate instead')
        ->callMountedAction()
        ->assertNotified('Not deleted');

    expect($skill->refresh()->status->value)->toBe('active')->and(PersonSkill::query()->where('skill_id', $skill->id)->count())->toBe(1);
});

it('counts history a delete would silently take with it as a use too', function () {
    $employee = activeEmployee();
    $skill = Skill::query()->create(['name' => 'Negotiation', 'code' => 'NEGO']);
    PersonSkill::query()->create(['person_id' => $employee->person_id, 'skill_id' => $skill->id, 'proficiency' => 'expert']);

    // person_skills would be deleted with the skill (ON DELETE CASCADE): the guard refuses instead.
    expect(fn () => $skill->delete())->toThrow(MasterInUseException::class, '1 person skill uses it')
        ->and(PersonSkill::query()->where('skill_id', $skill->id)->count())->toBe(1);

    // A designation's own employment-type links are its parts: an unused designation still deletes with them.
    $designation = Designation::query()->create(['name' => 'Buyer', 'code' => 'BUY']);
    $designation->employmentTypes()->attach(EmploymentType::query()->create(['name' => 'Permanent R1', 'code' => 'R1-PERM'])->id);
    expect($designation->delete())->toBeTrue();
});

it('deletes a company with only its own legal entity and establishment, and refuses once something uses those parts', function () {
    // Every company gets a primary legal entity and establishment when it is created: they are its parts, not uses.
    $spare = Company::factory()->create(['name' => 'Created by mistake']);
    expect(LegalEntity::query()->where('company_id', $spare->id)->count())->toBe(1)
        ->and(Establishment::query()->where('company_id', $spare->id)->count())->toBe(1)
        ->and($spare->delete())->toBeTrue()
        ->and(LegalEntity::query()->where('company_id', $spare->id)->exists())->toBeFalse()
        ->and(Establishment::query()->where('company_id', $spare->id)->exists())->toBeFalse();

    // What uses a part is a use of the company: deleting it would take that history with it.
    $company = Company::factory()->create(['name' => 'Operating company']);
    $establishment = Establishment::query()->where('company_id', $company->id)->firstOrFail();
    EmployeeEstablishmentAssignment::query()->create(['employee_id' => Employee::factory()->create()->id, 'company_id' => $company->id,
        'establishment_id' => $establishment->id, 'effective_from' => '2026-10-01', 'assignment_reason' => 'Joined the Pune office', 'source' => 'manual']);

    expect(fn () => $company->delete())->toThrow(MasterInUseException::class, '1 employee establishment assignment of its establishments')
        ->and(Establishment::query()->whereKey($establishment->id)->exists())->toBeTrue();
});

it('proposes the deactivation for approval when the organisation governs the record', function () {
    app(FeatureFlags::class)->set('configuration.approval', true);
    app(SettingsRepository::class)->set('configuration.approval.minimum_risk', 'low');
    $employee = activeEmployee();
    $designation = Designation::query()->create(['name' => 'Sourcing Manager', 'code' => 'SRC-MGR']);
    app(AssignPositionAction::class)->handle($employee, ['designation_id' => $designation->id], 'transfer', '2026-10-01', 'Moved');

    Livewire::test(EditDesignation::class, ['record' => $designation->getRouteKey()])
        ->callAction('delete')
        ->assertNotified('Deactivation submitted for approval');

    expect($designation->refresh()->status->value)->toBe('active')
        ->and(ConfigurationChange::query()->where('subject_type', Designation::class)->where('subject_id', $designation->id)->sole()->payload)->toBe(['status' => 'inactive']);
});

it('keeps each tenant to its own records: a refusal in one tenant counts nothing of another', function () {
    $designation = Designation::query()->create(['name' => 'Sourcing Manager', 'code' => 'SRC-MGR']);
    activeEmployee();

    $other = provisionTenant('Other');
    actAsTenant($other);
    $this->actingAs(tenantUser($other, ['*']));
    $theirs = Designation::query()->create(['name' => 'Sourcing Manager', 'code' => 'SRC-MGR']);
    app(AssignPositionAction::class)->handle(activeEmployee(), ['designation_id' => $theirs->id], 'transfer', '2026-10-01', 'Moved');

    actAsTenant($this->tenant);
    $this->actingAs($this->admin);
    expect(app(MasterUsage::class)->of($designation->refresh()))->toBe([])
        ->and($designation->delete())->toBeTrue();
});
