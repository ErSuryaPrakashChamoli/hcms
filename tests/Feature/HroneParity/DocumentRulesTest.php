<?php

use App\Domain\Analytics\Services\ReportLibrary;
use App\Domain\Analytics\Services\ReportRunner;
use App\Domain\Configuration\Models\OptionList;
use App\Domain\Configuration\Services\OptionLists;
use App\Domain\Documents\Models\DocumentType;
use App\Domain\Documents\Models\EmployeeDocument;
use App\Domain\Documents\Policies\EmployeeDocumentPolicy;
use App\Domain\Documents\Services\DocumentRules;
use App\Domain\Documents\Services\Documents;
use App\Domain\Employment\Actions\ChangeBankAccountAction;
use App\Domain\Employment\Actions\ChangeStatutoryIdentityAction;
use App\Domain\Employment\Exceptions\ProfileChangeRefused;
use App\Domain\Employment\Models\Employee;
use App\Domain\Experience\Services\NeedsAttention;
use App\Domain\Lifecycle\Enums\LifecycleState;
use App\Domain\Lifecycle\Services\LifecycleEngine;
use App\Domain\People\Actions\ChangeAddressAction;
use App\Domain\Platform\Services\SettingsRepository;
use App\Filament\Pages\MyHr;
use App\Filament\Resources\DocumentTypes\DocumentTypeResource;
use App\Http\Controllers\ParityFileController;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

require_once __DIR__.'/../Workflow/WorkflowTestHelpers.php';

/* HROne setup parity — Core HR → Employee → Document (CA-4): document type rules. */

beforeEach(function () {
    Storage::fake('local');
    config(['peopleos.documents.disk' => 'local']);
    $this->travelTo('2026-09-21 10:00:00');
    $this->tenant = provisionTenant();
    actAsTenant($this->tenant);
    $this->hr = tenantUser($this->tenant, ['*']);
    $this->actingAs($this->hr);
    $this->documents = app(Documents::class);
    $this->rules = app(DocumentRules::class);
    $this->pan = DocumentType::query()->where('code', 'PAN')->firstOrFail();
});

/** An active employee whose user keeps their own documents. */
function documentRulesEmployee(): Employee
{
    return forceLifecycle(employeeWithUser(null, ['document.own', 'employee.self']), LifecycleState::Active, ['joining_date' => '2024-01-01']);
}

function documentRulesFile(string $name = 'scan.pdf'): UploadedFile
{
    return UploadedFile::fake()->create($name, 20, 'application/pdf');
}

it('keeps today\'s behaviour by default: employees see their documents, only HR uploads, nothing is required', function () {
    $employee = documentRulesEmployee();
    $doc = $this->documents->store($employee, documentRulesFile(), $this->pan);

    expect($this->pan->employee_can_view)->toBeTrue()
        ->and($this->pan->employee_can_upload)->toBeFalse()
        ->and(EmployeeDocumentPolicy::isOwn($employee->user, $doc))->toBeTrue()
        ->and($this->rules->missing($employee))->toHaveCount(0)
        ->and($this->rules->selfUploadTypes())->toHaveCount(0)
        ->and(app(NeedsAttention::class)->forEmployee($employee, $employee->user)->pluck('key')->all())->not->toContain('documents');

    // Joining is not blocked while the setting is off, even with onboarding documents missing.
    $joiner = forceLifecycle(Employee::factory()->create(), LifecycleState::Onboarding);
    app(LifecycleEngine::class)->transition($joiner, LifecycleState::Joined, now(), 'Joined');
    expect($joiner->refresh()->lifecycle_state)->toBe(LifecycleState::Joined);
});

it('keeps a document type from the employee when "employee can view" is off', function () {
    $employee = documentRulesEmployee();
    $medical = DocumentType::create(['name' => 'Medical report', 'code' => 'MED', 'category' => 'medical', 'employee_can_view' => false, 'status' => 'active']);
    $doc = $this->documents->store($employee, documentRulesFile(), $medical);

    expect(EmployeeDocumentPolicy::isOwn($employee->user, $doc))->toBeFalse()
        ->and($this->hr->can('view', $doc))->toBeTrue();
    $this->actingAs($employee->user)->get($this->documents->downloadUrl($doc))->assertForbidden();
});

it('lets employees upload the types that allow it from My HR, pending HR verification', function () {
    $employee = documentRulesEmployee();
    $colleague = documentRulesEmployee();
    $passport = DocumentType::query()->where('code', 'PASSPORT')->firstOrFail();
    $passport->update(['employee_can_upload' => true, 'sample_instructions' => 'Both pages, in colour.']);

    expect(fn () => $this->rules->employeeUpload($employee->user, $employee, $this->pan, documentRulesFile()))->toThrow(RuntimeException::class, 'HR uploads this document type')
        ->and(fn () => $this->rules->employeeUpload($employee->user, $colleague, $passport, documentRulesFile(), '2030-01-01'))->toThrow(RuntimeException::class, 'only to your own record')
        ->and(fn () => $this->rules->employeeUpload($employee->user, $employee, $passport, documentRulesFile()))->toThrow(RuntimeException::class, 'expiry date');

    $this->actingAs($employee->user);
    $doc = $this->rules->employeeUpload($employee->user, $employee, $passport, documentRulesFile('passport.pdf'), '2030-01-01');
    expect($doc->status)->toBe('pending')->and($doc->uploaded_by)->toBe($employee->user->id)
        ->and(fn () => $this->documents->review($doc, true, 'Mine', $employee->user))->toThrow(RuntimeException::class, 'cannot verify');

    Livewire::test(MyHr::class, ['tab' => 'documents'])->assertOk()->assertActionVisible('uploadDocument')->assertSee('Passport');
    $this->actingAs(tenantUser($this->tenant, ['employee.self']));
    expect($this->rules->selfUploadTypes()->pluck('code')->all())->toBe(['PASSPORT']);
});

it('reminds employees of documents everyone must have, and reports what is missing', function () {
    $employee = documentRulesEmployee();
    $this->pan->update(['mandatory_for_employee' => true]);
    $joiner = forceLifecycle(Employee::factory()->create(), LifecycleState::Onboarding, ['expected_joining_date' => now()->addDays(5)]);

    $attention = app(NeedsAttention::class)->forEmployee($employee, $employee->user)->keyBy('key');
    expect($attention['documents']['count'])->toBe(1)->and($attention['documents']['url'])->toContain('tab=documents');

    $library = app(ReportLibrary::class);
    $runner = app(ReportRunner::class);
    $missing = $runner->execute('missing_documents', $library->find('documents_missing')['definition'], $this->hr);
    expect(collect($missing->rows)->pluck('document_type', 'employee_code')->all())->toMatchArray([$employee->employee_code => 'PAN card']);
    $joiners = $runner->execute('missing_documents', $library->find('documents_missing_joiners')['definition'], $this->hr);
    expect(collect($joiners->rows)->where('employee_code', $joiner->employee_code)->pluck('document_type')->sort()->values()->all())
        ->toBe(DocumentType::query()->where('mandatory_for_onboarding', true)->orderBy('name')->pluck('name')->sort()->values()->all());

    // A pending upload counts; a rejected one does not.
    $doc = $this->documents->store($employee, documentRulesFile(), $this->pan);
    expect($this->rules->missing($employee))->toHaveCount(0);
    $this->documents->review($doc, false, 'Blurry', tenantUser($this->tenant, ['document.verify']));
    expect($this->rules->missing($employee)->pluck('code')->all())->toBe(['PAN']);

    $this->actingAs($employee->user);
    Livewire::test(MyHr::class, ['tab' => 'documents'])->assertOk()->assertSee('Still needed from you: PAN card');
});

it('refuses joining while an onboarding document is missing, when the organisation asks for it', function () {
    app(SettingsRepository::class)->set('documents.block_joining_without_mandatory', true, 'Documents first');
    DocumentType::query()->where('mandatory_for_onboarding', true)->where('code', '!=', 'PAN')->update(['mandatory_for_onboarding' => false]);
    $joiner = forceLifecycle(Employee::factory()->create(), LifecycleState::Onboarding);
    $engine = app(LifecycleEngine::class);

    expect(fn () => $engine->transition($joiner, LifecycleState::Joined, now(), 'Joined'))->toThrow(RuntimeException::class, 'needed before joining: PAN card');
    $this->documents->store($joiner->refresh(), documentRulesFile(), $this->pan);
    $engine->transition($joiner->refresh(), LifecycleState::Joined, now(), 'Joined');
    expect($joiner->refresh()->lifecycle_state)->toBe(LifecycleState::Joined);

    // A rehire is not a joiner: the rule does not apply.
    $returning = forceLifecycle(Employee::factory()->create(), LifecycleState::Exited);
    $engine->transition($returning, LifecycleState::Active, now(), 'Rehired');
    expect($returning->refresh()->lifecycle_state)->toBe(LifecycleState::Active);
});

it('requires the linked document before a profile field is entered or changed', function () {
    $employee = documentRulesEmployee();
    $this->pan->update(['linked_field' => 'statutory.pan', 'required_with_field' => true]);
    $bankProof = DocumentType::query()->where('code', 'BANK_PROOF')->firstOrFail();
    $bankProof->update(['linked_field' => 'bank_account', 'required_with_field' => true]);
    DocumentType::query()->where('code', 'ADDRESS_PROOF')->update(['linked_field' => 'address', 'required_with_field' => false]);

    expect(fn () => app(ChangeStatutoryIdentityAction::class)->handle($employee, ['pan' => 'ABCDE1234F'], $this->hr))->toThrow(ProfileChangeRefused::class, 'PAN card first')
        ->and(fn () => app(ChangeBankAccountAction::class)->add($employee, ['bank_name' => 'HDFC', 'account_number' => '50100012345678', 'ifsc' => 'HDFC0000001', 'account_holder_name' => 'A', 'is_primary' => true], $this->hr))->toThrow(ProfileChangeRefused::class, 'Cancelled cheque');

    // Other fields, and a linked type not "required with" its field, change freely.
    app(ChangeStatutoryIdentityAction::class)->handle($employee, ['uan' => '100200300400'], $this->hr);
    app(ChangeAddressAction::class)->add($employee, ['type' => 'current', 'country_code' => 'IN', 'address_line_1' => '12 Lake View'], $this->hr);

    $this->documents->store($employee, documentRulesFile(), $this->pan);
    expect(app(ChangeStatutoryIdentityAction::class)->handle($employee, ['pan' => 'ABCDE1234F'], $this->hr)->pan)->toBe('ABCDE1234F');
    $this->documents->store($employee, documentRulesFile(), $bankProof);
    expect(app(ChangeBankAccountAction::class)->add($employee, ['bank_name' => 'HDFC', 'account_number' => '50100012345678', 'ifsc' => 'HDFC0000001', 'account_holder_name' => 'A', 'is_primary' => true], $this->hr)->exists)->toBeTrue()
        ->and(fn () => $this->pan->update(['linked_field' => 'shoe_size']))->toThrow(RuntimeException::class, 'Unknown profile field');
});

it('alerts on expiry by the document type\'s own days, else the organisation setting', function () {
    $employee = documentRulesEmployee();
    $passport = DocumentType::query()->where('code', 'PASSPORT')->firstOrFail();
    $visa = DocumentType::create(['name' => 'Visa', 'code' => 'VISA', 'category' => 'identity', 'requires_expiry' => true, 'expiry_alert_days' => 90, 'status' => 'active']);
    $this->documents->store($employee, documentRulesFile(), $passport, null, now()->addDays(60)->toDateString());
    $this->documents->store($employee, documentRulesFile(), $visa, null, now()->addDays(60)->toDateString());
    $this->documents->store($employee, documentRulesFile(), $passport, null, now()->addDays(20)->toDateString());

    expect($this->documents->expiringWithin(30)->map(fn (EmployeeDocument $d) => $d->type->code)->sort()->values()->all())->toBe(['PASSPORT', 'VISA']);
});

it('shows a type\'s sample and instructions to whoever uploads it, and keeps categories in the organisation\'s list', function () {
    $employee = documentRulesEmployee();
    Storage::disk('local')->put(DocumentType::sampleDirectory().'/pan-sample.pdf', '%PDF-1.4 sample');
    $this->pan->update(['sample_path' => DocumentType::sampleDirectory().'/pan-sample.pdf', 'sample_instructions' => 'Front side only.']);

    $this->actingAs($employee->user)->get(ParityFileController::url('document-type-sample', $this->pan, 0))->assertOk();
    $this->actingAs(tenantUser($this->tenant, ['attendance.regularise']))->get(ParityFileController::url('document-type-sample', $this->pan, 0))->assertForbidden();

    OptionList::create(['list_key' => 'document_categories', 'code' => 'licence', 'label' => 'Licences']);
    expect(app(OptionLists::class)->options('document_categories'))->toHaveKey('licence');
    $this->actingAs($this->hr);
    $this->get(DocumentTypeResource::getUrl('index'))->assertOk()->assertSee('PAN card');
    DocumentType::create(['name' => 'Driving licence', 'code' => 'DL', 'category' => 'licence', 'status' => 'active']);
    $this->get(DocumentTypeResource::getUrl('index'))->assertOk()->assertSee('Licences');
});
