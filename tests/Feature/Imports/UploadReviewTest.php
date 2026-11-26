<?php

use App\Domain\Employment\Actions\HireEmployeeAction;
use App\Domain\Employment\Imports\EmployeeImport;
use App\Domain\Employment\Imports\EmployeeImports;
use App\Domain\Employment\Imports\ImportTypes;
use App\Domain\Organisation\Models\Company;
use App\Domain\Organisation\Resolution\MasterResolver;
use App\Domain\Organisation\Resolution\ResolutionStatus;
use App\Domain\Performance\Models\Kra;
use App\Domain\Performance\Models\PerformanceLookup;
use App\Filament\Pages\DataImport;
use App\Filament\Pages\RecentUploads;
use App\Livewire\Imports\ColumnMatch;
use App\Livewire\Resolution\ReferenceReview;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

require_once __DIR__.'/../Leave/LeaveTestHelpers.php';

/*
| After an upload (owner request 2026-11-26): every column of the file with the field it is read as — a heading PeopleOS
| does not recognise is flagged so the person chooses its field or leaves it out — and every name the file gives that is
| not available, with a choice of an existing record, adding it as new, or setting it up on its own screen.
*/

beforeEach(function () {
    $this->travelTo('2026-11-26 10:00:00');
    Storage::fake('local');
    config(['peopleos.documents.disk' => 'local']);
    $this->tenant = provisionTenant();
    actAsTenant($this->tenant);
    $this->hr = tenantUser($this->tenant, ['*']);
    $this->actingAs($this->hr);
    $this->company = Company::factory()->create(['name' => 'Markedge Technologies', 'code' => 'MK']);
    $this->asha = app(HireEmployeeAction::class)->handle(['first_name' => 'Asha', 'last_name' => 'Rao'], ['joining_date' => '2025-01-01', 'employee_code' => 'EMP1'], ['company_id' => $this->company->id]);
});

/** Stage and check a leave balance file the way the upload screens do. */
function urUpload(string $csv): EmployeeImport
{
    $path = EmployeeImports::directory().'/'.uniqid('ur').'.csv';
    Storage::disk('local')->put($path, $csv);
    $import = ImportTypes::register('leave_balances', $path, 'balances.csv', test()->hr, ['mode' => 'both']);
    $service = ImportTypes::service('leave_balances');
    $service->inspect($import);
    $service->map($import->refresh(), $import->mapping);

    return $service->validate($import->refresh());
}

it('flags a column heading it does not recognise on the Data import page, instead of dropping it silently', function () {
    leavePolicy(['EL' => ['days' => 0]]);
    $file = UploadedFile::fake()->createWithContent('balances.csv', "Employee code,Leave type,Days,Leave yr\nEMP1,EL,4,2026\n");

    Livewire::withQueryParams(['process' => 'time_office.leave_balance'])->test(DataImport::class)
        ->set('data.mode', 'both')->call('toPrepare')
        ->fillForm(['file' => $file])->call('uploadAndCheck')
        ->assertSet('step', 4)->assertSet('mappingError', null)
        ->assertSee('Columns in your file')->assertSee('Leave yr')->assertSee('Not recognised')->assertSee('1 column is not recognised');
});

it('reads an unrecognised column as the field the person chooses, and checks the file again', function () {
    $import = urUpload("Employee code,Leave type,Days,Leave yr\nEMP1,EL,4,2025\n");
    expect($import->mapping)->not->toHaveKey('Leave yr');

    Livewire::test(ColumnMatch::class, ['importId' => $import->id])
        ->assertSee('Not recognised')
        ->set('mapping.3', 'year')->call('save')
        ->assertDispatched('upload-rechecked', importId: $import->id)->assertSet('problem', null);

    expect($import->refresh()->mapping['Leave yr'])->toBe('year')->and($import->status)->toBe('validated');
});

it('says what is wrong instead of saving columns that leave out a required field', function () {
    $import = urUpload("Employee code,Leave type,Days\nEMP1,EL,4\n");

    Livewire::test(ColumnMatch::class, ['importId' => $import->id])
        ->set('mapping.2', '')->call('save')
        ->assertNotDispatched('upload-rechecked')->assertSet('problem', fn (string $problem) => str_contains($problem, 'Days'));

    expect($import->refresh()->mapping)->toHaveKey('Days');
});

it('shows the columns read-only once the upload is approved', function () {
    $import = urUpload("Employee code,Leave type,Days,Leave year\nEMP1,EL,4,2026\n");
    ImportTypes::service('leave_balances')->approve($import->refresh(), $this->hr, 'ok');

    Livewire::test(ColumnMatch::class, ['importId' => $import->id])
        ->assertSee('Columns in your file')->assertDontSee('Save and check again')
        ->set('mapping.2', '')->call('save')->assertNotDispatched('upload-rechecked');
    expect($import->refresh()->mapping)->toHaveKey('Days');
});

it('shows the columns and the names to decide on the upload\'s own page', function () {
    $import = urUpload("Employee code,Leave type,Days,Leave year\nEMP1,Zebra time,4,2026\n");

    $this->get(RecentUploads::urlFor($import))->assertOk()
        ->assertSee('Columns in your file')->assertSee('Names in your file')->assertSee('Zebra time')->assertSee('Not available');
});

it('adds a value that is not available as a new record of a simple list, with the kind it was looked up in', function () {
    $session = app(MasterResolver::class)->session($this->hr, 'employee_imports:7');
    $session->resolve('kra', 'Customer delight', sheet: null, row: 2, column: 'KRA');
    $session->resolve('perspective', 'Sustainability', sheet: null, row: 2, column: 'Perspective');
    $report = $session->report();
    $key = fn (string $master) => collect($report->entries(ResolutionStatus::Unmatched))->firstWhere('master', $master)['key'];
    $review = Livewire::test(ReferenceReview::class, ['report' => $report->toArray(), 'scope' => 'employee_imports:7'])
        ->assertSee('Add Customer delight as new')->assertSee('Add Sustainability as new');

    $review->call('createMissing', $key('kra'))->assertDispatched('references-resolved');
    $review->call('createMissing', $key('perspective'));

    expect(Kra::query()->where('name', 'Customer delight')->value('code'))->toBe('CUSTOMER_DELIGHT')
        ->and(PerformanceLookup::query()->where('name', 'Sustainability')->value('kind'))->toBe('perspective');
});

it('links a list that carries rules to its own screen instead of adding it from a name, then checks again', function () {
    $session = app(MasterResolver::class)->session($this->hr, 'employee_imports:8');
    $session->resolve('leave_type', 'Zebra time', sheet: null, row: 2, column: 'Leave type');

    Livewire::test(ReferenceReview::class, ['report' => $session->report()->toArray(), 'scope' => 'employee_imports:8'])
        ->assertSee('Not available')->assertSee('Choose an existing leave type')
        ->assertSee('Set up a new leave type')->assertDontSee('Add Zebra time as new')
        ->assertSee('Check the file again')
        ->call('checkAgain')->assertDispatched('references-resolved', scope: 'employee_imports:8');
});

it('does not offer to add a person, and says where people come from', function () {
    $session = app(MasterResolver::class)->session($this->hr, 'employee_imports:9');
    $session->resolve('employee', 'EMP404', sheet: null, row: 2, column: 'Manager');

    Livewire::test(ReferenceReview::class, ['report' => $session->report()->toArray(), 'scope' => 'employee_imports:9'])
        ->assertSee('Not available')->assertSee('People are added by the employee upload')
        ->assertDontSee('Add EMP404 as new')->assertDontSee('Set up a new employee');
});
