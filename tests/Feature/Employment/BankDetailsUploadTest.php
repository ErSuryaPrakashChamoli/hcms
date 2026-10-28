<?php

use App\Domain\Audit\Models\AuditEvent;
use App\Domain\Employment\Actions\ChangeBankAccountAction;
use App\Domain\Employment\Actions\HireEmployeeAction;
use App\Domain\Employment\Enums\ImportMode;
use App\Domain\Employment\Imports\BankAccountImports;
use App\Domain\Employment\Imports\EmployeeImport;
use App\Domain\Employment\Imports\EmployeeImports;
use App\Domain\Employment\Models\EmployeeBankAccount;
use App\Domain\Lifecycle\Models\EmployeeTimelineEntry;
use App\Domain\Organisation\Models\Company;
use App\Filament\Resources\BankAccountImports\BankAccountImportResource;
use App\Filament\Resources\BankAccountImports\Pages\ListBankAccountImports;
use App\Filament\Resources\BankAccountImports\Pages\ViewBankAccountImport;
use App\Filament\Resources\EmployeeImports\RelationManagers\RowsRelationManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

/* Bank details uploads: every row goes through ChangeBankAccountAction; account numbers are never staged or shown in clear. */

beforeEach(function () {
    $this->travelTo('2026-10-20 09:00:00');
    Storage::fake('local');
    config(['peopleos.documents.disk' => 'local']);
    $this->tenant = provisionTenant();
    actAsTenant($this->tenant);
    $this->hr = tenantUser($this->tenant, ['employee.view', 'employee.import', 'employee.sensitive.view', 'employee.sensitive.update']);
    $this->actingAs($this->hr);
    $company = Company::factory()->create();
    $hire = fn (string $first, string $code) => app(HireEmployeeAction::class)->handle(['first_name' => $first, 'last_name' => 'Test'], ['joining_date' => '2025-01-01', 'employee_code' => $code], ['company_id' => $company->id]);
    $this->fresh = $hire('Asha', 'EMP1');
    $this->paid = $hire('Vikram', 'EMP2');
    $this->other = $hire('Neel', 'EMP3');
    $this->onFile = app(ChangeBankAccountAction::class)->add($this->paid, ['account_holder_name' => 'Vikram Test', 'bank_name' => 'SBI', 'account_number' => '11112222333', 'ifsc' => 'SBIN0000001', 'is_primary' => true], $this->hr);
    $this->imports = app(BankAccountImports::class);
});

function stageBankCsv(string $csv, ImportMode $mode = ImportMode::Both): EmployeeImport
{
    $path = EmployeeImports::directory().'/'.uniqid().'.csv';
    Storage::disk('local')->put($path, $csv);

    return app(BankAccountImports::class)->register($path, 'bank.csv', auth()->user(), mode: $mode);
}

function validatedBankUpload(string $csv, ImportMode $mode = ImportMode::Both): EmployeeImport
{
    $imports = app(BankAccountImports::class);
    $import = $imports->inspect(stageBankCsv($csv, $mode));

    return $imports->validate($imports->map($import, $import->mapping));
}

const BANK_HEADER = "Employee code,Account holder name,Bank name,Account number,IFSC,Branch name,Account type\n";

it('adds accounts for employees without one and replaces the salary account of those with one, through the Employee 360 action', function () {
    $csv = BANK_HEADER
        ."EMP1,Asha Test,HDFC Bank,000 111 222 333,hdfc0001234,Andheri,salary\n"   // none on file -> add (spaces removed, IFSC upper-cased)
        ."EMP2,Vikram Test,ICICI Bank,99998888777,ICIC0000002,,savings\n"          // on file -> update the primary account
        ."NOBODY,X,Y,123456,,,\n"
        ."EMP3,Neel Test,Axis,5.01E+13,,,\n"                                       // spreadsheet turned the number into a float
        ."EMP3,Neel Test,Axis,123,BAD,,cheque\n";
    $import = $this->imports->inspect(stageBankCsv($csv));
    expect($import->type)->toBe(BankAccountImports::TYPE)
        ->and($import->mapping)->toMatchArray(['Employee code' => 'employee_code', 'Account number' => 'account_number', 'IFSC' => 'ifsc', 'Account type' => 'account_type']);

    // Staging holds no account number in clear.
    $raw = DB::table('employee_import_rows')->where('employee_import_id', $import->id)->get();
    expect($raw->pluck('data')->implode(' '))->not->toContain('99998888777')
        ->and($raw->pluck('sealed_data')->implode(' '))->not->toContain('99998888777');

    $import = $this->imports->validate($this->imports->map($import, $import->mapping));
    $rows = $import->rows()->get()->keyBy('row_number');
    expect($import->create_count)->toBe(1)->and($import->update_count)->toBe(1)->and($import->error_count)->toBe(3)
        ->and($rows[1]->action)->toBe('create')->and($rows[1]->match['label'])->toBe('EMP1 · Asha Test · HDFC Bank ••••2333')
        ->and($rows[2]->action)->toBe('update')
        ->and($rows[3]->errors[0])->toContain("'NOBODY' does not exist")
        ->and($rows[4]->errors[0])->toContain('5.01E+13')
        ->and($rows[5]->errors)->toContain('Employee EMP3 also appears in row 4.')
        ->and(implode(' ', $rows[5]->errors))->toContain('IFSC field format is invalid')->toContain('account type');
    expect(EmployeeBankAccount::query()->count())->toBe(1); // nothing written yet

    $import = $this->imports->run($this->imports->approve($import, $this->hr, 'Payroll onboarding'), $this->hr);
    expect($import->status)->toBe('imported')->and($import->create_count)->toBe(1)->and($import->update_count)->toBe(1)->and($import->failure_count)->toBe(0);

    $added = EmployeeBankAccount::query()->where('employee_id', $this->fresh->id)->sole();
    expect($added->account_number)->toBe('000111222333')->and($added->ifsc)->toBe('HDFC0001234')->and($added->is_primary)->toBeTrue()->and($added->account_type)->toBe('salary');
    $updated = $this->onFile->refresh();
    expect(EmployeeBankAccount::query()->where('employee_id', $this->paid->id)->count())->toBe(1)
        ->and($updated->account_number)->toBe('99998888777')->and($updated->bank_name)->toBe('ICICI Bank')->and($updated->branch_name)->toBeNull()
        ->and($updated->account_type)->toBe('savings')->and($updated->is_primary)->toBeTrue();

    // Audited as one operation, on each employee's timeline, and the clear-text sources are gone.
    expect(AuditEvent::query()->where('operation_id', $import->operation_id)->where('action', 'BULK_OPERATION')->exists())->toBeTrue()
        ->and(AuditEvent::query()->where('operation_id', $import->operation_id)->where('entity_type', EmployeeBankAccount::class)->count())->toBe(2)
        ->and(EmployeeTimelineEntry::query()->where('employee_id', $this->fresh->id)->where('title', 'Bank account added')->exists())->toBeTrue()
        ->and(DB::table('employee_import_rows')->where('employee_import_id', $import->id)->whereNotNull('sealed_data')->count())->toBe(0)
        ->and(Storage::disk('local')->exists($import->path))->toBeFalse()
        ->and($import->rows()->where('row_number', 1)->value('result'))->toBe('Added HDFC Bank ••••2333');
});

it('lets the uploader choose new employees only or existing bank details only, and skips the rest with the reason', function () {
    $csv = BANK_HEADER."EMP1,Asha Test,HDFC Bank,000111222333,,,\nEMP2,Vikram Test,ICICI Bank,99998888777,,,\n";

    $new = validatedBankUpload($csv, ImportMode::New);
    $rows = $new->rows()->get()->keyBy('row_number');
    expect($rows[1]->action)->toBe('create')->and($rows[2]->action)->toBe('skip')->and($rows[2]->match['reason'])->toContain('already has bank details on file');

    $existing = validatedBankUpload($csv, ImportMode::Update);
    $rows = $existing->rows()->get()->keyBy('row_number');
    expect($rows[1]->action)->toBe('skip')->and($rows[1]->match['reason'])->toContain('has no bank details on file')->and($rows[2]->action)->toBe('update');

    // A row matching what is on file changes nothing; blank optional cells keep what is on file.
    $same = validatedBankUpload(BANK_HEADER."EMP2,Vikram Test,SBI,11112222333,,,\n", ImportMode::Update);
    expect($same->rows()->first()->action)->toBe('skip')->and($same->rows()->first()->match['reason'])->toBe('These bank details are already on file.');

    // The same account number on two people is refused on the second.
    $dragged = validatedBankUpload(BANK_HEADER."EMP1,Asha Test,HDFC Bank,000111222333,,,\nEMP3,Neel Test,HDFC Bank,000111222333,,,\n");
    expect($dragged->rows()->where('row_number', 2)->first()->errors)->toContain('The same account number is on row 1 for another employee.');

    // Discarding drops the sealed rows and the file too.
    $this->imports->discard($dragged);
    expect(DB::table('employee_import_rows')->where('employee_import_id', $dragged->id)->whereNotNull('sealed_data')->count())->toBe(0)
        ->and(Storage::disk('local')->exists($dragged->path))->toBeFalse();
});

it('keeps each bank file to its uploader and behind employee.import + employee.sensitive.update', function () {
    $import = validatedBankUpload(BANK_HEADER."EMP1,Asha Test,HDFC Bank,000111222333,,,\n");
    $this->get(BankAccountImportResource::getUrl('index'))->assertOk()->assertSee('bank.csv');
    $this->get(BankAccountImportResource::getUrl('view', ['record' => $import]))->assertOk()->assertSee('File is for')->assertDontSee('000111222333');

    $colleague = tenantUser($this->tenant, ['employee.view', 'employee.import', 'employee.sensitive.update']);
    $this->actingAs($colleague)->get(BankAccountImportResource::getUrl('index'))->assertOk()->assertDontSee('bank.csv');
    $this->actingAs($colleague)->get(BankAccountImportResource::getUrl('view', ['record' => $import]))->assertNotFound();
    expect(fn () => $this->imports->approve($import, $colleague))->toThrow(RuntimeException::class, 'Only the person who uploaded');

    $importer = tenantUser($this->tenant, ['employee.view', 'employee.import']);
    $this->actingAs($importer)->get(BankAccountImportResource::getUrl('index'))->assertForbidden();
    expect(fn () => stageBankCsv(BANK_HEADER))->toThrow(RuntimeException::class, 'employee.sensitive.update');
});

it('maps from the page, shows rows with the last four digits only, and offers the template and format guide', function () {
    $import = $this->imports->inspect(stageBankCsv(BANK_HEADER."EMP1,Asha Test,HDFC Bank,000111224321,,,\n"));

    Livewire::test(ViewBankAccountImport::class, ['record' => $import->getRouteKey()])
        ->callAction('map', ['mode' => 'new'])->assertHasNoActionErrors()
        ->callAction('validate');
    expect($import->refresh()->status)->toBe('validated')->and($import->options['mode'])->toBe('new')->and($import->create_count)->toBe(1);

    Livewire::test(RowsRelationManager::class, ['ownerRecord' => $import, 'pageClass' => ViewBankAccountImport::class])
        ->assertSee('HDFC Bank ••••4321')->assertDontSee('000111224321');

    Livewire::test(ListBankAccountImports::class)->callAction('downloadTemplate')->assertFileDownloaded('bank-details-template.csv');
    $template = $this->imports->inspect(stageBankCsv($this->imports->template()));
    expect(array_values($template->mapping))->toEqualCanonicalizing(array_keys(BankAccountImports::FIELDS));
});
