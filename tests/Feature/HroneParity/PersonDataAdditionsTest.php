<?php

use App\Domain\Analytics\Datasets\EmployeesDataset;
use App\Domain\Attendance\Models\AttendanceDevice;
use App\Domain\Attendance\Models\AttendancePunch;
use App\Domain\Attendance\Services\PunchIngestion;
use App\Domain\Audit\Models\AuditEvent;
use App\Domain\Compliance\Models\ComplianceRule;
use App\Domain\Compliance\Services\ComplianceRules;
use App\Domain\Compliance\Services\InvestmentDeclarations;
use App\Domain\Compliance\Services\PriorExemptions;
use App\Domain\Configuration\Services\OptionLists;
use App\Domain\Documents\Models\DocumentType;
use App\Domain\Employment\Exceptions\ProfileChangeRefused;
use App\Domain\Employment\Imports\EmployeeImport;
use App\Domain\Employment\Imports\EmployeeImports;
use App\Domain\Employment\Models\Employee;
use App\Domain\Employment\Models\EmployeeStatutoryDetail;
use App\Domain\Employment\Support\ChangeOrigin;
use App\Domain\Experience\Services\MyProfileSections;
use App\Domain\Identity\Models\UserAccessScope;
use App\Domain\Letters\Services\LetterContext;
use App\Domain\Lifecycle\Enums\LifecycleState;
use App\Domain\Lifecycle\Events\EmployeeReminderDue;
use App\Domain\Offboarding\Support\DataInventory;
use App\Domain\Organisation\Models\Company;
use App\Domain\Organisation\Models\Department;
use App\Domain\Organisation\Models\Designation;
use App\Domain\Organisation\Models\Location;
use App\Domain\Payroll\Models\PayrollPeriod;
use App\Domain\Payroll\Services\PayrollCalculator;
use App\Domain\People\Actions\ChangeEmergencyContactAction;
use App\Domain\People\Actions\ChangeExperienceAction;
use App\Domain\People\Actions\ChangeFamilyMemberAction;
use App\Domain\People\Actions\ChangeIdentityDocumentAction;
use App\Domain\People\Actions\ChangeNominationsAction;
use App\Domain\People\Actions\ChangeQualificationAction;
use App\Domain\People\Actions\UpdatePersonAction;
use App\Domain\People\Models\Person;
use App\Domain\People\Models\PersonEmergencyContact;
use App\Domain\People\Models\PersonExperience;
use App\Domain\People\Models\PersonFamilyMember;
use App\Domain\People\Models\PersonIdentityDocument;
use App\Domain\People\Models\PersonNomination;
use App\Domain\People\Models\PersonQualification;
use App\Domain\People\Services\ProfileChanges;
use App\Domain\People\Support\PhoneNumbers;
use App\Domain\Platform\Services\FeatureFlags;
use App\Domain\Platform\Services\SettingsRepository;
use App\Filament\Pages\MyProfile;
use App\Filament\Resources\Employees\Pages\ViewEmployee;
use App\Filament\Resources\Employees\RelationManagers\IdentityDocumentsRelationManager;
use App\Filament\Resources\Employees\RelationManagers\NominationsRelationManager;
use App\Http\Controllers\EmployeeProfilePrintController;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

require_once __DIR__.'/../Workflow/WorkflowTestHelpers.php';
require_once __DIR__.'/../Payroll/PayrollTestHelpers.php';

/*
| HROne parity MS-4 — person data additions: salutation and fresher on the person, passport / driving licence (number
| encrypted, expiry reminders, CA-4 linked field), the device user id tried before the employee code, E.164 phones with a
| country code, schooling and remarks on family members, nominations per benefit (shares total 100, guardians for
| minors), the highest qualification, mode and year of starting, and the previous employer's exemptions read by tax only
| through a verified rule. Defaults reproduce today's behaviour.
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

function ms4Passport(Employee $employee, array $values = []): PersonIdentityDocument
{
    return app(ChangeIdentityDocumentAction::class)->add($employee, $values + ['kind' => 'passport', 'number' => 'z1234567', 'issued_on' => '2020-01-10', 'expires_on' => '2030-01-09', 'issuing_country' => 'in'], test()->hr, 'Passport on file');
}

function ms4Member(Employee $employee, string $name, ?string $dob = '1990-05-01', string $relation = 'spouse'): PersonFamilyMember
{
    return app(ChangeFamilyMemberAction::class)->add($employee, ['name' => $name, 'relation' => $relation, 'date_of_birth' => $dob], test()->hr);
}

function ms4Allow(array $sections, bool $approval = true): void
{
    $matrix = (array) app(SettingsRepository::class)->get('profile.self_edit.sections', config('peopleos.settings')['profile.self_edit.sections']);
    foreach ($sections as $section) {
        $matrix[$section] = ['editable' => true, 'needs_approval' => $approval];
    }
    app(SettingsRepository::class)->set('profile.self_edit.sections', $matrix, 'test');
}

/** Inspect, map as suggested and validate a staged employee file. */
function ms4Validate(EmployeeImport $import): EmployeeImport
{
    $imports = app(EmployeeImports::class);
    $import = $imports->inspect($import);

    return $imports->validate($imports->map($import, $import->mapping));
}

function ms4StageCsv(string $csv): EmployeeImport
{
    $path = EmployeeImports::directory().'/'.uniqid().'.csv';
    Storage::disk('local')->put($path, $csv);

    return app(EmployeeImports::class)->register($path, 'people.csv', auth()->user());
}

it('keeps today\'s behaviour by default', function () {
    // Every new self-service section is off, like the rest; the new columns default to "nothing recorded".
    expect(config('peopleos.settings')['profile.self_edit.sections']['identity_documents'])->toBe(['editable' => false, 'needs_approval' => true])
        ->and(config('peopleos.settings')['profile.self_edit.sections']['nominations'])->toBe(['editable' => false, 'needs_approval' => true])
        ->and(app(ProfileChanges::class)->editableSections($this->me, $this->employee))->toBe([]);
    $person = Person::query()->findOrFail($this->employee->person_id);
    expect($person->salutation)->toBeNull()->and($person->isFresher())->toBeFalse()
        ->and($this->employee->fresh()->device_user_id)->toBeNull()
        ->and(PersonIdentityDocument::query()->count())->toBe(0)->and(PersonNomination::query()->count())->toBe(0);

    // A phone without a country code is kept exactly as typed (every number already on file stays valid).
    app(UpdatePersonAction::class)->handle($person, ['personal_phone' => '98450 12345', 'gender' => 'female']);
    $this->employee->update(['work_phone' => '080-4000 0000']);
    expect($person->fresh()->personal_phone)->toBe('98450 12345')->and($this->employee->fresh()->work_phone)->toBe('080-4000 0000');

    // Letters: no recorded salutation → from the gender, as before; a recorded one wins.
    expect(app(LetterContext::class)->build($this->employee->fresh())['employee']['salutation'])->toBe('Ms');
    app(UpdatePersonAction::class)->handle($person->fresh(), ['salutation' => 'dr']);
    expect(app(LetterContext::class)->build($this->employee->fresh())['employee']['salutation'])->toBe('Dr')
        ->and(app(OptionLists::class)->options('salutations'))->toHaveKey('dr');

    // The import keeps its earlier columns in place; the new ones are appended (later packages append after them).
    expect(array_slice(array_keys(EmployeeImports::FIELDS), 30, 5))->toBe(['person.salutation', 'person.is_fresher', 'person.personal_phone_country_code', 'employee.work_phone_country_code', 'employee.device_user_id'])
        ->and(array_keys(EmployeeImports::FIELDS)[29])->toBe('manager_code');

    // Previous-employer exemptions are not declared: the tax basis has no such head.
    expect(app(PriorExemptions::class)->for(null, now()))->toBe([]);
});

it('stores passport and licence numbers encrypted at rest, masked in the audit trail, one per kind', function () {
    $passport = ms4Passport($this->employee);

    $raw = (string) DB::table('person_identity_documents')->where('id', $passport->id)->value('number');
    expect($raw)->not->toContain('Z1234567')->not->toBe('Z1234567')
        ->and($passport->fresh()->number)->toBe('Z1234567')
        ->and($passport->fresh()->number_last4)->toBe('4567')
        ->and($passport->fresh()->issuing_country)->toBe('IN')
        ->and($passport->toArray())->not->toHaveKey('number')
        ->and(config('peopleos.data_classification.highly_sensitive')[PersonIdentityDocument::class])->toBe(['number'])
        ->and(DataInventory::TABLES['people'])->toContain('person_identity_documents')->toContain('person_nominations');

    $event = AuditEvent::query()->where('entity_type', PersonIdentityDocument::class)->where('entity_id', (string) $passport->id)->where('action', 'CREATE')->with('fieldChanges')->sole();
    $change = $event->fieldChanges->firstWhere('field', 'number');
    expect($change)->not->toBeNull()->and((bool) $change->is_sensitive)->toBeTrue()->and((string) $change->after)->not->toContain('1234567');

    // One per kind; a renewal changes it (a blank number keeps the one on file); the expiry never precedes the issue.
    expect(fn () => ms4Passport($this->employee, ['number' => 'Y7654321']))->toThrow(ProfileChangeRefused::class, 'already on file')
        ->and(fn () => app(ChangeIdentityDocumentAction::class)->update($this->employee, $passport, ['expires_on' => '2019-01-01'], $this->hr))->toThrow(ProfileChangeRefused::class, 'before the date of issue');
    app(ChangeIdentityDocumentAction::class)->update($this->employee, $passport, ['number' => '', 'expires_on' => '2031-01-09'], $this->hr, 'Renewed');
    expect($passport->fresh()->number)->toBe('Z1234567')->and($passport->fresh()->expires_on->toDateString())->toBe('2031-01-09');

    // CA-4: a document type "required with" the passport must be on file before a number is entered.
    $type = DocumentType::query()->where('code', 'PASSPORT')->first()
        ?? DocumentType::create(['code' => 'PASSPORT', 'name' => 'Passport', 'category' => 'identity']);
    $type->update(['linked_field' => 'passport', 'required_with_field' => true]);
    $other = forceLifecycle(employeeWithUser(), LifecycleState::Active);
    expect(fn () => ms4Passport($other))->toThrow(ProfileChangeRefused::class, 'Passport first')
        ->and(config('peopleos.documents.linked_fields'))->toHaveKey('passport')->toHaveKey('driving_licence');
    app(ChangeIdentityDocumentAction::class)->add($other, ['kind' => 'driving_licence', 'number' => 'KA01 2020 1234567'], $this->hr);
    expect(PersonIdentityDocument::query()->where('person_id', $other->person_id)->sole()->number)->toBe('KA0120201234567');
});

it('reminds through the lifecycle sweep when a passport or licence is about to expire, without the number', function () {
    ms4Passport($this->employee, ['expires_on' => '2026-10-20']);
    $later = forceLifecycle(employeeWithUser(), LifecycleState::Active);
    ms4Passport($later, ['expires_on' => '2027-06-01']);
    $gone = forceLifecycle(employeeWithUser(), LifecycleState::Exited);
    ms4Passport($gone, ['expires_on' => '2026-10-15']);
    Event::fake([EmployeeReminderDue::class]);

    $this->artisan('peopleos:lifecycle:reminders', ['--tenant' => $this->tenant->slug, '--force' => true])
        ->expectsOutputToContain('identity documents expiring 1')->assertSuccessful();

    Event::assertDispatchedTimes(EmployeeReminderDue::class, 1);
    Event::assertDispatched(EmployeeReminderDue::class, fn (EmployeeReminderDue $e) => $e->name === 'employee.identity_document_expiring'
        && $e->employee->is($this->employee) && $e->context['kind'] === 'passport' && $e->context['days_left'] === 15
        && ! str_contains(json_encode($e->context), '1234567'));
    expect(config('peopleos.workflows.trigger_events'))->toHaveKey('employee.identity_document_expiring')
        ->and(config('peopleos.notifications.events'))->toContain('employee.identity_document_expiring');
});

it('matches device punches by the device user id first, and by the employee code exactly as before', function () {
    $device = AttendanceDevice::create(['name' => 'Gate 1', 'code' => 'GATE1', 'adapter' => 'generic']);
    $ingestion = app(PunchIngestion::class);
    $code = $this->employee->employee_code;

    // No device ids anywhere: matched by the code, as today.
    $result = $ingestion->ingest($device, ['punches' => [['employee_code' => $code, 'punched_at' => '2026-10-05 09:01:00', 'direction' => 'in', 'id' => 'P1']]]);
    expect($result['accepted'])->toBe(1)->and(AttendancePunch::query()->latest('id')->first()->employee_id)->toBe($this->employee->id);

    // A device id: tried first; the code still works for someone without one.
    $colleague = forceLifecycle(employeeWithUser(), LifecycleState::Active);
    $colleague->update(['device_user_id' => ' 1042 ']);
    expect($colleague->fresh()->device_user_id)->toBe('1042');
    $result = app(PunchIngestion::class)->ingest($device, ['punches' => [
        ['employee_code' => '1042', 'punched_at' => '2026-10-05 09:05:00', 'direction' => 'in', 'id' => 'P2'],
        ['employee_code' => $code, 'punched_at' => '2026-10-05 18:00:00', 'direction' => 'out', 'id' => 'P3'],
        ['employee_code' => 'NOBODY', 'punched_at' => '2026-10-05 18:00:00', 'direction' => 'out', 'id' => 'P4'],
    ]]);
    expect($result)->toMatchArray(['accepted' => 2, 'failed' => 1, 'unknown' => ['NOBODY']])
        ->and(AttendancePunch::query()->where('external_id', 'P2')->value('employee_id'))->toBe($colleague->id)
        ->and(AttendancePunch::query()->where('external_id', 'P3')->value('employee_id'))->toBe($this->employee->id)
        ->and(AttendancePunch::query()->where('external_id', 'P4')->value('processing_error'))->toBe('Unknown employee code [NOBODY].');

    // A failed punch retried after HR records the id is matched by it.
    $failed = AttendancePunch::query()->where('external_id', 'P4')->firstOrFail();
    $this->employee->update(['device_user_id' => 'NOBODY']);
    expect(app(PunchIngestion::class)->retry($failed)->employee_id)->toBe($this->employee->id);

    // Unique per tenant (database), never across tenants.
    expect(fn () => $colleague->fresh()->update(['device_user_id' => 'NOBODY']))->toThrow(UniqueConstraintViolationException::class);
    $other = provisionTenant('Other');
    actAsTenant($other);
    $stranger = Employee::factory()->create(['device_user_id' => '1042']);
    expect($stranger->device_user_id)->toBe('1042');
});

it('stores phones with a country code in E.164 and refuses numbers that cannot be one', function () {
    expect(PhoneNumbers::normalise('91', '098450 12345'))->toBe('+919845012345')
        ->and(PhoneNumbers::normalise('+91', '+91 98450-12345'))->toBe('+919845012345')
        ->and(PhoneNumbers::normalise('91', '0091 9845012345'))->toBe('+919845012345')
        ->and(PhoneNumbers::normalise(null, '98450 12345'))->toBe('98450 12345')
        ->and(PhoneNumbers::problem('91', '+44 20 7946 0000'))->toContain('different country code')
        ->and(PhoneNumbers::problem('91', '12'))->toContain('7 to 15 digits')
        ->and(PhoneNumbers::problem('91', 'call me'))->toContain('only contain digits')
        ->and(PhoneNumbers::problem('91', '1234567890123456'))->toContain('7 to 15 digits');

    $person = Person::query()->findOrFail($this->employee->person_id);
    app(UpdatePersonAction::class)->handle($person, ['personal_phone_country_code' => '91', 'personal_phone' => '98450 12345']);
    $this->employee->update(['work_phone_country_code' => '+44', 'work_phone' => '020 7946 0000']);
    expect($person->fresh()->personal_phone)->toBe('+919845012345')
        ->and($this->employee->fresh()->work_phone)->toBe('+442079460000')->and($this->employee->fresh()->work_phone_country_code)->toBe('44');

    $contact = app(ChangeEmergencyContactAction::class)->add($this->employee, ['name' => 'Ravi', 'phone_country_code' => '971', 'phone' => '50 123 4567'], $this->hr);
    expect($contact->fresh()->phone)->toBe('+971501234567')
        ->and(fn () => app(ChangeEmergencyContactAction::class)->add($this->employee, ['name' => 'Bad', 'phone_country_code' => '91', 'phone' => '12'], $this->hr))->toThrow(ProfileChangeRefused::class, '7 to 15 digits')
        ->and(PersonEmergencyContact::query()->where('name', 'Bad')->exists())->toBeFalse();
});

it('keeps a benefit\'s nominations together: shares total 100 and a minor nominee has a guardian', function () {
    $spouse = ms4Member($this->employee, 'Kiran', '1990-05-01');
    $child = ms4Member($this->employee, 'Anu', '2015-03-01', 'daughter');
    $nominations = app(ChangeNominationsAction::class);

    expect(fn () => $nominations->replace($this->employee, 'pf', [['family_member_id' => $spouse->id, 'share' => 60]], $this->hr))
        ->toThrow(ProfileChangeRefused::class, 'The shares for Provident fund total 60%: they must total 100%.')
        ->and(fn () => $nominations->replace($this->employee, 'pf', [['family_member_id' => $spouse->id, 'share' => 60], ['family_member_id' => $child->id, 'share' => 40]], $this->hr))
        ->toThrow(ProfileChangeRefused::class, 'Anu is a minor: give the guardian\'s name and relation')
        ->and(fn () => $nominations->replace($this->employee, 'pf', [['family_member_id' => $spouse->id, 'share' => 50], ['family_member_id' => $spouse->id, 'share' => 50]], $this->hr))
        ->toThrow(ProfileChangeRefused::class, 'nominated twice')
        ->and(fn () => $nominations->replace($this->employee, 'lottery', [['family_member_id' => $spouse->id, 'share' => 100]], $this->hr))
        ->toThrow(ProfileChangeRefused::class, 'benefit the nomination is for');
    $stranger = ms4Member(forceLifecycle(employeeWithUser(), LifecycleState::Active), 'Stranger');
    expect(fn () => $nominations->replace($this->employee, 'gratuity', [['family_member_id' => $stranger->id, 'share' => 100]], $this->hr))
        ->toThrow(ProfileChangeRefused::class, 'family members on the profile')
        ->and(PersonNomination::query()->count())->toBe(0);

    $saved = $nominations->replace($this->employee, 'pf', [
        ['family_member_id' => $spouse->id, 'share' => 66.67, 'address' => '12 MG Road'],
        ['family_member_id' => $child->id, 'share' => 33.33, 'guardian_name' => 'Kiran', 'guardian_relation' => 'Mother'],
    ], $this->hr, 'Nominations from the joining form');
    expect($saved)->toHaveCount(2)->and(ChangeNominationsAction::totals($this->employee))->toBe(['pf' => 100.0]);

    // Replacing the set keeps one audit trail per nomination; a family member on a nomination cannot be removed first.
    $nominations->replace($this->employee, 'pf', [['family_member_id' => $spouse->id, 'share' => 100]], $this->hr);
    expect(PersonNomination::query()->where('benefit', 'pf')->sole()->family_member_id)->toBe($spouse->id)
        ->and(fn () => app(ChangeFamilyMemberAction::class)->remove($this->employee, $spouse->fresh(), $this->hr))->toThrow(ProfileChangeRefused::class, 'is a nominee');

    // Family members carry schooling and remarks.
    app(ChangeFamilyMemberAction::class)->update($this->employee, $child, ['name' => 'Anu', 'relation' => 'daughter', 'in_school' => true, 'in_hostel' => true, 'remarks' => 'Boarding school'], $this->hr);
    expect($child->fresh()->only(['in_school', 'in_hostel', 'remarks']))->toBe(['in_school' => true, 'in_hostel' => true, 'remarks' => 'Boarding school']);
});

it('keeps one highest qualification per person, with the mode and the year of starting', function () {
    $action = app(ChangeQualificationAction::class);
    $bachelor = $action->add($this->employee, ['qualification' => 'B.Tech', 'year_started' => 2010, 'year_of_completion' => 2014, 'mode' => 'full_time', 'is_highest' => true], $this->hr);
    $master = $action->add($this->employee, ['qualification' => 'MBA', 'year_started' => 2016, 'year_of_completion' => 2018, 'mode' => 'distance', 'is_highest' => true], $this->hr);

    expect($bachelor->fresh()->is_highest)->toBeFalse()->and($master->fresh()->is_highest)->toBeTrue()
        ->and(Person::query()->findOrFail($this->employee->person_id)->highestQualification->qualification)->toBe('MBA')
        ->and(fn () => $action->add($this->employee, ['qualification' => 'PhD', 'year_started' => 2022, 'year_of_completion' => 2020], $this->hr))->toThrow(ProfileChangeRefused::class, 'after the year of passing')
        ->and(fn () => $action->add($this->employee, ['qualification' => 'PhD', 'mode' => 'telepathy'], $this->hr))->toThrow(ProfileChangeRefused::class);

    // "I am a fresher" refuses earlier employment, and cannot be set while some is recorded.
    $person = Person::query()->findOrFail($this->employee->person_id);
    app(UpdatePersonAction::class)->handle($person, ['is_fresher' => true]);
    expect(fn () => app(ChangeExperienceAction::class)->add($this->employee, ['employer' => 'Acme', 'from_date' => '2015-01-01'], $this->hr))->toThrow(ProfileChangeRefused::class, 'fresher');
    app(UpdatePersonAction::class)->handle($person->fresh(), ['is_fresher' => false]);
    app(ChangeExperienceAction::class)->add($this->employee, ['employer' => 'Acme', 'from_date' => '2015-01-01'], $this->hr);
    expect(fn () => app(UpdatePersonAction::class)->handle($person->fresh(), ['is_fresher' => true]))->toThrow(ProfileChangeRefused::class, 'Remove the earlier employment first')
        ->and(PersonExperience::query()->count())->toBe(1);
});

it('keeps the previous employer\'s exemptions on the declaration with no effect on tax unless a verified rule carries the limits', function () {
    syncComplianceRules();
    $company = payrollCompany();
    $employee = salariedEmployee(1800000, from: '2026-01-01');
    EmployeeStatutoryDetail::create(['employee_id' => $employee->id, 'pan' => 'ABCDE1234F']);
    $declarations = app(InvestmentDeclarations::class);
    $tax = fn () => app(PayrollCalculator::class)->calculate($employee->refresh(), PayrollPeriod::for($company, 2026, 9))->inputs['tax'];
    $declarations->save($this->hr, $employee, '2026-27', ['regime' => 'old', 'items' => [], 'previous_employer_income' => 100000, 'previous_employer_tds' => 5000, 'previous_employer' => ['name' => 'Acme Ltd']], true);
    $before = $tax();

    $declarations->save($this->hr, $employee, '2026-27', ['regime' => 'old', 'items' => [], 'previous_employer_income' => 100000, 'previous_employer_tds' => 5000,
        'previous_employer' => ['name' => 'Acme Ltd', 'gratuity_exempt' => 150000, 'leave_encashment_exempt' => '40000']], true);
    $declaration = $declarations->declaration($employee, '2026-27');
    expect((float) $declaration->previousEmployer('gratuity_exempt'))->toBe(150000.0)->and((float) $declaration->previousEmployer('leave_encashment_exempt'))->toBe(40000.0);

    // No verified rule carries the limits (none is coded anywhere): stored, shown "not counted", tax unchanged.
    $after = $tax();
    expect($after['annual_tax'])->toBe($before['annual_tax'])->and($after['taxable_income'])->toBe($before['taxable_income'])
        ->and($after['previous_exemptions']['gratuity'])->toMatchArray(['declared' => 150000.0, 'counted' => false, 'limit' => null, 'remaining' => null])
        ->and($after['previous_exemptions']['leave_encashment']['counted'])->toBeFalse();

    // A verified TDS rule version that carries the limits (test data): the remaining exemption is worked out from it; the
    // monthly tax still does not change (salary TDS exempts no gratuity or leave encashment).
    $current = app(ComplianceRules::class)->resolve('TDS', now());
    $rule = ComplianceRule::query()->create(['jurisdiction' => 'IN', 'code' => 'TDS', 'state' => null, 'name' => 'TDS test rule', 'version' => (int) ComplianceRule::query()->where('code', 'TDS')->max('version') + 1,
        'effective_from' => $current->effective_from->toDateString(), 'parameters' => $current->parameters + ['gratuity_exemption_limit' => 1000000, 'leave_encashment_exemption_limit' => 500000], 'status' => ComplianceRule::ACTIVE]);
    DB::table('compliance_rules')->where('id', $rule->id)->update(['verification_status' => ComplianceRule::VERIFIED]);
    app(ComplianceRules::class)->forget();
    $verified = app(PriorExemptions::class)->for($declaration, now());
    expect($verified['gratuity'])->toMatchArray(['declared' => 150000.0, 'counted' => true, 'limit' => 1000000.0, 'remaining' => 850000.0])
        ->and($verified['leave_encashment']['remaining'])->toBe(460000.0);
    $counted = $tax();
    expect($counted['annual_tax'])->toBe($before['annual_tax'])->and($counted['previous_exemptions']['gratuity']['counted'])->toBeTrue();
});

it('sends passport and licence changes made by the employee for approval, always, and applies them in the approver\'s name', function () {
    // The organisation even ticks "applies at once": identity documents still wait for approval (SSE-4).
    ms4Allow(['identity_documents', 'nominations'], approval: false);
    $this->actingAs($this->me);
    $request = app(ProfileChanges::class)->submit($this->employee->fresh(), $this->me, 'identity_documents',
        ['entries' => [['kind' => 'passport', 'number' => 'P7654321', 'issued_on' => '2021-01-01', 'expires_on' => '2031-01-01']]], 'New passport');
    expect($request->status)->toBe('pending')->and($request->route)->toBe('approval')
        ->and(PersonIdentityDocument::query()->count())->toBe(0)
        ->and((string) DB::table('profile_change_requests')->where('id', $request->id)->value('payload'))->not->toContain('P7654321')
        ->and(collect($request->fields)->pluck('new')->implode(' '))->not->toContain('P7654321')
        ->and($request->approver_permissions)->toBe(['employee.sensitive.update']);

    // The employee cannot apply it through a forged self-service origin either.
    expect(fn () => app(ChangeIdentityDocumentAction::class)->add($this->employee, ['kind' => 'passport', 'number' => 'P7654321'], $this->me, 'x', ChangeOrigin::selfService($request->reference())))
        ->toThrow(ProfileChangeRefused::class, 'employee.sensitive.update');

    $this->actingAs($this->hr);
    app(ProfileChanges::class)->approve($request->fresh(), $this->hr, 'Checked the copy');
    $document = PersonIdentityDocument::query()->sole();
    expect($document->number)->toBe('P7654321')->and($request->fresh()->status)->toBe('approved')->and($request->fresh()->purged_at)->not->toBeNull();

    // Nominations go through the same grant: applied at once when the organisation says so, in the employee's name.
    $spouse = ms4Member($this->employee, 'Kiran');
    $this->actingAs($this->me);
    $nomination = app(ProfileChanges::class)->submit($this->employee->fresh(), $this->me, 'nominations', ['benefit' => 'gratuity', 'rows' => [['family_member_id' => $spouse->id, 'share' => 100]]], 'Gratuity nominee');
    expect($nomination->status)->toBe('approved')->and($nomination->route)->toBe('direct')
        ->and(PersonNomination::query()->where('benefit', 'gratuity')->sole()->family_member_id)->toBe($spouse->id)
        ->and(fn () => app(ProfileChanges::class)->submit($this->employee->fresh(), $this->me, 'nominations', ['benefit' => 'pf', 'rows' => [['family_member_id' => $spouse->id, 'share' => 70]]], 'PF'))
        ->toThrow(ProfileChangeRefused::class, 'must total 100%');
});

it('never shows the employee their own numbers unless PRV-1 allows it, masked and audited', function () {
    $passport = ms4Passport($this->employee);
    $this->actingAs($this->me);

    // Today's default (no employee.self_sensitive): the kind and dates, never the number.
    $page = Livewire::test(MyProfile::class)->call('setSection', 'personal')
        ->assertSee('Passport and driving licence')->assertSee('9 Jan 2030')->assertDontSee('4567')->assertDontSee('Z1234567');
    $page->call('reveal', 'identity:'.$passport->id)->assertSet('revealed', [])->assertDontSee('Z1234567');
    expect(app(MyProfileSections::class)->personal($this->me, $this->employee)['identity_documents'][0]['number'])->toBeNull();

    // With employee.self_sensitive: masked, and "Show" is an audited sensitive view of one's own document only.
    $me = forceLifecycle(employeeWithUser(null, ['employee.self', 'document.own', 'employee.self_sensitive']), LifecycleState::Active);
    $this->actingAs($this->hr);
    $mine = ms4Passport($me, ['number' => 'M1112223']);
    $this->actingAs($me->user);
    $page = Livewire::test(MyProfile::class)->call('setSection', 'personal')->assertSee('••••2223')->assertDontSee('M1112223');
    $page->call('reveal', 'identity:'.$passport->id)->assertSet('revealed', []);
    $page->call('reveal', 'identity:'.$mine->id)->assertSee('M1112223')->assertSet('revealed', ['identity:'.$mine->id]);
    expect(AuditEvent::query()->where('action', 'VIEW')->get()->filter(fn (AuditEvent $e) => ($e->metadata['scope'] ?? null) === 'self_reveal')->count())->toBe(1);
});

it('adds the fields to the employee import, the employees dataset and the printable profile', function () {
    app(FeatureFlags::class)->setForTenant($this->tenant, EmployeeImports::FEATURE, true, platformAdmin(), 'Data migration agreed');
    $company = Company::factory()->create(['code' => 'ALPHA']);
    Location::factory()->create(['company_id' => $company->id, 'code' => 'DEL', 'name' => 'Delhi']);
    Department::factory()->create(['company_id' => $company->id, 'code' => 'ENG', 'name' => 'Engineering']);
    Designation::factory()->create(['code' => 'SE', 'name' => 'Software Engineer']);
    $csv = "First Name,Last Name,Joining Date,Company Code,Salutation,Fresher,Personal phone,Personal phone country code,Device user id\n"
        ."Asha,Rao,2026-01-05,ALPHA,Dr.,yes,098450 12345,+91,BIO-7\n"
        ."Vikram,Sen,2026-02-01,ALPHA,Sir,maybe,12,91,BIO-7\n";
    $import = ms4Validate(ms4StageCsv($csv));
    $rows = $import->rows()->orderBy('row_number')->get();
    expect($rows[0]->action)->toBe('create')->and($rows[0]->errors)->toBeNull()
        ->and($rows[1]->action)->toBe('error')
        ->and(implode(' ', $rows[1]->errors))->toContain('Fresher must be yes or no')->toContain('Salutation must be one of')
        ->toContain('Personal phone: ')->toContain("Device user id 'bio-7' also appears in row 1");

    // A device user id another employee already has is refused too.
    $this->employee->update(['device_user_id' => 'GATE-9']);
    $taken = ms4Validate(ms4StageCsv('First Name,Last Name,Joining Date,Company Code,Device user id
Ria,Das,2026-01-05,ALPHA,GATE-9
'));
    expect(implode(' ', $taken->rows()->sole()->errors))->toContain("Device user id 'GATE-9' is already used by employee {$this->employee->employee_code}.");
});

it('creates employees from the import with the new fields, and offers them in the dataset and the print', function () {
    app(FeatureFlags::class)->setForTenant($this->tenant, EmployeeImports::FEATURE, true, platformAdmin(), 'Data migration agreed');
    Company::factory()->create(['code' => 'ALPHA']);
    $csv = "First Name,Last Name,Joining Date,Company Code,Salutation,Fresher,Personal phone,Personal phone country code,Device user id\n"
        ."Asha,Rao,2026-01-05,ALPHA,Dr.,yes,098450 12345,+91,BIO-7\n";
    $import = ms4Validate(ms4StageCsv($csv));
    app(EmployeeImports::class)->run(app(EmployeeImports::class)->approve($import, $this->hr), $this->hr);
    $asha = Employee::query()->with('person')->where('device_user_id', 'BIO-7')->sole();
    expect($asha->person->salutation)->toBe('dr')->and($asha->person->isFresher())->toBeTrue()
        ->and($asha->person->personal_phone)->toBe('+919845012345')->and($asha->person->personal_phone_country_code)->toBe('91');

    // The export carries them back in the same columns.
    $export = app(EmployeeImports::class)->export(Employee::query()->whereKey($asha->id), $this->hr);
    expect($export)->toContain(',dr,1,91,,BIO-7');

    // Dataset: the new fields; the identity document expiry is sensitive and never carries the number.
    ms4Passport($asha, ['expires_on' => '2029-12-31']);
    app(ChangeQualificationAction::class)->add($asha, ['qualification' => 'MBA', 'is_highest' => true], $this->hr);
    $dataset = new EmployeesDataset;
    $row = $dataset->query()->whereKey($asha->id)->first();
    expect($dataset->value('salutation', $row))->toBe('Dr')->and($dataset->value('is_fresher', $row))->toBeTrue()
        ->and($dataset->value('device_user_id', $row))->toBe('BIO-7')->and($dataset->value('highest_qualification', $row))->toBe('MBA')
        ->and($dataset->value('passport_expires_on', $row))->toBe('2029-12-31')
        ->and(array_keys($dataset->fieldsFor(tenantUser($this->tenant, ['employee.view']))))->not->toContain('passport_expires_on')->toContain('salutation')
        ->and(array_keys($dataset->fieldsFor($this->hr)))->toContain('passport_expires_on');

    // The printable profile: salutation, device id, nominations and the masked passport (HR with sensitive access).
    $spouse = ms4Member($asha, 'Kiran');
    app(ChangeNominationsAction::class)->replace($asha, 'pf', [['family_member_id' => $spouse->id, 'share' => 100]], $this->hr);
    $html = $this->get(EmployeeProfilePrintController::url($asha))->assertOk()->getContent();
    expect($html)->toContain('Dr')->toContain('BIO-7')->toContain('Nominations')->toContain('Provident fund')->toContain('••••4567')->not->toContain('Z1234567');
});

it('keeps identity documents and nominations inside the organisation scope and the tenant', function () {
    $department = Department::factory()->create();
    $scoped = tenantUser($this->tenant, ['employee.view', 'employee.update', 'employee.create', 'employee.sensitive.view', 'employee.sensitive.update']);
    UserAccessScope::query()->create(['user_id' => $scoped->id, 'dimension' => 'department', 'scope_id' => $department->id]);
    $spouse = ms4Member($this->employee, 'Kiran');

    expect(fn () => app(ChangeIdentityDocumentAction::class)->add($this->employee, ['kind' => 'passport', 'number' => 'S1234567'], $scoped->fresh()))->toThrow(ProfileChangeRefused::class, 'outside your organisation scope')
        ->and(fn () => app(ChangeNominationsAction::class)->replace($this->employee, 'pf', [['family_member_id' => $spouse->id, 'share' => 100]], $scoped->fresh()))->toThrow(ProfileChangeRefused::class, 'outside your organisation scope')
        ->and(PersonIdentityDocument::query()->count())->toBe(0);

    // Without employee.sensitive.update nobody adds one; without viewSensitive the 360 tab is not offered.
    $plain = tenantUser($this->tenant, ['employee.view', 'employee.update']);
    expect(fn () => app(ChangeIdentityDocumentAction::class)->add($this->employee, ['kind' => 'passport', 'number' => 'S1234567'], $plain))->toThrow(ProfileChangeRefused::class, 'employee.sensitive.update');
    $this->actingAs($plain);
    expect(IdentityDocumentsRelationManager::canViewForRecord($this->employee, ViewEmployee::class))->toBeFalse()
        ->and(NominationsRelationManager::canViewForRecord($this->employee, ViewEmployee::class))->toBeTrue();
    $this->actingAs($this->hr);
    expect(IdentityDocumentsRelationManager::canViewForRecord($this->employee, ViewEmployee::class))->toBeTrue();
    ms4Passport($this->employee);
    Livewire::test(IdentityDocumentsRelationManager::class, ['ownerRecord' => $this->employee, 'pageClass' => ViewEmployee::class])
        ->assertOk()->assertSee('••••4567')->assertDontSee('Z1234567');

    // Another tenant never sees them.
    $other = provisionTenant('Other');
    actAsTenant($other);
    expect(PersonIdentityDocument::query()->count())->toBe(0)->and(PersonNomination::query()->count())->toBe(0)
        ->and(PersonQualification::query()->count())->toBe(0);
});
