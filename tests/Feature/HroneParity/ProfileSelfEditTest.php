<?php

use App\Domain\Audit\Models\AuditEvent;
use App\Domain\Bgv\Models\BgvCase;
use App\Domain\Configuration\Models\CustomField;
use App\Domain\Configuration\Services\CustomFields;
use App\Domain\Documents\Models\DocumentType;
use App\Domain\Documents\Models\EmployeeDocument;
use App\Domain\Employment\Actions\ChangeBankAccountAction;
use App\Domain\Employment\Exceptions\ProfileChangeRefused;
use App\Domain\Employment\Models\Employee;
use App\Domain\Employment\Models\EmployeeBankAccount;
use App\Domain\Employment\Support\ChangeOrigin;
use App\Domain\Experience\Services\ApprovalCenter;
use App\Domain\Experience\Services\ApprovalDecisions;
use App\Domain\Experience\Services\MyRequests as MyRequestsModel;
use App\Domain\Experience\Services\RequestRegistry;
use App\Domain\Experience\Services\RequestTimeline;
use App\Domain\Identity\Models\User;
use App\Domain\Lifecycle\Enums\LifecycleState;
use App\Domain\Offboarding\Support\DataInventory;
use App\Domain\People\Actions\ChangeAddressAction;
use App\Domain\People\Models\Person;
use App\Domain\People\Models\PersonAddress;
use App\Domain\People\Models\PersonExperience;
use App\Domain\People\Models\PersonQualification;
use App\Domain\People\Models\PersonSkill;
use App\Domain\People\Models\ProfileChangeRequest;
use App\Domain\People\Models\Skill;
use App\Domain\People\Services\ProfileChanges;
use App\Domain\Platform\Services\SettingsRepository;
use App\Domain\Workflow\Enums\TaskStatus;
use App\Domain\Workflow\Models\Workflow;
use App\Domain\Workflow\Models\WorkflowInstance;
use App\Domain\Workflow\Services\WorkflowEngine;
use App\Filament\Pages\MyProfile;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

require_once __DIR__.'/../Workflow/WorkflowTestHelpers.php';

/*
| HROne parity MS-3 — self-service profile changes on My profile: nothing editable by default (owner decisions SSE-1 /
| SSE-2 open), section edit → workflow, Approval Center or at once, executed only through the People / Employment
| actions, values encrypted and purged, one open request per section, locks, documents, diff, send back, history.
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
    $this->changes = app(ProfileChanges::class);
});

/** Let employees change these sections; $approval false = applied at once. */
function ms3Allow(array $sections, bool $approval = true): void
{
    $matrix = (array) app(SettingsRepository::class)->get('profile.self_edit.sections', config('peopleos.settings')['profile.self_edit.sections']);
    foreach ($sections as $section) {
        $matrix[$section] = ['editable' => true, 'needs_approval' => $approval];
    }
    app(SettingsRepository::class)->set('profile.self_edit.sections', $matrix, 'test');
}

function ms3Submit(string $section, array $input, string $comment = 'Moved house', ?array $document = null): ProfileChangeRequest
{
    test()->actingAs(test()->me);

    return app(ProfileChanges::class)->submit(test()->employee->fresh(), test()->me, $section, $input, $comment, $document);
}

function ms3Pending(User $user): array
{
    app(ApprovalCenter::class)->forget($user);

    return app(ApprovalCenter::class)->pending($user)->pluck('id')->all();
}

function ms3File(string $name = 'proof.pdf'): UploadedFile
{
    return UploadedFile::fake()->create($name, 20, 'application/pdf');
}

it('keeps today\'s behaviour by default: nothing on My profile is editable and no request type is offered', function () {
    expect(config('peopleos.settings')['profile.self_edit.legal_identity'])->toBeFalse()
        ->and(config('peopleos.settings')['profile.self_edit.lock_during_bgv'])->toBeFalse()
        ->and(array_keys(config('peopleos.settings')['profile.self_edit.sections']))->toEqualCanonicalizing(array_keys(config('peopleos.profile.self_edit_sections')))
        ->and(collect(config('peopleos.settings')['profile.self_edit.sections'])->every(fn ($row) => $row === ['editable' => false, 'needs_approval' => true]))->toBeTrue();

    $this->actingAs($this->me);
    expect($this->changes->editableSections($this->me, $this->employee))->toBe([])
        ->and(fn () => ms3Submit('contact', ['personal_email' => 'asha@home.test']))->toThrow(ProfileChangeRefused::class, 'cannot be changed here')
        ->and(app(RequestRegistry::class)->isAvailable($this->me, 'profile_change'))->toBeFalse();
    Livewire::test(MyProfile::class)->call('setSection', 'personal')->assertDontSee('Change your details')->assertActionHidden('editSection', ['section' => 'contact']);
    expect(ProfileChangeRequest::query()->count())->toBe(0);
});

it('applies a section that needs no approval at once, through the People action, with the reason and request on the audit', function () {
    ms3Allow(['contact'], approval: false);
    $request = ms3Submit('contact', ['personal_email' => 'asha@home.test', 'personal_phone' => '98450 12345'], 'New personal e-mail');

    expect($request->status)->toBe('approved')->and($request->route)->toBe('direct')->and($request->decided_by)->toBeNull()
        ->and($request->payload)->toBeNull()->and($request->purged_at)->not->toBeNull()->and($request->open_key)->toBeNull()
        ->and(Person::query()->find($this->employee->person_id)->personal_email)->toBe('asha@home.test');
    // Only masked values are kept on the request.
    expect(collect($request->fields)->pluck('label')->all())->toBe(['Personal e-mail', 'Personal phone'])
        ->and(json_encode($request->fields))->not->toContain('asha@home.test');
    $audit = AuditEvent::query()->where('entity_type', Person::class)->where('action', 'UPDATE')->latest('id')->firstOrFail();
    expect($audit->approval_reference)->toBe($request->reference())->and($audit->reason)->toContain('New personal e-mail')->and((int) $audit->actor_id)->toBe($this->me->id);
    // The raw values never reach the request's own audit rows.
    $own = AuditEvent::query()->where('entity_type', ProfileChangeRequest::class)->where('entity_id', (string) $request->id)->with('fieldChanges')->get();
    expect($own->flatMap->fieldChanges->pluck('field')->all())->not->toContain('payload')->not->toContain('fields');
});

it('waits for approval when the section needs it, lists it to HR with earlier → new, and applies it in the approver\'s name', function () {
    PersonAddress::query()->create(['person_id' => $this->employee->person_id, 'type' => 'current', 'country_code' => 'IN', 'address_line_1' => '1 Old Road', 'city' => 'Pune']);
    ms3Allow(['addresses']);
    $request = ms3Submit('addresses', ['current' => ['country_code' => 'IN', 'address_line_1' => '22 MG Road', 'city' => 'Bengaluru'], 'same_as_current' => true]);

    expect($request->status)->toBe('pending')->and($request->route)->toBe('approval')->and($request->payload)->not->toBeNull()
        ->and(PersonAddress::query()->where('person_id', $this->employee->person_id)->count())->toBe(1);
    // Not for the employee, nor a colleague; for HR, with the diff.
    expect(ms3Pending($this->me))->not->toContain('profile_change:'.$request->id)
        ->and(ms3Pending(tenantUser($this->tenant, ['employee.view'])))->not->toContain('profile_change:'.$request->id)
        ->and(ms3Pending($this->hr))->toContain('profile_change:'.$request->id);
    $item = app(ApprovalCenter::class)->find($this->hr, 'profile_change:'.$request->id);
    expect(collect($item->changes)->firstWhere('label', 'Address (current) · Address line 1'))->toBe(['label' => 'Address (current) · Address line 1', 'before' => '1 Old Road', 'after' => '22 MG Road'])
        ->and(collect($item->changes)->pluck('label'))->toContain('Address (permanent) · Address line 1');
    // The employee can never approve their own.
    expect(fn () => $this->changes->approve($request, $this->me))->toThrow(ProfileChangeRefused::class);

    $this->actingAs($this->hr);
    app(ApprovalDecisions::class)->decide($this->hr, 'profile_change:'.$request->id, 'approve');
    $request->refresh();
    expect($request->status)->toBe('approved')->and($request->decided_by)->toBe($this->hr->id)->and($request->payload)->toBeNull()
        ->and(PersonAddress::query()->where('person_id', $this->employee->person_id)->where('type', 'current')->value('address_line_1'))->toBe('22 MG Road')
        ->and(PersonAddress::query()->where('person_id', $this->employee->person_id)->where('type', 'permanent')->value('city'))->toBe('Bengaluru');
    $audit = AuditEvent::query()->where('entity_type', PersonAddress::class)->latest('id')->firstOrFail();
    expect($audit->approval_reference)->toBe($request->reference())->and((int) $audit->actor_id)->toBe($this->hr->id);
});

it('keeps one open request per section, lets the requester withdraw it, and rejects only with a note', function () {
    ms3Allow(['contact']);
    $first = ms3Submit('contact', ['personal_email' => 'one@home.test']);
    expect(fn () => ms3Submit('contact', ['personal_email' => 'two@home.test']))->toThrow(ProfileChangeRefused::class, 'already waiting')
        ->and(fn () => ms3Submit('contact', ['personal_email' => null, 'personal_phone' => null]))->toThrow(ProfileChangeRefused::class);

    // Nobody else withdraws it.
    expect(fn () => $this->changes->withdraw($first, $this->hr))->toThrow(ProfileChangeRefused::class, 'Only the person');
    $this->changes->withdraw($first, $this->me);
    expect($first->fresh()->status)->toBe('withdrawn')->and($first->fresh()->payload)->toBeNull()
        ->and(Person::query()->find($this->employee->person_id)->personal_email)->not->toBe('one@home.test');

    $second = ms3Submit('contact', ['personal_email' => 'two@home.test']);
    expect(fn () => $this->changes->reject($second, $this->hr, ' '))->toThrow(ProfileChangeRefused::class, 'Say why');
    $this->actingAs($this->hr);
    $this->changes->reject($second, $this->hr, 'Use your own address');
    expect($second->fresh()->status)->toBe('rejected')->and($second->fresh()->payload)->toBeNull()->and($second->fresh()->decision_note)->toBe('Use your own address')
        ->and(fn () => $this->changes->approve($second->fresh(), $this->hr))->toThrow(ProfileChangeRefused::class, 'no longer waiting')
        ->and(fn () => ms3Submit('contact', ['personal_email' => 'two@home.test'], str_repeat('x', 501)))->toThrow(ProfileChangeRefused::class, '500')
        ->and(fn () => ms3Submit('contact', ['personal_email' => 'two@home.test'], ' '))->toThrow(ProfileChangeRefused::class, 'reason');
    // Requests are never deleted.
    expect(ProfileChangeRequest::query()->count())->toBe(2);
});

it('always needs approval for a bank account, checks the confirmation, the bank rule and the linked document, and masks the number', function () {
    ms3Allow(['bank'], approval: false);
    $proof = DocumentType::query()->where('code', 'BANK_PROOF')->firstOrFail();
    $proof->update(['linked_field' => 'bank_account', 'required_with_field' => true, 'employee_can_upload' => true]);
    $input = ['account_holder_name' => 'Asha', 'bank_name' => 'Canara Bank', 'ifsc' => 'cnrb0001234', 'account_number' => '001122334455', 'account_number_confirmation' => '001122334455', 'account_type' => 'savings', 'is_primary' => true];

    expect(fn () => ms3Submit('bank', ['account_number_confirmation' => '999'] + $input))->toThrow(ProfileChangeRefused::class, 'do not match')
        ->and(fn () => ms3Submit('bank', $input))->toThrow(ProfileChangeRefused::class, 'Cancelled cheque');
    $request = ms3Submit('bank', $input, 'New salary account', ['type_id' => $proof->id, 'file' => ms3File(), 'comment' => 'Cheque leaf']);

    expect($request->status)->toBe('pending')->and($request->route)->toBe('approval')->and($request->approver_permissions)->toBe(['employee.sensitive.update'])
        ->and(EmployeeBankAccount::query()->count())->toBe(0);
    $document = EmployeeDocument::query()->findOrFail($request->document_ids[0]);
    expect($document->status)->toBe('pending')->and($document->upload_comment)->toBe('Cheque leaf')->and($document->document_type_id)->toBe($proof->id);

    // employee.update alone may not apply a bank change; the sensitive right may, and sees the number only with sensitive.view.
    $records = tenantUser($this->tenant, ['employee.view', 'employee.update', 'employee.create', 'task.view']);
    $bankDesk = tenantUser($this->tenant, ['employee.view', 'employee.sensitive.update', 'task.view']);
    expect(ms3Pending($records))->not->toContain('profile_change:'.$request->id)->and(ms3Pending($bankDesk))->toContain('profile_change:'.$request->id);
    $masked = collect($this->changes->diff($request, $bankDesk))->firstWhere('label', 'New bank account · Account number');
    $full = collect($this->changes->diff($request, $this->hr))->firstWhere('label', 'New bank account · Account number');
    expect($masked['after'])->toBe('••••4455')->and($full['after'])->toBe('001122334455');

    $this->actingAs($bankDesk);
    $this->changes->approve($request, $bankDesk);
    expect(EmployeeBankAccount::query()->where('employee_id', $this->employee->id)->firstOrFail()->ifsc)->toBe('CNRB0001234')
        ->and(collect($request->fresh()->fields)->firstWhere('label', 'New bank account · Account number')['new'])->toBe('••••4455');
});

it('refuses while the employee request lock holds profile changes, or a background verification is open when that is switched on', function () {
    ms3Allow(['contact'], approval: false);
    $settings = app(SettingsRepository::class);
    $settings->set('inbox.ess_lock.enabled', true, 'test');
    $settings->set('inbox.ess_lock.basis', 'period', 'test');
    $settings->set('inbox.ess_lock.period_from', '2026-10-01', 'test');
    $settings->set('inbox.ess_lock.period_to', '2026-10-31', 'test');
    $settings->set('inbox.ess_lock.request_kinds', ['profile_change'], 'test');
    expect(fn () => ms3Submit('contact', ['personal_email' => 'x@home.test']))->toThrow(ProfileChangeRefused::class, 'locked');
    $settings->set('inbox.ess_lock.enabled', false, 'test');

    BgvCase::query()->create(['employee_id' => $this->employee->id, 'provider' => 'manual', 'status' => 'in_progress', 'overall_result' => 'pending', 'initiated_at' => now()]);
    expect(ms3Submit('contact', ['personal_email' => 'x@home.test'])->status)->toBe('approved');
    $settings->set('profile.self_edit.lock_during_bgv', true, 'test');
    expect(fn () => ms3Submit('contact', ['personal_email' => 'y@home.test']))->toThrow(ProfileChangeRefused::class, 'background verification');
});

it('lets an employee edit only what the profile-access matrix shows them, and their own record only', function () {
    ms3Allow(['contact', 'family'], approval: false);
    $visibility = config('peopleos.settings')['profile.section_visibility'];
    $visibility['personal']['employee'] = false;
    app(SettingsRepository::class)->set('profile.section_visibility', $visibility, 'test');
    $this->actingAs($this->me);
    expect($this->changes->editableSections($this->me, $this->employee))->toBe(['family'])
        ->and(fn () => ms3Submit('contact', ['personal_email' => 'x@home.test']))->toThrow(ProfileChangeRefused::class);

    $colleague = forceLifecycle(employeeWithUser(null, ['employee.self']), LifecycleState::Active);
    expect(fn () => $this->changes->submit($this->employee, $colleague->user, 'family', ['marital_status' => 'married'], 'x'))->toThrow(ProfileChangeRefused::class, 'own record')
        ->and(fn () => $this->changes->submit($this->employee, $this->hr, 'family', ['marital_status' => 'married'], 'x'))->toThrow(ProfileChangeRefused::class, 'own record');
});

it('keeps legal name and date of birth off unless switched on, then always with approval and a document', function () {
    ms3Allow(['blood_group'], approval: false);
    expect(fn () => ms3Submit('legal_identity', ['first_name' => 'Asha', 'last_name' => 'Rao', 'date_of_birth' => '1990-01-01']))->toThrow(ProfileChangeRefused::class, 'cannot be changed');

    app(SettingsRepository::class)->set('profile.self_edit.legal_identity', true, 'test');
    $passport = DocumentType::query()->where('code', 'PASSPORT')->firstOrFail();
    $passport->update(['employee_can_upload' => true]);
    expect($this->changes->needsApproval('legal_identity'))->toBeTrue()
        ->and(fn () => ms3Submit('legal_identity', ['first_name' => 'Asha', 'last_name' => 'Rao', 'date_of_birth' => '1990-01-01']))->toThrow(ProfileChangeRefused::class, 'supporting document');
    $request = ms3Submit('legal_identity', ['first_name' => 'Asha', 'last_name' => 'Rao', 'date_of_birth' => '1990-01-01'], 'Name corrected in passport',
        ['type_id' => $passport->id, 'file' => ms3File('passport.pdf'), 'expires_on' => '2034-01-01']);
    expect($request->status)->toBe('pending')->and($request->route)->toBe('approval');

    $this->actingAs($this->hr);
    $this->changes->approve($request, $this->hr, 'Matches the passport');
    expect(Person::query()->find($this->employee->person_id)->date_of_birth->toDateString())->toBe('1990-01-01')
        ->and($request->fresh()->decision_note)->toBe('Matches the passport');
});

it('adds education, experience and profile skills through the new actions (self-declared, unverified) and custom fields HR marked editable', function () {
    ms3Allow(['education', 'experience', 'skills', 'other_details'], approval: false);
    $skill = Skill::query()->create(['name' => 'Payroll processing', 'code' => 'PAYROLL', 'status' => 'active']);
    ms3Submit('education', ['entries' => [['qualification' => 'MBA', 'institution' => 'IIM', 'year_of_completion' => 2015], ['qualification' => null]]]);
    ms3Submit('experience', ['entries' => [['employer' => 'Acme Ltd', 'designation' => 'Analyst', 'from_date' => '2012-01-01', 'to_date' => '2014-12-31']]]);
    ms3Submit('skills', ['entries' => [['skill_id' => $skill->id, 'proficiency' => 'advanced', 'years_of_experience' => 5]]]);

    expect(PersonQualification::query()->where('person_id', $this->employee->person_id)->sole()->only(['qualification', 'is_verified']))->toBe(['qualification' => 'MBA', 'is_verified' => false])
        ->and(PersonExperience::query()->where('person_id', $this->employee->person_id)->sole()->is_verified)->toBeFalse()
        ->and(PersonSkill::query()->where('person_id', $this->employee->person_id)->sole()->proficiency)->toBe('advanced')
        ->and(fn () => ms3Submit('skills', ['entries' => [['skill_id' => $skill->id, 'proficiency' => 'expert']]]))->toThrow(ProfileChangeRefused::class, 'already on the profile');
    // A skill of another tenant is not a choice.
    $other = provisionTenant('Other');
    $foreign = app(TenantContext::class)->runAs($other, fn () => Skill::query()->create(['name' => 'Foreign', 'code' => 'F', 'status' => 'active']));
    expect(fn () => ms3Submit('skills', ['entries' => [['skill_id' => $foreign->id]]]))->toThrow(ProfileChangeRefused::class, 'Choose a skill');

    // Other details: only while a custom field is marked editable by the employee; only those fields change.
    $this->actingAs($this->hr);
    $tshirt = CustomField::query()->create(['entity' => 'employee', 'key' => 'tshirt', 'label' => 'T-shirt size', 'type' => 'text', 'visible_to_employee' => true, 'editable_by_employee' => true, 'status' => 'active']);
    CustomField::query()->create(['entity' => 'employee', 'key' => 'grade_code', 'label' => 'Grade code', 'type' => 'text', 'visible_to_employee' => true, 'status' => 'active']);
    ms3Submit('other_details', ['values' => ['tshirt' => 'L', 'grade_code' => 'X9']]);
    expect(app(CustomFields::class)->valuesFor($this->employee->fresh()))->toBe(['tshirt' => 'L'])
        ->and($tshirt->fresh()->editable_by_employee)->toBeTrue();
});

it('lets a workflow on "profile change requested" decide, in the last approver\'s name; a step the approver may not apply waits for HR', function () {
    ms3Allow(['contact', 'blood_group'], approval: false);
    wfcLikeFlow($this->hr->id);
    $request = ms3Submit('contact', ['personal_email' => 'wf@home.test']);
    expect($request->status)->toBe('pending')->and($request->route)->toBe('workflow')
        ->and(ms3Pending($this->hr))->not->toContain('profile_change:'.$request->id);
    $this->actingAs($this->hr);
    ms3Decide($request, 'approved', $this->hr);
    expect($request->fresh()->status)->toBe('approved')->and($request->fresh()->decided_by)->toBe($this->hr->id)
        ->and(Person::query()->find($this->employee->person_id)->personal_email)->toBe('wf@home.test');

    // A line manager without employee.update approves the step: the change cannot apply in their name, so HR decides it.
    $manager = tenantUser($this->tenant, ['task.view', 'task.act']);
    Workflow::query()->update(['status' => 'inactive']);
    wfcLikeFlow($manager->id);
    $second = ms3Submit('blood_group', ['blood_group' => 'B+']);
    $this->actingAs($manager);
    ms3Decide($second, 'approved', $manager);
    expect($second->fresh()->status)->toBe('pending')->and(ms3Pending($this->hr))->toContain('profile_change:'.$second->id);
});

it('sends a change back to the employee, who corrects it and resubmits, and shows them its trail', function () {
    ms3Allow(['contact']);
    app(SettingsRepository::class)->set('inbox.send_back.types', ['profile_change'], 'test');
    $request = ms3Submit('contact', ['personal_email' => 'typo@home.tst']);
    $this->actingAs($this->hr);
    app(ApprovalDecisions::class)->decide($this->hr, 'profile_change:'.$request->id, 'send_back', 'Check the domain');
    expect(ms3Pending($this->hr))->not->toContain('profile_change:'.$request->id)
        ->and($this->changes->openReturn($request))->not->toBeNull();

    $this->actingAs($this->me);
    expect(fn () => $this->changes->revise($request, $this->hr, ['personal_email' => 'x@y.test'], 'x'))->toThrow(ProfileChangeRefused::class);
    $this->changes->revise($request->fresh(), $this->me, ['personal_email' => 'fixed@home.test'], 'Fixed the domain');
    expect(ms3Pending($this->hr))->toContain('profile_change:'.$request->id);
    $this->actingAs($this->hr);
    app(ApprovalDecisions::class)->decide($this->hr, 'profile_change:'.$request->id, 'approve');
    expect(Person::query()->find($this->employee->person_id)->personal_email)->toBe('fixed@home.test');

    $this->actingAs($this->me);
    $trail = app(RequestTimeline::class)->forOwn($this->me, app(RequestRegistry::class)->find('profile_change'), $request->fresh());
    expect(collect($trail['entries'])->pluck('kind'))->toContain('sent_back')->toContain('resubmitted')->toContain('approved');
});

it('registers "Profile change" as a request type: offered while a section is editable, listed in My requests, own records only', function () {
    ms3Allow(['contact']);
    $this->actingAs($this->me);
    expect(app(RequestRegistry::class)->isAvailable($this->me, 'profile_change'))->toBeTrue();
    $request = ms3Submit('contact', ['personal_email' => 'list@home.test']);
    $rows = app(MyRequestsModel::class)->for($this->me)['rows'];
    expect(collect($rows)->pluck('key')->all())->toContain('profile_change:'.$request->id);

    $colleague = forceLifecycle(employeeWithUser(null, ['employee.self']), LifecycleState::Active);
    $this->actingAs($colleague->user);
    expect(collect(app(MyRequestsModel::class)->for($colleague->user)['rows'])->pluck('key')->all())->not->toContain('profile_change:'.$request->id)
        ->and(app(RequestTimeline::class)->resolveOwn($colleague->user, 'profile_change:'.$request->id))->toBeNull();
});

it('accepts a self-service origin only for the request ProfileChanges is applying (no forged grant)', function () {
    $this->actingAs($this->me);
    expect(fn () => app(ChangeAddressAction::class)->add($this->employee, ['type' => 'current', 'country_code' => 'IN', 'address_line_1' => 'Forged'], $this->me, 'x', ChangeOrigin::selfService('PCR-000001')))
        ->toThrow(ProfileChangeRefused::class, 'employee.create')
        ->and(fn () => app(ChangeBankAccountAction::class)->add($this->employee, ['account_holder_name' => 'A', 'bank_name' => 'B', 'account_number' => '123456789'], $this->me, 'x', ChangeOrigin::selfService('PCR-000001')))
        ->toThrow(ProfileChangeRefused::class, 'employee.sensitive.update');
});

it('isolates tenants: another tenant\'s HR never sees or decides the request', function () {
    ms3Allow(['contact']);
    $request = ms3Submit('contact', ['personal_email' => 'iso@home.test']);
    $other = provisionTenant('Other');
    actAsTenant($other);
    $otherHr = tenantUser($other, ['*']);
    $this->actingAs($otherHr);
    expect(ms3Pending($otherHr))->not->toContain('profile_change:'.$request->id)
        ->and(ProfileChangeRequest::query()->count())->toBe(0);
});

it('shows Edit, the pending status, the history and the details on My profile; another person\'s request id does nothing', function () {
    ms3Allow(['contact']);
    $this->actingAs($this->me);
    Livewire::test(MyProfile::class)->call('setSection', 'personal')
        ->assertSee('Change your details')->assertActionVisible('editSection', ['section' => 'contact'])
        ->callAction('editSection', ['personal_email' => 'ui@home.test', 'personal_phone' => null, 'change_comment' => 'From the page'], ['section' => 'contact'])
        ->assertHasNoActionErrors()
        ->assertSee('Change pending since 5 Oct 2026')->assertActionHidden('editSection', ['section' => 'contact']);
    $request = ProfileChangeRequest::query()->sole();
    expect($request->comment)->toBe('From the page');

    Livewire::test(MyProfile::class)->call('setSection', 'history')->assertSee('Profile change requests')->assertSee($request->reference())
        ->mountAction('changeDetails', ['request' => $request->id])->assertSee('Personal e-mail');

    $colleague = forceLifecycle(employeeWithUser(null, ['employee.self']), LifecycleState::Active);
    $this->actingAs($colleague->user);
    Livewire::test(MyProfile::class)->callAction('withdrawChange', [], ['request' => $request->id]);
    expect($request->fresh()->status)->toBe('pending');
});

it('classifies the new table and keeps the waiting values encrypted', function () {
    expect(DataInventory::TABLES['people'])->toContain('profile_change_requests')
        ->and(config('peopleos.data_classification.highly_sensitive')[ProfileChangeRequest::class])->toBe(['payload'])
        ->and((new ProfileChangeRequest)->getCasts()['payload'])->toBe('encrypted:array');
    ms3Allow(['contact']);
    $request = ms3Submit('contact', ['personal_email' => 'secret@home.test']);
    expect((string) DB::table('profile_change_requests')->where('id', $request->id)->value('payload'))->not->toContain('secret@home.test');
});

/** A published approval workflow on profile.change_requested, decided by one user. */
function wfcLikeFlow(int $approverId): Workflow
{
    [$nodes, $edges] = linear([['id' => 'approve', 'type' => 'approval', 'name' => 'Approve', 'config' => ['mode' => 'single', 'approver' => ['type' => 'user', 'user_id' => $approverId]]]]);

    return publishWorkflow($nodes, $edges, ['trigger_event' => 'profile.change_requested']);
}

function ms3Decide(ProfileChangeRequest $request, string $decision, $user): WorkflowInstance
{
    $instance = WorkflowInstance::query()->where('subject_type', $request->getMorphClass())->where('subject_id', $request->getKey())->latest('id')->firstOrFail();
    $task = $instance->tasks()->where('status', TaskStatus::Pending)->firstOrFail();

    return app(WorkflowEngine::class)->completeTask($task, $decision, 'Fine', $user);
}
