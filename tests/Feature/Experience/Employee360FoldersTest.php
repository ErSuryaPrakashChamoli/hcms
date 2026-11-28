<?php

use App\Domain\Documents\Models\DocumentType;
use App\Domain\Documents\Models\EmployeeDocument;
use App\Domain\Documents\Services\Documents;
use App\Domain\Experience\Services\Employee360Folders;
use App\Domain\Letters\Models\Letter;
use App\Domain\Letters\Models\LetterTemplate;
use App\Domain\Letters\Services\Letters;
use App\Filament\Resources\Employees\EmployeeResource;
use App\Filament\Resources\Employees\Pages\EditEmployee;
use App\Filament\Resources\Employees\Pages\ViewEmployee;
use App\Filament\Resources\Employees\RelationManagers\DocumentsRelationManager;
use Filament\Actions\Testing\TestAction;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

require_once __DIR__.'/../Workflow/WorkflowTestHelpers.php';
require_once __DIR__.'/../Performance/PerformanceTestHelpers.php';

/*
 | Owner, 2026-11-27: the Employee 360 overview cards open what they sum up. Documents opens the person's documents by
 | category and document type, Letters by letter type (Offer letter, Appointment letter, Appraisal letter, …), with an
 | upload against any category or type right there; the same rules as the Documents and Letters records decide what is
 | listed and who may upload.
 */

beforeEach(function () {
    Storage::fake('local');
    config(['peopleos.documents.disk' => 'local', 'peopleos.storage.staging_disk' => 'local']);
    $this->tenant = provisionTenant();
    actAsTenant($this->tenant);
    $this->hr = tenantUser($this->tenant, ['*']);
    $this->actingAs($this->hr);
    $this->employee = activeEmployee(null, ['document.own', 'employee.self', 'task.view']);
    $this->pan = DocumentType::query()->where('code', 'PAN')->firstOrFail();
    $this->pdf = fn (string $name = 'scan.pdf') => UploadedFile::fake()->createWithContent($name, "%PDF-1.4\n%360 folder test\n");
    $this->folders = app(Employee360Folders::class);
});

it('makes the Documents and Letters cards open their drawers, and the other cards their full list', function () {
    $html = $this->get(EmployeeResource::getUrl('view', ['record' => $this->employee]))->assertOk()->getContent();

    expect($html)->toContain('data-pos-card="documents"')->toContain("mountAction('documents360')")
        ->toContain('data-pos-card="letters"')->toContain("mountAction('letters360')")
        ->toContain('Open by document type')->toContain('Open by letter type')
        ->toContain('data-pos-card="exit"');
});

it('lists every document type by name under its category, with the files of each or "not uploaded"', function () {
    app(Documents::class)->store($this->employee, ($this->pdf)('pan.pdf'), $this->pan);

    $folder = $this->folders->documents($this->hr, $this->employee);
    $identity = collect($folder['categories'])->firstWhere('key', 'identity');
    $pan = collect($identity['types'])->firstWhere('id', $this->pan->id);

    expect($identity['label'])->toBe('Identity')
        ->and($pan['name'])->toBe($this->pan->name)
        ->and($pan['documents'])->toHaveCount(1)
        ->and($pan['documents'][0]['file'])->toBe('pan.pdf')
        ->and($pan['upload'])->toBeTrue()
        ->and($folder['summary']['documents'])->toBe(1)
        // Types with nothing on file are listed too.
        ->and(collect($folder['categories'])->flatMap(fn ($c) => $c['types'])->where('documents', [])->isNotEmpty())->toBeTrue();

    Livewire::test(ViewEmployee::class, ['record' => $this->employee->id])
        ->mountAction('documents360')->assertMountedActionModalSee([$this->pan->name, 'pan.pdf', 'Identity', 'Upload a document']);
});

it('uploads from the drawer against the chosen type, as HR, pending verification', function () {
    Livewire::test(ViewEmployee::class, ['record' => $this->employee->id])
        ->callAction([TestAction::make('documents360'), TestAction::make('upload360Document')->arguments(['type' => $this->pan->id])],
            data: ['file' => ($this->pdf)('pan-front.pdf'), 'audit_reason' => 'Collected at joining'])
        ->assertNotified('PAN card uploaded');

    $doc = EmployeeDocument::query()->where('employee_id', $this->employee->id)->sole();
    expect($doc->document_type_id)->toBe($this->pan->id)
        ->and($doc->status)->toBe('pending')
        ->and($doc->original_name)->toBe('pan-front.pdf');
});

it('asks for a document type when the upload is opened on a category or the drawer itself', function () {
    Livewire::test(ViewEmployee::class, ['record' => $this->employee->id])
        ->callAction([TestAction::make('documents360'), TestAction::make('upload360Document')->arguments(['category' => 'identity'])],
            data: ['file' => ($this->pdf)()])
        ->assertHasActionErrors(['document_type_id' => 'required']);

    expect(EmployeeDocument::query()->count())->toBe(0);
});

it('lets the employee see only the types HR shows them and upload only the ones they may', function () {
    $this->pan->update(['employee_can_upload' => true]);
    $hidden = DocumentType::query()->create(['name' => 'Internal note', 'code' => 'INTERNAL_NOTE', 'category' => 'company', 'employee_can_view' => false, 'status' => 'active']);
    $me = $this->employee->user;

    $folder = $this->folders->documents($me, $this->employee);
    $types = collect($folder['categories'])->flatMap(fn ($c) => $c['types']);
    expect($types->pluck('id'))->toContain($this->pan->id)->not->toContain($hidden->id)
        ->and($types->firstWhere('id', $this->pan->id)['upload'])->toBeTrue()
        ->and($types->where('upload', true)->pluck('id')->all())->toBe([$this->pan->id]);

    $this->actingAs($me);
    Livewire::test(ViewEmployee::class, ['record' => $this->employee->id])
        ->callAction([TestAction::make('documents360'), TestAction::make('upload360Document')->arguments(['type' => $this->pan->id])],
            data: ['file' => ($this->pdf)('my-pan.pdf'), 'comment' => 'Front side'])
        ->assertNotified();
    expect(EmployeeDocument::query()->where('employee_id', $this->employee->id)->sole()->upload_comment)->toBe('Front side');

    // A colleague's 360 gives them nothing to open.
    $colleague = activeEmployee(null, ['document.own', 'employee.self']);
    expect($this->folders->maySeeDocuments($me, $colleague))->toBeFalse()
        ->and($this->folders->documents($me, $colleague)['categories'])->toBe([]);
});

it('lists every letter type by name, generated and uploaded letters under their type', function () {
    $template = LetterTemplate::create(['name' => 'Standard offer', 'code' => 'OFFER_360', 'type' => 'offer', 'subject' => 'Offer', 'requires_approval' => true, 'body' => '<p>Dear {{ employee.first_name }}</p>']);
    $letter = app(Letters::class)->generate($template, $this->employee, [], $this->hr);

    $folder = $this->folders->letters($this->hr, $this->employee);
    $labels = collect($folder['types'])->pluck('label');
    $offer = collect($folder['types'])->firstWhere('key', 'offer');

    expect($labels)->toContain('Offer letter')->toContain('Appointment letter')->toContain('Appraisal letter')->toContain('Salary certificate')
        ->and($offer['letters'][0]['number'])->toBe($letter->number)
        ->and($offer['generate'])->toBeTrue()->and($offer['upload'])->toBeTrue()
        // Types with something on file come first.
        ->and($folder['types'][0]['key'])->toBe('offer');

    Livewire::test(ViewEmployee::class, ['record' => $this->employee->id])
        ->callAction([TestAction::make('letters360'), TestAction::make('upload360Letter')->arguments(['type' => 'appointment'])],
            data: ['file' => ($this->pdf)('appointment-signed.pdf'), 'issued_on' => '2025-01-01', 'audit_reason' => 'Signed copy from the joining file'])
        ->assertNotified('Appointment letter uploaded');

    $uploaded = EmployeeDocument::query()->where('employee_id', $this->employee->id)->sole();
    expect($uploaded->type->code)->toBe('LETTER_APPOINTMENT')
        ->and($uploaded->title)->toBe('Appointment letter');
    $appointment = collect($this->folders->letters($this->hr, $this->employee)['types'])->firstWhere('key', 'appointment');
    expect($appointment['letters'])->toHaveCount(1)->and($appointment['letters'][0]['kind'])->toBe('upload');
});

it('generates a letter of a type from the drawer', function () {
    $template = LetterTemplate::create(['name' => 'Appraisal 2026', 'code' => 'APPRAISAL_26', 'type' => 'appraisal', 'subject' => 'Your appraisal', 'requires_approval' => true, 'body' => '<p>Dear {{ employee.first_name }}</p>']);

    Livewire::test(ViewEmployee::class, ['record' => $this->employee->id])
        ->callAction([TestAction::make('letters360'), TestAction::make('generate360Letter')->arguments(['type' => 'appraisal'])])
        ->assertNotified();

    expect(Letter::query()->where('employee_id', $this->employee->id)->sole())
        ->letter_template_id->toBe($template->id)->type->toBe('appraisal');
});

it('shows the employee only their issued letters and no generate or upload', function () {
    $me = $this->employee->user;
    $folder = $this->folders->letters($me, $this->employee);

    expect($folder['generate'])->toBeFalse()->and($folder['upload'])->toBeFalse()->and($folder['types'])->toBe([]);
});

it('keeps the uploader\'s file name on uploads from the full Documents list too', function () {
    Livewire::test(DocumentsRelationManager::class, ['ownerRecord' => $this->employee, 'pageClass' => EditEmployee::class])
        ->callTableAction('upload', data: ['document_type_id' => $this->pan->id, 'file' => ($this->pdf)('pan-back.pdf')])
        ->assertHasNoTableActionErrors();

    expect(EmployeeDocument::query()->where('employee_id', $this->employee->id)->sole()->original_name)->toBe('pan-back.pdf');
});
