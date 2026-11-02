<?php

use App\Domain\Employment\Actions\HireEmployeeAction;
use App\Domain\Employment\Enums\ImportMode;
use App\Domain\Employment\Imports\EmployeeImport;
use App\Domain\Employment\Imports\EmployeeImports;
use App\Domain\Employment\Imports\ImportCatalogue;
use App\Domain\Employment\Imports\ImportTypes;
use App\Domain\Leave\Models\LeaveType;
use App\Domain\Leave\Services\LeaveBalances;
use App\Domain\Organisation\Models\Company;
use App\Domain\Platform\Services\FeatureFlags;
use App\Filament\Pages\DataImport;
use App\Filament\Pages\RecentUploads;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use League\Csv\Reader;
use Livewire\Livewire;

require_once __DIR__.'/../Leave/LeaveTestHelpers.php';

/* Data import: every upload by module (ImportCatalogue) and the four-step page that runs them. */

beforeEach(function () {
    $this->travelTo('2026-09-25 10:00:00');
    Storage::fake('local');
    config(['peopleos.documents.disk' => 'local']);
    $this->tenant = provisionTenant();
    actAsTenant($this->tenant);
    $this->hr = tenantUser($this->tenant, ['*']);
    $this->actingAs($this->hr);
    // Employee uploads wait for the platform's data-migration switch, as on their own screen.
    app(FeatureFlags::class)->setForTenant($this->tenant, EmployeeImports::FEATURE, true, platformAdmin(), 'Data migration agreed');
    $company = Company::factory()->create();
    $this->asha = app(HireEmployeeAction::class)->handle(['first_name' => 'Asha', 'last_name' => 'Rao'], ['joining_date' => '2025-01-01', 'employee_code' => 'EMP1'], ['company_id' => $company->id]);
});

/** @return list<list<string>> */
function dataImportCsvRows(string $csv): array
{
    return iterator_to_array(Reader::fromString($csv)->getRecords(), false);
}

it('files every process under a head with a type that fits employee_imports.type', function () {
    $labels = [];
    foreach (ImportCatalogue::PROCESSES as $key => $process) {
        expect(ImportCatalogue::HEADS)->toHaveKey($process['head'])
            ->and(str_starts_with($key, $process['head'].'.'))->toBeTrue($key)
            ->and(strlen($process['type']))->toBeLessThanOrEqual(32, $key)
            ->and($process['about'])->not->toBe('')
            ->and(($process['type'] === '') === isset($process['na']))->toBeTrue("{$key}: a process has a type or says why not");
        $labels[$process['head']][] = $process['label'];
        foreach (array_keys($process['defaults'] ?? []) as $field) {
            if (ImportCatalogue::built(['type' => $process['type']])) {
                expect(ImportCatalogue::fields(ImportCatalogue::find($key)))->toHaveKey($field);
            }
        }
    }
    foreach ($labels as $head => $names) {
        expect(count($names))->toBe(count(array_unique($names)), "duplicate process name under {$head}");
    }
    expect(array_keys(ImportTypes::MODULES))->toBe(array_keys(ImportCatalogue::HEADS));
});

it('gives every built process a CSV format and a sample file with example rows', function () {
    foreach (array_keys(ImportCatalogue::PROCESSES) as $key) {
        $process = ImportCatalogue::find($key);
        if (! ImportCatalogue::built($process)) {
            continue;
        }
        $format = dataImportCsvRows(ImportCatalogue::csv($process, false));
        $sample = dataImportCsvRows(ImportCatalogue::csv($process, true));
        expect($format)->toHaveCount(1, "{$key}: the format is the header row")
            ->and(count($sample))->toBeGreaterThan(1, "{$key}: the sample has example rows")
            ->and($sample[0])->toBe($format[0]);
        if (isset($process['columns'])) {
            $fields = ImportCatalogue::fields($process);
            expect($format[0])->toBe(array_map(fn (string $f) => $fields[$f][0], $process['columns']), "{$key}: template columns");
        }
    }
});

it('fills a process default into its sample and keeps the full upload columns', function () {
    $opening = ImportCatalogue::find('time_office.opening_balance');
    $sample = dataImportCsvRows(ImportCatalogue::csv($opening, true));
    $kind = array_search('Kind (opening/adjustment)', $sample[0], true);

    expect($kind)->not->toBeFalse()
        ->and(collect(array_slice($sample, 1))->pluck($kind)->unique()->all())->toBe(['opening'])
        // Only rows written for an opening balance: an adjustment row forced to "opening" (-1 days) would not validate.
        ->and(collect(array_slice($sample, 1))->pluck(array_search('Days', $sample[0], true))->every(fn ($days) => (float) $days >= 0))->toBeTrue()
        ->and(ImportCatalogue::fileName($opening, true))->toBe('opening-leave-balance-sample.csv');
});

it('lists the processes a person may run by module and finds them by name', function () {
    Livewire::test(DataImport::class)
        ->assertOk()
        ->assertSee('All modules')->assertSee('Time Office')->assertSee('Opening leave balance')->assertSee('New hires (advance)')
        ->set('search', 'opening')
        ->assertSee('Opening leave balance')->assertDontSee('Health insurance');

    app(FeatureFlags::class)->setForTenant($this->tenant, EmployeeImports::FEATURE, false, platformAdmin(), 'Migration finished');
    Livewire::test(DataImport::class)
        ->assertSee('Opening leave balance')->assertDontSee('New hires (advance)');
});

it('refuses the page to people who may run no upload, and ignores a process they may not run', function () {
    $this->actingAs(tenantUser($this->tenant, ['employee.view']));
    Livewire::test(DataImport::class)->assertForbidden();

    $this->actingAs(tenantUser($this->tenant, ['employee.import', 'leave.adjust']));
    Livewire::withQueryParams(['process' => 'workforce.bank'])->test(DataImport::class)
        ->assertSet('process', null)->assertSet('step', 1)->assertSee('Opening leave balance')->assertDontSee('Bank details');
});

it('runs an upload through the four steps with the process default for blank cells', function () {
    leavePolicy(['EL' => ['days' => 0]]);
    $file = UploadedFile::fake()->createWithContent('balances.csv', "Employee code,Leave code,Days,Leave year\nEMP1,EL,12,2026\nNOPE,EL,3,2026\n");

    $page = Livewire::withQueryParams(['process' => 'time_office.opening_balance'])->test(DataImport::class)
        ->assertSet('step', 2)->assertSee('What is this file for?')
        ->set('data.mode', 'both')
        ->call('toPrepare')->assertSet('step', 3)->assertSee('Download sample file')->assertSee('A blank Kind (opening/adjustment) cell is read as “opening”.')
        ->fillForm(['file' => $file])
        ->call('uploadAndCheck')->assertSet('step', 4)->assertSet('mappingError', null);

    $import = EmployeeImport::query()->latest('id')->first();
    expect($import->type)->toBe('leave_balances')->and($import->status)->toBe('validated')
        ->and($import->options['process'])->toBe('time_office.opening_balance')->and($import->options['defaults'])->toBe(['kind' => 'opening'])
        ->and($import->create_count)->toBe(1)->and($import->error_count)->toBe(1);
    $page->assertSee('does not exist')->assertSee('Import 1 row(s)');

    $page->fillForm(['reason' => 'Go-live balances'])->call('submit')->assertSee('Import finished');
    expect($import->refresh()->status)->toBe('imported')
        ->and(app(LeaveBalances::class)->balance($this->asha, LeaveType::query()->where('code', 'EL')->first(), 2026)->available())->toBe(12.0);

    Livewire::test(RecentUploads::class)->assertSee('Opening leave balance');
});

it('asks for the columns when they cannot be matched, then checks the file', function () {
    leavePolicy(['EL' => ['days' => 0]]);
    $file = UploadedFile::fake()->createWithContent('balances.csv', "Staff,Type,Qty\nEMP1,EL,4\n");

    $page = Livewire::withQueryParams(['process' => 'time_office.leave_balance'])->test(DataImport::class)
        ->set('data.mode', 'both')->call('toPrepare')
        ->fillForm(['file' => $file])->call('uploadAndCheck')
        ->assertSet('step', 4)->assertSee('Match the columns');
    expect($page->get('mappingError'))->toContain('Required fields are not mapped');

    $page->set('mapping', ['0' => 'employee_code', '1' => 'leave_code', '2' => 'days'])->call('saveMapping')->assertSet('mappingError', null);
    expect(EmployeeImport::query()->latest('id')->first())->status->toBe('validated')->update_count->toBe(1);
});

it('drops a checked upload and goes back for a corrected file', function () {
    leavePolicy(['EL' => ['days' => 0]]);
    $page = Livewire::withQueryParams(['process' => 'time_office.opening_balance'])->test(DataImport::class)
        ->set('data.mode', 'both')->call('toPrepare')
        ->fillForm(['file' => UploadedFile::fake()->createWithContent('b.csv', "Employee code,Leave code,Days\nNOPE,EL,1\n")])->call('uploadAndCheck');
    $first = EmployeeImport::query()->latest('id')->first();

    $page->call('reupload')->assertSet('step', 3)->assertSet('importId', null);
    expect($first->refresh()->status)->toBe('discarded');
});

it('lists a process PeopleOS does not take from a file and says why', function () {
    Livewire::test(DataImport::class)->assertSee('Bonus slabs')->assertSee('Not available as an upload');
    Livewire::withQueryParams(['process' => 'payroll.bonus_slabs'])->test(DataImport::class)
        ->assertSet('step', 1)->assertSee('verified Payment of Bonus rule')->assertDontSee('Download sample file');
});

it('limits a template to the columns of its process', function () {
    $header = dataImportCsvRows(ImportCatalogue::csv(ImportCatalogue::find('workforce.personal_details'), false))[0];

    expect($header[0])->toBe('Employee code')->and($header)->toContain('Date of birth')->not->toContain('Company code')
        ->and(EmployeeImports::required(ImportMode::Update))->toBe(['employee.employee_code']);
});

it('builds every process it lists, or says why it is not an upload', function () {
    $unbuilt = collect(ImportCatalogue::PROCESSES)
        ->filter(fn (array $process) => ! isset($process['na']) && ! ImportCatalogue::built($process))
        ->keys()->all();

    expect($unbuilt)->toBe([]);
});
