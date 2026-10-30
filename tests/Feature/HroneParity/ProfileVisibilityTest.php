<?php

use App\Domain\Attendance\Models\AttendancePunch;
use App\Domain\Configuration\Services\SetupCatalogue;
use App\Domain\Documents\Models\DocumentType;
use App\Domain\Documents\Services\Documents;
use App\Domain\Employment\Models\Employee;
use App\Domain\Experience\Services\DirectorySections;
use App\Domain\Experience\Services\Employee360;
use App\Domain\Experience\Services\PersonWorkspace;
use App\Domain\Experience\Services\ProfileSectionVisibility;
use App\Domain\Leave\Models\LeaveRequest;
use App\Domain\Leave\Models\LeaveType;
use App\Domain\Lifecycle\Enums\LifecycleState;
use App\Domain\People\Models\PersonExperience;
use App\Domain\People\Models\PersonQualification;
use App\Domain\Performance\Services\Goals;
use App\Domain\Platform\Services\SettingsRepository;
use App\Filament\Pages\MyHr;
use App\Filament\Pages\Preferences;
use App\Filament\Pages\SetupCentre;
use App\Filament\Resources\Employees\Pages\ViewEmployee;
use App\Filament\Resources\Employees\RelationManagers\AddressesRelationManager;
use App\Filament\Resources\Employees\RelationManagers\BankAccountsRelationManager;
use App\Filament\Resources\Employees\RelationManagers\DocumentsRelationManager;
use App\Filament\Resources\Employees\RelationManagers\ExperiencesRelationManager;
use App\Filament\Resources\Employees\RelationManagers\FamilyMembersRelationManager;
use App\Filament\Resources\Employees\RelationManagers\SkillsRelationManager;
use App\Filament\Resources\Employees\RelationManagers\TimelineRelationManager;
use App\Livewire\Experience\DrawerHost;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

require_once __DIR__.'/../Workflow/WorkflowTestHelpers.php';

/*
| HROne setup parity CA-6 — Core HR → Employee → Profile access (profile.section_visibility: sections × employee / manager /
| others, an extra AND that can only hide) and Social profile access (directory.sections_visible,
| directory.sections_employee_may_hide, people.directory_hidden).
*/

beforeEach(function () {
    Storage::fake('local');
    config(['peopleos.documents.disk' => 'local']);
    $this->travelTo('2026-10-05 10:00:00');
    $this->tenant = provisionTenant();
    actAsTenant($this->tenant);
    $this->hr = tenantUser($this->tenant, ['*']);
    $this->actingAs($this->hr);
    // A manager (manager-like rights), their report (an employee), a colleague of the report, and an outsider who may open
    // every profile (employee.view) without maintaining records.
    $this->manager = forceLifecycle(employeeWithUser(null, ['employee.view', 'performance.team', 'document.own', 'employee.self']), LifecycleState::Active);
    $this->employee = forceLifecycle(employeeWithUser($this->manager, ['employee.self', 'document.own', 'skills.self', 'performance.goals', 'attendance.regularise', 'leave.apply']), LifecycleState::Active);
    $this->colleague = forceLifecycle(employeeWithUser($this->manager, ['employee.self', 'document.own']), LifecycleState::Active);
    $this->outsider = tenantUser($this->tenant, ['employee.view', 'document.view', 'skills.view']);
});

function ca6Matrix(array $cells): void
{
    $matrix = config('peopleos.settings')['profile.section_visibility'];
    foreach ($cells as $section => $columns) {
        $matrix[$section] = array_merge($matrix[$section], $columns);
    }
    app(SettingsRepository::class)->set('profile.section_visibility', $matrix, 'test');
}

function ca6Can(string $manager, $user, Employee $employee): bool
{
    test()->actingAs($user);

    return $manager::canViewForRecord($employee, ViewEmployee::class);
}

it('keeps today\'s behaviour by default: every box ticked, no directory sections, nothing hideable', function () {
    $defaults = config('peopleos.settings');
    expect(array_keys($defaults['profile.section_visibility']))->toBe(array_keys(config('peopleos.profile.sections')))
        ->and(collect($defaults['profile.section_visibility'])->flatten()->unique()->all())->toBe([true])
        ->and($defaults['directory.sections_visible'])->toBe([])
        ->and($defaults['directory.sections_employee_may_hide'])->toBe([]);

    $matrix = app(ProfileSectionVisibility::class);
    foreach (ProfileSectionVisibility::AUDIENCES as $audience) {
        expect($matrix->visibleTo($audience))->toBe(array_keys(config('peopleos.profile.sections')));
    }
    // The 360 tabs each audience saw before are still there (permissions decide, as before).
    expect(ca6Can(FamilyMembersRelationManager::class, $this->employee->user, $this->employee))->toBeTrue()
        ->and(ca6Can(FamilyMembersRelationManager::class, $this->manager->user, $this->employee))->toBeTrue()
        ->and(ca6Can(TimelineRelationManager::class, $this->outsider, $this->employee))->toBeTrue()
        ->and(ca6Can(DocumentsRelationManager::class, $this->outsider, $this->employee))->toBeTrue()
        // ... and what they could not see stays hidden: the matrix never grants.
        ->and(ca6Can(BankAccountsRelationManager::class, $this->manager->user, $this->employee))->toBeFalse()
        ->and(ca6Can(DocumentsRelationManager::class, $this->employee->user, $this->employee))->toBeFalse();

    // No directory section is computed and the person sheet carries none.
    $this->actingAs($this->outsider);
    expect(app(DirectorySections::class)->for($this->outsider, $this->employee))->toBe([])
        ->and(Livewire::test(DrawerHost::class)->call('show', 'person', (string) $this->employee->id)->instance()->person()['sections'])->toBe([]);
    $this->actingAs($this->employee->user);
    expect(Livewire::test(Preferences::class)->instance()->directoryCard)->toBe([]);
});

it('lists the three settings in Setup → Core HR and validates the matrix', function () {
    $settings = collect(config('peopleos_setup.modules.core.settings'))->keyBy('key');
    expect($settings->get('profile.section_visibility')['type'])->toBe('matrix')
        ->and($settings->get('directory.sections_visible')['options_from'])->toBe('directory_sections')
        ->and(app(SetupCatalogue::class)->optionsFor($settings->get('directory.sections_employee_may_hide')))->toBe(config('peopleos.directory.sections'));

    $field = fn (string $key) => SetupCatalogue::field($key);
    $form = SetupCatalogue::matrixToForm($settings->get('profile.section_visibility'), config('peopleos.settings')['profile.section_visibility']);
    $form['family'] = ['manager'];
    expect(app(SetupCatalogue::class)->save('core', [$field('profile.section_visibility') => $form, $field('directory.sections_visible') => ['experience']], 'Profile access', $this->hr))
        ->toBe(['profile.section_visibility', 'directory.sections_visible']);
    expect(app(SettingsRepository::class)->get('profile.section_visibility')['family'])->toBe(['employee' => false, 'manager' => true, 'others' => false])
        ->and(fn () => app(SetupCatalogue::class)->save('core', [$field('profile.section_visibility') => ['family' => ['hr']]], 'x', $this->hr))->toThrow(RuntimeException::class, 'column that does not exist')
        ->and(fn () => app(SetupCatalogue::class)->save('core', [$field('directory.sections_visible') => ['salary']], 'x', $this->hr))->toThrow(RuntimeException::class, 'option that does not exist');

    Livewire::test(SetupCentre::class)->call('selectModule', 'core')->assertOk()->assertSee('Profile sections each audience sees')->assertSee('Sections shown on a person');
});

it('hides each section from the audiences the matrix unticks, and never from people who maintain records', function () {
    ca6Matrix([
        'family' => ['employee' => false, 'others' => false],
        'professional' => ['manager' => false],
        'history' => ['others' => false],
        'documents' => ['others' => false, 'employee' => false],
        'skills' => ['employee' => false],
    ]);

    // Family: hidden from the employee themself and from others, shown to their manager and to HR.
    expect(ca6Can(FamilyMembersRelationManager::class, $this->employee->user, $this->employee))->toBeFalse()
        ->and(ca6Can(FamilyMembersRelationManager::class, $this->manager->user, $this->employee))->toBeTrue()
        ->and(ca6Can(FamilyMembersRelationManager::class, $this->outsider, $this->employee))->toBeFalse()
        ->and(ca6Can(FamilyMembersRelationManager::class, $this->hr, $this->employee))->toBeTrue()
        // Professional: hidden from the manager only.
        ->and(ca6Can(ExperiencesRelationManager::class, $this->manager->user, $this->employee))->toBeFalse()
        ->and(ca6Can(ExperiencesRelationManager::class, $this->employee->user, $this->employee))->toBeTrue()
        // A manager viewing someone outside their team is "others".
        ->and(app(ProfileSectionVisibility::class)->audience($this->manager->user, $this->manager))->toBe('employee')
        ->and(app(ProfileSectionVisibility::class)->audience($this->employee->user, $this->colleague))->toBe('others')
        ->and(app(ProfileSectionVisibility::class)->audience($this->hr, $this->employee))->toBeNull()
        // History and documents for others.
        ->and(ca6Can(TimelineRelationManager::class, $this->outsider, $this->employee))->toBeFalse()
        ->and(ca6Can(TimelineRelationManager::class, $this->manager->user, $this->employee))->toBeTrue()
        ->and(ca6Can(DocumentsRelationManager::class, $this->outsider, $this->employee))->toBeFalse()
        ->and(ca6Can(DocumentsRelationManager::class, $this->hr, $this->employee))->toBeTrue()
        // Personal stays (not unticked).
        ->and(ca6Can(AddressesRelationManager::class, $this->outsider, $this->employee))->toBeTrue();

    // The 360 summaries and the workspace follow: no documents summary for others, no skills for the employee, no recent
    // changes for others.
    $keys = fn ($user) => collect(app(Employee360::class)->for($user, $this->employee))->pluck('key')->all();
    $this->actingAs($this->outsider);
    expect($keys($this->outsider))->not->toContain('documents')
        ->and(app(PersonWorkspace::class)->for($this->outsider, $this->employee)['changes'])->toBe([]);
    $this->actingAs($this->employee->user);
    expect($keys($this->employee->user))->not->toContain('skills');
    $this->actingAs($this->hr);
    expect($keys($this->hr))->toContain('documents', 'skills');
    expect(ca6Can(SkillsRelationManager::class, $this->employee->user, $this->employee))->toBeFalse();

    // My HR documents (the employee audience): hidden with the documents row.
    $pan = DocumentType::query()->where('code', 'PAN')->firstOrFail();
    app(Documents::class)->store($this->employee, UploadedFile::fake()->create('pan.pdf', 10, 'application/pdf'), $pan);
    $this->actingAs($this->employee->user);
    expect((new MyHr)->getDocuments())->toHaveCount(0);
    ca6Matrix(['documents' => ['employee' => true]]);
    expect((new MyHr)->getDocuments())->toHaveCount(1);
});

it('fails closed on an unknown section, audience or viewer', function () {
    $matrix = app(ProfileSectionVisibility::class);
    expect($matrix->allows($this->employee->user, $this->employee, 'health_insurance'))->toBeFalse()
        ->and($matrix->allows(null, $this->employee, 'family'))->toBeFalse()
        ->and($matrix->allowsAudience('hr', 'family'))->toBeFalse()
        ->and($matrix->allowsAudience('employee', 'salary'))->toBeFalse();

    // A stored matrix missing a row (a section added later) takes the platform default for it.
    app(SettingsRepository::class)->set('profile.section_visibility', ['family' => ['employee' => false, 'manager' => true, 'others' => true]], 'test');
    expect($matrix->allowsAudience('employee', 'family'))->toBeFalse()
        ->and($matrix->allowsAudience('employee', 'bank'))->toBeTrue();
});

it('shows the chosen directory sections on a person\'s card, read through their domains', function () {
    $s = app(SettingsRepository::class);
    $s->set('directory.sections_visible', ['experience', 'education', 'planned_leaves', 'last_checkin', 'goals', 'off_days'], 'test');
    PersonExperience::create(['person_id' => $this->employee->person_id, 'employer' => 'Globex', 'designation' => 'Analyst', 'from_date' => '2019-01-01', 'to_date' => '2022-06-30']);
    PersonQualification::create(['person_id' => $this->employee->person_id, 'qualification' => 'B.Com', 'institution' => 'Delhi University', 'year_of_completion' => 2018]);
    $type = LeaveType::query()->orderBy('id')->firstOrFail();
    LeaveRequest::create(['employee_id' => $this->employee->id, 'leave_type_id' => $type->id, 'from_date' => '2026-10-12', 'to_date' => '2026-10-13', 'days' => 2,
        'dates' => [['date' => '2026-10-12', 'days' => 1, 'session' => 'full'], ['date' => '2026-10-13', 'days' => 1, 'session' => 'full']], 'reason' => 'Family wedding', 'status' => 'approved']);
    AttendancePunch::create(['employee_id' => $this->employee->id, 'punched_at' => '2026-10-05 09:12:00', 'direction' => 'in', 'source' => 'web', 'processing_status' => 'processed']);
    app(Goals::class)->create(['employee_id' => $this->employee->id, 'title' => 'Close the books by day 3', 'weight' => 100]);

    // Someone who may find the employee in the directory but not open their goals.
    $this->actingAs($this->outsider);
    $sections = collect(app(DirectorySections::class)->for($this->outsider, $this->employee))->keyBy('key');
    expect($sections->keys()->all())->toBe(['experience', 'education', 'planned_leaves', 'last_checkin'])
        ->and($sections['experience']['items'][0]['title'])->toBe('Analyst · Globex')
        ->and($sections['education']['items'][0]['title'])->toBe('B.Com')
        ->and($sections['planned_leaves']['items'][0])->toBe(['title' => 'Away', 'detail' => '12 Oct – 13 Oct'])
        ->and($sections['last_checkin']['items'][0]['title'])->toBe('Mon 5 Oct, 09:12')
        // Never the leave type or reason; goals only for those who may already open them.
        ->and(json_encode($sections->all()))->not->toContain($type->name)
        ->and(json_encode($sections->all()))->not->toContain('Family wedding');

    $this->actingAs($this->manager->user);
    expect(collect(app(DirectorySections::class)->for($this->manager->user, $this->employee))->pluck('key')->all())->toContain('goals');

    // The person sheet (directory preview, command search "Preview") renders them.
    $this->actingAs($this->outsider);
    $sheet = Livewire::test(DrawerHost::class)->call('show', 'person', (string) $this->employee->id);
    expect(collect($sheet->instance()->person()['sections'])->pluck('key')->all())->toBe(['experience', 'education', 'planned_leaves', 'last_checkin']);
    $sheet->assertSee('Globex')->assertSee('Work experience');
});

it('lets a person hide only the sections the organisation allows, from colleagues only', function () {
    $s = app(SettingsRepository::class);
    $s->set('directory.sections_visible', ['experience', 'education'], 'test');
    $s->set('directory.sections_employee_may_hide', ['experience', 'skills'], 'test');
    PersonExperience::create(['person_id' => $this->employee->person_id, 'employer' => 'Globex', 'designation' => 'Analyst', 'from_date' => '2019-01-01']);
    PersonQualification::create(['person_id' => $this->employee->person_id, 'qualification' => 'B.Com', 'year_of_completion' => 2018]);

    $this->actingAs($this->employee->user);
    $page = Livewire::test(Preferences::class)->assertSee('Directory card');
    // Skills may be hidden but is not shown on cards, so it is not offered; education is shown but may not be hidden.
    expect(array_keys($page->instance()->directoryCard))->toBe(['experience']);
    $page->call('toggleDirectorySection', 'education')->call('toggleDirectorySection', 'experience');
    expect($this->employee->person->refresh()->directory_hidden)->toBe(['experience']);

    $colleagueView = fn () => collect(app(DirectorySections::class)->for($this->outsider, $this->employee->refresh()))->pluck('key')->all();
    $this->actingAs($this->outsider);
    expect($colleagueView())->toBe(['education']);
    // The person still sees their own card in full.
    $this->actingAs($this->employee->user);
    expect(collect(app(DirectorySections::class)->for($this->employee->user, $this->employee))->pluck('key')->all())->toBe(['experience', 'education']);

    // A stored choice for a section the organisation does not let people hide is ignored.
    $this->employee->person->forceFill(['directory_hidden' => ['experience', 'education']])->save();
    $this->actingAs($this->outsider);
    expect($colleagueView())->toBe(['education']);
    // Withdrawing the permission to hide shows the section again.
    $s->set('directory.sections_employee_may_hide', [], 'test');
    expect($colleagueView())->toBe(['experience', 'education']);

    // Showing it again from Preferences (the stored list keeps only sections that may be hidden).
    $s->set('directory.sections_employee_may_hide', ['experience'], 'test');
    $this->actingAs($this->employee->user);
    Livewire::test(Preferences::class)->call('toggleDirectorySection', 'experience');
    expect($this->employee->person->refresh()->directory_hidden)->toBeNull();
});

it('shows directory sections only for people the viewer may already find, and nothing outside a tenant', function () {
    app(SettingsRepository::class)->set('directory.sections_visible', ['experience'], 'test');
    PersonExperience::create(['person_id' => $this->manager->person_id, 'employer' => 'Initech', 'designation' => 'Lead', 'from_date' => '2015-01-01']);
    $stranger = forceLifecycle(employeeWithUser(null, ['employee.self']), LifecycleState::Active);

    // The employee's circle has their manager, not an unrelated employee.
    $this->actingAs($this->employee->user);
    expect(app(DirectorySections::class)->for($this->employee->user, $this->manager))->not->toBe([])
        ->and(app(DirectorySections::class)->for($this->employee->user, $stranger))->toBe([]);

    // Another tenant's person is never found.
    $other = provisionTenant('Other');
    actAsTenant($other);
    $foreignUser = tenantUser($other, ['employee.view']);
    $this->actingAs($foreignUser);
    expect(app(DirectorySections::class)->for($foreignUser, $this->manager))->toBe([]);
});
