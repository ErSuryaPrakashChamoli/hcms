<?php

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Models\AuditEvent;
use App\Domain\Documents\Jobs\RunDocumentShare;
use App\Domain\Documents\Models\DocumentShareBatch;
use App\Domain\Documents\Models\DocumentShareItem;
use App\Domain\Documents\Models\DocumentType;
use App\Domain\Documents\Models\EmployeeDocument;
use App\Domain\Documents\Services\Documents;
use App\Domain\Documents\Services\DocumentShares;
use App\Domain\Employment\Models\Employee;
use App\Domain\Experience\Services\ExperienceNavigation;
use App\Domain\Experience\Services\MyProfileSections;
use App\Domain\Identity\Services\AccessScopes;
use App\Domain\Notifications\Mail\NotificationMail;
use App\Domain\Notifications\Models\NotificationDelivery;
use App\Domain\Offboarding\Support\DataInventory;
use App\Domain\Organisation\Models\Company;
use App\Filament\Pages\WorkforceDesk;
use App\Filament\Resources\DocumentShareBatches\DocumentShareBatchResource;
use App\Filament\Resources\DocumentShareBatches\Pages\ListDocumentShareBatches;
use App\Filament\Resources\DocumentShareBatches\Pages\ViewDocumentShareBatch;
use App\Filament\Resources\DocumentShareBatches\RelationManagers\ItemsRelationManager;
use App\Filament\Resources\Letters\LetterResource;
use App\Filament\Resources\PublishedDocuments\Pages\ListPublishedDocuments;
use App\Filament\Resources\PublishedDocuments\PublishedDocumentResource;
use Filament\Facades\Filament;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

/*
| HROne parity WO-14 (matrix 23 §23.7.3 #1–9, §23.7.4 #1–3, §23.9 #20 "Share letters / documents"): HR shares ready-made files
| with many employees — a zip mapped by a sheet of employee code + file name, or one common file for everyone chosen —
| the share register with counts and errors, unpublish (D-WO5: hidden from the employee, kept), the published documents
| list, and the permission gate. Nothing changes until someone with document.share uses it.
*/

require_once __DIR__.'/../Workflow/WorkflowTestHelpers.php';

beforeEach(function () {
    $this->travelTo('2026-10-31 10:00:00');
    $this->tenant = provisionTenant();
    actAsTenant($this->tenant);
    $this->hr = tenantUser($this->tenant, ['document.share', 'document.view', 'employee.view', 'letter.view', 'letter.issue']);
    $this->actingAs($this->hr);
    Filament::getPanel('admin')->boot();
    Storage::fake((string) config('peopleos.documents.disk'));
    $this->type = DocumentType::query()->where('code', 'OFFER')->firstOrFail();
    $this->asha = employeeWithUser(null, ['document.own']);
    $this->ravi = employeeWithUser(null, ['document.own']);
    $this->shares = app(DocumentShares::class);
});

/** @param  array<string, string>  $files  name => content */
function wo14Zip(array $files): UploadedFile
{
    $path = (string) tempnam(sys_get_temp_dir(), 'wo14zip');
    $zip = new ZipArchive;
    $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    foreach ($files as $name => $content) {
        str_ends_with($name, '/') ? $zip->addEmptyDir(rtrim($name, '/')) : $zip->addFromString($name, $content);
    }
    $zip->close();
    $content = (string) file_get_contents($path);
    @unlink($path);

    return UploadedFile::fake()->createWithContent('files.zip', $content);
}

/** @param  list<array{0: string, 1: string}>  $rows */
function wo14Sheet(array $rows, string $header = "Employee code,File name\n"): UploadedFile
{
    return UploadedFile::fake()->createWithContent('sheet.csv', $header.implode("\n", array_map(fn (array $r) => implode(',', $r), $rows))."\n");
}

/** @return array<string, DocumentShareItem> by employee code (or file name when no code) */
function wo14Items(DocumentShareBatch $batch): array
{
    return DocumentShareItem::query()->with('document')->where('document_share_batch_id', $batch->id)->orderBy('id')->get()
        ->keyBy(fn (DocumentShareItem $i) => $i->employee_code ?: (string) $i->file_name)->all();
}

function wo14Share(array $data, array $files, ?int $confirmed = null): DocumentShareBatch
{
    return app(DocumentShares::class)->start(test()->hr, $data + ['document_type_id' => test()->type->id, 'title' => 'Form 16 for 2025-26'], $files, $confirmed)->refresh();
}

it('keeps every default: the permission is declared and held by HR roles only, nothing is shared until someone does, the desk lists it only for sharers', function () {
    expect(config('peopleos.permissions.document'))->toHaveKey('document.share')
        ->and(config('peopleos.roles.hr-manager.permissions'))->toContain('document.share')
        ->and(config('peopleos.roles.tenant-hr-admin.permissions'))->toContain('document.*')
        ->and(config('peopleos.roles.employee.permissions'))->not->toContain('document.share')
        ->and(config('peopleos.roles.hr-executive.permissions'))->not->toContain('document.share')
        ->and(DocumentShareBatch::query()->count())->toBe(0);

    // An ordinary upload is unchanged: pending verification, versions supersede, the upload event fires.
    $documents = app(Documents::class);
    $v1 = $documents->store($this->asha, UploadedFile::fake()->createWithContent('a.pdf', '%PDF-1.4 a'), $this->type);
    $documents->store($this->asha, UploadedFile::fake()->createWithContent('b.pdf', '%PDF-1.4 b'), $this->type);
    expect($v1->refresh()->status)->toBe('archived')->and(EmployeeDocument::query()->latest('id')->first()->status)->toBe('pending');

    // The desk lists the share entries only for people who share documents.
    $operator = tenantUser($this->tenant, ['employee.view', 'letter.issue', 'letter.view']);
    $this->actingAs($operator);
    $this->get(WorkforceDesk::getUrl())->assertOk()->assertDontSee(DocumentShareBatchResource::getUrl('index', ['action' => 'shareDocuments']), false);
    $this->actingAs($this->hr);
    $this->get(WorkforceDesk::getUrl())->assertOk()
        ->assertSee(DocumentShareBatchResource::getUrl('index', ['action' => 'shareDocuments']), false)
        ->assertSee(PublishedDocumentResource::getUrl('index'), false);

    // The job carries its tenant, tries, timeout and backoff; both tables are in the data inventory.
    $job = new RunDocumentShare(1);
    expect([$job->tries, $job->timeout, $job->backoff, $job->tenantId])->toBe([3, 1800, [60, 300], $this->tenant->id])
        ->and($job->middleware())->not->toBeEmpty()
        ->and(DataInventory::TABLES['documents'])->toContain('document_share_batches', 'document_share_items')
        ->and(DataInventory::FILES)->toHaveKey('document_share_batches');
});

it('shares a zip mapped by a sheet: each employee gets their file, unknown codes and missing files are listed as failures with reasons', function () {
    $zip = wo14Zip([
        $this->asha->employee_code.'.pdf' => '%PDF-1.4 asha form 16',
        'folder/ravi-form16.pdf' => '%PDF-1.4 ravi form 16',
        'nobody.pdf' => '%PDF-1.4 named by a row whose code nobody has',
        'extra.pdf' => '%PDF-1.4 not named in the sheet',
        'evil.pdf' => '<html><script>alert(1)</script></html>',
        'twice.pdf' => '%PDF-1.4 one', 'twice.docx' => 'PK not really',
        '__MACOSX/._ravi-form16.pdf' => 'resource fork', 'empty/' => '',
    ]);
    $sheet = wo14Sheet([
        [$this->asha->employee_code, $this->asha->employee_code],     // without extension
        [$this->ravi->employee_code, 'RAVI-FORM16.PDF'],               // with extension, any case
        ['NOPE-1', 'nobody'],                                          // nobody has this code
        [$this->asha->employee_code, 'missing'],                       // not in the zip
        [$this->ravi->employee_code, 'evil'],                          // refused by the file checks
        [$this->asha->employee_code, 'twice'],                         // two files of that name
        ['', 'ravi-form16'],                                           // no code
        [$this->ravi->employee_code, 'RAVI-FORM16.PDF'],               // a repeated row
    ]);

    $batch = wo14Share(['mode' => 'sheet', 'message' => 'Your Form 16 is ready.'], ['zip' => $zip, 'sheet' => $sheet]);
    $items = DocumentShareItem::query()->with('document')->where('document_share_batch_id', $batch->id)->orderBy('id')->get();

    expect($batch->status)->toBe('completed')->and($batch->total)->toBe(8)->and($batch->shared)->toBe(2)->and($batch->failed)->toBe(6)
        ->and($items->pluck('status')->all())->toBe(['shared', 'shared', 'failed', 'failed', 'failed', 'failed', 'failed', 'failed'])
        ->and($items->pluck('reason', 'row')->filter()->all())->toBe([
            4 => 'No employee with this code in your access.',
            5 => 'No file named "missing" in the zip.',
            6 => 'The file content is not an accepted document type.',
            7 => 'Several files in the zip are named "twice": give the file name with its extension.',
            8 => 'No employee code on this row.',
            9 => 'Repeats row 3.',
        ])
        ->and($batch->unused_files)->toBe(['extra.pdf'])
        // The files given were removed once the share had run; the shared files are employee documents.
        ->and($batch->source_path)->toBeNull()->and($batch->sheet_path)->toBeNull()
        ->and(Storage::disk((string) config('peopleos.documents.disk'))->allFiles("tenants/{$this->tenant->id}/document-shares"))->toBe([]);

    $asha = $items[0]->document;
    expect($asha->employee_id)->toBe($this->asha->id)->and($asha->document_type_id)->toBe($this->type->id)->and($asha->title)->toBe('Form 16 for 2025-26')
        ->and($asha->status)->toBe('verified')->and($asha->original_name)->toBe($this->asha->employee_code.'.pdf')->and($asha->uploaded_by)->toBe($this->hr->id)
        ->and(Storage::disk($asha->disk)->get($asha->path))->toBe('%PDF-1.4 asha form 16')
        ->and($items[1]->document->original_name)->toBe('ravi-form16.pdf');

    // The employee sees their own document where they see their documents, and opens it; not the colleague's.
    $ashaUser = $this->asha->user;
    expect(app(MyProfileSections::class)->downloadable($ashaUser, $this->asha)->pluck('id')->all())->toBe([$asha->id])
        ->and(Gate::forUser($ashaUser)->allows('view', $asha))->toBeTrue()
        ->and(Gate::forUser($ashaUser)->allows('view', $items[1]->document))->toBeFalse();

    // Told in-app with the message (the notification log, event document.shared); no e-mail by default.
    expect(NotificationDelivery::query()->where('event', DocumentShares::EVENT)->where('channel', 'in_app')->pluck('user_id')->sort()->values()->all())
        ->toBe(collect([$this->asha->user_id, $this->ravi->user_id])->sort()->values()->all())
        ->and(NotificationDelivery::query()->where('event', DocumentShares::EVENT)->where('user_id', $this->asha->user_id)->value('body'))->toBe('Your Form 16 is ready.')
        ->and(NotificationDelivery::query()->where('event', DocumentShares::EVENT)->where('channel', 'email')->count())->toBe(0)
        ->and(NotificationDelivery::query()->where('event', DocumentShares::DONE_EVENT)->where('user_id', $this->hr->id)->count())->toBe(1);

    // The errors CSV lists every failed row and the unused file.
    $csv = $this->shares->errorCsv($batch);
    expect($csv)->toContain('Row,"Employee code","File name",Reason')->toContain('NOPE-1,nobody,"No employee with this code in your access."')
        ->toContain('extra.pdf,"In the zip, but no row of the sheet names this file"');
    expect(AuditEvent::query()->withoutGlobalScopes()->where('action', AuditAction::DocumentsShared->value)->where('entity_id', (string) $batch->id)->value('metadata'))
        ->toMatchArray(['shared' => 2, 'failed' => 6, 'mode' => 'sheet']);

    // A wrong sheet is refused before anything is queued.
    expect(fn () => wo14Share(['mode' => 'sheet'], ['zip' => wo14Zip(['a.pdf' => '%PDF-1.4']), 'sheet' => wo14Sheet([['X', 'a']], "Code only\n")]))
        ->toThrow(RuntimeException::class, 'needs the columns')
        ->and(fn () => wo14Share(['mode' => 'sheet'], ['zip' => UploadedFile::fake()->createWithContent('files.zip', 'not a zip'), 'sheet' => wo14Sheet([['X', 'a']])]))
        ->toThrow(RuntimeException::class, 'could not be opened')
        ->and(DocumentShareBatch::query()->count())->toBe(1);
});

it('shares one common file with everyone chosen by the employee selection; pasted codes nobody has are listed', function () {
    config(['peopleos.bulk.confirm_over' => 1]);
    $file = UploadedFile::fake()->createWithContent('Holiday policy 2027.pdf', '%PDF-1.4 policy');
    $codes = ['mode' => 'common', 'selection' => ['source' => 'codes', 'codes' => $this->asha->employee_code.", NOPE-9\n".$this->ravi->employee_code]];

    // Over the confirmation threshold the number must be typed.
    expect(fn () => wo14Share($codes, ['file' => $file]))->toThrow(RuntimeException::class, 'Type the number of employees (2)');
    $batch = wo14Share($codes, ['file' => $file], 2);
    $items = wo14Items($batch);

    expect($batch->status)->toBe('completed')->and($batch->mode)->toBe('common')->and($batch->shared)->toBe(2)->and($batch->failed)->toBe(1)
        ->and($items['NOPE-9']->reason)->toBe('No employee with this code in your access.')
        ->and($items[$this->asha->employee_code]->document->original_name)->toBe('Holiday policy 2027.pdf')
        ->and(Storage::disk((string) config('peopleos.documents.disk'))->get($items[$this->ravi->employee_code]->document->path))->toBe('%PDF-1.4 policy')
        // Each employee has their own copy: unpublishing one never touches another's file.
        ->and($items[$this->asha->employee_code]->document->path)->not->toBe($items[$this->ravi->employee_code]->document->path);

    // Filters must narrow (or "everyone" be ticked on purpose); a not-joined person never gets a document.
    expect(fn () => wo14Share(['mode' => 'common', 'selection' => ['source' => 'filters']], ['file' => $file]))->toThrow(RuntimeException::class, 'Choose at least one filter');
    $company = Company::query()->orderBy('id')->firstOrFail();
    forceLifecycle($this->ravi, 'not_joined');
    $batch = wo14Share(['mode' => 'common', 'selection' => ['source' => 'filters', 'company_id' => [$company->id]]], ['file' => $file], 2);
    expect(wo14Items($batch)[$this->ravi->employee_code]->reason)->toBe('This person never joined: documents are not shared with them.')
        ->and($batch->shared)->toBe(1);
});

it('refuses employees outside the sharer\'s access scope, by sheet or by pasted codes', function () {
    $scoped = tenantUser($this->tenant, ['document.share', 'document.view', 'employee.view']);
    app(AccessScopes::class)->assign($scoped, ['company' => [Company::factory()->create()->id]], 'Another company');
    $scoped = $scoped->fresh();
    $this->actingAs($scoped);

    $batch = app(DocumentShares::class)->start($scoped, ['document_type_id' => $this->type->id, 'title' => 'Circular', 'mode' => 'sheet'],
        ['zip' => wo14Zip(['a.pdf' => '%PDF-1.4 a']), 'sheet' => wo14Sheet([[$this->asha->employee_code, 'a']])])->refresh();
    expect($batch->shared)->toBe(0)->and($batch->failed)->toBe(1)
        ->and(DocumentShareItem::query()->withoutGlobalScopes()->where('document_share_batch_id', $batch->id)->value('reason'))->toBe('No employee with this code in your access.')
        ->and(EmployeeDocument::query()->withoutGlobalScopes()->where('employee_id', $this->asha->id)->count())->toBe(0);

    $batch = app(DocumentShares::class)->start($scoped, ['document_type_id' => $this->type->id, 'title' => 'Circular', 'mode' => 'common',
        'selection' => ['source' => 'codes', 'codes' => $this->asha->employee_code]], ['file' => UploadedFile::fake()->createWithContent('c.pdf', '%PDF-1.4 c')])->refresh();
    expect($batch->shared)->toBe(0)->and($batch->failed)->toBe(1);

    // A shared document outside the scope cannot be unpublished by them either.
    $this->actingAs($this->hr);
    $shared = wo14Share(['mode' => 'sheet'], ['zip' => wo14Zip(['a.pdf' => '%PDF-1.4 a']), 'sheet' => wo14Sheet([[$this->asha->employee_code, 'a']])]);
    $item = DocumentShareItem::query()->where('document_share_batch_id', $shared->id)->sole();
    $this->actingAs($scoped);
    expect(fn () => app(DocumentShares::class)->unpublishItem($item, $scoped, 'Wrong file'))->toThrow(RuntimeException::class, 'not in your access')
        ->and(app(DocumentShares::class)->unpublish($shared, $scoped, 'Wrong file'))->toBe(['unpublished' => 0, 'skipped' => 1]);
});

it('unpublishes one document or a whole share: hidden from the employee, kept with its file, audited (D-WO5)', function () {
    $batch = wo14Share(['mode' => 'sheet'], ['zip' => wo14Zip(['a.pdf' => '%PDF-1.4 a', 'r.pdf' => '%PDF-1.4 r']),
        'sheet' => wo14Sheet([[$this->asha->employee_code, 'a'], [$this->ravi->employee_code, 'r']])]);
    $items = wo14Items($batch);
    $ashaItem = $items[$this->asha->employee_code];
    $document = $ashaItem->document;
    $ashaUser = $this->asha->user;

    expect(fn () => $this->shares->unpublishItem($ashaItem, $this->hr, ' '))->toThrow(RuntimeException::class, 'Say why')
        ->and(fn () => $this->shares->unpublishItem($ashaItem, tenantUser($this->tenant, ['document.view', 'employee.view']), 'Wrong'))->toThrow(RuntimeException::class, 'cannot unpublish');

    $this->shares->unpublishItem($ashaItem, $this->hr, 'Wrong year');
    expect($ashaItem->refresh()->status)->toBe('unpublished')->and($ashaItem->unpublished_by)->toBe($this->hr->id)->and($ashaItem->unpublish_reason)->toBe('Wrong year')
        ->and($document->refresh()->status)->toBe('archived')
        ->and(Storage::disk($document->disk)->exists($document->path))->toBeTrue()
        ->and(EmployeeDocument::query()->whereKey($document->id)->exists())->toBeTrue()
        // The employee no longer lists or opens it; HR still does.
        ->and(app(MyProfileSections::class)->downloadable($ashaUser, $this->asha))->toBeEmpty()
        ->and(Gate::forUser($ashaUser)->allows('view', $document))->toBeFalse()
        ->and(Gate::forUser($this->hr)->allows('view', $document))->toBeTrue()
        ->and(AuditEvent::query()->withoutGlobalScopes()->where('action', AuditAction::DocumentUnpublished->value)->where('entity_id', (string) $document->id)->value('reason'))->toBe('Wrong year')
        ->and(fn () => $this->shares->unpublishItem($ashaItem, $this->hr, 'Again'))->toThrow(RuntimeException::class, 'Only a shared document')
        ->and($batch->refresh()->unpublished_at)->toBeNull();

    // The whole share: what is still shared is unpublished, the rest skipped; the share is marked unpublished.
    expect($this->shares->unpublish($batch, $this->hr, 'Sent to the wrong people'))->toBe(['unpublished' => 1, 'skipped' => 1]);
    expect($items[$this->ravi->employee_code]->refresh()->status)->toBe('unpublished')
        ->and($batch->refresh()->unpublished_at)->not->toBeNull()->and($batch->unpublish_reason)->toBe('Sent to the wrong people')
        ->and(EmployeeDocument::query()->whereIn('id', [$document->id, $items[$this->ravi->employee_code]->employee_document_id])->pluck('status')->unique()->all())->toBe(['archived'])
        ->and(DocumentShareBatch::query()->count())->toBe(1);

    // An e-mail still waiting is not sent once its document is unpublished.
    expect(fn () => $items[$this->ravi->employee_code]->refresh()->mailAttachments(new NotificationDelivery))->toThrow(RuntimeException::class, 'no longer shared');
});

it('e-mails the document only when asked, only to the employee, attached — never a copy', function () {
    Mail::fake();
    Employee::query()->whereKey($this->asha->id)->update(['work_email' => 'asha@acme.test']);
    Employee::query()->whereKey($this->ravi->id)->update(['work_email' => null]);
    expect(fn () => wo14Share(['mode' => 'sheet', 'email' => true, 'email_to' => 'personal'], ['zip' => wo14Zip(['a.pdf' => '%PDF-1.4 a']), 'sheet' => wo14Sheet([[$this->asha->employee_code, 'a']])]))
        ->toThrow(RuntimeException::class, 'personal e-mail needs');

    $batch = wo14Share(['mode' => 'sheet', 'email' => true, 'email_to' => 'work'], ['zip' => wo14Zip(['a.pdf' => '%PDF-1.4 a', 'r.pdf' => '%PDF-1.4 r']),
        'sheet' => wo14Sheet([[$this->asha->employee_code, 'a'], [$this->ravi->employee_code, 'r']])]);
    $items = wo14Items($batch);
    $delivery = NotificationDelivery::query()->where('event', DocumentShares::EVENT)->where('channel', 'email')->sole();

    expect($batch->emailed)->toBe(1)->and($delivery->recipient)->toBe('asha@acme.test')
        ->and((int) $delivery->source_id)->toBe($items[$this->asha->employee_code]->id)->and($delivery->source_type)->toBe($items[$this->asha->employee_code]->getMorphClass())
        ->and($items[$this->ravi->employee_code]->status)->toBe('shared')->and($items[$this->ravi->employee_code]->reason)->toBe('Shared; not e-mailed: no work e-mail on file.');
    // Delivered by the tenant-bound job after the share's transaction (sync queue here).
    Mail::assertSent(NotificationMail::class, fn (NotificationMail $mail) => $mail->hasTo('asha@acme.test') && $mail->cc === [] && $mail->bcc === []
        && count($mail->files) === 1 && $mail->files[0]['content'] === '%PDF-1.4 a' && $mail->files[0]['name'] === 'a.pdf');
    expect($delivery->refresh()->status)->toBe('sent');
});

it('runs from the register: the wizard (opened by ?action=shareDocuments), the batch with its rows, errors and unpublish, the published documents list', function () {
    // Both screens sit with the letters in the People rail (group Letters, space Lifecycle), not in an area of their own.
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $areas = collect(app(ExperienceNavigation::class)->children($this->hr, 'people'));
    $letters = $areas->first(fn (array $a) => collect($a['modules'])->pluck('key')->contains(LetterResource::class));
    expect(collect($letters['modules'] ?? [])->pluck('key')->all())->toContain(DocumentShareBatchResource::class, PublishedDocumentResource::class)
        ->and($areas->pluck('label')->all())->not->toContain('Shared documents')->not->toContain('Published documents');

    // ?action=shareDocuments is Filament's default action: the page mounts it on load (wire:init).
    Livewire::withQueryParams(['action' => 'shareDocuments'])->test(ListDocumentShareBatches::class)->assertSet('defaultAction', 'shareDocuments')
        ->mountAction('shareDocuments')->assertActionMounted('shareDocuments');
    $this->get(DocumentShareBatchResource::getUrl('index', ['action' => 'shareDocuments']))->assertOk()->assertSee('wire:init="mountAction(', false);

    Livewire::test(ListDocumentShareBatches::class)
        ->callAction('shareDocuments', [
            'document_type_id' => $this->type->id, 'title' => 'Insurance e-card', 'message' => 'Your e-card', 'mode' => 'sheet',
            'zip' => wo14Zip(['asha.pdf' => '%PDF-1.4 card']), 'sheet' => wo14Sheet([[$this->asha->employee_code, 'asha'], ['NOPE-3', 'asha']]),
            'notify_in_app' => true, 'email' => false,
        ])
        ->assertHasNoActionErrors();
    $batch = DocumentShareBatch::query()->latest('id')->firstOrFail();
    expect($batch->title)->toBe('Insurance e-card')->and($batch->shared)->toBe(1)->and($batch->failed)->toBe(1)->and($batch->sheet_name)->toBe('sheet.csv');

    Livewire::test(ListDocumentShareBatches::class)->assertCanSeeTableRecords([$batch])->assertActionVisible('shareLetters');
    Livewire::test(ViewDocumentShareBatch::class, ['record' => $batch->getRouteKey()])
        ->assertActionVisible('downloadErrors')->assertActionVisible('unpublishBatch');
    Livewire::test(ItemsRelationManager::class, ['ownerRecord' => $batch, 'pageClass' => ViewDocumentShareBatch::class])
        ->assertCanSeeTableRecords(DocumentShareItem::query()->where('document_share_batch_id', $batch->id)->get())
        ->assertSee('No employee with this code in your access.');

    // A common file through the wizard, to pasted codes.
    Livewire::test(ListDocumentShareBatches::class)
        ->callAction('shareDocuments', [
            'document_type_id' => $this->type->id, 'title' => 'Code of conduct', 'mode' => 'common',
            'file' => UploadedFile::fake()->createWithContent('conduct.pdf', '%PDF-1.4 conduct'), 'source' => 'codes', 'codes' => $this->ravi->employee_code,
        ])
        ->assertHasNoActionErrors();
    $common = DocumentShareBatch::query()->latest('id')->firstOrFail();
    expect($common->mode)->toBe('common')->and($common->shared)->toBe(1)
        ->and(DocumentShareItem::query()->where('document_share_batch_id', $common->id)->sole()->document->original_name)->toBe('conduct.pdf');

    // The published documents list: one row per employee and document; filters; unpublish the rows ticked.
    $shared = DocumentShareItem::query()->where('status', 'shared')->get();
    Livewire::test(ListPublishedDocuments::class)->assertCanSeeTableRecords($shared)
        ->filterTable('batch', $common->id)->assertCanSeeTableRecords($shared->where('document_share_batch_id', $common->id))
        ->assertCanNotSeeTableRecords($shared->where('document_share_batch_id', $batch->id));
    Livewire::test(ListPublishedDocuments::class)->callTableBulkAction('unpublishSelected', $shared, ['reason' => 'Replaced'])->assertHasNoTableBulkActionErrors();
    expect(DocumentShareItem::query()->where('status', 'unpublished')->count())->toBe(2)
        ->and($batch->refresh()->unpublished_at)->not->toBeNull()->and($common->refresh()->unpublished_at)->not->toBeNull();
});

it('gates every screen and action with document.share', function () {
    $reader = tenantUser($this->tenant, ['document.view', 'document.upload', 'employee.view']);
    expect(fn () => app(DocumentShares::class)->start($reader, ['document_type_id' => $this->type->id, 'title' => 'X', 'mode' => 'common',
        'selection' => ['source' => 'codes', 'codes' => $this->asha->employee_code]], ['file' => UploadedFile::fake()->createWithContent('x.pdf', '%PDF-1.4 x')]))
        ->toThrow(RuntimeException::class, 'cannot share');

    $this->actingAs($reader);
    expect(DocumentShareBatchResource::canAccess())->toBeFalse()->and(PublishedDocumentResource::canAccess())->toBeFalse();
    $this->get(DocumentShareBatchResource::getUrl('index'))->assertForbidden();
    $this->get(PublishedDocumentResource::getUrl('index'))->assertForbidden();

    // An employee is never offered the share screens; a type kept from employees cannot be shared.
    $this->actingAs($this->asha->user);
    $this->get(DocumentShareBatchResource::getUrl('index'))->assertForbidden();
    $this->actingAs($this->hr);
    $hidden = DocumentType::query()->where('code', 'PAN')->firstOrFail();
    $hidden->update(['employee_can_view' => false]);
    expect(fn () => wo14Share(['document_type_id' => $hidden->id, 'mode' => 'common', 'selection' => ['source' => 'codes', 'codes' => $this->asha->employee_code]],
        ['file' => UploadedFile::fake()->createWithContent('x.pdf', '%PDF-1.4 x')]))->toThrow(RuntimeException::class, 'employees may see');

    $this->get(DocumentShareBatchResource::getUrl('index'))->assertOk();
    $this->get(PublishedDocumentResource::getUrl('index'))->assertOk();
});
