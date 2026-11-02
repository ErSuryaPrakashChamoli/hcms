<?php

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Models\AuditEvent;
use App\Domain\Compliance\Models\ComplianceRule;
use App\Domain\Compliance\Services\ComplianceRules;
use App\Domain\Configuration\Models\Policy;
use App\Domain\Configuration\Models\PolicyAssignmentRule;
use App\Domain\Configuration\Services\Policies;
use App\Domain\Documents\Models\EmployeeDocument;
use App\Domain\Employment\Models\Employee;
use App\Domain\Employment\Services\TransferRequests;
use App\Domain\Exit\Services\Exits;
use App\Domain\Experience\Models\BulkRunItem;
use App\Domain\Experience\Services\BulkRuns;
use App\Domain\Letters\Contracts\LetterSignatureProvider;
use App\Domain\Letters\Models\Letter;
use App\Domain\Letters\Models\LetterDownload;
use App\Domain\Letters\Models\LetterEmail;
use App\Domain\Letters\Models\LetterSchedule;
use App\Domain\Letters\Models\LetterScheduleItem;
use App\Domain\Letters\Models\LetterSignatory;
use App\Domain\Letters\Models\LetterTemplate;
use App\Domain\Letters\Services\LetterDownloads;
use App\Domain\Letters\Services\LetterEmails;
use App\Domain\Letters\Services\Letters;
use App\Domain\Letters\Services\LetterSalaryChecks;
use App\Domain\Letters\Services\LetterSchedules;
use App\Domain\Letters\Support\LetterTaskSource;
use App\Domain\Letters\Support\PolicyLetters;
use App\Domain\Notifications\Mail\NotificationMail;
use App\Domain\Notifications\Models\NotificationDelivery;
use App\Domain\Notifications\Services\Notifier;
use App\Domain\Organisation\Models\Department;
use App\Domain\Organisation\Models\Establishment;
use App\Domain\Organisation\Services\EstablishmentAssignments;
use App\Domain\Organisation\Services\LegalEntities;
use App\Domain\Platform\Services\SettingsRepository;
use App\Filament\Pages\WorkforceDesk;
use App\Filament\Resources\Letters\LetterResource;
use App\Filament\Resources\Letters\Pages\ListLetters;
use App\Filament\Resources\Letters\Pages\ViewLetter;
use App\Filament\Resources\LetterSchedules\LetterScheduleResource;
use App\Filament\Resources\LetterSchedules\Pages\CreateLetterSchedule;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;

require_once __DIR__.'/../Workflow/WorkflowTestHelpers.php';
require_once __DIR__.'/../Payroll/PayrollTestHelpers.php';

/*
| HROne parity WO-13 (matrix 23 §23.7.1, §23.7.2, §23.11 letter task kinds): letter registers and lifecycle — per-type
| views, process / template / signatory / generated-by filters, up to four signatories, revoke and generate again (D-WO5 /
| D-WO6: nothing deleted), e-mailing a letter (off by default), bulk generate / publish / download, salary-letter
| compliance warnings from verified rules only, the new letter types and policy hooks, letter schedules.
*/

beforeEach(function () {
    $this->travelTo('2026-10-20 10:00:00');
    syncComplianceRules();
    $this->tenant = provisionTenant();
    actAsTenant($this->tenant);
    $this->hr = tenantUser($this->tenant, ['*']);
    $this->actingAs($this->hr);
    Filament::getPanel('admin')->boot();
    $this->company = payrollCompany();
    // The employee signs in with only their own documents (no letter rights): "My letters".
    $this->employee = salariedEmployee(600000, ['document.own', 'task.view']);
    $this->letters = app(Letters::class);
    $this->issuer = tenantUser($this->tenant, ['letter.issue', 'letter.view', 'employee.view', 'employee.position', 'bulk.run']);
});

function wo13Signatory(string $name, int $width): LetterSignatory
{
    $image = imagecreatetruecolor($width, 40);
    ob_start();
    imagepng($image);
    $path = Letters::directory('signatures').'/'.Str::slug($name).'.png';
    Storage::disk(config('peopleos.documents.disk'))->put($path, (string) ob_get_clean());

    return LetterSignatory::create(['name' => $name, 'designation' => $name.' role', 'signature_disk' => config('peopleos.documents.disk'), 'signature_path' => $path]);
}

function wo13Template(array $attributes = []): LetterTemplate
{
    return LetterTemplate::create($attributes + [
        'name' => 'WO13 letter '.Str::random(4), 'code' => 'WO13_'.strtoupper(Str::random(5)), 'type' => 'custom', 'format' => LetterTemplate::HTML,
        'subject' => 'Letter for {{ employee.name }}', 'body' => '<p>Dear {{ employee.first_name }}, about {{ purpose }}.</p>', 'requires_approval' => false,
    ]);
}

function wo13Issued(LetterTemplate $template, Employee $employee, array $extra = ['purpose' => 'Visa']): Letter
{
    $letters = app(Letters::class);

    return $letters->issue($letters->generate($template, $employee, $extra, test()->issuer), test()->issuer);
}

function wo13Policy(string $type, array $settings): Policy
{
    $policy = Policy::create(['type' => $type, 'name' => $type.' letters', 'code' => strtoupper($type).'_WO13']);
    app(Policies::class)->draft($policy, $settings);
    app(Policies::class)->publish($policy, '2020-01-01');
    PolicyAssignmentRule::create(['policy_type' => $type, 'policy_id' => $policy->id, 'name' => 'All', 'conditions' => []]);

    return $policy;
}

function wo13Rule(string $code, ?string $state, array $parameters): ComplianceRule
{
    $version = (int) ComplianceRule::query()->where('code', $code)->when($state === null, fn ($q) => $q->whereNull('state'), fn ($q) => $q->where('state', $state))->max('version') + 1;
    $rule = ComplianceRule::query()->create(['jurisdiction' => 'IN', 'code' => $code, 'state' => $state, 'name' => $code.' test rule', 'version' => $version, 'effective_from' => '2025-01-01', 'parameters' => $parameters, 'status' => ComplianceRule::ACTIVE]);
    DB::table('compliance_rules')->where('id', $rule->id)->update(['verification_status' => ComplianceRule::VERIFIED]);
    app(ComplianceRules::class)->forget();

    return $rule->refresh();
}

/** A minimal .docx whose body is the given paragraphs' text. */
function wo13Docx(array $paragraphs): string
{
    $body = implode('', array_map(fn (string $text) => '<w:p><w:r><w:t>'.$text.'</w:t></w:r></w:p>', $paragraphs));
    $files = [
        '[Content_Types].xml' => '<?xml version="1.0" encoding="UTF-8"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/></Types>',
        '_rels/.rels' => '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/></Relationships>',
        'word/document.xml' => '<?xml version="1.0" encoding="UTF-8"?><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body>'.$body.'<w:sectPr/></w:body></w:document>',
    ];
    $tmp = tempnam(sys_get_temp_dir(), 'docx');
    $zip = new ZipArchive;
    $zip->open($tmp, ZipArchive::OVERWRITE);
    foreach ($files as $name => $content) {
        $zip->addFromString($name, $content);
    }
    $zip->close();
    $bytes = (string) file_get_contents($tmp);
    unlink($tmp);

    return $bytes;
}

/** @return array<string, string> part => content */
function wo13Zip(string $bytes): array
{
    $tmp = tempnam(sys_get_temp_dir(), 'zip');
    file_put_contents($tmp, $bytes);
    $zip = new ZipArchive;
    $zip->open($tmp);
    $parts = [];
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $parts[$zip->getNameIndex($i)] = (string) $zip->getFromIndex($i);
    }
    $zip->close();
    unlink($tmp);

    return $parts;
}

it('keeps today\'s behaviour by default: switches off, no new letters drafted, the register views and columns as before', function () {
    expect(config('peopleos.letters.types'))->toHaveKeys(['confirmation', 'transfer', 'relieving', 'probation_extension', 'termination', 'contract', 'transfer_intimation', 'resignation_acceptance', 'custom'])
        ->and(config('peopleos.letters.statuses'))->toHaveKey('revoked')
        ->and(app(SettingsRepository::class)->get('letters.email.enabled'))->toBeFalse()
        ->and(app(SettingsRepository::class)->get('letters.tasks.issue_queue'))->toBeFalse()
        ->and(app(LetterEmails::class)->enabled())->toBeFalse()
        ->and(app(LetterSignatureProvider::class)->available())->toBeFalse()
        ->and(app(LetterTaskSource::class)->tasksFor($this->hr, null))->toHaveCount(0);

    // The earlier views are unchanged; the new ones are added.
    $views = ListLetters::presetViews();
    expect($views['confirmation'])->toBe(['label' => 'Confirmation letters', 'filters' => ['type' => ['value' => 'confirmation']]])
        ->and($views['pending'])->toBe(['label' => 'Waiting for approval', 'filters' => ['status' => ['value' => 'pending_approval']]])
        ->and($views['increment']['filters'])->toBe(['type' => ['value' => 'increment']])
        ->and($views['probation_extension']['label'])->toBe('Extension letters')
        ->and($views)->toHaveKeys(['termination', 'contract', 'transfer_intimation', 'resignation_acceptance', 'to_issue', 'revoked']);

    // A resignation accepted and a transfer approved draft no letter without the new policy settings.
    $case = app(Exits::class)->resign($this->employee, 'Moving on', null, $this->employee->user);
    app(Exits::class)->acceptResignation($case, $this->hr);
    $request = app(TransferRequests::class)->request($this->employee, '2026-10-20', ['department_id' => Department::factory()->create()->id], null, null, null, $this->hr);
    app(TransferRequests::class)->approve($request, null, $this->issuer);
    expect(Letter::query()->count())->toBe(0);

    // The new columns are off by default (the register looks as before) and the new filters are HR's only.
    $page = Livewire::test(ListLetters::class)->assertOk();
    foreach (['process', 'template.name', 'requester.name', 'signatory.name'] as $column) {
        $page->assertTableColumnVisible($column);
        expect($page->instance()->isTableColumnToggledHidden($column))->toBeTrue();
    }
    $page->assertTableFilterVisible('process')->assertTableFilterVisible('letter_template_id');

    $this->actingAs($this->employee->user);
    Livewire::test(ListLetters::class)->assertOk()->assertTableColumnHidden('process')->assertTableFilterHidden('process')->assertTableFilterHidden('signatory')
        ->assertActionHidden('downloadMatching')->assertActionHidden('schedules');
});

it('prints up to four signatories, in written and Word letters', function () {
    [$a, $b, $c, $d] = [wo13Signatory('Neha Kapoor', 120), wo13Signatory('Arjun Mehta', 130), wo13Signatory('Priya Nair', 140), wo13Signatory('Rahul Verma', 150)];
    $template = wo13Template(['letter_signatory_id' => $a->id, 'letter_signatory_2_id' => $b->id, 'letter_signatory_3_id' => $c->id, 'letter_signatory_4_id' => $d->id,
        'body' => '<p>{{ signatory.name }} / {{ signatory_2.name }} / {{ signatory_3.name }}, {{ signatory_3.designation }} / {{ signatory_4.name }}</p><p>{{ signatory_2.signature }}</p>']);

    $letter = $this->letters->generate($template, $this->employee, [], $this->issuer);
    expect($letter->signatoryIds())->toBe([$a->id, $b->id, $c->id, $d->id])
        ->and($letter->body)->toContain('Neha Kapoor / Arjun Mehta / Priya Nair, Priya Nair role / Rahul Verma')->toContain('<img');

    // Chosen at generation, the others replace the template's; positions not named print blank.
    $only = $this->letters->generate($template, $this->employee, [], $this->issuer, null, $d, [3 => $a]);
    expect($only->signatoryIds())->toBe([$d->id, $a->id])->and($only->letter_signatory_3_id)->toBe($a->id)
        ->and($only->body)->toContain('Rahul Verma /  / Neha Kapoor, Neha Kapoor role / <');

    // A Word letter embeds each distinct signature as its own picture.
    $path = Letters::directory('templates').'/wo13.docx';
    Storage::disk(config('peopleos.documents.disk'))->put($path, wo13Docx(['{{ signatory.signature }}', '{{ signatory_2.signature }}', '{{ signatory_4.name }}']));
    $word = wo13Template(['format' => LetterTemplate::DOCX, 'file_disk' => config('peopleos.documents.disk'), 'file_path' => $path, 'body' => '',
        'letter_signatory_id' => $a->id, 'letter_signatory_2_id' => $b->id, 'letter_signatory_4_id' => $d->id]);
    $parts = wo13Zip($this->letters->file($this->letters->generate($word, $this->employee, [], $this->issuer))['content']);
    expect($parts)->toHaveKeys(['word/media/peopleos-signature.png', 'word/media/peopleos-signature-2.png'])
        ->and($parts['word/_rels/document.xml.rels'])->toContain('Id="rIdPeopleOSSignature"')->toContain('Id="rIdPeopleOSSignature2"')
        ->and($parts['word/document.xml'])->toContain('Rahul Verma');
});

it('revokes an issued letter: hidden from the employee, kept with its file, audited (D-WO5)', function () {
    $letter = wo13Issued(wo13Template(), $this->employee);
    $document = EmployeeDocument::query()->findOrFail($letter->document_id);
    $employeeUser = $this->employee->user;
    expect(Gate::forUser($employeeUser)->allows('view', $letter))->toBeTrue()->and(Gate::forUser($employeeUser)->allows('view', $document))->toBeTrue();

    expect(fn () => $this->letters->revoke($letter, tenantUser($this->tenant, ['letter.view']), 'Wrong address'))->toThrow(RuntimeException::class, 'cannot change')
        ->and(fn () => $this->letters->revoke($letter, $this->issuer, ' '))->toThrow(RuntimeException::class, 'Say why');

    $this->letters->revoke($letter, $this->issuer, 'Wrong salary figure');
    $letter->refresh();
    expect($letter->status)->toBe('revoked')->and($letter->revoked_by)->toBe($this->issuer->id)->and($letter->revoke_reason)->toBe('Wrong salary figure')
        ->and($document->refresh()->status)->toBe('archived')
        ->and(Storage::disk($document->disk)->exists($document->path))->toBeTrue()
        ->and(Letter::query()->whereKey($letter->id)->exists())->toBeTrue()
        // The employee no longer sees or opens it…
        ->and(Gate::forUser($employeeUser)->allows('view', $letter))->toBeFalse()
        ->and(Gate::forUser($employeeUser)->allows('view', $document))->toBeFalse()
        // …HR still reads and downloads it.
        ->and($this->letters->download($letter, $this->issuer)['name'])->toEndWith('.html')
        ->and(fn () => $this->letters->download($letter, $employeeUser))->toThrow(RuntimeException::class);
    expect(AuditEvent::query()->withoutGlobalScopes()->where('action', AuditAction::LetterRevoked->value)->where('entity_id', (string) $letter->id)->value('reason'))->toBe('Wrong salary figure')
        ->and(fn () => $this->letters->revoke($letter, $this->issuer, 'Again'))->toThrow(RuntimeException::class, 'Only an issued letter');

    $this->actingAs($employeeUser);
    expect(LetterResource::getEloquentQuery()->whereKey($letter->id)->exists())->toBeFalse();
});

it('generates a letter again as a new linked letter, revoking the old one only when the new one is issued (D-WO6)', function () {
    $old = wo13Issued(wo13Template(), $this->employee, ['purpose' => 'Visa application']);
    $reviewed = wo13Template(['requires_approval' => true, 'body' => '<p>Corrected: {{ purpose }}</p>']);

    $new = $this->letters->supersede($old, $this->issuer, 'Corrected wording', $reviewed);
    expect($new->supersedes_letter_id)->toBe($old->id)->and($new->status)->toBe('pending_approval')->and($new->body)->toContain('Corrected: Visa application')
        ->and($new->number)->not->toBe($old->number)
        ->and($old->refresh()->status)->toBe('issued')
        ->and(fn () => $this->letters->supersede($old, $this->issuer, 'Twice'))->toThrow(RuntimeException::class, 'already replaces');

    $this->letters->approve($new, $this->hr);
    $this->letters->issue($new->refresh(), $this->hr);
    expect($old->refresh()->status)->toBe('revoked')->and($old->revoke_reason)->toBe("Superseded by {$new->number}")
        ->and(EmployeeDocument::query()->find($old->document_id)->status)->toBe('archived')
        ->and(EmployeeDocument::query()->find($new->refresh()->document_id)->status)->not->toBe('archived')
        ->and(AuditEvent::query()->withoutGlobalScopes()->where('action', AuditAction::LetterSuperseded->value)->where('entity_id', (string) $old->id)->exists())->toBeTrue();

    // A letter not yet issued is revoked at once; with "issue", a replacement needing no approval is issued straight away.
    $draft = $this->letters->generate(wo13Template(), $this->employee, ['purpose' => 'Loan'], $this->issuer);
    $replacement = $this->letters->supersede($draft, $this->issuer, 'Other template', wo13Template(['body' => '<p>New {{ purpose }}</p>']), issue: true);
    expect($draft->refresh()->status)->toBe('revoked')->and($replacement->status)->toBe('issued')->and($replacement->body)->toContain('New Loan');
});

it('drafts letters on the new policy hooks and shows what drafted each letter', function () {
    $acceptance = wo13Template(['type' => 'resignation_acceptance']);
    $intimation = wo13Template(['type' => 'transfer_intimation', 'body' => '<p>From {{ transfer.effective_date }}: {{ transfer.changes }}</p>']);
    $transfer = wo13Template(['type' => 'transfer']);
    wo13Policy('exit', ['letter_on_acceptance' => $acceptance->id]);
    wo13Policy('transfer', ['letter_on_request_approval' => $intimation->id, 'letter_on_effect' => $transfer->id]);

    $case = app(Exits::class)->resign($this->employee, 'Moving on', null, $this->employee->user);
    expect(Letter::query()->where('process', 'exit.letter_on_acceptance')->exists())->toBeFalse();
    app(Exits::class)->acceptResignation($case, $this->hr);
    $letter = Letter::query()->where('process', 'exit.letter_on_acceptance')->sole();
    expect($letter->type)->toBe('resignation_acceptance')->and(PolicyLetters::label($letter))->toBe('Resignation accepted')
        ->and($letter->source_id)->toBe($case->id);

    // A transfer approved ahead of its date: the intimation now, the transfer letter on the effective date (once).
    $sales = Department::factory()->create(['name' => 'Sales']);
    $colleague = employeeWithUser();
    $request = app(TransferRequests::class)->request($colleague, '2026-10-25', ['department_id' => $sales->id], null, null, null, $this->hr);
    app(TransferRequests::class)->approve($request, 'ok', $this->issuer);
    $sent = Letter::query()->where('employee_id', $colleague->id)->get();
    expect($sent->pluck('process')->all())->toBe(['transfer.letter_on_request_approval'])
        ->and($sent->first()->body)->toContain('Department → Sales');

    expect(app(LetterSchedules::class)->transferEffects(now()))->toBe(0);
    $this->travelTo('2026-10-25 07:00:00');
    Artisan::call('peopleos:letters:tick', ['--tenant' => $this->tenant->id]);
    Artisan::call('peopleos:letters:tick', ['--tenant' => $this->tenant->id]);
    $effect = Letter::query()->where('employee_id', $colleague->id)->where('process', 'transfer.letter_on_effect')->sole();
    expect($effect->type)->toBe('transfer')->and(PolicyLetters::label($effect))->toBe('Transfer in effect');

    // Letters from before (no process kept) are named from their source; a letter by hand is "Manual".
    $legacy = $this->letters->generate(wo13Template(), $this->employee, [], $this->issuer, $case);
    expect(PolicyLetters::label($legacy))->toBe('Separation')
        ->and(PolicyLetters::label($this->letters->generate(wo13Template(), $this->employee, [], $this->issuer)))->toBe('Manual');
});

it('e-mails an issued letter with the file attached, to the person\'s addresses and allowed copies, only when switched on', function () {
    Mail::fake();
    $this->employee->person->update(['personal_email' => 'home@example.test']);
    $letter = wo13Issued(wo13Template(), $this->employee);
    $emails = app(LetterEmails::class);
    expect(fn () => $emails->send($letter, $this->issuer))->toThrow(RuntimeException::class, 'switched off');
    Livewire::test(ViewLetter::class, ['record' => $letter->id])->assertActionHidden('email');

    app(SettingsRepository::class)->set('letters.email.enabled', true, 'test');
    $colleague = tenantUser($this->tenant, ['task.view']);
    expect(fn () => $emails->send($letter, $this->issuer, ['personal']))->toThrow(RuntimeException::class, 'sensitive personal data')
        ->and(fn () => $emails->send($letter, $this->issuer, ['work'], ['cc' => ['addresses' => ['not an address']]]))->toThrow(RuntimeException::class, 'not an e-mail address')
        ->and(fn () => $emails->send($letter, $this->issuer, ['work'], ['cc' => ['users' => [platformAdmin()->id]]]))->toThrow(RuntimeException::class, 'people of your organisation')
        ->and(fn () => $emails->send($letter, tenantUser($this->tenant, ['letter.view']), ['work']))->toThrow(RuntimeException::class, 'cannot e-mail');

    $email = $emails->send($letter, $this->issuer, ['work'], ['cc' => ['users' => [$colleague->id], 'addresses' => ['Legal@Example.test']], 'bcc' => ['addresses' => ['archive@example.test']]], null, 'Your letter is attached.');
    $delivery = NotificationDelivery::query()->where('event', LetterEmails::EVENT)->sole();
    expect($email->notification_delivery_id)->toBe($delivery->id)->and($delivery->recipient)->toBe($this->employee->work_email)
        ->and($delivery->source_type)->toBe($email->getMorphClass())
        ->and($email->cc)->toEqualCanonicalizing([$colleague->email, 'Legal@Example.test'])->and($email->bcc)->toBe(['archive@example.test']);
    // Audited on the letter with the kinds of address and the number of copies — never the message.
    $audit = AuditEvent::query()->withoutGlobalScopes()->where('action', AuditAction::LetterEmailed->value)->where('entity_id', (string) $letter->id)->sole();
    expect($audit->metadata)->toMatchArray(['to' => ['work'], 'cc' => 2, 'bcc' => 1])->and(json_encode($audit->metadata))->not->toContain('Your letter is attached');

    // Sent on the delivery path with the copies and the letter attached.
    $delivery->update(['status' => 'queued']);
    app(Notifier::class)->deliver($delivery->refresh());
    Mail::assertSent(NotificationMail::class, fn (NotificationMail $mail) => $mail->hasTo($this->employee->work_email) && $mail->hasCc('Legal@Example.test') && $mail->hasCc($colleague->email)
        && $mail->hasBcc('archive@example.test') && count($mail->attachments()) === 1 && $mail->files[0]['name'] === Str::slug($letter->number).'.html');

    // A letter revoked before the mail goes is not sent (the delivery fails like any other send error).
    $again = $emails->send($letter, $this->issuer, ['work']);
    $this->letters->revoke($letter, $this->issuer, 'Withdrawn');
    $pending = NotificationDelivery::query()->findOrFail($again->notification_delivery_id);
    $pending->update(['status' => 'queued']);
    app(Notifier::class)->deliver($pending->refresh());
    expect($pending->refresh()->status)->toBe('failed')->and($pending->error)->toContain('no longer issued');
});

it('downloads issued letters as one zip — only those the person may open, each audited — for the person who asked', function () {
    $template = wo13Template();
    [$one, $two] = [wo13Issued($template, $this->employee), wo13Issued($template, employeeWithUser())];
    $waiting = $this->letters->generate(wo13Template(['requires_approval' => true]), $this->employee, [], $this->issuer);
    $reader = tenantUser($this->tenant, ['letter.view']);

    expect(fn () => app(LetterDownloads::class)->request(tenantUser($this->tenant, ['employee.view']), [$one->id]))->toThrow(RuntimeException::class, 'cannot download');

    $download = app(LetterDownloads::class)->request($reader, [$one->id, $two->id, $waiting->id]);
    expect($download->status)->toBe('ready')->and($download->included)->toBe(2)->and($download->skipped)->toBe(1)
        ->and(AuditEvent::query()->withoutGlobalScopes()->where('action', 'DOWNLOAD')->where('module', 'letters')->where('metadata->purpose', 'bulk_download')->count())->toBe(2);
    $file = app(LetterDownloads::class)->fetch($download, $reader);
    expect(array_keys(wo13Zip($file['content'])))->toHaveCount(2)
        ->and(fn () => app(LetterDownloads::class)->fetch($download, $this->issuer))->toThrow(RuntimeException::class, 'Only the person');

    $this->actingAs($reader);
    $this->get(LetterDownloads::url($download))->assertOk();
    $this->get(route('letters.downloads.zip', ['download' => $download->id]))->assertForbidden();
    $this->actingAs($this->issuer);
    $this->get(LetterDownloads::url($download))->assertForbidden();

    // Expired zips are removed by the daily tick; the row stays.
    $this->travel(25)->hours();
    Artisan::call('peopleos:letters:tick', ['--tenant' => $this->tenant->id]);
    expect($download->refresh()->status)->toBe('expired')->and($download->file_path)->toBeNull()
        ->and(LetterDownload::query()->count())->toBe(1);
});

it('generates letters in bulk from a chosen template or as per policy, and publishes letters not yet issued', function () {
    $acceptance = wo13Template(['type' => 'resignation_acceptance']);
    $ravi = employeeWithUser();
    $template = wo13Template();

    $run = app(BulkRuns::class)->start($this->issuer, 'employees', 'employees.generate_letter', [$this->employee->id, $ravi->id], ['template_mode' => 'chosen', 'template_id' => $template->id, 'issue' => true, 'extra' => ['purpose' => 'Bulk']]);
    expect($run->done)->toBe(2)->and(Letter::query()->where('letter_template_id', $template->id)->where('status', 'issued')->count())->toBe(2)
        ->and(Letter::query()->where('letter_template_id', $template->id)->pluck('process')->unique()->all())->toBe(['bulk']);

    // As per policy: only employees whose policy names a template get a letter.
    $run = app(BulkRuns::class)->start($this->issuer, 'employees', 'employees.generate_letter', [$this->employee->id], ['template_mode' => 'policy', 'hook' => 'exit.letter_on_acceptance']);
    expect($run->refused)->toBe(1)->and(BulkRunItem::query()->where('bulk_run_id', $run->id)->value('message'))->toContain('names no letter');
    wo13Policy('exit', ['letter_on_acceptance' => $acceptance->id]);
    $run = app(BulkRuns::class)->start($this->issuer, 'employees', 'employees.generate_letter', [$this->employee->id], ['template_mode' => 'policy', 'hook' => 'exit.letter_on_acceptance']);
    expect($run->done)->toBe(1)->and(Letter::query()->where('letter_template_id', $acceptance->id)->value('process'))->toBe('exit.letter_on_acceptance');

    // Generate & publish: approved letters are issued; letters waiting for approval are refused (approval is never skipped).
    $approved = $this->letters->generate(wo13Template(), $ravi, [], $this->hr);
    $waiting = $this->letters->generate(wo13Template(['requires_approval' => true]), $ravi, [], $this->hr);
    $run = app(BulkRuns::class)->start($this->issuer, 'letters', 'letters.publish', [$approved->id, $waiting->id], ['change_template' => false]);
    expect($approved->refresh()->status)->toBe('issued')->and($waiting->refresh()->status)->toBe('pending_approval')
        ->and($run->done)->toBe(1)->and($run->refused)->toBe(1);

    // …or with another template: a new letter linked to the old one, issued when it needs no approval.
    $other = wo13Template(['body' => '<p>Other</p>']);
    $run = app(BulkRuns::class)->start($this->issuer, 'letters', 'letters.publish', [$waiting->id], ['change_template' => true, 'template_id' => $other->id, 'reason' => 'Template changed']);
    $replacement = Letter::query()->where('supersedes_letter_id', $waiting->id)->sole();
    expect($run->done)->toBe(1)->and($replacement->status)->toBe('issued')->and($waiting->refresh()->status)->toBe('revoked');
});

it('warns on salary letters from verified rules only, reading pay through CompensationOutput', function () {
    $salary = wo13Template(['body' => '<p>Your CTC is {{ salary.ctc.annual }}.</p><p>{{ salary.annexure }}</p>']);
    $letter = $this->letters->generate($salary, $this->employee, [], $this->issuer);
    $plain = $this->letters->generate(wo13Template(), $this->employee, ['purpose' => 'x'], $this->issuer);
    // A fresh service each time: rule lookups are memoised for one request.
    $checks = fn () => app(LetterSalaryChecks::class);

    expect($letter->usesSalary())->toBeTrue()->and($checks()->forLetter($plain))->toBe([])
        // No verified rule: the checks say so and do not run (no fallback number).
        ->and(collect($checks()->forLetter($letter))->pluck('state', 'check')->all())->toBe(['wage_share' => 'no_rule', 'minimum_wage' => 'no_rule']);

    $resolved = app(EstablishmentAssignments::class)->resolve($this->employee, now())['establishment'];
    if ($resolved === null) {
        $entity = app(LegalEntities::class);
        $resolved = $entity->primaryEstablishment($this->company->id);
    }
    expect($resolved)->toBeInstanceOf(Establishment::class);
    $resolved->forceFill(['state' => 'KA'])->save();

    wo13Rule('WAGES', null, ['min_wage_share' => 99]);
    wo13Rule('MIN_WAGE', 'KA', ['monthly_minimum' => 999999]);
    $found = collect($checks()->forLetter($letter))->keyBy('check');
    expect($found['wage_share']['state'])->toBe('attention')->and($found['wage_share']['text'])->toContain('Exclusions are greater than wages')
        ->and($found['minimum_wage']['state'])->toBe('attention')->and($found['minimum_wage']['text'])->toContain('below the defined minimum wage of KA');

    wo13Rule('WAGES', null, ['min_wage_share' => 10]);
    wo13Rule('MIN_WAGE', 'KA', ['monthly_minimum' => 1]);
    expect(collect($checks()->forLetter($letter))->pluck('state')->unique()->all())->toBe(['ok']);

    $this->get(LetterResource::getUrl('view', ['record' => $letter]))->assertOk()->assertSee('Compliance warnings');
});

it('runs letter schedules once or around employee dates, once per employee and occurrence, as their owner', function () {
    $template = wo13Template(['body' => '<p>Happy birthday {{ employee.first_name }}</p>']);
    $ravi = employeeWithUser();
    $schedules = app(LetterSchedules::class);
    expect(fn () => $schedules->save(['name' => 'x'], tenantUser($this->tenant, ['letter.view'])))->toThrow(RuntimeException::class, 'cannot schedule');

    // One time, publish now: generated and issued at once for the named employees.
    $now = $schedules->save(['name' => 'Policy update', 'letter_template_id' => $template->id, 'trigger' => 'one_time', 'publish' => 'now', 'audience' => 'employees',
        'employee_ids' => [$this->employee->id, $ravi->id], 'issue' => true], $this->issuer);
    expect($now->refresh()->status)->toBe('completed')->and($now->letters_count)->toBe(2)
        ->and(Letter::query()->where('letter_schedule_id', $now->id)->where('status', 'issued')->where('process', 'schedule')->count())->toBe(2)
        ->and(LetterScheduleItem::query()->where('letter_schedule_id', $now->id)->pluck('outcome')->unique()->all())->toBe(['issued']);

    // Publish later: nothing before the time; the daily tick queues it for its time.
    $later = $schedules->save(['name' => 'Tomorrow', 'letter_template_id' => $template->id, 'trigger' => 'one_time', 'publish' => 'later', 'run_at' => '2026-10-21 09:00:00',
        'audience' => 'employees', 'employee_ids' => [$ravi->id]], $this->issuer);
    expect($later->refresh()->status)->toBe('active')->and(Letter::query()->where('letter_schedule_id', $later->id)->count())->toBe(0);

    // Event based: on the birthday (0 days), and three days before the work anniversary; saved for later does not run.
    $this->employee->person->update(['date_of_birth' => '1990-10-21']);
    $birthday = $schedules->save(['name' => 'Birthday letters', 'letter_template_id' => $template->id, 'trigger' => 'event', 'event' => 'date_of_birth', 'offset_days' => 0,
        'audience' => 'criteria', 'audience_criteria' => [], 'issue' => true], $this->issuer);
    $anniversary = $schedules->save(['name' => 'Anniversary', 'letter_template_id' => $template->id, 'trigger' => 'event', 'event' => 'work_anniversary', 'offset_direction' => 'before', 'offset_days' => 3,
        'audience' => 'employees', 'employee_ids' => [$ravi->id], 'issue' => false], $this->issuer);
    $parked = $schedules->save(['name' => 'Parked', 'letter_template_id' => $template->id, 'trigger' => 'event', 'event' => 'date_of_birth', 'audience' => 'criteria', 'save_for_later' => true], $this->issuer);
    expect($anniversary->offset_days)->toBe(-3)->and($parked->status)->toBe('saved');
    $ravi->update(['joining_date' => '2024-10-24']);

    $this->travelTo('2026-10-21 09:30:00');
    Artisan::call('peopleos:letters:tick', ['--tenant' => $this->tenant->id]);
    Artisan::call('peopleos:letters:tick', ['--tenant' => $this->tenant->id]);
    expect($later->refresh()->status)->toBe('completed')->and(Letter::query()->where('letter_schedule_id', $later->id)->count())->toBe(1)
        ->and(Letter::query()->where('letter_schedule_id', $birthday->id)->pluck('employee_id')->all())->toBe([$this->employee->id])
        ->and(LetterScheduleItem::query()->where('letter_schedule_id', $birthday->id)->value('occurrence')->toDateString())->toBe('2026-10-21')
        ->and(Letter::query()->where('letter_schedule_id', $anniversary->id)->where('employee_id', $ravi->id)->value('status'))->toBe('approved')
        ->and(Letter::query()->where('letter_schedule_id', $parked->id)->count())->toBe(0);

    // The owner can no longer issue letters: the schedule pauses with the reason, and writes nothing.
    $owner = tenantUser($this->tenant, ['letter.issue', 'employee.view']);
    $orphan = $schedules->save(['name' => 'Orphan', 'letter_template_id' => $template->id, 'trigger' => 'event', 'event' => 'date_of_birth', 'audience' => 'criteria'], $owner);
    $owner->roles()->detach();
    $owner->flushPermissionCache();
    $this->travelTo('2027-10-21 09:30:00');
    Artisan::call('peopleos:letters:tick', ['--tenant' => $this->tenant->id]);
    expect($orphan->refresh()->status)->toBe('paused')->and($orphan->last_error)->toContain('can no longer issue letters')
        ->and(Letter::query()->where('letter_schedule_id', $orphan->id)->count())->toBe(0);
});

it('lists letters ready to issue as tasks only when switched on', function () {
    $this->letters->generate(wo13Template(['type' => 'confirmation']), $this->employee, [], $this->hr);
    expect(app(LetterTaskSource::class)->tasksFor($this->issuer, null))->toHaveCount(0);

    app(SettingsRepository::class)->set('letters.tasks.issue_queue', true, 'test');
    $tasks = app(LetterTaskSource::class)->tasksFor($this->issuer, null);
    expect($tasks)->toHaveCount(1)->and($tasks->first()->title)->toContain('Issue the confirmation letter of')
        ->and(app(LetterTaskSource::class)->tasksFor(tenantUser($this->tenant, ['letter.view']), null))->toHaveCount(0);
});

it('renders the letter screens, acts from the letter page, and keeps schedules tenant-isolated', function () {
    $letter = wo13Issued(wo13Template(), $this->employee);
    $this->get(LetterResource::getUrl('index', ['view' => 'probation_extension']))->assertOk();
    Livewire::withQueryParams(['view' => 'transfer_intimation'])->test(ListLetters::class)->assertOk()->assertSet('tableFilters.type.value', 'transfer_intimation');
    $this->get(LetterScheduleResource::getUrl('index'))->assertOk()->assertSee('Schedule letters');
    $this->get(LetterScheduleResource::getUrl('create'))->assertOk();
    $this->get(WorkforceDesk::getUrl())->assertOk()->assertSee('Extension letters')->assertSee('Letter schedules');

    Livewire::test(ViewLetter::class, ['record' => $letter->id])->assertActionVisible('revoke')->assertActionVisible('supersede')
        ->callAction('revoke', data: ['reason' => 'Issued by mistake'])->assertNotified('Letter revoked');
    expect($letter->refresh()->status)->toBe('revoked');
    $this->get(LetterResource::getUrl('view', ['record' => $letter]))->assertOk()->assertSee('Issued by mistake');

    $template = wo13Template();
    Livewire::test(CreateLetterSchedule::class)->fillForm(['name' => 'Through the form', 'letter_template_id' => $template->id, 'trigger' => 'one_time', 'publish' => 'later',
        'run_at' => '2026-11-01 10:00', 'audience' => 'employees', 'employee_ids' => [(string) $this->employee->id]])->call('create')->assertHasNoFormErrors();
    $schedule = LetterSchedule::query()->where('name', 'Through the form')->sole();
    expect($schedule->status)->toBe('active')->and($schedule->run_at->format('Y-m-d H:i'))->toBe('2026-11-01 10:00');
    $this->get(LetterScheduleResource::getUrl('view', ['record' => $schedule]))->assertOk()->assertSee('One time · publish later');

    $other = provisionTenant('Other');
    actAsTenant($other);
    expect(LetterSchedule::query()->count())->toBe(0)->and(LetterEmail::query()->count())->toBe(0);
});
