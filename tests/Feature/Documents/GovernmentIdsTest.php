<?php

use App\Domain\Documents\Models\DocumentType;
use App\Domain\Documents\Models\EmployeeDocument;
use App\Domain\Documents\Services\DocumentDefaults;
use App\Domain\Employment\Exceptions\ProfileChangeRefused;
use App\Domain\Lifecycle\Enums\LifecycleState;
use App\Domain\People\Actions\ChangeIdentityDocumentAction;
use App\Domain\People\Models\PersonIdentityDocument;
use App\Filament\Resources\Employees\Pages\EditEmployee;
use App\Filament\Resources\Employees\Pages\ViewEmployee;
use App\Filament\Resources\Employees\RelationManagers\DocumentsRelationManager;
use App\Filament\Resources\Employees\RelationManagers\IdentityDocumentsRelationManager;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

require_once __DIR__.'/../Workflow/WorkflowTestHelpers.php';

/*
| Government IDs and the document catalogue (owner, 2026-11-27: "personal profile does not have government ID proof …
| Aadhaar, PAN, passport, and for other countries give the option to fill in; upload these docs as well; choose what type
| of doc: Aadhaar card, 10th marksheet, 12th, graduation, PG … not limited to what I mentioned"). PAN, Aadhaar, UAN, PF
| and ESIC stay in the statutory details; every other government ID is a PersonIdentityDocument of the catalogue, its
| copy filed as the document type linked to it.
*/

beforeEach(function () {
    Storage::fake('local');
    config(['peopleos.documents.disk' => 'local']);
    $this->travelTo('2026-11-27 10:00:00');
    $this->tenant = provisionTenant();
    actAsTenant($this->tenant);
    $this->hr = tenantUser($this->tenant, ['*']);
    $this->actingAs($this->hr);
    $this->employee = forceLifecycle(employeeWithUser(null, ['employee.self']), LifecycleState::Active, ['joining_date' => '2024-04-01']);
});

it('offers India\'s IDs, those any country issues and each country\'s own, grouped by country', function () {
    $groups = PersonIdentityDocument::groupedKinds();

    expect(array_slice(array_keys($groups), 0, 2))->toBe(['India', 'Any country'])
        ->and($groups['India'])->toHaveKeys(['voter_id', 'ration_card', 'oci_card'])
        ->and($groups['Any country'])->toHaveKeys(['passport', 'driving_licence', 'national_id', 'residence_permit', 'work_permit', 'visa', 'other_id'])
        ->and($groups['United Arab Emirates'])->toHaveKey('ae_emirates_id')
        ->and($groups['United States'])->toHaveKey('us_ssn')
        ->and(max(array_map('strlen', array_keys(PersonIdentityDocument::kinds()))))->toBeLessThanOrEqual(24)
        ->and(PersonIdentityDocument::countryOf('voter_id'))->toBe('IN')
        ->and(PersonIdentityDocument::expires('visa'))->toBeTrue();
});

it('checks numbers only where the format is fixed', function () {
    expect(PersonIdentityDocument::numberProblem('voter_id', 'abc 1234567'))->toBeNull()
        ->and(PersonIdentityDocument::numberProblem('voter_id', 'AB12345678'))->toContain('not a valid Voter ID (EPIC) number')
        ->and(PersonIdentityDocument::numberProblem('passport', 'K1234567', 'IN'))->toBeNull()
        ->and(PersonIdentityDocument::numberProblem('passport', '123456789', 'IN'))->toContain('not a valid Passport number')
        ->and(PersonIdentityDocument::numberProblem('passport', '123456789', 'US'))->toBeNull()
        ->and(PersonIdentityDocument::numberProblem('us_ssn', '123-45-6789'))->toBeNull()
        ->and(PersonIdentityDocument::numberProblem('sg_nric_fin', 'S1234567D'))->toBeNull()
        ->and(PersonIdentityDocument::numberProblem('ae_emirates_id', '784-1990-1234567-1'))->toBeNull()
        ->and(PersonIdentityDocument::numberProblem('uk_ni_number', 'QQ123456Z'))->not->toBeNull()
        ->and(PersonIdentityDocument::numberProblem('driving_licence', 'MH12 20110012345'))->toBeNull();
});

it('records a voter ID with its country, and refuses a number in the wrong format', function () {
    expect(fn () => app(ChangeIdentityDocumentAction::class)->add($this->employee, ['kind' => 'voter_id', 'number' => '12345'], $this->hr))
        ->toThrow(ProfileChangeRefused::class, 'not a valid Voter ID (EPIC) number');

    $id = app(ChangeIdentityDocumentAction::class)->add($this->employee, ['kind' => 'voter_id', 'number' => 'abc1234567'], $this->hr, 'Voter ID on file');

    expect($id->number)->toBe('ABC1234567')->and($id->issuing_country)->toBe('IN')->and($id->maskedNumber())->toBe('••••4567');
});

it('seeds the document catalogue: 10th to doctorate, every government ID, without changing types an organisation has', function () {
    $codes = DocumentType::query()->pluck('code')->all();
    expect($codes)->toContain('SSC_MARKSHEET', 'SSC_CERTIFICATE', 'HSC_MARKSHEET', 'HSC_CERTIFICATE', 'UG_MARKSHEET', 'UG_DEGREE', 'PG_DEGREE', 'PHD_DEGREE',
        'VOTER_ID', 'DRIVING_LICENCE', 'VISA', 'EXPERIENCE_LETTER', 'FORM_12BB', 'BIRTH_CERTIFICATE')
        ->and(DocumentType::forField('voter_id')?->code)->toBe('VOTER_ID');

    // An organisation's own changes stay; a removed default comes back; running again adds nothing.
    DocumentType::query()->where('code', 'PAN')->update(['name' => 'PAN (permanent account number)']);
    DocumentType::query()->where('code', 'PHD_DEGREE')->delete();
    expect(app(DocumentDefaults::class)->seed())->toBe(1)
        ->and(app(DocumentDefaults::class)->seed())->toBe(0)
        ->and(DocumentType::query()->where('code', 'PAN')->value('name'))->toBe('PAN (permanent account number)');

    $this->artisan('peopleos:documents:seed-types', ['--tenant' => $this->tenant->id])->assertSuccessful();
});

it('groups document types by category for the upload pickers', function () {
    $groups = DocumentType::grouped(DocumentType::query()->get());

    expect(array_slice(array_keys($groups), 0, 3))->toBe(['Identity', 'Education', 'Previous employment'])
        ->and($groups['Education'])->toContain('10th (secondary) marksheet', '12th (higher secondary) marksheet', 'Post-graduation degree certificate');
});

it('adds a government ID with a copy of it on the employee\'s Government IDs tab, filed as its document type', function () {
    // The list is edited in its step of the Edit page (Government IDs); the 360 reads it in Profile.
    Livewire::test(IdentityDocumentsRelationManager::class, ['ownerRecord' => $this->employee, 'pageClass' => EditEmployee::class])
        ->assertSee('Government IDs')->assertSee('PAN not on file')
        ->callTableAction('create', data: ['kind' => 'voter_id', 'number' => 'ABC1234567', 'issuing_country' => 'IN',
            'proof' => UploadedFile::fake()->createWithContent('voter.pdf', '%PDF-1.4 voter'), 'audit_reason' => 'Voter ID with copy'])
        ->assertHasNoTableActionErrors();

    $document = EmployeeDocument::query()->where('employee_id', $this->employee->id)->sole();
    expect(PersonIdentityDocument::query()->where('kind', 'voter_id')->sole()->maskedNumber())->toBe('••••4567')
        ->and($document->type->code)->toBe('VOTER_ID')
        ->and($document->status)->toBe('pending')
        ->and(Storage::disk('local')->exists($document->path))->toBeTrue();

    // A number in the wrong format is refused, and no copy is left behind.
    Livewire::test(IdentityDocumentsRelationManager::class, ['ownerRecord' => $this->employee, 'pageClass' => EditEmployee::class])
        ->callTableAction('create', data: ['kind' => 'sg_nric_fin', 'number' => '12345678', 'issuing_country' => 'SG',
            'proof' => UploadedFile::fake()->createWithContent('nric.pdf', '%PDF-1.4 nric')])
        ->assertHasTableActionErrors(['number']);
    expect(EmployeeDocument::query()->count())->toBe(1);
});

it('files the PAN card copy with the statutory details, in the Statutory step of the Edit page', function () {
    Livewire::test(EditEmployee::class, ['record' => $this->employee->getRouteKey()])
        ->fillForm(['statutory.pan' => 'ABCDE1234F', 'statutory.pf_applicable' => true, 'statutory.pt_applicable' => true,
            'statutory.pan_copy' => UploadedFile::fake()->createWithContent('pan.pdf', '%PDF-1.4 pan'), 'audit_reason' => 'PAN with copy'])
        ->call('save')->assertHasNoFormErrors();

    expect($this->employee->fresh()->statutoryDetail->pan)->toBe('ABCDE1234F')
        ->and(EmployeeDocument::query()->where('employee_id', $this->employee->id)->sole()->type->code)->toBe('PAN');
});

it('links the Documents view to the government IDs, which are edited in their step of the Edit page', function () {
    $page = Livewire::test(ViewEmployee::class, ['record' => $this->employee->getRouteKey()]);

    // HR may edit the employee: the Edit page on the Government IDs step, scrolled to the list; a viewer gets Profile.
    expect($page->instance()->listUrl(IdentityDocumentsRelationManager::class))->toContain('/edit?step=government-ids')->toEndWith('#list-IdentityDocumentsRelationManager')
        ->and($page->instance()->listUrl(DocumentsRelationManager::class))->toEndWith('#list-DocumentsRelationManager')->not->toContain('/edit');
    $page->assertSee('Government IDs')->assertDontSee('Upload or open documents');
});
