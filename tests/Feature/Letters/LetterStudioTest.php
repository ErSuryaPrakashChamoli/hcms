<?php

use App\Domain\Audit\Models\AuditEvent;
use App\Domain\Documents\Models\EmployeeDocument;
use App\Domain\Letters\Models\Letter;
use App\Domain\Letters\Models\LetterSignatory;
use App\Domain\Letters\Models\LetterTemplate;
use App\Domain\Letters\Services\Letters;
use App\Domain\Letters\Support\DocxLetter;
use App\Domain\Letters\Support\LetterVariables;
use App\Domain\Letters\Support\SalaryAnnexure;
use App\Domain\People\Models\PersonFamilyMember;
use App\Filament\Resources\Letters\Pages\ViewLetter;
use App\Filament\Resources\LetterSignatories\Pages\ManageLetterSignatories;
use App\Filament\Resources\LetterTemplates\Pages\ManageLetterTemplates;
use App\Filament\Support\LetterVariablesUi;
use Filament\Actions\Testing\TestAction;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

require_once __DIR__.'/../Workflow/WorkflowTestHelpers.php';
require_once __DIR__.'/../Payroll/PayrollTestHelpers.php';

beforeEach(function () {
    $this->travelTo('2026-09-21 09:00:00');
    syncComplianceRules();
    $this->tenant = provisionTenant();
    actAsTenant($this->tenant);
    $this->hr = tenantUser($this->tenant, ['*']);
    $this->actingAs($this->hr);
    $this->company = payrollCompany();
    $this->employee = salariedEmployee(600000);
    $this->letters = app(Letters::class);
});

/** A small PNG signature on the documents disk. */
function letterSignatory(array $attributes = []): LetterSignatory
{
    $image = imagecreatetruecolor(120, 40);
    ob_start();
    imagepng($image);
    $path = Letters::directory('signatures').'/sign.png';
    Storage::disk(config('peopleos.documents.disk'))->put($path, (string) ob_get_clean());

    return LetterSignatory::create($attributes + ['name' => 'Neha Kapoor', 'designation' => 'Head of HR', 'signature_disk' => config('peopleos.documents.disk'), 'signature_path' => $path, 'is_default' => true]);
}

/** A .docx whose body splits a variable over two runs (as Word does), with a header, an annexure line and a signature line. */
function letterDocx(string $body = ''): string
{
    $paragraph = fn (string $runs) => "<w:p><w:pPr><w:jc w:val=\"left\"/></w:pPr>{$runs}</w:p>";
    $run = fn (string $text, bool $bold = false) => '<w:r>'.($bold ? '<w:rPr><w:b/></w:rPr>' : '').'<w:t>'.$text.'</w:t></w:r>';
    $body = $body ?: implode('', [
        $paragraph($run('Dear ').$run('{{ employee.').'<w:proofErr w:type="spellStart"/>'.$run('first_name }}', true).'<w:proofErr w:type="spellEnd"/>'.$run(',')),
        $paragraph($run('Your CTC is {{ salary.ctc.annual }} a year ({{ salary.ctc.annual_words }}) and Basic {{ salary.basic.monthly }} a month.')),
        $paragraph($run('{{ salary.annexure }}')),
        $paragraph($run('{{ signatory.signature }}')),
        $paragraph($run('{{ signatory.name }}, {{ signatory.designation }} &amp; {{ purpose }}')),
    ]);
    $files = [
        '[Content_Types].xml' => '<?xml version="1.0" encoding="UTF-8"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/><Override PartName="/word/header1.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.header+xml"/></Types>',
        '_rels/.rels' => '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/></Relationships>',
        'word/_rels/document.xml.rels' => '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId9" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/header" Target="header1.xml"/></Relationships>',
        'word/header1.xml' => '<?xml version="1.0" encoding="UTF-8"?><w:hdr xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:p><w:r><w:t>{{ company.name }}</w:t></w:r></w:p></w:hdr>',
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

/** @return array<string, string> part name => content */
function docxParts(string $bytes): array
{
    $tmp = tempnam(sys_get_temp_dir(), 'docx');
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

it('fills every catalogue variable for an employee, with the salary annexure monthly and yearly', function () {
    PersonFamilyMember::create(['person_id' => $this->employee->person_id, 'name' => 'Ravi Rao', 'relation' => 'father']);
    $context = $this->letters->context($this->employee->refresh(), [], letterSignatory());

    // The guide never promises a variable the letter is not filled with.
    $fixed = collect(LetterVariables::GROUPS)->flatMap(fn (array $group) => array_keys($group[2]));
    expect($fixed->reject(fn (string $path) => Arr::has($context, $path))->values()->all())->toBe([]);

    expect(Arr::get($context, 'salary.ctc.annual'))->toBe('600,000.00')
        ->and(Arr::get($context, 'salary.ctc.monthly'))->toBe('50,000.00')
        ->and(Arr::get($context, 'salary.ctc.annual_words'))->toBe('Six lakh rupees only')
        ->and(Arr::get($context, 'salary.basic'))->toBe(['name' => 'Basic', 'monthly' => '20,000.00', 'annual' => '240,000.00'])
        ->and(Arr::get($context, 'salary.gross'))->toBe(['monthly' => '47,600.00', 'annual' => '571,200.00'])
        ->and(Arr::get($context, 'salary.employer_contributions'))->toBe(['monthly' => '2,400.00', 'annual' => '28,800.00'])
        ->and(Arr::get($context, 'employee.father_name'))->toBe('Ravi Rao')
        ->and(Arr::get($context, 'employee.ctc_annual'))->toBe('600,000.00')
        ->and(Arr::get($context, 'signatory.name'))->toBe('Neha Kapoor');

    $annexure = Arr::get($context, 'salary.annexure');
    expect($annexure)->toBeInstanceOf(SalaryAnnexure::class)
        ->and(collect($annexure->rows)->where('kind', '!=', 'heading')->mapWithKeys(fn (array $row) => [$row['label'] => [$row['monthly'], $row['annual']]])->all())->toBe([
            'Basic' => [20000.0, 240000.0], 'House rent allowance' => [10000.0, 120000.0], 'Conveyance allowance' => [1600.0, 19200.0], 'Special allowance' => [16000.0, 192000.0],
            'Gross salary' => [47600.0, 571200.0], 'Employer PF' => [2400.0, 28800.0], 'Total employer contributions' => [2400.0, 28800.0], 'Cost to company (CTC)' => [50000.0, 600000.0],
        ]);
});

it('renders a written template: variable chips and typed variables, escaped values, the annexure table and the signature', function () {
    $this->employee->person->update(['first_name' => 'Asha<script>alert(1)</script>']);
    letterSignatory();
    $template = LetterTemplate::create(['name' => 'Offer', 'code' => 'OFFER_X', 'type' => 'offer', 'subject' => 'Offer for {{ employee.first_name }}', 'requires_approval' => false,
        'body' => '<p>Dear <span data-type="mergeTag" data-id="employee.first_name"></span>, your CTC is <strong>{{ salary.ctc.annual }}</strong>.</p><p>{{ salary.annexure }}</p><p>{{ signatory.signature }}</p><p>{{ signatory.name }}</p><script>steal()</script>']);

    $letter = $this->letters->generate($template, $this->employee->refresh(), [], $this->hr);

    expect($letter->format)->toBe('html')
        ->and($letter->body)->toContain('Dear Asha&lt;script&gt;alert(1)&lt;/script&gt;, your CTC is <strong>600,000.00</strong>')
        ->toContain('<table class="salary-annexure"')->toContain('571,200.00')->toContain('<img class="signature"')->toContain('data:image/png;base64,')->toContain('Neha Kapoor')
        ->not->toContain('<script>')->not->toContain('<p><table')
        ->and($letter->signatory->name)->toBe('Neha Kapoor')
        // The snapshot keeps what was printed, never the signature image, and only the variables the template used.
        ->and($letter->context['salary']['annexure'][1])->toEqual(['kind' => 'line', 'label' => 'Basic', 'monthly' => 20000, 'annual' => 240000])
        ->and(json_encode($letter->context))->not->toContain('base64')
        ->and($letter->context)->not->toHaveKey('statutory');

    $this->letters->issue($letter, $this->hr);
    $file = $this->letters->download($letter->refresh(), $this->hr);
    expect($file['mime'])->toBe('text/html')->and($file['content'])->toContain('salary-annexure')->toContain('<h2>Offer for Asha&lt;script&gt;');
});

it('fills an uploaded Word template, joining the runs Word split a variable over, with the annexure table and the signature', function () {
    letterSignatory();
    $path = Letters::directory('templates').'/offer.docx';
    Storage::disk(config('peopleos.documents.disk'))->put($path, letterDocx());
    $template = LetterTemplate::create(['name' => 'Our offer', 'code' => 'OUR_OFFER', 'type' => 'offer', 'format' => 'docx', 'subject' => 'Offer – {{ employee.name }}', 'requires_approval' => true,
        'file_disk' => config('peopleos.documents.disk'), 'file_path' => $path, 'file_name' => 'offer.docx']);

    expect($this->letters->check($template))->toBe([
        'used' => ['employee.name', 'employee.first_name', 'salary.ctc.annual', 'salary.ctc.annual_words', 'salary.basic.monthly', 'salary.annexure', 'signatory.signature', 'signatory.name', 'signatory.designation', 'purpose', 'company.name'],
        'extras' => ['purpose'],
    ]);

    $letter = $this->letters->generate($template, $this->employee, ['purpose' => 'a visa'], $this->hr);
    $first = $this->employee->person->first_name;
    expect($letter->format)->toBe('docx')->and($letter->status)->toBe('pending_approval')
        ->and($letter->body)->toContain("Dear {$first},")->toContain('Your CTC is 600,000.00 a year (Six lakh rupees only) and Basic 20,000.00 a month.')->toContain('Neha Kapoor, Head of HR & a visa');

    $parts = docxParts($this->letters->draft($letter, $this->hr)['content']);
    $document = $parts['word/document.xml'];
    expect($document)->toContain('<w:t xml:space="preserve">Dear </w:t></w:r><w:r><w:t xml:space="preserve">'.$first.'</w:t>')
        ->toContain('<w:tbl>')->toContain('Cost to company (CTC)')->toContain('571,200.00')
        ->toContain('r:embed="rIdPeopleOSSignature"')->toContain('Neha Kapoor, Head of HR &amp; a visa')
        ->not->toContain('{{')
        ->and($parts['word/header1.xml'])->toContain($this->company->legal_name ?: $this->company->name)
        ->and($parts['word/_rels/document.xml.rels'])->toContain('Target="media/peopleos-signature.png"')->toContain('Target="header1.xml"')
        ->and($parts)->toHaveKey('word/media/peopleos-signature.png')
        ->and($parts['[Content_Types].xml'])->toContain('<Default Extension="png" ContentType="image/png"/>');
    foreach (['word/document.xml', 'word/header1.xml', 'word/_rels/document.xml.rels', '[Content_Types].xml'] as $part) {
        expect((new DOMDocument)->loadXML($parts[$part]))->toBeTrue();
    }

    $this->letters->approve($letter, tenantUser($this->tenant, ['letter.issue']), 'ok');
    $this->letters->issue($letter->refresh(), $this->hr);
    $document = EmployeeDocument::query()->findOrFail($letter->refresh()->document_id);
    expect($document->original_name)->toBe('ltr-2026-00001.docx')->and($document->mime_type)->toBe(Letters::DOCX_MIME)
        ->and($this->letters->download($letter, $this->hr))->toMatchArray(['name' => 'ltr-2026-00001.docx', 'mime' => Letters::DOCX_MIME]);
});

it('refuses a variable Word cannot hold, a file that is not a Word document, and starts clients from a sample', function () {
    $tmp = tempnam(sys_get_temp_dir(), 'docx');
    file_put_contents($tmp, 'not a zip');
    expect(DocxLetter::problem($tmp))->toBe('The file is not a Word (.docx) document.');

    file_put_contents($tmp, DocxLetter::sample());
    expect(DocxLetter::problem($tmp))->toBeNull()
        ->and(DocxLetter::variables($tmp))->toContain('salary.annexure', 'signatory.signature', 'salary.ctc.annual_words', 'manager.name')
        ->and(LetterVariables::extras(DocxLetter::variables($tmp)))->toBe([]);

    // A variable broken up by a hyperlink cannot be filled: the upload says which one to retype.
    file_put_contents($tmp, letterDocx('<w:p><w:r><w:t>{{ employee.</w:t></w:r><w:hyperlink><w:r><w:t>name }}</w:t></w:r></w:hyperlink></w:p>'));
    expect(DocxLetter::problem($tmp))->toBe('In the Word file, {{ employee.name }} is broken up by a link, field or table. Delete it and type it again in one go.');
    unlink($tmp);
});

it('previews a template for an employee without generating a letter, audited once', function () {
    $template = LetterTemplate::query()->where('code', 'SALARY_CERT')->firstOrFail();
    $before = AuditEvent::query()->where('module', 'letters')->where('action', 'VIEW')->count();

    $first = $this->letters->preview($template, $this->employee, $this->hr);
    $this->letters->preview($template, $this->employee, $this->hr);

    expect($first['mime'])->toBe('text/html')->and($first['content'])->toContain('Authorised Signatory')->toContain('salary-annexure')->toContain('Six lakh rupees only')
        ->and(Letter::query()->count())->toBe(0)
        ->and(AuditEvent::query()->where('module', 'letters')->where('action', 'VIEW')->count())->toBe($before + 1);
});

it('starts tenants on rich-text templates that sign with the signing authority', function () {
    $template = LetterTemplate::query()->where('code', 'EMPLOYMENT_CERT')->firstOrFail();
    expect($template->format)->toBe('html')->and($template->body)->toContain('<p>')->toContain('{{ signatory.signature }}');

    $letter = $this->letters->generate($template, $this->employee, [], $this->hr);
    expect($letter->body)->toContain('Authorised Signatory')->not->toContain('{{');

    letterSignatory(['name' => 'Second', 'is_default' => true]);
    letterSignatory(['name' => 'Third', 'is_default' => true]);
    expect(LetterSignatory::query()->where('is_default', true)->pluck('name')->all())->toBe(['Third'])
        ->and($this->letters->generate($template, $this->employee, [], $this->hr)->body)->toContain('Third');
});

it('puts the variables guide beside New letter template, to view, download and start a Word template from', function () {
    expect(LetterVariablesUi::guideAction()->getModalContent()->render())
        ->toContain('How to use variables')->toContain('{{ salary.annexure }}')->toContain('{{ salary.basic.monthly }}')->toContain('{{ signatory.signature }}')
        ->and(LetterVariablesUi::guideDocument())->toStartWith('<!doctype html>')->toContain('{{ employee.joining_date }}')
        ->and(LetterVariablesUi::csv())->toStartWith("Group,Variable,Meaning,Example\n")->toContain('"{{ salary.hra.annual }}"');

    $screen = fn () => Livewire::test(ManageLetterTemplates::class)->assertOk();
    $screen()->assertActionExists('letterVariables')->mountAction('letterVariables')->assertActionMounted('letterVariables');
    $screen()->callAction([TestAction::make('letterVariables'), TestAction::make('downloadVariablesGuide')])->assertFileDownloaded('letter-variables.html');
    $screen()->callAction([TestAction::make('letterVariables'), TestAction::make('downloadVariablesList')])->assertFileDownloaded('letter-variables.csv');
    $screen()->callAction([TestAction::make('letterVariables'), TestAction::make('downloadSampleWordTemplate')])->assertFileDownloaded('peopleos-letter-template.docx');
});

it('creates a template from an uploaded Word file through the form, warns about unknown variables, and previews it', function () {
    Livewire::test(ManageLetterTemplates::class)
        ->callAction('create', ['name' => 'Bank letter', 'code' => 'BANK', 'type' => 'custom', 'format' => 'docx', 'subject' => 'Letter for {{ employee.name }}', 'status' => 'active',
            'file_path' => UploadedFile::fake()->createWithContent('bank.docx', letterDocx())])
        ->assertHasNoActionErrors()->assertNotified('Check these variables');

    $template = LetterTemplate::query()->where('code', 'BANK')->sole();
    expect($template->format)->toBe('docx')->and($template->file_name)->toBe('bank.docx')->and($template->body)->toContain('{{ salary.annexure }}')
        ->and(Storage::disk($template->file_disk)->exists($template->file_path))->toBeTrue()
        ->and($template->file_path)->toStartWith("tenants/{$this->tenant->id}/letters/templates/");

    Livewire::test(ManageLetterTemplates::class)
        ->callAction('create', ['name' => 'Not Word', 'code' => 'NOTWORD', 'type' => 'custom', 'format' => 'docx', 'subject' => 'x', 'status' => 'active',
            'file_path' => UploadedFile::fake()->createWithContent('fake.docx', 'plain text')])
        ->assertHasActionErrors();

    $preview = $this->letters->preview($template, $this->employee, $this->hr);
    expect(view('filament.letters.template-preview', ['message' => null, 'page' => null, 'text' => $preview['text']])->render())->toContain('Dear '.$this->employee->person->first_name.',');

    Livewire::test(ManageLetterTemplates::class)
        ->mountAction(TestAction::make('preview')->table($template))
        ->setActionData(['employee_id' => $this->employee->id])
        ->callMountedAction()->assertFileDownloaded('bank-'.strtolower($this->employee->employee_code).'-preview.docx');
});

it('writes a template in the editor with variable chips, and switching it to a Word file and back keeps one file at a time', function () {
    $body = '<p>Dear <span data-type="mergeTag" data-id="employee.first_name"></span>,</p><p>{{ salary.annexure }}</p>';
    Livewire::test(ManageLetterTemplates::class)
        ->callAction('create', ['name' => 'Written', 'code' => 'WRITTEN', 'type' => 'custom', 'format' => 'html', 'subject' => 'For {{ employee.name }}', 'status' => 'active', 'body' => $body])
        ->assertHasNoActionErrors()->assertNotNotified('Check these variables');
    $template = LetterTemplate::query()->where('code', 'WRITTEN')->sole();
    expect($template->format)->toBe('html')->and($template->body)->toContain('data-id="employee.first_name"')->and($template->version)->toBe(1);

    Livewire::test(ManageLetterTemplates::class)
        ->callAction(TestAction::make('edit')->table($template), ['format' => 'docx', 'file_path' => UploadedFile::fake()->createWithContent('ours.docx', letterDocx()), 'audit_reason' => 'Our own letterhead'])
        ->assertHasNoActionErrors();
    $file = $template->refresh()->file_path;
    expect($template->format)->toBe('docx')->and($template->version)->toBe(2)->and(Storage::disk($template->file_disk)->exists($file))->toBeTrue();

    Livewire::test(ManageLetterTemplates::class)
        ->callAction(TestAction::make('edit')->table($template), ['format' => 'html', 'body' => $body])
        ->assertHasNoActionErrors();
    expect($template->refresh()->format)->toBe('html')->and($template->file_path)->toBeNull()
        ->and(Storage::disk(config('peopleos.documents.disk'))->exists($file))->toBeFalse();
});

it('sets up signing authorities with a private signature image, and shows a letter as issued', function () {
    Livewire::test(ManageLetterSignatories::class)->assertOk()
        ->callAction('create', ['name' => 'Neha Kapoor', 'designation' => 'Head of HR', 'is_default' => true, 'status' => 'active',
            'signature_upload' => UploadedFile::fake()->image('sign.png', 120, 40)])
        ->assertHasNoActionErrors();
    $signatory = LetterSignatory::query()->sole();
    expect($signatory->signatureImage()['mime'])->toBe('image/png')->and($signatory->signature_path)->toStartWith("tenants/{$this->tenant->id}/letters/signatures/");

    Livewire::test(ManageLetterSignatories::class)
        ->callAction('create', ['name' => 'Fake', 'status' => 'active', 'signature_upload' => UploadedFile::fake()->createWithContent('sign.png', '<svg xmlns="http://www.w3.org/2000/svg"/>')])
        ->assertHasActionErrors();

    $letter = $this->letters->generate('salary_certificate', $this->employee, [], $this->hr);
    Livewire::test(ViewLetter::class, ['record' => $letter->getRouteKey()])->assertOk()->assertSee('Neha Kapoor')->assertSee('srcdoc', false);
});
