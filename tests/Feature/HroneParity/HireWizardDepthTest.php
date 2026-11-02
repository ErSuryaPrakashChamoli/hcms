<?php

use App\Domain\Audit\Models\AuditEvent;
use App\Domain\Configuration\Models\CustomField;
use App\Domain\Configuration\Models\CustomFieldValue;
use App\Domain\Documents\Models\DocumentType;
use App\Domain\Employment\Actions\ChangeBankAccountAction;
use App\Domain\Employment\Actions\ChangeStatutoryIdentityAction;
use App\Domain\Employment\Actions\HireEmployeeAction;
use App\Domain\Employment\Actions\HireWithDetailsAction;
use App\Domain\Employment\Exceptions\ProfileChangeRefused;
use App\Domain\Employment\Models\Employee;
use App\Domain\Employment\Models\EmployeeBankAccount;
use App\Domain\Employment\Models\EmployeePosition;
use App\Domain\Employment\Models\EmployeeStatutoryDetail;
use App\Domain\Employment\Models\ReportingRelationship;
use App\Domain\Employment\Models\WorkforceReason;
use App\Domain\Employment\Services\ReturningEmployees;
use App\Domain\Exit\Models\ExitCase;
use App\Domain\Identity\Enums\UserStatus;
use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Notifications\TemporaryPasswordMail;
use App\Domain\Identity\Services\AccessScopes;
use App\Domain\Identity\Services\RoleGrants;
use App\Domain\Lifecycle\Enums\LifecycleState;
use App\Domain\Lifecycle\Models\EmployeeLifecycleTransition;
use App\Domain\Lifecycle\Models\EmployeeTimelineEntry;
use App\Domain\Offboarding\Support\DataInventory;
use App\Domain\Organisation\Models\Company;
use App\Domain\People\Actions\ChangeAddressAction;
use App\Domain\People\Actions\ChangeExperienceAction;
use App\Domain\People\Actions\ChangeFamilyMemberAction;
use App\Domain\People\Models\Person;
use App\Domain\People\Models\PersonAddress;
use App\Domain\People\Models\PersonExperience;
use App\Domain\People\Models\PersonFamilyMember;
use App\Filament\Resources\Employees\EmployeeResource;
use App\Filament\Resources\Employees\Pages\CreateEmployee;
use App\Support\Tenancy\TenantContext;
use Filament\Facades\Filament;
use Filament\Forms\Components\Repeater;
use Filament\Schemas\Components\Wizard;
use Filament\Schemas\Components\Wizard\Step;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

require_once __DIR__.'/../Workforce/WorkforceTestHelpers.php';

/*
| HROne parity WO-3 (matrix 23 §23.2.3 #2, #6–9, 12, 15–18, 22): the hire wizard's optional depth — identity numbers,
| the returning-employee check, a starting state for historic loads, the seat, functional manager and HR business
| partner, the Additional Details sections through the 360's own actions, "create a sign-in and invite", the completion
| panel — and that a hire filling only the earlier fields creates exactly what it did before.
*/

beforeEach(function () {
    $this->travelTo('2026-10-20 10:00:00');
    $this->tenant = provisionTenant();
    actAsTenant($this->tenant);
    $this->hr = tenantUser($this->tenant, ['*']);
    $this->actingAs($this->hr);
    Filament::getPanel('admin')->boot();
    $this->company = Company::factory()->create();
    $this->manager = wo3Hire('Mohan', 'Lal');
});

function wo3Hire(string $first, string $last = 'Test', ?Company $company = null, array $person = [], array $employment = []): Employee
{
    $company ??= Company::query()->orderBy('id')->first();

    return app(HireEmployeeAction::class)->handle(['first_name' => $first, 'last_name' => $last] + $person, $employment + ['joining_date' => '2025-01-01'], ['company_id' => $company->id]);
}

/** @param  array<string, mixed>  $data */
function wo3Data(array $data = []): array
{
    return $data + ['first_name' => 'Kiran', 'last_name' => 'Das', 'joining_date' => '2026-10-01', 'company_id' => Company::query()->orderBy('id')->value('id'), 'audit_reason' => 'Campus hire'];
}

/** @param  array<string, mixed>  $data */
function wo3Wizard(array $data): Testable
{
    $undo = Repeater::fake();
    $page = Livewire::test(CreateEmployee::class)->fillForm(wo3Data($data))->call('create');
    $undo();

    return $page;
}

/** @return list<string> the wizard's steps the signed-in person sees for what is filled in */
function wo3Steps(Testable $page): array
{
    /** @var Wizard $wizard */
    $wizard = $page->instance()->form->getComponents()[0];

    return array_values(array_map(fn (Step $step) => $step->getLabel(), $wizard->getChildSchema()->getComponents()));
}

function wo3Mark(): int
{
    return (int) (AuditEvent::query()->max('id') ?? 0);
}

/** @return list<string> "ACTION Model" for every audit event after the mark (optionally only these models) */
function wo3AuditSince(int $mark, array $models = []): array
{
    return AuditEvent::query()->where('id', '>', $mark)->orderBy('id')->get()
        ->filter(fn (AuditEvent $e) => $models === [] || in_array($e->entity_type, $models, true))
        ->map(fn (AuditEvent $e) => $e->action->value.' '.class_basename((string) $e->entity_type))->values()->all();
}

/** What a hire created for an employee, without names or ids. */
function wo3Records(Employee $employee): array
{
    $employee->refresh();

    return [
        'state' => $employee->lifecycle_state->value,
        'probation_end' => $employee->probation_end_date?->toDateString(),
        'transitions' => EmployeeLifecycleTransition::query()->where('employee_id', $employee->id)->orderBy('id')->toBase()->pluck('to_state')->all(),
        'timeline' => EmployeeTimelineEntry::query()->where('employee_id', $employee->id)->orderBy('id')->pluck('title')->all(),
        'positions' => EmployeePosition::query()->where('employee_id', $employee->id)->count(),
        'lines' => ReportingRelationship::query()->where('employee_id', $employee->id)->orderBy('id')->pluck('type')->all(),
        'statutory' => EmployeeStatutoryDetail::query()->where('employee_id', $employee->id)->count(),
        'addresses' => PersonAddress::query()->where('person_id', $employee->person_id)->count(),
        'family' => PersonFamilyMember::query()->where('person_id', $employee->person_id)->count(),
        'experiences' => PersonExperience::query()->where('person_id', $employee->person_id)->count(),
        'bank' => EmployeeBankAccount::query()->where('employee_id', $employee->id)->count(),
        'custom' => CustomFieldValue::query()->where('model_id', $employee->id)->count(),
        'user' => $employee->user_id,
    ];
}

/** An employee who has left, with a PAN (and optionally an Aadhaar reference) on file and a completed exit. */
function wo3Leaver(string $first, string $pan, bool $eligible, ?Company $company = null, array $person = [], ?string $aadhaar = null): Employee
{
    $employee = wo3Hire($first, 'Kumar', $company, $person);
    app(ChangeStatutoryIdentityAction::class)->handle($employee, array_filter(['pan' => $pan, 'aadhaar_reference' => $aadhaar]), auth()->user(), 'On file');
    $block = $eligible ? null : WorkforceReason::query()->create(['kind' => 'blacklist', 'name' => 'Policy breach '.$first, 'status' => 'active']);
    $separation = WorkforceReason::query()->create(['kind' => 'separation', 'name' => 'Better prospects '.$first, 'status' => 'active']);
    ExitCase::query()->create(['number' => 'EXIT-'.$first, 'employee_id' => $employee->id, 'type' => 'resignation', 'status' => 'completed', 'initiated_on' => '2026-05-01',
        'last_working_day' => '2026-06-30', 'is_rehire_eligible' => $eligible, 'rehire_block_reason_id' => $block?->id, 'workforce_reason_id' => $separation->id]);

    return forceLifecycle($employee, LifecycleState::Exited, ['exit_date' => '2026-06-30']);
}

it('creates exactly what it created before when only the earlier fields are filled', function () {
    $mark = wo3Mark();
    $direct = app(HireEmployeeAction::class)->handle(['first_name' => 'Asha', 'last_name' => 'Rao'], ['joining_date' => '2026-10-01', 'work_email' => 'asha@acme.test'],
        ['company_id' => $this->company->id], $this->manager->id, 'Campus hire');
    $directAudit = wo3AuditSince($mark);

    $mark = wo3Mark();
    $page = wo3Wizard(['first_name' => 'Bina', 'last_name' => 'Das', 'work_email' => 'bina@acme.test', 'manager_id' => $this->manager->id])->assertHasNoFormErrors();
    $wizard = Employee::query()->where('work_email', 'bina@acme.test')->sole();

    expect(wo3Records($wizard))->toBe(wo3Records($direct))
        ->and(wo3Records($wizard)['transitions'])->toBe(['joined', 'probation'])
        ->and(wo3AuditSince($mark))->toBe($directAudit)
        ->and(AuditEvent::query()->where('action', 'REVIEWED')->exists())->toBeFalse()
        ->and(User::query()->where('email', 'bina@acme.test')->exists())->toBeFalse();
    $page->assertRedirect(CreateEmployee::getUrl(['hired' => $wizard->id]));

    // A future joining date still starts in pre-boarding, as before.
    wo3Wizard(['first_name' => 'Chitra', 'last_name' => 'Sen', 'joining_date' => '2026-11-15', 'work_email' => 'chitra@acme.test'])->assertHasNoFormErrors();
    expect(Employee::query()->where('work_email', 'chitra@acme.test')->sole()->lifecycle_state)->toBe(LifecycleState::Preboarding);
});

it('keeps the MS-4 person fields in the wizard: salutation, phone country codes and the device user id (#2, #6, #9)', function () {
    wo3Wizard(['first_name' => 'Divya', 'last_name' => 'Iyer', 'salutation' => 'dr', 'personal_phone_country_code' => '91', 'personal_phone' => '98765 43210',
        'work_email' => 'divya@acme.test', 'work_phone_country_code' => '91', 'work_phone' => '022 2345 6789', 'device_user_id' => 'BIO-77'])->assertHasNoFormErrors();

    $divya = Employee::query()->with('person')->where('work_email', 'divya@acme.test')->sole();
    expect($divya->person->salutation)->toBe('dr')
        ->and($divya->person->personal_phone)->toBe('+919876543210')
        ->and($divya->work_phone)->toBe('+912223456789')
        ->and($divya->device_user_id)->toBe('BIO-77');
});

it('records PAN and Aadhaar reference at hire through the statutory action: encrypted, hashed for matching, never audited in clear (#7)', function () {
    $mark = wo3Mark();
    wo3Wizard(['work_email' => 'kiran@acme.test', 'pan' => 'ABCDE1234F', 'aadhaar_reference' => '1234 5678 9012'])->assertHasNoFormErrors();
    $kiran = Employee::query()->where('work_email', 'kiran@acme.test')->sole();
    $detail = EmployeeStatutoryDetail::query()->where('employee_id', $kiran->id)->sole();
    $raw = DB::table('employee_statutory_details')->where('id', $detail->id)->first();

    expect($detail->pan)->toBe('ABCDE1234F')
        ->and($detail->aadhaar_reference)->toBe('1234 5678 9012')
        ->and($raw->pan)->not->toContain('ABCDE1234F')
        ->and($raw->pan_hash)->toBe(EmployeeStatutoryDetail::panHash('abcde 1234f'))
        ->and($raw->aadhaar_hash)->toBe(EmployeeStatutoryDetail::aadhaarHash('1234-5678-9012'))
        ->and($detail->toArray())->not->toHaveKey('pan_hash')->not->toHaveKey('aadhaar_hash')
        ->and((bool) $detail->pf_applicable)->toBeTrue()
        ->and(DataInventory::EXCLUDED_COLUMNS['employee_statutory_details'])->toBe(['pan_hash', 'aadhaar_hash'])
        ->and(EmployeeTimelineEntry::query()->where('employee_id', $kiran->id)->pluck('title')->all())->toContain('Statutory identifiers added');
    $event = AuditEvent::query()->where('entity_type', EmployeeStatutoryDetail::class)->where('entity_id', (string) $detail->id)->with('fieldChanges')->sole();
    $pan = $event->fieldChanges->firstWhere('field', 'pan');
    expect($event->fieldChanges->pluck('field')->all())->not->toContain('pan_hash')->not->toContain('aadhaar_hash')
        ->and((bool) $pan->is_sensitive)->toBeTrue()
        ->and((string) $pan->after)->not->toContain('ABCDE1234F');

    // The same as "Edit statutory details" on the 360 for another employee.
    $wizardAudit = wo3AuditSince($mark, [EmployeeStatutoryDetail::class]);
    $twin = wo3Hire('Twin', 'Path');
    $mark = wo3Mark();
    app(ChangeStatutoryIdentityAction::class)->handle($twin, ['pan' => 'PQRSX6789K', 'aadhaar_reference' => '1111 2222 3333'], $this->hr, 'Campus hire');
    expect(wo3AuditSince($mark, [EmployeeStatutoryDetail::class]))->toBe($wizardAudit);

    // A changed or cleared identifier keeps its hash in step; a short Aadhaar reference gets none (never compared).
    app(ChangeStatutoryIdentityAction::class)->handle($twin, ['pan' => 'PQRSX6789L', 'aadhaar_reference' => '3333'], $this->hr, 'Corrected');
    $twinRaw = DB::table('employee_statutory_details')->where('employee_id', $twin->id)->first();
    expect($twinRaw->pan_hash)->toBe(EmployeeStatutoryDetail::panHash('PQRSX6789L'))->and($twinRaw->aadhaar_hash)->toBeNull();

    // The 360's rules apply: the PAN format, and CA-4 (a PAN card required with the PAN cannot be on file for a new hire).
    wo3Wizard(['first_name' => 'Bad', 'pan' => 'ABC123'])->assertHasFormErrors(['pan']);
    $type = DocumentType::query()->where('code', 'PAN')->first() ?? DocumentType::query()->create(['code' => 'PAN', 'name' => 'PAN card', 'category' => 'identity']);
    $type->update(['linked_field' => 'statutory.pan', 'required_with_field' => true]);
    wo3Wizard(['first_name' => 'Linked', 'pan' => 'LMNOP1234Q'])->assertHasFormErrors(['pan']);
    expect(Person::query()->whereIn('first_name', ['Bad', 'Linked'])->exists())->toBeFalse();
});

it('backfills the lookup hashes of identifiers recorded before the migration', function () {
    $earlier = wo3Hire('Earlier', 'Record');
    app(ChangeStatutoryIdentityAction::class)->handle($earlier, ['pan' => 'BCDEF2345G', 'aadhaar_reference' => '2222 3333 4444', 'uan' => '100200300400'], $this->hr, 'On file');
    $short = wo3Hire('Short', 'Reference');
    app(ChangeStatutoryIdentityAction::class)->handle($short, ['aadhaar_reference' => '4444'], $this->hr, 'On file');
    DB::table('employee_statutory_details')->update(['pan_hash' => null, 'aadhaar_hash' => null]);

    $migration = require database_path('migrations/2027_01_01_200001_add_identifier_hashes_to_employee_statutory_details.php');
    $migration->down();
    $migration->up();

    $rows = DB::table('employee_statutory_details')->get()->keyBy('employee_id');
    expect($rows[$earlier->id]->pan_hash)->toBe(EmployeeStatutoryDetail::panHash('BCDEF2345G'))
        ->and($rows[$earlier->id]->aadhaar_hash)->toBe(EmployeeStatutoryDetail::aadhaarHash('222233334444'))
        ->and($rows[$short->id]->pan_hash)->toBeNull()
        ->and($rows[$short->id]->aadhaar_hash)->toBeNull()
        ->and(app(ReturningEmployees::class)->matches($this->hr, [], [], ['pan' => 'BCDEF2345G'])[0]['employee_id'] ?? null)->toBe($earlier->id);
});

it('offers the identity, bank, starting-state and sign-in parts only to people with those rights', function () {
    $clerk = tenantUser($this->tenant, ['employee.view', 'employee.create', 'employee.update', 'employee.position']);
    $this->actingAs($clerk);
    $page = Livewire::test(CreateEmployee::class)->fillForm(wo3Data());

    expect(wo3Steps($page))->toBe(['Person', 'Employment', 'Position', 'Manager', 'Address', 'Family', 'Previous employment']);
    $page->assertFormFieldHidden('start_state')->assertFormFieldHidden('position_id');

    // Forged values for hidden parts record nothing.
    wo3Wizard(['work_email' => 'forged@acme.test', 'pan' => 'ABCDE1234F', 'start_state' => 'confirmed', 'confirmation_date' => '2026-10-02', 'add_bank' => true,
        'bank' => ['account_holder_name' => 'X', 'bank_name' => 'Y', 'account_number' => '123456789'], 'invite_user' => true])->assertHasNoFormErrors();
    $forged = Employee::query()->where('work_email', 'forged@acme.test')->sole();
    expect(wo3Records($forged))->toMatchArray(['state' => 'probation', 'statutory' => 0, 'bank' => 0, 'user' => null]);

    // The action refuses the parts the actor has no right to, whatever the screen sent.
    $action = app(HireWithDetailsAction::class);
    $hire = fn (array $details) => $action->handle($clerk, ['first_name' => 'Direct', 'last_name' => 'Call'], ['joining_date' => '2026-10-01'], ['company_id' => $this->company->id], null, 'r', $details);
    expect(fn () => $hire(['start_state' => 'confirmed', 'confirmation_date' => '2026-10-01']))->toThrow(ProfileChangeRefused::class, 'employee.lifecycle')
        ->and(fn () => $hire(['statutory' => ['pan' => 'ABCDE1234F']]))->toThrow(ProfileChangeRefused::class, 'employee.sensitive.update')
        ->and(fn () => $hire(['invite' => ['roles' => []]]))->toThrow(ProfileChangeRefused::class, 'user.create')
        ->and(Person::query()->where('first_name', 'Direct')->exists())->toBeFalse();
});

it('finds a returning employee on PAN, shows the earlier employment and exit, and asks a confirmation with a reason when not eligible (#8)', function () {
    $ravi = wo3Leaver('Ravi', 'ABCDE1234F', eligible: false);
    $before = Employee::query()->count();

    $undo = Repeater::fake();
    $page = Livewire::test(CreateEmployee::class)->fillForm(wo3Data(['first_name' => 'Ravi', 'last_name' => 'K', 'work_email' => 'ravi.k@acme.test', 'pan' => 'abcde1234f']));
    expect(wo3Steps($page))->toContain('Returning employee');
    $page->assertSee('Ravi Kumar ('.$ravi->employee_code.')')
        ->assertSee('same PAN')
        ->assertSee('last working day 2026-06-30')
        ->assertSee('separation reason: Better prospects Ravi')
        ->assertSee('NOT eligible for rehire (Policy breach Ravi)')
        ->assertSee('Rehire / reactivate');

    $page->fillForm(['pan' => 'ABCDE1234F'])->call('create')->assertHasFormErrors(['returning_confirmed', 'returning_reason']);
    expect(Employee::query()->count())->toBe($before);

    $page->fillForm(['returning_confirmed' => true, 'returning_reason' => 'Cleared by the CHRO after review'])->call('create')->assertHasNoFormErrors();
    $undo();
    $new = Employee::query()->where('work_email', 'ravi.k@acme.test')->sole();
    $audit = AuditEvent::query()->where('entity_type', Employee::class)->where('entity_id', (string) $new->id)->where('action', 'REVIEWED')->sole();
    expect($audit->reason)->toContain('Cleared by the CHRO after review')
        ->and($audit->metadata['check'])->toBe('returning_employee')
        ->and($audit->metadata['not_eligible_employee_ids'])->toBe([$ravi->id])
        ->and($audit->metadata['matched_on'])->toBe(['PAN'])
        ->and(json_encode($audit->getAttributes()))->not->toContain('ABCDE1234F');

    // The action enforces it whatever the screen sends.
    expect(fn () => app(HireWithDetailsAction::class)->handle($this->hr, ['first_name' => 'Another', 'last_name' => 'Try'], ['joining_date' => '2026-10-01'],
        ['company_id' => $this->company->id], null, 'r', ['statutory' => ['pan' => 'ABCDE 1234 F']]))->toThrow(ProfileChangeRefused::class, 'may not be rehired');

    // An eligible earlier employee is shown for information only: no confirmation is asked.
    wo3Leaver('Sunil', 'FGHIJ5678K', eligible: true);
    $sunil = Livewire::test(CreateEmployee::class)->fillForm(wo3Data(['first_name' => 'Sunil', 'work_email' => 'sunil.new@acme.test', 'pan' => 'FGHIJ5678K']));
    $sunil->assertSee('eligible for rehire')->assertFormFieldHidden('returning_reason');
    $sunil->call('create')->assertHasNoFormErrors();
    expect(Employee::query()->where('work_email', 'sunil.new@acme.test')->exists())->toBeTrue();
});

it('matches on the earlier PersonMatcher criteria and on a full Aadhaar reference, never on a short one', function () {
    $dob = ['date_of_birth' => '1990-05-05'];
    $asha = wo3Leaver('Asha', 'KLMNO1234P', eligible: false, person: $dob, aadhaar: '9876 5432 1098');
    $returning = app(ReturningEmployees::class);

    $byName = $returning->matches($this->hr, ['first_name' => 'asha', 'last_name' => 'KUMAR', 'date_of_birth' => '1990-05-05']);
    expect($byName)->toHaveCount(1)->and($byName[0]['employee_id'])->toBe($asha->id)->and($byName[0]['matched_on'])->toBe(['name and date of birth'])
        ->and($returning->needsConfirmation($byName))->toBeTrue();

    expect($returning->matches($this->hr, ['first_name' => 'X'], [], ['aadhaar_reference' => '987654321098'])[0]['matched_on'] ?? null)->toBe(['Aadhaar reference'])
        ->and($returning->matches($this->hr, ['first_name' => 'X'], [], ['aadhaar_reference' => '1098']))->toBe([]);

    // Through the wizard the name + date of birth match also needs the confirmation.
    wo3Wizard(['first_name' => 'Asha', 'last_name' => 'Kumar', 'date_of_birth' => '1990-05-05', 'work_email' => 'asha.again@acme.test'])->assertHasFormErrors(['returning_reason']);
    wo3Wizard(['first_name' => 'Asha', 'last_name' => 'Kumar', 'date_of_birth' => '1990-05-05', 'work_email' => 'asha.again@acme.test', 'returning_confirmed' => true, 'returning_reason' => 'Different person: checked the papers'])
        ->assertHasNoFormErrors();
    expect(Employee::query()->where('work_email', 'asha.again@acme.test')->exists())->toBeTrue();

    // A current employee with the same PAN is shown (currently employed) but needs no confirmation.
    $current = wo3Hire('Neha', 'Current');
    app(ChangeStatutoryIdentityAction::class)->handle($current, ['pan' => 'QRSTU1234V'], $this->hr, 'On file');
    $rows = $returning->matches($this->hr, [], [], ['pan' => 'QRSTU1234V']);
    expect($rows[0]['currently_employed'])->toBeTrue()->and($rows[0]['status'])->toBe('Probation')->and($returning->needsConfirmation($rows))->toBeFalse();
});

it('never reveals more of a returning match than the person may see (PRV-1, exit details, access scope, tenant)', function () {
    $ravi = wo3Leaver('Ravi', 'ABCDE1234F', eligible: false, person: ['date_of_birth' => '1991-01-01']);
    $returning = app(ReturningEmployees::class);

    // No employee.sensitive.view: an identifier-only match names nobody, but still needs the confirmation.
    $clerk = tenantUser($this->tenant, ['employee.view', 'employee.create', 'employee.sensitive.update']);
    $rows = $returning->matches($clerk, ['first_name' => 'Someone', 'last_name' => 'Else'], [], ['pan' => 'ABCDE1234F']);
    expect($rows)->toHaveCount(1)
        ->and($rows[0])->toMatchArray(['visible' => false, 'employee_id' => null, 'code' => null, 'status' => null, 'left_on' => null, 'rehire' => null, 'block_reason' => null, 'matched_on' => ['PAN'], 'not_eligible' => true])
        ->and(ReturningEmployees::describe($rows[0]))->not->toContain('Ravi')->not->toContain($ravi->employee_code)->not->toContain('Policy breach');
    $this->actingAs($clerk);
    Livewire::test(CreateEmployee::class)->fillForm(wo3Data(['first_name' => 'Someone', 'last_name' => 'Else', 'pan' => 'ABCDE1234F']))
        ->assertSee('A person on record whose details you may not see')->assertDontSee($ravi->employee_code)->assertDontSee('Policy breach Ravi');

    // Matched on name + date of birth as well: named, but only for that (the PAN match is not shown).
    $both = $returning->matches($clerk, ['first_name' => 'Ravi', 'last_name' => 'Kumar', 'date_of_birth' => '1991-01-01'], [], ['pan' => 'ABCDE1234F']);
    expect($both[0]['name'])->toBe('Ravi Kumar')->and($both[0]['matched_on'])->toBe(['name and date of birth']);

    // employee.sensitive.view without exit.view: named, but no exit reasons or blacklist reason — still a confirmation.
    $viewer = tenantUser($this->tenant, ['employee.view', 'employee.create', 'employee.sensitive.view', 'employee.sensitive.update']);
    $seen = $returning->matches($viewer, [], [], ['pan' => 'ABCDE1234F'])[0];
    expect($seen)->toMatchArray(['visible' => true, 'name' => 'Ravi Kumar', 'code' => $ravi->employee_code, 'separation_reason' => null, 'rehire' => null, 'block_reason' => null, 'not_eligible' => true])
        ->and(ReturningEmployees::describe($seen))->toContain('needs a confirmation before rehire')->not->toContain('Policy breach');

    // Outside the organisation scope: a person on record, whatever the rights.
    $other = Company::factory()->create();
    $scoped = tenantUser($this->tenant, ['*']);
    app(AccessScopes::class)->assign($scoped, ['company' => [$other->id]]);
    expect($returning->matches($scoped->fresh(), [], [], ['pan' => 'ABCDE1234F'])[0])->toMatchArray(['visible' => false, 'employee_id' => null, 'not_eligible' => true]);

    // Another organisation's employee with the same PAN is never a match.
    $tenantB = provisionTenant('Beta');
    app(TenantContext::class)->runAs($tenantB, function () use ($tenantB) {
        $this->actingAs(tenantUser($tenantB, ['*']));
        wo3Leaver('Bharat', 'ZYXWV9876U', eligible: false, company: Company::factory()->create());
    });
    $this->actingAs($this->hr);
    actAsTenant($this->tenant);
    expect($returning->matches($this->hr, [], [], ['pan' => 'ZYXWV9876U']))->toBe([]);
});

it('loads a historic employee as already confirmed, or active without probation, through the lifecycle engine (#12)', function () {
    wo3Wizard(['first_name' => 'Old', 'last_name' => 'Timer', 'joining_date' => '2019-04-01', 'start_state' => 'confirmed', 'confirmation_date' => '2019-10-01', 'work_email' => 'old@acme.test'])
        ->assertHasNoFormErrors();
    $old = Employee::query()->where('work_email', 'old@acme.test')->sole();
    expect($old->lifecycle_state)->toBe(LifecycleState::Confirmed)
        ->and($old->confirmation_date->toDateString())->toBe('2019-10-01')
        ->and($old->probation_end_date->toDateString())->toBe('2019-10-01')
        ->and(wo3Records($old)['transitions'])->toBe(['joined', 'probation', 'confirmed'])
        ->and(EmployeeLifecycleTransition::query()->where('employee_id', $old->id)->orderBy('id')->get()->map(fn ($t) => $t->effective_date->toDateString())->all())
        ->toBe(['2019-04-01', '2019-04-01', '2019-10-01']);

    wo3Wizard(['first_name' => 'No', 'last_name' => 'Probation', 'joining_date' => '2020-01-06', 'start_state' => 'active', 'work_email' => 'np@acme.test'])->assertHasNoFormErrors();
    $active = Employee::query()->where('work_email', 'np@acme.test')->sole();
    expect($active->lifecycle_state)->toBe(LifecycleState::Active)->and($active->probation_end_date)->toBeNull()->and(wo3Records($active)['transitions'])->toBe(['joined', 'active']);

    // Refused: a future joining date, a confirmation before joining or in the future, a state with its own records.
    wo3Wizard(['first_name' => 'Future', 'joining_date' => '2026-12-01', 'start_state' => 'active'])->assertHasFormErrors(['start_state']);
    wo3Wizard(['first_name' => 'Early', 'joining_date' => '2020-01-01', 'start_state' => 'confirmed', 'confirmation_date' => '2019-12-01'])->assertHasFormErrors(['confirmation_date']);
    wo3Wizard(['first_name' => 'Undated', 'joining_date' => '2020-01-01', 'start_state' => 'confirmed'])->assertHasFormErrors(['confirmation_date']);
    $hire = app(HireEmployeeAction::class);
    expect(fn () => $hire->handle(['first_name' => 'A', 'last_name' => 'B'], ['joining_date' => '2020-01-01'], ['company_id' => $this->company->id], startState: LifecycleState::NoticePeriod))
        ->toThrow(InvalidArgumentException::class, 'has its own steps')
        ->and(fn () => $hire->handle(['first_name' => 'A', 'last_name' => 'B'], ['joining_date' => '2020-01-01'], ['company_id' => $this->company->id], startState: LifecycleState::Confirmed, confirmationDate: '2026-12-01'))
        ->toThrow(InvalidArgumentException::class, 'not in the future')
        ->and(Person::query()->whereIn('first_name', ['Future', 'Early', 'Undated', 'A'])->exists())->toBeFalse();
});

it('seats the hire in an open position and adds the functional manager and HR business partner from the joining date (#15, #16)', function () {
    $org = workforceOrg();
    $planner = tenantUser($this->tenant, ['workforce.view', 'workforce.plan', 'workforce.manage']);
    $approver = tenantUser($this->tenant, ['workforce.view', 'workforce.approve', 'workforce.manage']);
    $seat = openPosition(['code' => 'ENG-1', 'title' => 'Engineer', 'organisation_node_id' => $org['department']->id], $planner, $approver, '2026-01-01');
    $functional = wo3Hire('Farah', 'Functional');
    $partner = wo3Hire('Hari', 'Partner');

    $page = Livewire::test(CreateEmployee::class)->fillForm(wo3Data());
    $page->assertFormFieldVisible('position_id');
    wo3Wizard(['work_email' => 'seated@acme.test', 'position_id' => $seat->id, 'manager_id' => $this->manager->id, 'functional_manager_id' => $functional->id, 'hrbp_id' => $partner->id])
        ->assertHasNoFormErrors();
    $seated = Employee::query()->where('work_email', 'seated@acme.test')->sole();
    expect($seated->currentPosition->position_id)->toBe($seat->id)
        ->and($seated->currentPosition->department_id)->toBe($org['department']->nodeable_id)
        ->and(ReportingRelationship::query()->where('employee_id', $seated->id)->orderBy('id')->get()->map(fn ($r) => [$r->type, $r->manager_id, $r->effective_from->toDateString(), (bool) $r->is_primary])->all())
        ->toBe([['line', $this->manager->id, '2026-10-01', true], ['functional', $functional->id, '2026-10-01', false], ['hrbp', $partner->id, '2026-10-01', false]])
        ->and(EmployeeTimelineEntry::query()->where('employee_id', $seated->id)->pluck('title')->all())->toContain('Functional manager assigned', 'HR business partner assigned');

    // The seat is full now: the second hire is refused and leaves nothing behind.
    $people = Person::query()->count();
    wo3Wizard(['first_name' => 'Second', 'work_email' => 'second@acme.test', 'position_id' => $seat->id])->assertHasNoFormErrors();
    expect(Employee::query()->where('work_email', 'second@acme.test')->exists())->toBeFalse()->and(Person::query()->count())->toBe($people);
});

it('records each optional section through the same action as the Employee 360 (#17)', function () {
    CustomField::query()->create(['entity' => 'employee', 'key' => 'tshirt', 'label' => 'T-shirt size', 'type' => 'text', 'status' => 'active']);
    $address = ['type' => 'current', 'country_code' => 'IN', 'address_line_1' => '12 MG Road', 'city' => 'Pune', 'state_code' => 'MH', 'postal_code' => '411001'];
    $member = ['name' => 'Meera', 'relation' => 'spouse', 'gender' => 'female', 'is_dependent' => true, 'is_nominee' => false, 'in_school' => false, 'in_hostel' => false];
    $bank = ['account_holder_name' => 'Kiran Das', 'bank_name' => 'HDFC Bank', 'ifsc' => 'HDFC0001234', 'account_number' => '50100123456789', 'account_type' => 'salary', 'is_primary' => true];
    $job = ['employer' => 'Globex', 'designation' => 'Analyst', 'from_date' => '2020-01-01', 'to_date' => '2023-12-31', 'is_verified' => false];
    $sections = [PersonAddress::class, PersonFamilyMember::class, EmployeeBankAccount::class, PersonExperience::class, CustomFieldValue::class];

    // The 360 way, on a twin.
    $twin = wo3Hire('Twin', 'Path');
    $mark = wo3Mark();
    app(ChangeAddressAction::class)->add($twin, $address, $this->hr);
    app(ChangeFamilyMemberAction::class)->add($twin, $member, $this->hr);
    app(ChangeBankAccountAction::class)->add($twin, $bank, $this->hr);
    app(ChangeExperienceAction::class)->add($twin, $job, $this->hr);
    $twin->setCustomFields(['tshirt' => 'M']);
    $via360 = wo3AuditSince($mark, $sections);

    $mark = wo3Mark();
    wo3Wizard(['work_email' => 'kiran@acme.test', 'addresses' => [$address], 'family' => [$member], 'add_bank' => true, 'bank' => $bank, 'experiences' => [$job],
        'fill_custom_fields' => true, 'hire_custom_fields' => ['tshirt' => 'M']])->assertHasNoFormErrors();
    $kiran = Employee::query()->where('work_email', 'kiran@acme.test')->sole();
    $account = EmployeeBankAccount::query()->where('employee_id', $kiran->id)->sole();

    expect(wo3AuditSince($mark, $sections))->toBe($via360)
        ->and(wo3Records($kiran))->toMatchArray(['addresses' => 1, 'family' => 1, 'bank' => 1, 'experiences' => 1, 'custom' => 1])
        ->and(PersonAddress::query()->where('person_id', $kiran->person_id)->sole()->only(array_keys($address)))->toEqual(PersonAddress::query()->where('person_id', $twin->person_id)->sole()->only(array_keys($address)))
        ->and(PersonFamilyMember::query()->where('person_id', $kiran->person_id)->sole()->only(['name', 'relation', 'is_dependent']))->toEqual(['name' => 'Meera', 'relation' => 'spouse', 'is_dependent' => true])
        ->and($account->account_number)->toBe('50100123456789')
        ->and($account->account_number_last4)->toBe('6789')
        ->and(DB::table('employee_bank_accounts')->where('id', $account->id)->value('account_number'))->not->toContain('50100123456789')
        ->and($kiran->fresh()->customField('tshirt'))->toBe('M')
        ->and(EmployeeTimelineEntry::query()->where('employee_id', $kiran->id)->pluck('title')->all())
        ->toContain('Address added', 'Family member added', 'Bank account added', 'Previous employment added');

    // A fresher has no previous employment to record; the step says so.
    wo3Wizard(['first_name' => 'Fresh', 'work_email' => 'fresh@acme.test', 'is_fresher' => true, 'experiences' => [$job]])->assertHasNoFormErrors();
    expect(wo3Records(Employee::query()->where('work_email', 'fresh@acme.test')->sole())['experiences'])->toBe(0);

    // A refusal by any part leaves nothing behind (one transaction).
    $people = Person::query()->count();
    expect(fn () => app(HireWithDetailsAction::class)->handle($this->hr, ['first_name' => 'Roll', 'last_name' => 'Back'], ['joining_date' => '2026-10-01'],
        ['company_id' => $this->company->id], null, 'r', ['addresses' => [$address], 'experiences' => [['employer' => 'Future Co', 'from_date' => '2027-01-01']]]))
        ->toThrow(ProfileChangeRefused::class)
        ->and(Person::query()->count())->toBe($people)
        ->and(PersonAddress::query()->count())->toBe(2);
});

it('creates a sign-in and sends the invitation only when asked, through WO-16 (#18)', function () {
    Notification::fake();
    $page = Livewire::test(CreateEmployee::class);
    $page->assertFormSet(['invite_user' => false]);

    wo3Wizard(['first_name' => 'Nina', 'work_email' => 'nina@acme.test'])->assertHasNoFormErrors();
    expect(Employee::query()->where('work_email', 'nina@acme.test')->sole()->user_id)->toBeNull()
        ->and(User::query()->where('email', 'nina@acme.test')->exists())->toBeFalse();
    Notification::assertSentTimes(TemporaryPasswordMail::class, 0);

    wo3Wizard(['first_name' => 'Omar', 'work_email' => 'Omar@Acme.test', 'invite_user' => true])->assertHasNoFormErrors();
    $omar = Employee::query()->where('work_email', 'Omar@Acme.test')->sole();
    $user = User::query()->findOrFail($omar->user_id);
    expect($user->email)->toBe('omar@acme.test')
        ->and($user->status)->toBe(UserStatus::Invited)
        ->and($user->roleSlugs())->toBe(['employee'])
        ->and(AuditEvent::query()->where('action', 'TEMPORARY_PASSWORD_ISSUED')->where('entity_id', (string) $user->id)->sole()->reason)->toBe('Hire wizard invitation');
    Notification::assertSentTo($user, TemporaryPasswordMail::class);

    // Checked before the hire: a work e-mail is needed and must be free.
    wo3Wizard(['first_name' => 'Nomail', 'invite_user' => true])->assertHasFormErrors(['invite_user']);
    wo3Wizard(['first_name' => 'Taken', 'work_email' => $this->hr->email, 'invite_user' => true])->assertHasFormErrors(['invite_user']);
    expect(Person::query()->whereIn('first_name', ['Nomail', 'Taken'])->exists())->toBeFalse();

    // Roles above the RoleGrants ceiling are refused before anything is created.
    $inviter = tenantUser($this->tenant, ['employee.view', 'employee.create', 'user.create', 'user.assign_roles']);
    $admin = Role::query()->where('slug', RoleGrants::SUPER_ADMIN)->sole();
    expect(fn () => app(HireWithDetailsAction::class)->handle($inviter, ['first_name' => 'Ceiling', 'last_name' => 'Test'], ['joining_date' => '2026-10-01', 'work_email' => 'ceiling@acme.test'],
        ['company_id' => $this->company->id], null, 'r', ['invite' => ['roles' => [$admin->id]]]))->toThrow(RuntimeException::class, 'Tenant Super Admin')
        ->and(Person::query()->where('first_name', 'Ceiling')->exists())->toBeFalse();

    // A refusal by the invitation itself (after the hire's commit) keeps the hire and reports it.
    $outcome = app(HireWithDetailsAction::class)->handle($this->hr, ['first_name' => 'Late', 'last_name' => 'Refusal'], ['joining_date' => '2026-10-01', 'work_email' => 'not-an-email'],
        ['company_id' => $this->company->id], null, 'r', ['invite' => ['roles' => []]]);
    expect($outcome['invitation'])->toBeNull()->and($outcome['invitation_refused'])->toBe('No work e-mail on file.')->and($outcome['employee']->exists)->toBeTrue();
});

it('shows what the hire created, what was skipped and the next steps, once (#22)', function () {
    $page = wo3Wizard(['work_email' => 'panel@acme.test', 'pan' => 'ABCDE1234F', 'addresses' => [['type' => 'current', 'country_code' => 'IN', 'address_line_1' => '1 Main Road']]]);
    $hired = Employee::query()->where('work_email', 'panel@acme.test')->sole();
    $page->assertHasNoFormErrors()->assertRedirect(CreateEmployee::getUrl(['hired' => $hired->id]));

    Livewire::withQueryParams(['hired' => $hired->id])->test(CreateEmployee::class)
        ->assertSee('Employee hired')
        ->assertSee('Employee '.$hired->employee_code)
        ->assertSee('Statutory identifiers')
        ->assertSee('Left for later')
        ->assertSee('Bank account')
        ->assertSee('Open the Employee 360')
        ->assertSee('Add another employee')
        ->{$hired->onboardingPlan()->where('status', 'in_progress')->exists() ? 'assertDontSee' : 'assertSee'}('Start onboarding')
        ->assertSee('Complete employee details')
        ->assertSee(EmployeeResource::getUrl('view', ['record' => $hired]), false);

    // Shown once: opening the link again (or a forged id) gives the wizard.
    Livewire::withQueryParams(['hired' => $hired->id])->test(CreateEmployee::class)->assertDontSee('Left for later')->assertSee('Hire employee');
    Livewire::withQueryParams(['hired' => $this->manager->id])->test(CreateEmployee::class)->assertDontSee('Left for later');
});

it('keeps the access scope: managers, seats and earlier employees outside it are not reachable', function () {
    $other = Company::factory()->create();
    $outside = wo3Hire('Outside', 'Manager', $other);
    $scoped = tenantUser($this->tenant, ['*']);
    app(AccessScopes::class)->assign($scoped, ['company' => [$this->company->id]]);
    $scoped = $scoped->fresh();
    $this->actingAs($scoped);

    expect(CreateEmployee::managerOptions())->not->toHaveKey($outside->id)->toHaveKey($this->manager->id);
    expect(fn () => app(HireWithDetailsAction::class)->handle($scoped, ['first_name' => 'In', 'last_name' => 'Scope'], ['joining_date' => '2026-10-01'],
        ['company_id' => $this->company->id], null, 'r', ['functional_manager_id' => $outside->id]))->toThrow(ProfileChangeRefused::class, 'not in your access')
        ->and(Person::query()->where('first_name', 'In')->exists())->toBeFalse();

    // Within scope the hire goes through with the details.
    wo3Wizard(['first_name' => 'Inside', 'work_email' => 'inside@acme.test', 'functional_manager_id' => $this->manager->id, 'pan' => 'MNOPQ1234R'])->assertHasNoFormErrors();
    expect(wo3Records(Employee::query()->where('work_email', 'inside@acme.test')->sole())['lines'])->toBe(['functional']);
});
