<?php

use App\Domain\Documents\Models\DocumentType;
use App\Domain\Documents\Models\DocumentUploadBatch;
use App\Domain\Documents\Models\DocumentUploadItem;
use App\Domain\Documents\Models\EmployeeDocument;
use App\Domain\Documents\Services\DocumentBulkUploads;
use App\Domain\Documents\Services\DocumentNameMatcher;
use App\Domain\Documents\Services\Documents;
use App\Domain\Employment\Actions\HireEmployeeAction;
use App\Domain\Employment\Models\Employee;
use App\Domain\Experience\Services\ActionCatalogue;
use App\Domain\Identity\Services\AccessScopes;
use App\Domain\Notifications\Models\NotificationDelivery;
use App\Domain\Offboarding\Support\DataInventory;
use App\Domain\Organisation\Models\Company;
use App\Filament\Pages\EmployeeDocumentUpload;
use App\Filament\Pages\WorkforceDesk;
use Filament\Facades\Filament;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

/*
| Bulk upload of employee documents (Admin → Data import → Employee documents): zips named "employee code_name" (or one zip
| with a folder or zip per employee), each file matched to a document type by its name, checked by the person, then
| uploaded as ordinary pending employee documents. Nothing reaches an employee until the person starts the upload.
*/

beforeEach(function () {
    $this->travelTo('2026-11-27 10:00:00');
    $this->tenant = provisionTenant();
    actAsTenant($this->tenant);
    $this->hr = tenantUser($this->tenant, ['document.bulk_upload', 'document.view', 'document.upload', 'employee.view']);
    $this->actingAs($this->hr);
    Filament::getPanel('admin')->boot();
    Storage::fake((string) config('peopleos.documents.disk'));
    Storage::fake((string) config('peopleos.storage.staging_disk', 'local'));
    eduTypes();
    $this->asha = eduEmployee('EMP001', 'Asha', 'Rao');
    $this->ravi = eduEmployee('EMP002', 'Ravi', 'Kumar');
    $this->uploads = app(DocumentBulkUploads::class);
});

/** The standard document types these tests name (an organisation starts with them; created here if a list lacks one). */
function eduTypes(): void
{
    foreach ([
        ['AADHAAR', 'Aadhaar', 'identity', false], ['PAN', 'PAN card', 'identity', false], ['PASSPORT', 'Passport', 'identity', true],
        ['SSC_MARKSHEET', '10th (secondary) marksheet', 'education', false], ['HSC_MARKSHEET', '12th (higher secondary) marksheet', 'education', false],
        ['UG_DEGREE', 'Graduation degree certificate', 'education', false], ['EXPERIENCE_LETTER', 'Experience / service certificate', 'employment', false],
        ['RELIEVING', 'Previous relieving letter', 'employment', false], ['BANK_PROOF', 'Cancelled cheque / bank proof', 'statutory', false],
        ['PHOTOGRAPH', 'Passport-size photograph', 'personal', false], ['SSC_CERTIFICATE', '10th (secondary) passing certificate', 'education', false],
        ['HSC_CERTIFICATE', '12th (higher secondary) passing certificate', 'education', false], ['VOTER_ID', 'Voter ID card (EPIC)', 'identity', false],
        ['UAN_CARD', 'UAN card / EPF passbook', 'statutory', false],
    ] as [$code, $name, $category, $expires]) {
        DocumentType::query()->firstOrCreate(['code' => $code], ['name' => $name, 'category' => $category, 'requires_expiry' => $expires, 'mandatory_for_onboarding' => false]);
    }
}

function eduType(string $code): DocumentType
{
    return DocumentType::query()->where('code', $code)->firstOrFail();
}

function eduEmployee(string $code, string $first, string $last): Employee
{
    $company = Company::query()->orderBy('id')->first() ?? Company::factory()->create();

    return app(HireEmployeeAction::class)->handle(['first_name' => $first, 'last_name' => $last], ['joining_date' => '2025-01-01', 'employee_code' => $code], ['company_id' => $company->id]);
}

/** A small PDF (content sniffed as application/pdf); $tag makes files of the same name differ in size. */
function eduPdf(string $tag = ''): string
{
    return "%PDF-1.4\n% sample {$tag}\n".str_repeat('0', 64)."\n%%EOF\n";
}

/** @param  array<string, string>  $files  entry path => content ("folder/" for an empty folder) */
function eduZipBytes(array $files): string
{
    $path = (string) tempnam(sys_get_temp_dir(), 'eduzip');
    $zip = new ZipArchive;
    $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    foreach ($files as $entry => $content) {
        str_ends_with($entry, '/') ? $zip->addEmptyDir(rtrim($entry, '/')) : $zip->addFromString($entry, $content);
    }
    $zip->close();
    $bytes = (string) file_get_contents($path);
    @unlink($path);

    return $bytes;
}

/** @param  array<string, string>  $files */
function eduZip(string $name, array $files): UploadedFile
{
    return UploadedFile::fake()->createWithContent($name, eduZipBytes($files));
}

/** @return Collection<string, DocumentUploadItem> by "folder/file" */
function eduItems(DocumentUploadBatch $batch): Collection
{
    return DocumentUploadItem::query()->with('type')->where('document_upload_batch_id', $batch->id)->orderBy('id')->get()
        ->keyBy(fn (DocumentUploadItem $i) => $i->folder.'/'.$i->file_name);
}

function eduDocuments(Employee $employee): Collection
{
    return EmployeeDocument::query()->withoutGlobalScopes()->with('type')->where('employee_id', $employee->id)->orderBy('id')->get();
}

it('declares the permission for HR roles only, and changes nothing until someone uploads', function () {
    expect(config('peopleos.permissions.document'))->toHaveKey('document.bulk_upload')
        ->and(config('peopleos.roles.hr-manager.permissions'))->toContain('document.bulk_upload')
        ->and(config('peopleos.roles.tenant-hr-admin.permissions'))->toContain('document.*')
        ->and(config('peopleos.roles.employee.permissions'))->not->toContain('document.bulk_upload')
        ->and(config('peopleos.roles.hr-executive.permissions'))->not->toContain('document.bulk_upload')
        ->and(DataInventory::TABLES['documents'])->toContain('document_upload_batches', 'document_upload_items')
        ->and(DocumentUploadBatch::query()->count())->toBe(0);
});

it('reads the document type from the way people name their files', function () {
    // The type issued experience letters are kept under (Letters) answers only when no other type does.
    DocumentType::query()->firstOrCreate(['code' => 'LETTER_EXPERIENCE'], ['name' => 'Experience letter', 'category' => 'company', 'requires_expiry' => false, 'mandatory_for_onboarding' => false]);
    $matcher = new DocumentNameMatcher(DocumentType::query()->where('status', 'active')->get());
    $code = fn (string $file, array $ignore = []) => $matcher->match($file, $ignore)['type']?->code;

    expect($code('Aadhar card.pdf'))->toBe('AADHAAR')
        ->and($code('adhar.jpg'))->toBe('AADHAAR')
        ->and($code('AadhaarCard_front.png'))->toBe('AADHAAR')
        ->and($code('PAN.pdf'))->toBe('PAN')
        ->and($code('pan card scan.pdf'))->toBe('PAN')
        ->and($code('10th marksheet.pdf'))->toBe('SSC_MARKSHEET')
        ->and($code('12th.pdf'))->toBe('HSC_MARKSHEET')
        ->and($code('B.Tech Degree.pdf'))->toBe('UG_DEGREE')
        ->and($code('pasport.pdf'))->toBe('PASSPORT')
        ->and($code('Passport photo.jpg'))->toBe('PHOTOGRAPH')
        ->and($code('Relieveing letter.pdf'))->toBe('RELIEVING')
        ->and($code('Experience letter.pdf'))->toBe('EXPERIENCE_LETTER')
        ->and($code('salary slip.pdf'))->not->toBe('AADHAAR')
        ->and($code('cancelled cheque.jpg'))->toBe('BANK_PROOF')
        // The employee's own code and name in a file's name are not read as the document.
        ->and($code('EMP001_Asha_Rao_PAN.pdf', ['EMP001', 'Asha', 'Rao']))->toBe('PAN')
        ->and($code('random scan.pdf'))->toBeNull()
        // Words joined by underscores read the same as with spaces.
        ->and($code('aadhar_card.pdf'))->toBe('AADHAAR')
        ->and($code('pan_card.pdf'))->toBe('PAN')
        ->and($code('10th_certificate.pdf'))->toBe('SSC_CERTIFICATE');

    // Two different documents named with phrases as long as each other: not guessed, the person chooses.
    expect($matcher->match('aadhaar and pan.pdf')['candidates'])->toContain('Aadhaar', 'PAN card')
        // A folder's name is used when the file's own name says nothing.
        ->and($matcher->match('front.jpg', [], 'Aadhaar')['type']?->code)->toBe('AADHAAR')
        ->and($matcher->match('front.jpg', [], 'Aadhaar')['by'])->toBe('folder');

    // Dates: "valid till" / "exp" or a future date is the expiry, "issued" or a past date the issue date.
    expect(DocumentNameMatcher::dates('Passport valid till 2031-03-31.pdf'))->toBe(['issued_on' => null, 'expires_on' => '2031-03-31'])
        ->and(DocumentNameMatcher::dates('visa_exp_15.08.2027.pdf'))->toBe(['issued_on' => null, 'expires_on' => '2027-08-15'])
        ->and(DocumentNameMatcher::dates('licence issued 01-02-2020.pdf'))->toBe(['issued_on' => '2020-02-01', 'expires_on' => null]);
});

it('checks a zip per employee: each file under its employee with the type its name says, nothing uploaded yet', function () {
    $batch = $this->uploads->check($this->hr, [
        eduZip('EMP001_Asha Rao.zip', ['Aadhar card.pdf' => eduPdf('a'), 'PAN.pdf' => eduPdf('p'), 'Passport valid till 2031-03-31.pdf' => eduPdf('pp'), '__MACOSX/._PAN.pdf' => 'x', '.DS_Store' => 'x', 'Thumbs.db' => 'x']),
        eduZip('EMP002_Ravi Kumar.zip', ['scans/10th marksheet.jpg' => eduPdf('10'), 'resume.pdf' => eduPdf('r'), 'notes.txt' => 'hello', 'empty.pdf' => '', 'certificates.zip' => eduZipBytes(['x.pdf' => eduPdf()])]),
    ]);
    $items = eduItems($batch);

    expect($batch->status)->toBe('review')
        ->and($batch->zip_count)->toBe(2)
        ->and($batch->employee_count)->toBe(2)
        ->and($items)->toHaveCount(8)
        ->and($items['EMP001_Asha Rao/Aadhar card.pdf']->type->code)->toBe('AADHAAR')
        ->and($items['EMP001_Asha Rao/Aadhar card.pdf']->status)->toBe('ready')
        ->and($items['EMP001_Asha Rao/Aadhar card.pdf']->employee_id)->toBe($this->asha->id)
        ->and($items['EMP001_Asha Rao/PAN.pdf']->type->code)->toBe('PAN')
        ->and($items['EMP001_Asha Rao/Passport valid till 2031-03-31.pdf']->expires_on->toDateString())->toBe('2031-03-31')
        ->and($items['EMP002_Ravi Kumar/10th marksheet.jpg']->type->code)->toBe('SSC_MARKSHEET')
        ->and($items['EMP002_Ravi Kumar/resume.pdf']->status)->toBe('needs_type')
        ->and($items['EMP002_Ravi Kumar/notes.txt']->status)->toBe('blocked')
        ->and($items['EMP002_Ravi Kumar/notes.txt']->reason)->toContain('PeopleOS keeps')
        ->and($items['EMP002_Ravi Kumar/empty.pdf']->reason)->toBe('The file is empty.')
        ->and($items['EMP002_Ravi Kumar/certificates.zip']->reason)->toContain('A zip inside an employee\'s zip is not opened')
        ->and($batch->ready)->toBe(4)
        ->and($batch->needs_type)->toBe(1)
        ->and($batch->blocked)->toBe(3)
        // The zips are kept until the upload runs; no employee has a document yet.
        ->and(Storage::disk((string) config('peopleos.documents.disk'))->exists($batch->sources[0]['path']))->toBeTrue()
        ->and(EmployeeDocument::query()->withoutGlobalScopes()->count())->toBe(0);
});

it('takes a zip or folder only when the exact employee code and the employee\'s name (the first name will do) match', function () {
    eduEmployee('MK', 'Meera', 'Kapoor');
    $mk07 = eduEmployee('MK-07', 'Kiran', 'Shah');
    $vikram = eduEmployee('ME0001', 'Vikram', 'Singh');
    $vikram->person->forceFill(['middle_name' => 'Pratap'])->save();
    $madhu = eduEmployee('ME0002', 'Madhu', 'Kumari');
    $madhu->person->forceFill(['last_name' => null])->save();

    $batch = $this->uploads->check($this->hr, [
        // The longest code before an underscore wins (MK-07, not MK); any case, spaces or underscores, either order of the names.
        eduZip('MK-07_Kiran Shah.zip', ['pan_card.pdf' => eduPdf('1')]),
        eduZip('me0001_VIKRAM_SINGH.zip', ['pan_card.pdf' => eduPdf('2')]),
        eduZip('ME0001_Vikram_Pratap_Singh.zip', ['aadhar_card.pdf' => eduPdf('3')]),
        eduZip('ME0001_Singh Vikram (1).zip', ['10th_certificate.pdf' => eduPdf('4')]),
        // The first name alone will do (the code is matched exactly), and is all there is for someone without a last name.
        eduZip('ME0001_Vikram.zip', ['voter_id.pdf' => eduPdf('5')]),
        eduZip('ME0002_Madhu.zip', ['pan_card.pdf' => eduPdf('6')]),
        // A different name, a misspelt one, a last name alone, or no name: nothing of it is uploaded, and it cannot be included.
        eduZip('ME0001_Vikram_Shah.zip', ['photo.jpg' => eduPdf('7')]),
        eduZip('ME0001_Vikrm.zip', ['passport.pdf' => eduPdf('8')]),
        eduZip('ME0001_Singh.zip', ['passport.pdf' => eduPdf('9')]),
        eduZip('ME0001.zip', ['uan_card.pdf' => eduPdf('10')]),
        // A code that is no employee's is never read as a shorter one: MK-99 is not MK, even with MK's name after it.
        eduZip('MK-99_Meera Kapoor.zip', ['pan_card.pdf' => eduPdf('11')]),
        eduZip('EMP404_Nobody Here.zip', ['pan_card.pdf' => eduPdf('12')]),
    ]);
    $items = eduItems($batch);

    expect($items['MK-07_Kiran Shah/pan_card.pdf']->employee_id)->toBe($mk07->id)
        ->and($items['MK-07_Kiran Shah/pan_card.pdf']->status)->toBe('ready')
        ->and($items['me0001_VIKRAM_SINGH/pan_card.pdf']->employee_id)->toBe($vikram->id)
        ->and($items['ME0001_Vikram_Pratap_Singh/aadhar_card.pdf']->status)->toBe('ready')
        ->and($items['ME0001_Singh Vikram (1)/10th_certificate.pdf']->status)->toBe('ready')
        ->and($items['ME0001_Vikram/voter_id.pdf']->status)->toBe('ready')
        ->and($items['ME0001_Vikram/voter_id.pdf']->employee_id)->toBe($vikram->id)
        ->and($items['ME0002_Madhu/pan_card.pdf']->employee_id)->toBe($madhu->id)
        ->and($items['ME0001_Vikram_Shah/photo.jpg']->status)->toBe('blocked')
        ->and($items['ME0001_Vikram_Shah/photo.jpg']->employee_id)->toBeNull()
        ->and($items['ME0001_Vikram_Shah/photo.jpg']->reason)->toBe('“Vikram_Shah” is not the name of ME0001, who is Vikram Pratap Singh in PeopleOS. The code and the name must both match: name it ME0001_Vikram_Singh or ME0001_Vikram.')
        ->and($items['ME0001_Vikrm/passport.pdf']->status)->toBe('blocked')
        ->and($items['ME0001_Singh/passport.pdf']->status)->toBe('blocked')
        ->and($items['ME0001/uan_card.pdf']->reason)->toBe('“ME0001” has the code but not the name. Name it ME0001_Vikram_Singh or ME0001_Vikram.')
        ->and($items['MK-99_Meera Kapoor/pan_card.pdf']->status)->toBe('blocked')
        ->and($items['MK-99_Meera Kapoor/pan_card.pdf']->reason)->toContain('No employee with the code “MK-99” in your access')
        ->and($items['EMP404_Nobody Here/pan_card.pdf']->reason)->toContain('No employee with the code “EMP404” in your access')
        ->and($batch->ready)->toBe(6)
        ->and($batch->blocked)->toBe(6);

    expect($this->uploads->include([$items['ME0001_Vikram_Shah/photo.jpg'], $items['EMP404_Nobody Here/pan_card.pdf']], $this->hr))->toBe(0)
        ->and($items['ME0001_Vikram_Shah/photo.jpg']->refresh()->status)->toBe('blocked')
        ->and(fn () => $this->uploads->edit($items['ME0001_Vikram_Shah/photo.jpg'], $this->hr, ['document_type_id' => eduType('PHOTOGRAPH')->id]))->toThrow(RuntimeException::class);
});

it('reads one zip holding a folder or a zip per employee, looking through a folder around them all', function () {
    $batch = $this->uploads->check($this->hr, [eduZip('all-documents.zip', [
        'Documents/EMP001_Asha Rao/Aadhaar.pdf' => eduPdf('a'),
        'Documents/EMP001_Asha Rao/Education/10th.pdf' => eduPdf('b'),
        // Written by a Windows tool: backslashes between folders; the file is still found (by its position in the zip).
        'Documents\\EMP001_Asha Rao\\Photo.jpg' => eduPdf('w'),
        'Documents/EMP002_Ravi Kumar.zip' => eduZipBytes(['PAN card.pdf' => eduPdf('c'), 'Aadhaar/front.jpg' => eduPdf('d')]),
        'Documents/readme.pdf' => eduPdf('e'),
    ])]);
    $items = eduItems($batch);

    expect($items['EMP001_Asha Rao/Aadhaar.pdf']->employee_id)->toBe($this->asha->id)
        ->and($items['EMP001_Asha Rao/Photo.jpg']->type->code)->toBe('PHOTOGRAPH')
        ->and($items['EMP001_Asha Rao/10th.pdf']->type->code)->toBe('SSC_MARKSHEET')
        ->and($items['EMP002_Ravi Kumar/PAN card.pdf']->employee_id)->toBe($this->ravi->id)
        ->and($items['EMP002_Ravi Kumar/PAN card.pdf']->container)->toBe('Documents/EMP002_Ravi Kumar.zip')
        // A file whose own name says nothing takes its folder's.
        ->and($items['EMP002_Ravi Kumar/front.jpg']->type->code)->toBe('AADHAAR')
        ->and($items['EMP002_Ravi Kumar/front.jpg']->matched_by)->toBe('folder')
        ->and($items['(not in a folder) all-documents.zip/readme.pdf']->status)->toBe('blocked')
        ->and($items['(not in a folder) all-documents.zip/readme.pdf']->reason)->toContain('not in an employee\'s zip or folder');

    $this->uploads->start($batch, $this->hr);

    expect($batch->refresh()->status)->toBe('completed')
        ->and($batch->uploaded)->toBe(5)
        ->and(eduDocuments($this->asha)->pluck('type.code')->all())->toBe(['AADHAAR', 'SSC_MARKSHEET', 'PHOTOGRAPH'])
        ->and(eduDocuments($this->ravi)->pluck('type.code')->all())->toBe(['PAN', 'AADHAAR']);
});

it('lets the person choose a type, change dates, skip and include before uploading; only the uploader may', function () {
    $batch = $this->uploads->check($this->hr, [eduZip('EMP001_Asha Rao.zip', ['resume.pdf' => eduPdf('r'), 'PAN.pdf' => eduPdf('p'), 'Passport.pdf' => eduPdf('pp')])]);
    $items = eduItems($batch);

    $this->uploads->edit($items['EMP001_Asha Rao/resume.pdf'], $this->hr, ['document_type_id' => eduType('EXPERIENCE_LETTER')->id]);
    $this->uploads->edit($items['EMP001_Asha Rao/Passport.pdf'], $this->hr, ['expires_on' => '2030-01-31']);
    expect($items['EMP001_Asha Rao/resume.pdf']->refresh()->status)->toBe('ready')
        ->and($items['EMP001_Asha Rao/resume.pdf']->matched_by)->toBe('chosen')
        ->and($items['EMP001_Asha Rao/Passport.pdf']->refresh()->expires_on->toDateString())->toBe('2030-01-31')
        ->and(fn () => $this->uploads->edit($items['EMP001_Asha Rao/Passport.pdf'], $this->hr, ['issued_on' => '2031-01-01']))->toThrow(RuntimeException::class, 'cannot be before');

    expect($this->uploads->skip([$items['EMP001_Asha Rao/PAN.pdf']], $this->hr))->toBe(1)
        ->and($items['EMP001_Asha Rao/PAN.pdf']->refresh()->status)->toBe('skipped')
        ->and($batch->refresh()->ready)->toBe(2)
        ->and($this->uploads->include([$items['EMP001_Asha Rao/PAN.pdf']], $this->hr))->toBe(1)
        ->and($batch->refresh()->ready)->toBe(3);

    // Someone else with the permission neither changes nor starts it, and sees it only with bulk.view_all.
    $other = tenantUser($this->tenant, ['document.bulk_upload', 'employee.view']);
    $auditor = tenantUser($this->tenant, ['document.bulk_upload', 'employee.view', 'bulk.view_all']);
    expect(fn () => $this->uploads->skip([$items['EMP001_Asha Rao/PAN.pdf']], $other))->toThrow(RuntimeException::class, 'Only the person who uploaded')
        ->and(fn () => $this->uploads->start($batch, $other))->toThrow(RuntimeException::class, 'Only the person who uploaded')
        ->and(DocumentBulkUploads::canSee($other, $batch))->toBeFalse()
        ->and(DocumentBulkUploads::canSee($auditor, $batch))->toBeTrue()
        ->and(DocumentBulkUploads::canChange($auditor, $batch))->toBeFalse();
});

it('uploads each ready file as an ordinary pending document: a new version over what is on file, an ID\'s front and back side by side', function () {
    $old = app(Documents::class)->store($this->asha, UploadedFile::fake()->createWithContent('old-aadhaar.pdf', eduPdf('old')), eduType('AADHAAR'));
    $batch = $this->uploads->check($this->hr, [
        eduZip('EMP001_Asha Rao.zip', ['Aadhar front.pdf' => eduPdf('f'), 'Aadhar back.pdf' => eduPdf('bb'), 'PAN.pdf' => eduPdf('p'), 'resume.pdf' => eduPdf('r')]),
    ]);
    expect(eduItems($batch)['EMP001_Asha Rao/Aadhar front.pdf']->reason)->toContain('Becomes the new version of the Aadhaar on file');

    $this->uploads->start($batch, $this->hr);
    $batch->refresh();
    $documents = eduDocuments($this->asha);
    $aadhaar = $documents->where('document_type_id', eduType('AADHAAR')->id)->values();

    expect($batch->status)->toBe('completed')
        ->and($batch->uploaded)->toBe(3)
        // The file without a type was left out, and said so.
        ->and(eduItems($batch)['EMP001_Asha Rao/resume.pdf']->status)->toBe('skipped')
        ->and(eduItems($batch)['EMP001_Asha Rao/resume.pdf']->reason)->toBe('Left out: no document type was chosen.')
        // The earlier Aadhaar is archived once; the front and back are both current, named apart.
        ->and($old->refresh()->status)->toBe('archived')
        ->and($aadhaar->where('status', 'pending')->pluck('title')->sort()->values()->all())->toBe(['Aadhaar · Aadhar back', 'Aadhaar · Aadhar front'])
        ->and($documents->firstWhere('original_name', 'PAN.pdf')->title)->toBe('PAN card')
        ->and($documents->firstWhere('original_name', 'PAN.pdf')->mime_type)->toBe('application/pdf')
        ->and($documents->firstWhere('original_name', 'PAN.pdf')->uploaded_by)->toBe($this->hr->id)
        ->and($documents->firstWhere('original_name', 'PAN.pdf')->status)->toBe('pending')
        ->and(eduItems($batch)['EMP001_Asha Rao/PAN.pdf']->employee_document_id)->toBe($documents->firstWhere('original_name', 'PAN.pdf')->id)
        // The zips are removed once the upload has run; the uploader is told.
        ->and($batch->sources[0]['path'])->toBeNull()
        ->and(Storage::disk((string) config('peopleos.documents.disk'))->allFiles('tenants/'.$this->tenant->id.'/document-uploads'))->toBe([])
        ->and(NotificationDelivery::query()->where('event', DocumentBulkUploads::DONE_EVENT)->where('user_id', $this->hr->id)->exists())->toBeTrue()
        // The uploader cannot verify what they uploaded.
        ->and(fn () => app(Documents::class)->review($documents->firstWhere('original_name', 'PAN.pdf'), true, null, $this->hr))->toThrow(RuntimeException::class);

    expect(fn () => $this->uploads->start($batch, $this->hr))->toThrow(RuntimeException::class, 'already started or ended');
});

it('keeps what is on file when asked, and never uploads the same file twice', function () {
    app(Documents::class)->store($this->asha, UploadedFile::fake()->createWithContent('PAN.pdf', eduPdf('p')), eduType('PAN'));
    app(Documents::class)->store($this->ravi, UploadedFile::fake()->createWithContent('aadhaar-2019.pdf', eduPdf('old')), eduType('AADHAAR'));

    $batch = $this->uploads->check($this->hr, [
        eduZip('EMP001_Asha Rao.zip', ['PAN.pdf' => eduPdf('p')]),
        eduZip('EMP002_Ravi Kumar.zip', ['Aadhaar.pdf' => eduPdf('new'), 'Photo.jpg' => eduPdf('ph')]),
        eduZip('EMP002_Ravi Kumar (copy).zip', ['Photo.jpg' => eduPdf('ph')]),
    ], ['existing' => 'skip']);
    $items = eduItems($batch);

    expect($items['EMP001_Asha Rao/PAN.pdf']->status)->toBe('skipped')
        ->and($items['EMP001_Asha Rao/PAN.pdf']->flag)->toBe('duplicate')
        ->and($items['EMP002_Ravi Kumar/Aadhaar.pdf']->flag)->toBe('existing')
        ->and($items['EMP002_Ravi Kumar/Aadhaar.pdf']->reason)->toContain('already has a Aadhaar on file; it is kept')
        ->and($items['EMP002_Ravi Kumar (copy)/Photo.jpg']->flag)->toBe('duplicate')
        ->and($items['EMP002_Ravi Kumar/Photo.jpg']->status)->toBe('ready');

    // Included on purpose, it goes in as the new version.
    $this->uploads->include([$items['EMP002_Ravi Kumar/Aadhaar.pdf']], $this->hr);
    $this->uploads->start($batch, $this->hr);

    expect($batch->refresh()->uploaded)->toBe(2)
        ->and(eduDocuments($this->ravi)->where('document_type_id', eduType('AADHAAR')->id)->pluck('status')->all())->toBe(['archived', 'pending'])
        ->and(eduDocuments($this->asha))->toHaveCount(1);
});

it('reaches only employees in the uploader\'s access, and refuses people without the permission', function () {
    $scoped = tenantUser($this->tenant, ['document.bulk_upload', 'employee.view']);
    app(AccessScopes::class)->assign($scoped, ['company' => [Company::factory()->create()->id]], 'Another company');
    $batch = $this->uploads->check($scoped->fresh(), [eduZip('EMP001_Asha Rao.zip', ['PAN.pdf' => eduPdf()])]);

    expect(eduItems($batch)['EMP001_Asha Rao/PAN.pdf']->status)->toBe('blocked')
        ->and(eduItems($batch)['EMP001_Asha Rao/PAN.pdf']->reason)->toContain('in your access')
        ->and(fn () => $this->uploads->check(tenantUser($this->tenant, ['document.upload', 'employee.view']), [eduZip('EMP001_Asha Rao.zip', ['PAN.pdf' => eduPdf()])]))
        ->toThrow(RuntimeException::class, 'cannot upload employee documents in bulk')
        ->and(fn () => $this->uploads->check($this->hr, [UploadedFile::fake()->createWithContent('EMP001_Asha Rao.rar', 'x')]))->toThrow(RuntimeException::class, 'is not a .zip file');
});

it('discards an upload on request, or when it waits unchecked for a week; nothing is uploaded', function () {
    $batch = $this->uploads->check($this->hr, [eduZip('EMP001_Asha Rao.zip', ['PAN.pdf' => eduPdf()])]);
    $this->uploads->discard($batch, $this->hr);

    expect($batch->refresh()->status)->toBe('discarded')
        ->and($batch->sources[0]['path'])->toBeNull()
        ->and(EmployeeDocument::query()->withoutGlobalScopes()->count())->toBe(0);

    $stale = $this->uploads->check($this->hr, [eduZip('EMP001_Asha Rao.zip', ['PAN.pdf' => eduPdf()])]);
    $this->travel(DocumentBulkUploads::REVIEW_DAYS + 1)->days();
    expect($this->uploads->discardStale())->toBe(1)
        ->and($stale->refresh()->status)->toBe('discarded')
        ->and($stale->error)->toContain('Not checked within');
});

it('gives a sample zip that uploads as it is, a names guide and a results sheet', function () {
    $sample = UploadedFile::fake()->createWithContent('sample-employee-documents.zip', DocumentBulkUploads::sampleZip());
    $batch = $this->uploads->check($this->hr, [$sample]);
    $items = eduItems($batch);

    expect($batch->file_count)->toBe(13)
        ->and($batch->ready)->toBe(13)
        ->and($items->map(fn (DocumentUploadItem $i) => $i->type?->code)->all())->toMatchArray([
            'EMP001_Asha Rao/Aadhaar card.pdf' => 'AADHAAR', 'EMP001_Asha Rao/PAN card.pdf' => 'PAN', 'EMP001_Asha Rao/10th marksheet.pdf' => 'SSC_MARKSHEET',
            'EMP001_Asha Rao/12th marksheet.pdf' => 'HSC_MARKSHEET', 'EMP001_Asha Rao/Graduation degree.pdf' => 'UG_DEGREE', 'EMP001_Asha Rao/Passport valid till 2031-03-31.pdf' => 'PASSPORT',
            'EMP002_Ravi Kumar/Aadhar front.pdf' => 'AADHAAR', 'EMP002_Ravi Kumar/Aadhar back.pdf' => 'AADHAAR', 'EMP002_Ravi Kumar/Pan.pdf' => 'PAN',
            'EMP002_Ravi Kumar/Experience letter.pdf' => 'EXPERIENCE_LETTER', 'EMP002_Ravi Kumar/Relieving letter.pdf' => 'RELIEVING',
            'EMP002_Ravi Kumar/Cancelled cheque.pdf' => 'BANK_PROOF', 'EMP002_Ravi Kumar/Photo.pdf' => 'PHOTOGRAPH',
        ]);

    expect($this->uploads->namesGuideCsv())->toContain('Aadhaar,AADHAAR')->toContain('adhar')
        ->and($this->uploads->resultsCsv($batch))->toContain('Zip,"Employee folder",File,"Employee code",Employee,"Document type"')
        ->toContain('"EMP001_Asha Rao","Aadhaar card.pdf",EMP001,"Asha Rao",Aadhaar');
});

it('walks the page: upload the zips, check the matches, change a file, upload, follow the results', function () {
    $page = Livewire::test(EmployeeDocumentUpload::class)->assertOk()->assertSee('How to name the zips and files');

    $page->fillForm(['zips' => [eduZip('EMP001_Asha Rao.zip', ['PAN.pdf' => eduPdf('p'), 'resume.pdf' => eduPdf('r')])], 'existing' => 'version'])
        ->call('uploadAndCheck')->assertHasNoFormErrors();
    $batch = DocumentUploadBatch::query()->latest('id')->firstOrFail();
    $items = eduItems($batch);
    expect($batch->ready)->toBe(1)->and($batch->needs_type)->toBe(1);

    Livewire::withQueryParams(['batch' => $batch->id])->test(EmployeeDocumentUpload::class)
        ->assertSee('1 file(s) need a document type')
        ->assertCanSeeTableRecords($items->values())
        ->callTableAction('changeItem', $items['EMP001_Asha Rao/resume.pdf'], ['document_type_id' => eduType('EXPERIENCE_LETTER')->id])
        ->assertHasNoTableActionErrors()
        ->call('startUpload')
        ->assertSee('2 document(s) uploaded');

    expect(eduDocuments($this->asha)->pluck('type.code')->all())->toBe(['PAN', 'EXPERIENCE_LETTER']);

    // The Lifecycle desk and the Actions panel (and so the search) list it for uploaders; someone else's upload does not
    // open; the page needs the permission.
    $this->get(WorkforceDesk::getUrl())->assertOk()->assertSee(EmployeeDocumentUpload::getUrl(), false);
    expect(collect(app(ActionCatalogue::class)->for($this->hr))->firstWhere('key', 'upload_employee_documents')['url'] ?? null)->toBe(EmployeeDocumentUpload::getUrl());
    $this->actingAs(tenantUser($this->tenant, ['document.bulk_upload', 'employee.view']));
    Livewire::withQueryParams(['batch' => $batch->id])->test(EmployeeDocumentUpload::class)->assertSet('batch', null);
    $sharer = tenantUser($this->tenant, ['document.upload', 'document.share', 'employee.view']);
    $this->actingAs($sharer);
    Livewire::test(EmployeeDocumentUpload::class)->assertForbidden();
    expect(collect(app(ActionCatalogue::class)->for($sharer))->pluck('key'))->not->toContain('upload_employee_documents');
    $this->get(WorkforceDesk::getUrl())->assertOk()->assertDontSee(EmployeeDocumentUpload::getUrl(), false);
});
