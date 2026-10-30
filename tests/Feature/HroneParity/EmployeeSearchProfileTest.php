<?php

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Models\AuditEvent;
use App\Domain\Bgv\Services\Bgv;
use App\Domain\Employment\Actions\HireEmployeeAction;
use App\Domain\Employment\Actions\RehireEmployeeAction;
use App\Domain\Employment\Actions\TransferEmployeeAction;
use App\Domain\Employment\Models\Employee;
use App\Domain\Employment\Services\EmployeeMessages;
use App\Domain\Exit\Services\Exits;
use App\Domain\Identity\Services\AccessScopes;
use App\Domain\Lifecycle\Enums\LifecycleState;
use App\Domain\Lifecycle\Models\ConfirmationReview;
use App\Domain\Notifications\Models\NotificationDelivery;
use App\Domain\Organisation\Models\BusinessUnit;
use App\Domain\Organisation\Models\Company;
use App\Domain\Organisation\Models\Department;
use App\Domain\Organisation\Models\Designation;
use App\Domain\Organisation\Models\Location;
use App\Domain\People\Models\PersonFamilyMember;
use App\Domain\People\Models\PersonQualification;
use App\Domain\Platform\Services\SettingsRepository;
use App\Filament\Resources\ConfirmationReviews\Pages\ListConfirmationReviews;
use App\Filament\Resources\Employees\Pages\ListEmployees;
use App\Filament\Resources\Employees\Pages\ViewEmployee;
use App\Filament\Resources\Employees\RelationManagers\ActivityRelationManager;
use App\Filament\Resources\ExitCases\Pages\ListExitCases;
use App\Filament\Support\EmployeeFilters;
use App\Http\Controllers\EmployeeProfilePrintController;
use Filament\Facades\Filament;
use Livewire\Livewire;

/*
| HROne parity WO-2 (matrix 23 §23.1 #3, #5–8; §23.2.1 #7–8; §23.2.2 #2, #4, #12, #17–18; §23.3.2 #3; §23.4 #3; §23.6.1 #2):
| one employee search and one set of employee filters for every workforce register, and the Employee 360 extras —
| rehire / reactivate through the lifecycle engine, send e-mail, printable profile, activity from the audit trail.
*/

beforeEach(function () {
    $this->travelTo('2026-10-20 10:00:00');
    $this->tenant = provisionTenant();
    actAsTenant($this->tenant);
    $this->hr = tenantUser($this->tenant, ['*']);
    $this->actingAs($this->hr);
    Filament::getPanel('admin')->boot();
    $this->company = Company::factory()->create();
    $this->delhi = Location::factory()->create(['company_id' => $this->company->id, 'name' => 'Delhi']);
    $this->mumbai = Location::factory()->create(['company_id' => $this->company->id, 'name' => 'Mumbai']);
    $this->analyst = Designation::factory()->create(['name' => 'Data Analyst']);
    $this->engineering = Department::factory()->create(['company_id' => $this->company->id, 'name' => 'Engineering']);
    $this->platform = Department::factory()->create(['company_id' => $this->company->id, 'name' => 'Platform', 'parent_department_id' => $this->engineering->id]);
    $this->bu = BusinessUnit::factory()->create(['name' => 'Retail']);
    $this->asha = app(HireEmployeeAction::class)->handle(['first_name' => 'Asha', 'last_name' => 'Rao'], ['joining_date' => '2024-03-01', 'work_email' => 'asha@acme.test'],
        ['company_id' => $this->company->id, 'location_id' => $this->delhi->id, 'designation_id' => $this->analyst->id, 'department_id' => $this->platform->id, 'business_unit_id' => $this->bu->id]);
    $this->ravi = app(HireEmployeeAction::class)->handle(['first_name' => 'Ravi', 'last_name' => 'Iyer'], ['joining_date' => '2025-06-15', 'work_email' => 'ravi@acme.test'],
        ['company_id' => $this->company->id, 'location_id' => $this->mumbai->id]);
});

it('finds employees by code, name, work e-mail and designation on every register', function () {
    Livewire::test(ListEmployees::class)
        ->searchTable('Data Anal')->assertCanSeeTableRecords([$this->asha])->assertCanNotSeeTableRecords([$this->ravi])
        ->searchTable('ravi@acme')->assertCanSeeTableRecords([$this->ravi])->assertCanNotSeeTableRecords([$this->asha])
        ->searchTable($this->asha->employee_code)->assertCanSeeTableRecords([$this->asha])
        ->searchTable('Iyer')->assertCanSeeTableRecords([$this->ravi]);

    $first = app(Exits::class)->initiate($this->asha, 'resignation', 'Moving');
    $second = app(Exits::class)->initiate($this->ravi, 'resignation', 'Moving');
    Livewire::test(ListExitCases::class)
        ->searchTable('Data Anal')->assertCanSeeTableRecords([$first])->assertCanNotSeeTableRecords([$second])
        ->searchTable($this->ravi->employee_code)->assertCanSeeTableRecords([$second])->assertCanNotSeeTableRecords([$first]);
});

it('filters by a pasted list of codes, with codes to leave out', function () {
    $third = app(HireEmployeeAction::class)->handle(['first_name' => 'Kiran', 'last_name' => 'Das'], ['joining_date' => '2025-01-01'], ['company_id' => $this->company->id]);

    Livewire::test(ListEmployees::class)
        ->filterTable('employee_codes', ['codes' => $this->asha->employee_code.",\n".$this->ravi->employee_code.' NOPE1'])
        ->assertCanSeeTableRecords([$this->asha, $this->ravi])->assertCanNotSeeTableRecords([$third])
        ->filterTable('employee_codes', ['exclude_codes' => $this->asha->employee_code])
        ->assertCanSeeTableRecords([$this->ravi, $third])->assertCanNotSeeTableRecords([$this->asha]);

    config(['peopleos.bulk.pasted_codes_max' => 2]);
    expect(EmployeeFilters::parseCodes('A, B; C D'))->toBe(['A', 'B']);
});

it('filters by the organisation group (department with its sub-departments, business unit) and date ranges', function () {
    Livewire::test(ListEmployees::class)
        ->filterTable('more_organisation', ['department_id' => [$this->engineering->id]])
        ->assertCanSeeTableRecords([$this->asha])->assertCanNotSeeTableRecords([$this->ravi])
        ->resetTableFilters()
        ->filterTable('more_organisation', ['business_unit_id' => [$this->bu->id]])
        ->assertCanSeeTableRecords([$this->asha])->assertCanNotSeeTableRecords([$this->ravi])
        ->resetTableFilters()
        ->filterTable('joined', ['from' => '2025-01-01', 'until' => '2025-12-31'])
        ->assertCanSeeTableRecords([$this->ravi])->assertCanNotSeeTableRecords([$this->asha]);

    $review = ConfirmationReview::create(['employee_id' => $this->asha->id, 'probation_end_date' => '2026-10-25', 'due_on' => '2026-10-15', 'status' => 'pending', 'extension_number' => 0]);
    $other = ConfirmationReview::create(['employee_id' => $this->ravi->id, 'probation_end_date' => '2026-12-25', 'due_on' => '2026-12-15', 'status' => 'pending', 'extension_number' => 0]);
    Livewire::test(ListConfirmationReviews::class)
        ->filterTable('organisation', ['location_id' => [$this->delhi->id]])
        ->assertCanSeeTableRecords([$review])->assertCanNotSeeTableRecords([$other])
        ->resetTableFilters()
        ->filterTable('request_dates', ['from' => '2026-12-01'])
        ->assertCanSeeTableRecords([$other])->assertCanNotSeeTableRecords([$review]);
});

it('leaves out employees with a pending resignation and filters by background check', function () {
    app(Exits::class)->initiate($this->ravi, 'resignation', 'Moving');
    app(Bgv::class)->initiate($this->asha, ['identity'], 'manual', true);

    Livewire::test(ListEmployees::class)
        ->filterTable('exclude_pending_resignation', true)
        ->assertCanSeeTableRecords([$this->asha])->assertCanNotSeeTableRecords([$this->ravi])
        ->resetTableFilters()
        ->filterTable('background_check', 'none')
        ->assertCanSeeTableRecords([$this->ravi])->assertCanNotSeeTableRecords([$this->asha])
        ->filterTable('background_check', 'open')
        ->assertCanSeeTableRecords([$this->asha])->assertCanNotSeeTableRecords([$this->ravi]);
});

it('keeps the filters inside the access scope', function () {
    $delhiHr = tenantUser($this->tenant, ['*']);
    app(AccessScopes::class)->assign($delhiHr, ['location' => [$this->delhi->id]]);
    $this->actingAs($delhiHr);

    Livewire::test(ListEmployees::class)
        ->filterTable('employee_codes', ['codes' => $this->ravi->employee_code])
        ->assertCanNotSeeTableRecords([$this->ravi])
        ->resetTableFilters()
        ->searchTable('ravi@acme')->assertCanNotSeeTableRecords([$this->ravi]);
});

it('rehires an exited employee through the lifecycle engine, keeping the same employee and history', function () {
    $case = app(Exits::class)->initiate($this->ravi, 'resignation', 'Moving', '2026-09-01', '2026-09-30');
    forceLifecycle($this->ravi, LifecycleState::Exited, ['exit_date' => '2026-09-30']);
    $case->update(['status' => 'completed']);
    $transitions = $this->ravi->lifecycleTransitions()->count();

    Livewire::test(ViewEmployee::class, ['record' => $this->ravi->getRouteKey()])
        ->assertActionVisible('rehire')
        ->callAction('rehire', ['rejoin_date' => '2026-11-01', 'location_id' => $this->delhi->id, 'audit_reason' => 'Rejoining the Delhi team'])
        ->assertHasNoActionErrors();

    $ravi = $this->ravi->refresh();
    expect($ravi->lifecycle_state)->toBe(LifecycleState::Active)
        ->and($ravi->joining_date->toDateString())->toBe('2026-11-01')
        ->and($ravi->exit_date)->toBeNull()
        ->and(Employee::query()->where('person_id', $ravi->person_id)->count())->toBe(1)
        ->and($ravi->lifecycleTransitions()->count())->toBe($transitions + 1)
        ->and($ravi->lifecycleTransitions()->latest('id')->first()->metadata)->toMatchArray(['rehire' => true])
        ->and($ravi->positions()->effectiveOn('2026-11-02')->first()->location_id)->toBe($this->delhi->id);

    // Not offered to current employees; the action still refuses them.
    Livewire::test(ViewEmployee::class, ['record' => $this->asha->getRouteKey()])->assertActionHidden('rehire');
    expect(fn () => app(RehireEmployeeAction::class)->handle($this->asha, [], '2026-11-01'))->toThrow(InvalidArgumentException::class, 'Only an alumni or an exited employee');

    // Without the lifecycle and position permissions the action is not available.
    forceLifecycle($this->asha, LifecycleState::Exited, ['exit_date' => '2026-09-30']);
    $this->actingAs(tenantUser($this->tenant, ['employee.view']));
    Livewire::test(ViewEmployee::class, ['record' => $this->asha->getRouteKey()])->assertActionHidden('rehire');
});

it('e-mails an employee from the 360 and audits it without the body', function () {
    Livewire::test(ViewEmployee::class, ['record' => $this->asha->getRouteKey()])
        ->callAction('sendEmail', ['subject' => 'Your ID card', 'body' => 'Collect it from reception', 'to' => ['work'], 'copy_to_me' => true])
        ->assertHasNoActionErrors();

    expect(NotificationDelivery::query()->where('event', EmployeeMessages::EVENT)->pluck('recipient')->all())->toEqualCanonicalizing(['asha@acme.test', $this->hr->email]);
    $event = AuditEvent::query()->withoutGlobalScopes()->where('action', AuditAction::EmployeeEmailed->value)->sole();
    expect($event->entity_id)->toBe((string) $this->asha->id)->and($event->metadata['to'])->toBe(['work'])
        ->and(json_encode($event->metadata))->not->toContain('reception');

    $writer = tenantUser($this->tenant, ['employee.view', 'employee.message']);
    expect(fn () => app(EmployeeMessages::class)->email($this->asha, $writer, 'Hi', 'Body', ['personal']))->toThrow(RuntimeException::class, 'sensitive personal data');
    $noPermission = tenantUser($this->tenant, ['employee.view']);
    expect(fn () => app(EmployeeMessages::class)->email($this->asha, $noPermission, 'Hi', 'Body'))->toThrow(RuntimeException::class, 'cannot e-mail');
    $this->actingAs($noPermission);
    Livewire::test(ViewEmployee::class, ['record' => $this->asha->getRouteKey()])->assertActionHidden('sendEmail');
});

it('prints the profile behind a signed link, with sensitive sections only for those who may see them', function () {
    $this->asha->person->update(['personal_email' => 'asha.home@example.test', 'date_of_birth' => '1990-04-02']);

    $this->get(EmployeeProfilePrintController::url($this->asha))->assertOk()
        ->assertSee('Asha Rao')->assertSee('Data Analyst')->assertSee('asha.home@example.test');
    expect(AuditEvent::query()->withoutGlobalScopes()->where('action', AuditAction::Export->value)->where('entity_id', (string) $this->asha->id)->where('metadata->document', 'employee_profile')->exists())->toBeTrue();

    $viewer = tenantUser($this->tenant, ['employee.view']);
    $this->actingAs($viewer);
    $this->get(EmployeeProfilePrintController::url($this->asha))->assertOk()->assertDontSee('asha.home@example.test')->assertSee('need access to sensitive personal data');

    // The employee's own record (employee.self) is not an HR printout.
    $self = tenantUser($this->tenant, ['employee.self']);
    $this->asha->forceFill(['user_id' => $self->id])->save();
    $this->actingAs($self);
    $this->get(EmployeeProfilePrintController::url($this->asha))->assertForbidden();
    $this->actingAs($viewer);

    // Unsigned, outside the scope, or without view: refused.
    $this->get(route('employees.profile-print', ['employee' => $this->asha->id]))->assertForbidden();
    $mumbaiHr = tenantUser($this->tenant, ['employee.view']);
    app(AccessScopes::class)->assign($mumbaiHr, ['location' => [$this->mumbai->id]]);
    $this->actingAs($mumbaiHr);
    $this->get(EmployeeProfilePrintController::url($this->asha))->assertStatus(404);
});

it('leaves out of the printout the profile sections the profile access matrix hides from the viewer (CA-6)', function () {
    PersonFamilyMember::create(['person_id' => $this->asha->person_id, 'name' => 'Meera Rao', 'relation' => 'mother']);
    PersonQualification::create(['person_id' => $this->asha->person_id, 'qualification' => 'B.Sc Statistics', 'year_of_completion' => 2016]);
    app(SettingsRepository::class)->set('profile.section_visibility', ['family' => ['employee' => true, 'manager' => true, 'others' => false], 'professional' => ['employee' => true, 'manager' => true, 'others' => false]], 'test');

    // HR who maintains employee records is never governed by the matrix.
    $this->get(EmployeeProfilePrintController::url($this->asha))->assertOk()->assertSee('Meera Rao')->assertSee('B.Sc Statistics');

    $this->actingAs(tenantUser($this->tenant, ['employee.view']));
    $this->get(EmployeeProfilePrintController::url($this->asha))->assertOk()
        ->assertDontSee('Meera Rao')->assertDontSee('B.Sc Statistics')->assertDontSee('Qualifications and experience')->assertSee('Addresses');
});

it('shows the employee\'s activity across modules from the audit trail, to audit viewers only', function () {
    app(TransferEmployeeAction::class)->handle($this->asha, ['location_id' => $this->mumbai->id], '2026-11-01', reason: 'Moved to Mumbai');

    Livewire::test(ActivityRelationManager::class, ['ownerRecord' => $this->asha, 'pageClass' => ViewEmployee::class])
        ->assertOk()->assertSee('Moved to Mumbai')->assertDontSee('Ravi Iyer');

    expect(ActivityRelationManager::canViewForRecord($this->asha, ViewEmployee::class))->toBeTrue();
    $this->actingAs(tenantUser($this->tenant, ['employee.view']));
    expect(ActivityRelationManager::canViewForRecord($this->asha, ViewEmployee::class))->toBeFalse();
});
