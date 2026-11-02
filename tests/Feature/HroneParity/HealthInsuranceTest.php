<?php

use App\Domain\Analytics\Datasets\InsuranceMembersDataset;
use App\Domain\Analytics\Services\DatasetRegistry;
use App\Domain\Analytics\Services\ReportLibrary;
use App\Domain\Analytics\Services\ReportRunner;
use App\Domain\Audit\Models\AuditEvent;
use App\Domain\Employment\Events\EmploymentEvent;
use App\Domain\Employment\Exceptions\ProfileChangeRefused;
use App\Domain\Employment\Imports\EmployeeImport;
use App\Domain\Employment\Imports\ImportTypes;
use App\Domain\Employment\Imports\RowImportTypes;
use App\Domain\Employment\Models\Employee;
use App\Domain\Employment\Support\ChangeOrigin;
use App\Domain\Experience\Services\MyProfileSections;
use App\Domain\Identity\Models\UserAccessScope;
use App\Domain\Lifecycle\Enums\LifecycleState;
use App\Domain\Lifecycle\Events\EmployeeReminderDue;
use App\Domain\Offboarding\Support\DataInventory;
use App\Domain\Organisation\Models\Department;
use App\Domain\People\Actions\ChangeFamilyMemberAction;
use App\Domain\People\Imports\InsuranceImports;
use App\Domain\People\Models\InsurancePolicy;
use App\Domain\People\Models\InsuredMember;
use App\Domain\People\Models\PersonFamilyMember;
use App\Domain\People\Services\InsuranceRecords;
use App\Domain\People\Services\ProfileChanges;
use App\Domain\Platform\Services\SettingsRepository;
use App\Filament\Pages\MyProfile;
use App\Filament\Resources\Employees\Pages\EditEmployee;
use App\Filament\Resources\Employees\Pages\ViewEmployee;
use App\Filament\Resources\Employees\RelationManagers\InsuranceRelationManager;
use App\Filament\Resources\WorkforceUploads\WorkforceUploadResource;
use App\Http\Controllers\ParityFileController;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

require_once __DIR__.'/../Workflow/WorkflowTestHelpers.php';

/*
| HROne parity MS-5 — health insurance: policies (insurer, policy number, sum assured, validity, e-card) and the people
| each covers (the employee or a family member of the same person; member id encrypted, masked, reveal audited), the
| Employee 360 tab, the insurance upload (new / existing), My profile (PRV-1: member ids hidden by default), the MS-3
| section `health_insurance` (off by default), expiry reminders and the `insurance_members` dataset. Defaults reproduce
| today's behaviour.
*/

beforeEach(function () {
    Storage::fake('local');
    config(['peopleos.documents.disk' => 'local']);
    $this->travelTo('2026-10-05 10:00:00');
    $this->tenant = provisionTenant();
    actAsTenant($this->tenant);
    $this->hr = tenantUser($this->tenant, ['*']);
    $this->actingAs($this->hr);
    $this->employee = forceLifecycle(employeeWithUser(null, ['employee.self', 'document.own', 'task.view']), LifecycleState::Active, ['joining_date' => '2020-04-01']);
    $this->me = $this->employee->user;
});

function ms5Member(Employee $employee, string $name, string $relation = 'spouse', ?string $dob = '1990-05-01'): PersonFamilyMember
{
    return app(ChangeFamilyMemberAction::class)->add($employee, ['name' => $name, 'relation' => $relation, 'date_of_birth' => $dob, 'gender' => 'female'], test()->hr);
}

/** @param  list<array<string, mixed>>  $members */
function ms5Policy(Employee $employee, array $members = [], array $data = []): InsurancePolicy
{
    return app(InsuranceRecords::class)->save($employee, null, $data + ['insurer_name' => 'Star Health', 'insurer_code' => 'star', 'policy_number' => 'ghi/2026/001',
        'sum_assured' => 500000, 'issued_on' => '2026-04-01', 'valid_until' => '2027-03-31'], $members, test()->hr, 'Group policy enrolment');
}

function ms5Allow(bool $approval = true): void
{
    $matrix = (array) app(SettingsRepository::class)->get('profile.self_edit.sections', config('peopleos.settings')['profile.self_edit.sections']);
    $matrix['health_insurance'] = ['editable' => true, 'needs_approval' => $approval];
    app(SettingsRepository::class)->set('profile.self_edit.sections', $matrix, 'test');
}

/** Stage, map (suggested columns) and validate an insurance upload in a mode. */
function ms5Upload(string $csv, string $mode): EmployeeImport
{
    $path = 'tenants/'.test()->tenant->id.'/imports/'.uniqid().'.csv';
    Storage::disk('local')->put($path, $csv);
    $service = app(InsuranceImports::class);
    $import = $service->inspect($service->register($path, 'insurance.csv', auth()->user(), null, ['mode' => $mode]));

    return $service->validate($service->map($import, $import->mapping, ['mode' => $mode]));
}

it('keeps today\'s behaviour by default', function () {
    // Nothing editable, the section ticked for every audience, no policy, no new tab on My profile, no new line in the sweep.
    expect(config('peopleos.settings')['profile.self_edit.sections']['health_insurance'])->toBe(['editable' => false, 'needs_approval' => true])
        ->and(config('peopleos.settings')['profile.section_visibility']['health_insurance'])->toBe(['employee' => true, 'manager' => true, 'others' => true])
        ->and(app(ProfileChanges::class)->editableSections($this->me, $this->employee))->toBe([])
        ->and(InsurancePolicy::query()->count())->toBe(0)->and(InsuredMember::query()->count())->toBe(0);
    $this->actingAs($this->me);
    expect(array_keys(Livewire::test(MyProfile::class)->instance()->sections()))->toBe(['snapshot', 'personal', 'family', 'professional', 'skills', 'bank', 'documents', 'previous_employer', 'other_details', 'history'])
        ->and(fn () => app(ProfileChanges::class)->submit($this->employee->fresh(), $this->me, 'health_insurance', ['insurer_name' => 'X', 'policy_number' => 'P1'], 'Mine'))
        ->toThrow(ProfileChangeRefused::class, 'cannot be changed here');
    $this->actingAs($this->hr);
    $this->artisan('peopleos:lifecycle:reminders', ['--tenant' => $this->tenant->slug, '--force' => true])
        ->doesntExpectOutputToContain('insurance')->assertSuccessful();

    // The 360 tab is new and empty; nothing else on the profile changed.
    Livewire::test(InsuranceRelationManager::class, ['ownerRecord' => $this->employee, 'pageClass' => ViewEmployee::class])->assertOk()->assertSee('Health insurance');
});

it('stores member ids encrypted at rest, masks them in lists and the audit trail, and audits every reveal', function () {
    $spouse = ms5Member($this->employee, 'Kiran Rao');
    $policy = ms5Policy($this->employee, [['family_member_id' => 'self', 'member_id' => 'star 100245'], ['family_member_id' => $spouse->id, 'member_id' => 'STAR-7788', 'is_parents_cover' => false]]);
    $self = InsuredMember::query()->where('member_key', 'self')->sole();

    $raw = (string) DB::table('insured_members')->where('id', $self->id)->value('member_id');
    expect($raw)->not->toContain('STAR100245')->not->toContain('100245')
        ->and($self->member_id)->toBe('STAR100245')->and($self->member_id_last4)->toBe('0245')->and($self->maskedMemberId())->toBe('••••0245')
        ->and($self->toArray())->not->toHaveKey('member_id')
        ->and($self->name)->toBe($this->employee->person->full_name)->and($self->relation)->toBe('self')
        ->and($policy->policy_number)->toBe('GHI/2026/001')->and($policy->insurer_code)->toBe('STAR')
        ->and(InsuredMember::query()->where('member_key', 'family:'.$spouse->id)->sole()->only(['name', 'relation', 'gender']))->toBe(['name' => 'Kiran Rao', 'relation' => 'spouse', 'gender' => 'female'])
        ->and(config('peopleos.data_classification.highly_sensitive')[InsuredMember::class])->toBe(['member_id']);

    $event = AuditEvent::query()->where('entity_type', InsuredMember::class)->where('entity_id', (string) $self->id)->where('action', 'CREATE')->with('fieldChanges')->sole();
    $change = $event->fieldChanges->firstWhere('field', 'member_id');
    expect((bool) $change->is_sensitive)->toBeTrue()->and((string) $change->after)->not->toContain('100245')
        ->and($event->fieldChanges->pluck('field')->all())->not->toContain('member_id_last4');

    // The 360 tab: opening it is audited, member ids masked; "Reveal" needs a purpose and is audited again.
    $tab = Livewire::test(InsuranceRelationManager::class, ['ownerRecord' => $this->employee, 'pageClass' => ViewEmployee::class])
        ->assertOk()->assertSee('••••0245')->assertSee('••••7788')->assertDontSee('STAR100245')->assertSee('GHI/2026/001');
    $tab->callAction(TestAction::make('reveal')->table($policy), ['member' => $self->id, 'purpose' => 'Claim intimation'])->assertNotified();
    $views = AuditEvent::query()->where('action', 'VIEW')->get()->map(fn (AuditEvent $e) => $e->metadata['scope'] ?? null)->all();
    expect($views)->toContain('health_insurance')->toContain('insured_member:'.$self->id);

    // An update with a blank member id keeps it; the HR form's rows replace the members (a row left out is removed).
    app(InsuranceRecords::class)->save($this->employee, $policy, ['sum_assured' => 750000], [['record_id' => $self->id, 'family_member_id' => 'self', 'member_id' => '']], $this->hr, 'Top-up', removeOthers: true);
    expect($self->fresh()->member_id)->toBe('STAR100245')->and((float) $policy->fresh()->sum_assured)->toBe(750000.0)
        ->and(InsuredMember::query()->count())->toBe(1)->and(DataInventory::TABLES['people'])->toContain('employee_insurance_policies')->toContain('insured_members');
});

it('covers only the employee and their own family members, once per policy, with consistent dates', function () {
    $spouse = ms5Member($this->employee, 'Kiran Rao');
    $stranger = ms5Member(forceLifecycle(employeeWithUser(), LifecycleState::Active), 'Not Family');
    $records = app(InsuranceRecords::class);

    expect(fn () => ms5Policy($this->employee, [['family_member_id' => $stranger->id]]))->toThrow(ProfileChangeRefused::class, 'own family members')
        ->and(fn () => ms5Policy($this->employee, [['family_member_id' => $spouse->id], ['family_member_id' => $spouse->id]]))->toThrow(ProfileChangeRefused::class, 'appears twice')
        ->and(fn () => ms5Policy($this->employee, [['family_member_id' => 'self', 'is_parents_cover' => true]]))->toThrow(ProfileChangeRefused::class, 'parents’ cover is for family members')
        ->and(fn () => ms5Policy($this->employee, [], ['issued_on' => '2026-04-01', 'valid_until' => '2026-03-01']))->toThrow(ProfileChangeRefused::class, 'before it was issued')
        ->and(InsurancePolicy::query()->count())->toBe(0);

    $policy = ms5Policy($this->employee, [['family_member_id' => $spouse->id, 'date_of_birth' => '1991-01-01']]);
    expect(InsuredMember::query()->sole()->date_of_birth->toDateString())->toBe('1991-01-01') // given wins over the profile
        ->and(fn () => ms5Policy($this->employee))->toThrow(ProfileChangeRefused::class, 'already on file')
        ->and(fn () => $records->save($this->employee, $policy, [], [['family_member_id' => $spouse->id]], $this->hr))->toThrow(ProfileChangeRefused::class, 'already on this policy')
        // A family member a policy covers cannot be removed first; another employee's policy is never changed through this one.
        ->and(fn () => app(ChangeFamilyMemberAction::class)->remove($this->employee, $spouse->fresh(), $this->hr))->toThrow(ProfileChangeRefused::class, 'covered by a health insurance policy')
        ->and(fn () => $records->save(forceLifecycle(employeeWithUser(), LifecycleState::Active), $policy, [], [], $this->hr))->toThrow(ProfileChangeRefused::class, 'does not belong');

    // Each change is on the timeline (personal) with an event that carries no values.
    Event::fake([EmploymentEvent::class]);
    $records->save($this->employee, $policy, ['valid_until' => '2027-06-30'], [], $this->hr, 'Extended');
    Event::assertDispatched(EmploymentEvent::class, fn ($e) => $e->name === 'employee.insurance_changed' && ! array_key_exists('member_id', $e->context));
});

it('keeps the e-card on the documents disk and serves it, audited, to sensitive HR and the employee only', function () {
    $policy = ms5Policy($this->employee, [['family_member_id' => 'self', 'member_id' => 'STAR100245']]);
    Storage::disk('local')->put('insurance-ecards/upload.pdf', "%PDF-1.4\n%ecard\n");
    app(InsuranceRecords::class)->attachEcard($this->employee, $policy, 'insurance-ecards/upload.pdf', 'star-ecard.pdf', $this->hr);
    $policy->refresh();
    expect($policy->ecard_path)->toStartWith('tenants/'.$this->tenant->id.'/employees/'.$this->employee->id.'/insurance/')->and($policy->ecard_name)->toBe('star-ecard.pdf')
        ->and(DataInventory::FILES['employee_insurance_policies'][0]['path'])->toBe('ecard_path');
    Storage::disk('local')->assertExists($policy->ecard_path);
    Storage::disk('local')->assertMissing('insurance-ecards/upload.pdf');

    // A file that is not an accepted document type is refused and removed.
    Storage::disk('local')->put('insurance-ecards/bad.exe', 'MZ');
    expect(fn () => app(InsuranceRecords::class)->attachEcard($this->employee, $policy, 'insurance-ecards/bad.exe', 'bad.exe', $this->hr))->toThrow(ProfileChangeRefused::class, 'not accepted');
    Storage::disk('local')->assertMissing('insurance-ecards/bad.exe');

    $url = ParityFileController::url('insurance-ecard', $policy, 0);
    $this->get($url)->assertOk();
    expect(AuditEvent::query()->where('action', 'ATTACHMENT_DOWNLOADED')->where('entity_type', InsurancePolicy::class)->count())->toBeGreaterThanOrEqual(1);
    $this->actingAs($this->me)->get($url)->assertOk();
    $this->actingAs(tenantUser($this->tenant, ['employee.view', 'employee.update']))->get($url)->assertForbidden();
    $this->actingAs(employeeWithUser(null, ['employee.self'])->user)->get($url)->assertForbidden();

    // Removing the policy removes its members and the file.
    $this->actingAs($this->hr);
    $path = $policy->ecard_path;
    app(InsuranceRecords::class)->remove($this->employee, $policy, $this->hr, 'Left the scheme');
    expect(InsurancePolicy::query()->count())->toBe(0)->and(InsuredMember::query()->count())->toBe(0);
    Storage::disk('local')->assertMissing($path);
});

it('uploads policies and members through the staged pipeline, new and existing, with member ids sealed', function () {
    $spouse = ms5Member($this->employee, 'Kiran Rao');
    $code = $this->employee->employee_code;
    expect(RowImportTypes::allowed($this->hr, InsuranceImports::TYPE))->toBeTrue()->and(ImportTypes::moduleLabel(InsuranceImports::TYPE))->toBe('Workforce')
        ->and(RowImportTypes::allowed(tenantUser($this->tenant, ['employee.import']), InsuranceImports::TYPE))->toBeFalse()
        ->and(WorkforceUploadResource::canAccess())->toBeTrue();

    $csv = "Employee code,Insurance company,Policy number,Sum assured,Valid until,Insured person,Member id,Parents cover\n"
        ."{$code},Star Health,GHI/2026/001,\"5,00,000\",2027-03-31,Self,STAR100245,no\n"
        ."{$code},Star Health,GHI/2026/001,,2027-03-31,Kiran Rao,STAR7788,no\n"
        ."{$code},Star Health,GHI/2026/001,,2027-03-31,Nobody,X1,no\n"
        ."NOPE,Star Health,GHI/2026/001,,,Self,X2,maybe\n";
    $import = ms5Upload($csv, 'new');
    $rows = $import->rows()->orderBy('row_number')->get();
    expect($import->create_count)->toBe(2)->and($import->error_count)->toBe(2)
        ->and(implode(' ', $rows[2]->errors))->toContain("No family member named 'Nobody'")
        ->and(implode(' ', $rows[3]->errors))->toContain("Employee code 'NOPE' does not exist")->toContain('Parents cover must be yes or no')
        ->and($rows[0]->data)->toBe([])->and($rows[0]->match['label'])->toContain('••••0245')->not->toContain('STAR100245')
        ->and((string) DB::table('employee_import_rows')->where('id', $rows[0]->id)->value('sealed_data'))->not->toContain('STAR100245');

    $service = app(InsuranceImports::class);
    $service->run($service->approve($import, $this->hr, 'Enrolment'), $this->hr);
    $policy = InsurancePolicy::query()->sole();
    expect((float) $policy->sum_assured)->toBe(500000.0)->and($policy->members()->count())->toBe(2)
        ->and(InsuredMember::query()->where('family_member_id', $spouse->id)->sole()->member_id)->toBe('STAR7788')
        ->and($import->rows()->whereNotNull('sealed_data')->count())->toBe(0);
    Storage::disk('local')->assertMissing($import->path);

    // Existing only: the person on file is updated (blank cells keep what is on file); someone not on file is skipped.
    $father = ms5Member($this->employee, 'Ravi Rao', 'father', '1960-01-01');
    $csv = "Employee code,Insurance company,Policy number,Valid until,Insured person,Member id,Parents cover\n"
        ."{$code},Star Health,GHI/2026/001,2027-06-30,Self,STAR999000,\n"
        ."{$code},Star Health,GHI/2026/001,,Ravi Rao,STAR5555,yes\n";
    $update = ms5Upload($csv, 'update');
    expect($update->update_count)->toBe(1)->and($update->skip_count)->toBe(1)->and($update->create_count)->toBe(0);
    $service->run($service->approve($update, $this->hr, 'Renewal'), $this->hr);
    expect(InsuredMember::query()->where('member_key', 'self')->sole()->member_id)->toBe('STAR999000')
        ->and($policy->fresh()->valid_until->toDateString())->toBe('2027-06-30')->and((float) $policy->fresh()->sum_assured)->toBe(500000.0)
        ->and(InsuredMember::query()->where('family_member_id', $father->id)->exists())->toBeFalse();

    // New only: the father is added (with the parents' cover) and the policy on file is kept; people on file are skipped.
    $again = ms5Upload("Employee code,Insurance company,Policy number,Valid until,Insured person,Member id,Parents cover\n"
        ."{$code},Star Health,GHI/2026/001,2030-01-01,Ravi Rao,STAR5555,yes\n{$code},Star Health,GHI/2026/001,,Self,STAR999000,\n", 'new');
    expect($again->create_count)->toBe(1)->and($again->skip_count)->toBe(1);
    $service->run($service->approve($again, $this->hr, 'Parents'), $this->hr);
    expect(InsuredMember::query()->where('family_member_id', $father->id)->sole()->is_parents_cover)->toBeTrue()
        ->and($policy->fresh()->valid_until->toDateString())->toBe('2027-06-30');
});

it('shows the employee their own policy and members on My profile, member ids only as PRV-1 allows, masked and audited', function () {
    $spouse = ms5Member($this->employee, 'Kiran Rao');
    $policy = ms5Policy($this->employee, [['family_member_id' => 'self', 'member_id' => 'STAR100245'], ['family_member_id' => $spouse->id, 'member_id' => 'STAR7788']]);
    $self = InsuredMember::query()->where('member_key', 'self')->sole();
    $this->actingAs($this->me);

    // Today's default (no employee.self_sensitive): the policy and the people, never a member id.
    $page = Livewire::test(MyProfile::class);
    expect(array_keys($page->instance()->sections()))->toContain('health_insurance');
    $page->call('setSection', 'health_insurance')->assertSee('Star Health')->assertSee('GHI/2026/001')->assertSee('Kiran Rao')->assertSee('31 Mar 2027')
        ->assertDontSee('0245')->assertDontSee('STAR100245');
    $page->call('reveal', 'insured:'.$self->id)->assertSet('revealed', [])->assertDontSee('STAR100245');
    expect(app(MyProfileSections::class)->insurance($this->me, $this->employee)['policies'][0]['members'][0]['member_id'])->toBeNull();

    // With employee.self_sensitive: masked, "Show" is an audited view of one's own member id only.
    $other = forceLifecycle(employeeWithUser(null, ['employee.self', 'employee.self_sensitive']), LifecycleState::Active);
    $this->actingAs($this->hr);
    ms5Policy($other, [['family_member_id' => 'self', 'member_id' => 'MINE4321']], ['policy_number' => 'GHI/2026/777']);
    $mine = InsuredMember::query()->where('employee_id', $other->id)->sole();
    $this->actingAs($other->user);
    $page = Livewire::test(MyProfile::class)->call('setSection', 'health_insurance')->assertSee('••••4321')->assertDontSee('MINE4321');
    $page->call('reveal', 'insured:'.$self->id)->assertSet('revealed', []);
    $page->call('reveal', 'insured:'.$mine->id)->assertSee('MINE4321')->assertSet('revealed', ['insured:'.$mine->id]);
    expect(AuditEvent::query()->where('action', 'VIEW')->get()->filter(fn (AuditEvent $e) => ($e->metadata['scope'] ?? null) === 'self_reveal')->count())->toBe(1);

    // The profile-access matrix can hide the section from employees (HR still sees the 360 tab).
    $this->actingAs($this->hr);
    $matrix = config('peopleos.settings')['profile.section_visibility'];
    $matrix['health_insurance']['employee'] = false;
    app(SettingsRepository::class)->set('profile.section_visibility', $matrix, 'test');
    expect(InsuranceRelationManager::canViewForRecord($this->employee, ViewEmployee::class))->toBeTrue();
    $this->actingAs($this->me);
    expect(array_keys(Livewire::test(MyProfile::class)->instance()->sections()))->not->toContain('health_insurance')
        ->and(app(MyProfileSections::class)->insurance($this->me, $this->employee)['policies'])->toBe([])
        ->and($this->me->can('view', $policy))->toBeFalse();
});

it('lets the employee change their health insurance only when switched on: with approval, or at once under the request\'s grant', function () {
    $spouse = ms5Member($this->employee, 'Kiran Rao');
    ms5Allow(approval: true);
    $this->actingAs($this->me);
    expect(array_keys(Livewire::test(MyProfile::class)->instance()->sections()))->toContain('health_insurance');

    $request = app(ProfileChanges::class)->submit($this->employee->fresh(), $this->me, 'health_insurance', [
        'policy_id' => null, 'insurer_name' => 'Star Health', 'policy_number' => 'GHI/2026/001', 'valid_until' => '2027-03-31',
        'members' => [['family_member_id' => 'self', 'member_id' => 'SELF4455'], ['family_member_id' => (string) $spouse->id, 'member_id' => 'SPOU6677']],
    ], 'Enrolled in the group policy');
    expect($request->status)->toBe('pending')->and($request->route)->toBe('approval')->and(InsurancePolicy::query()->count())->toBe(0)
        ->and($request->approver_permissions)->toBe(['employee.sensitive.update'])
        ->and((string) DB::table('profile_change_requests')->where('id', $request->id)->value('payload'))->not->toContain('SELF4455')
        ->and(collect($request->fields)->map(fn ($f) => ($f['new'] ?? '').' '.($f['earlier'] ?? ''))->implode(' '))->not->toContain('SELF4455')->not->toContain('SPOU6677');

    // The employee cannot apply it through a forged self-service origin, nor cover someone outside their family.
    expect(fn () => app(InsuranceRecords::class)->save($this->employee, null, ['insurer_name' => 'X', 'policy_number' => 'P1'], [], $this->me, 'x', ChangeOrigin::selfService($request->reference())))
        ->toThrow(ProfileChangeRefused::class, 'employee.sensitive.update');

    $this->actingAs($this->hr);
    app(ProfileChanges::class)->approve($request->fresh(), $this->hr, 'Checked the card');
    $policy = InsurancePolicy::query()->sole();
    expect($policy->members()->count())->toBe(2)->and(InsuredMember::query()->where('member_key', 'self')->sole()->member_id)->toBe('SELF4455')
        ->and($request->fresh()->status)->toBe('approved')->and($request->fresh()->purged_at)->not->toBeNull();

    // Without approval: applied at once in the employee's name under the grant; a stranger's family member is refused.
    ms5Allow(approval: false);
    $father = ms5Member($this->employee, 'Ravi Rao', 'father', '1960-01-01');
    $stranger = ms5Member(forceLifecycle(employeeWithUser(), LifecycleState::Active), 'Not Family');
    $this->actingAs($this->me);
    expect(fn () => app(ProfileChanges::class)->submit($this->employee->fresh(), $this->me, 'health_insurance', ['policy_id' => $policy->id, 'members' => [['family_member_id' => (string) $stranger->id]]], 'Add'))
        ->toThrow(ProfileChangeRefused::class, 'own family members');
    $direct = app(ProfileChanges::class)->submit($this->employee->fresh(), $this->me, 'health_insurance', [
        'policy_id' => $policy->id, 'insurer_name' => 'Star Health', 'policy_number' => 'GHI/2026/001', 'valid_until' => '2027-03-31',
        'members' => [['family_member_id' => (string) $father->id, 'member_id' => 'DAD1122', 'is_parents_cover' => true]],
    ], 'Added my father');
    expect($direct->status)->toBe('approved')->and($direct->route)->toBe('direct')
        ->and(InsuredMember::query()->where('family_member_id', $father->id)->sole()->is_parents_cover)->toBeTrue()
        ->and($policy->members()->count())->toBe(3);
});

it('reminds through the lifecycle sweep when a policy\'s cover is about to end, without member ids', function () {
    ms5Policy($this->employee, [['family_member_id' => 'self', 'member_id' => 'STAR100245']], ['valid_until' => '2026-10-20']);
    $later = forceLifecycle(employeeWithUser(), LifecycleState::Active);
    ms5Policy($later, [], ['valid_until' => '2027-06-01']);
    $gone = forceLifecycle(employeeWithUser(), LifecycleState::Exited);
    ms5Policy($gone, [], ['valid_until' => '2026-10-15']);
    Event::fake([EmployeeReminderDue::class]);

    $this->artisan('peopleos:lifecycle:reminders', ['--tenant' => $this->tenant->slug, '--force' => true])
        ->expectsOutputToContain('insurance policies expiring 1')->assertSuccessful();

    Event::assertDispatchedTimes(EmployeeReminderDue::class, 1);
    Event::assertDispatched(EmployeeReminderDue::class, fn (EmployeeReminderDue $e) => $e->name === 'employee.insurance_expiring'
        && $e->employee->is($this->employee) && $e->context['insurer'] === 'Star Health' && $e->context['days_left'] === 15
        && ! str_contains(json_encode($e->context), '100245') && ! array_key_exists('member_id', $e->context));
    expect(config('peopleos.workflows.trigger_events'))->toHaveKey('employee.insurance_expiring')
        ->and(config('peopleos.notifications.events'))->toContain('employee.insurance_expiring');
});

it('reports insured members for sensitive HR only, never with a member id, and leaves sensitive fields out of reader-less feeds', function () {
    $spouse = ms5Member($this->employee, 'Kiran Rao');
    ms5Policy($this->employee, [['family_member_id' => 'self', 'member_id' => 'STAR100245'], ['family_member_id' => $spouse->id, 'member_id' => 'STAR7788', 'is_parents_cover' => true]]);
    $dataset = app(DatasetRegistry::class)->get('insurance_members');
    expect($dataset)->toBeInstanceOf(InsuranceMembersDataset::class)
        ->and($dataset->allowedFor($this->hr))->toBeTrue()->and($dataset->allowedFor(tenantUser($this->tenant, ['employee.view'])))->toBeFalse()
        ->and(collect(array_keys($dataset->fields()))->filter(fn ($k) => str_contains($k, 'member_id'))->values()->all())->toBe(['has_member_id'])
        ->and(array_keys($dataset->fieldsFor(null)))->not->toContain('policy_number')->not->toContain('sum_assured')->not->toContain('date_of_birth')->toContain('insurer');

    $rows = $dataset->query()->get();
    expect($rows)->toHaveCount(2);
    foreach ($rows as $row) {
        foreach (array_keys($dataset->fields()) as $field) {
            expect(json_encode($dataset->value($field, $row)))->not->toContain('100245')->not->toContain('7788');
        }
    }
    $spouseRow = $rows->firstWhere('family_member_id', $spouse->id);
    expect($dataset->value('relation', $spouseRow))->toBe('Spouse')->and($dataset->value('is_parents_cover', $spouseRow))->toBeTrue()
        ->and($dataset->value('has_member_id', $spouseRow))->toBeTrue()->and($dataset->value('policy_number', $spouseRow))->toBe('GHI/2026/001');

    // The library templates run on it.
    $library = app(ReportLibrary::class);
    $result = app(ReportRunner::class)->execute('insurance_members', $library->find('health_insurance_members')['definition'], $this->hr);
    expect($result->rows)->toHaveCount(2)->and(json_encode($result->rows))->not->toContain('100245');
    expect(app(ReportRunner::class)->execute('insurance_members', $library->find('health_insurance_expiring')['definition'], $this->hr)->rows)->toHaveCount(0);
});

it('keeps health insurance inside the organisation scope, behind the sensitive rights, and within the tenant', function () {
    $department = Department::factory()->create();
    $scoped = tenantUser($this->tenant, ['employee.view', 'employee.update', 'employee.sensitive.view', 'employee.sensitive.update']);
    UserAccessScope::query()->create(['user_id' => $scoped->id, 'dimension' => 'department', 'scope_id' => $department->id]);
    ms5Policy($this->employee, [['family_member_id' => 'self', 'member_id' => 'STAR100245']]);

    expect(fn () => app(InsuranceRecords::class)->save($this->employee, null, ['insurer_name' => 'X', 'policy_number' => 'P2'], [], $scoped->fresh()))->toThrow(ProfileChangeRefused::class, 'outside your organisation scope');
    $this->actingAs($scoped->fresh());
    expect(InsurancePolicy::query()->count())->toBe(0)->and(app(InsuranceMembersDataset::class)->query()->count())->toBe(0);

    // Without employee.sensitive.view the tab is not offered; without .update nothing is changed.
    $plain = tenantUser($this->tenant, ['employee.view', 'employee.update']);
    $this->actingAs($plain);
    expect(InsuranceRelationManager::canViewForRecord($this->employee, ViewEmployee::class))->toBeFalse()
        ->and(fn () => app(InsuranceRecords::class)->save($this->employee, null, ['insurer_name' => 'X', 'policy_number' => 'P3'], [], $plain))->toThrow(ProfileChangeRefused::class, 'employee.sensitive.update');
    $viewer = tenantUser($this->tenant, ['employee.view', 'employee.sensitive.view']);
    $this->actingAs($viewer);
    expect(InsuranceRelationManager::canViewForRecord($this->employee, ViewEmployee::class))->toBeTrue()
        ->and($viewer->can('create', InsurancePolicy::class))->toBeFalse()->and(WorkforceUploadResource::canAccess())->toBeFalse();

    // HR adds a policy through the tab's wizard (create action; profile tabs are editable on the Edit page).
    $this->actingAs($this->hr);
    $colleague = forceLifecycle(employeeWithUser(), LifecycleState::Active);
    Livewire::test(InsuranceRelationManager::class, ['ownerRecord' => $colleague, 'pageClass' => EditEmployee::class])
        ->callTableAction('create', data: ['insurer_name' => 'ICICI Lombard', 'policy_number' => 'IL-42', 'valid_until' => '2027-01-31',
            'members' => [['family_member_id' => 'self', 'member_id' => 'IL0042', 'is_parents_cover' => false]]])
        ->assertHasNoTableActionErrors();
    expect(InsurancePolicy::query()->where('employee_id', $colleague->id)->sole()->members()->sole()->member_id)->toBe('IL0042');

    // Another tenant never sees them.
    actAsTenant(provisionTenant('Other'));
    expect(InsurancePolicy::query()->count())->toBe(0)->and(InsuredMember::query()->count())->toBe(0);
});
