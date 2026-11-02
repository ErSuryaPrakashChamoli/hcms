<?php

use App\Domain\Compliance\Support\RegistrationNumberFormat;
use App\Domain\Employment\Imports\EmployeeContractImports;
use App\Domain\Employment\Imports\ImportCatalogue;
use App\Domain\Employment\Models\EmployeeContract;
use App\Domain\Employment\Services\EmployeeContracts;
use App\Domain\Lifecycle\Models\EmployeeTimelineEntry;
use App\Domain\Partners\Imports\VendorImports;
use App\Domain\Partners\Models\Vendor;
use App\Domain\Partners\Services\Vendors;
use App\Filament\Resources\EmployeeContracts\Pages\CreateEmployeeContract;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

require_once __DIR__.'/Di2Helpers.php';

/* Data import (Workforce → Vendor, Contract): vendors through Partners\Services\Vendors, contracts of contract staff through EmployeeContracts. */

beforeEach(function () {
    $this->travelTo('2026-10-15 10:00:00');
    Storage::fake('local');
    config(['peopleos.documents.disk' => 'local']);
    $this->tenant = provisionTenant();
    actAsTenant($this->tenant);
    $this->hr = tenantUser($this->tenant, ['*']);
    $this->actingAs($this->hr);
    $this->asha = di2Hire('EMP1', 'Asha');
});

it('adds and updates vendors through the vendor service, checking codes, kinds, PAN and GST number first', function () {
    $gst = '29AABCS1234Q1Z';
    $gst .= RegistrationNumberFormat::luhnMod36($gst);
    $csv = "Vendor code,Vendor name,Kind,GST number,PAN,Contact name,State code,Status\n"
        ."AGY-001,Skyline Staffing,Agency,{$gst},AABCS1234Q,Neha Kapoor,KA,active\n"
        ."CON-014,Metro Contractors,consultant,,,,MH,\n"
        ."BAD CODE,Nope,agency,,,,,\n"
        ."NEW-1,,,,,,,\n"
        ."AGY-002,Wrong PAN,agency,{$gst},ZZZZZ9999Z,,KA,\n"
        ."AGY-003,Unknown kind,recruiter,,,,,\n";

    $import = di2Stage(VendorImports::TYPE, $csv);
    expect(di2Actions($import))->toBe(['create', 'create', 'error', 'error', 'error', 'error'])
        ->and(di2Errors($import, 3))->toContain('A vendor code uses letters')
        ->and(di2Errors($import, 4))->toContain('Vendor name is required for a new vendor')->toContain('Kind is required')
        ->and(di2Errors($import, 5))->toContain('The PAN differs from the one inside the GST number')->not->toContain('ZZZZZ9999Z')
        ->and(di2Errors($import, 6))->toContain("Kind 'recruiter' is not in the list");

    di2Run($import);
    $skyline = Vendor::query()->where('code', 'AGY-001')->firstOrFail();
    expect($skyline->kind)->toBe('agency')->and($skyline->pan)->toBe('AABCS1234Q')->and($skyline->maskedPan())->not->toContain('AABCS')
        ->and(Vendor::query()->where('code', 'CON-014')->value('country'))->toBe('IN');

    // Existing vendors only: a row for a new code is skipped; blank cells keep what is on file.
    $update = di2Stage(VendorImports::TYPE, "Vendor code,Contact name,Status\nagy-001,Ravi Iyer,inactive\nNEW-9,Someone,\n", 'update');
    expect(di2Actions($update))->toBe(['update', 'skip']);
    di2Run($update);
    expect($skyline->fresh())->contact_name->toBe('Ravi Iyer')->status->toBe('inactive')->name->toBe('Skyline Staffing');
});

it('records contracts of contract staff with their agency and updates them by contract number', function () {
    $agency = app(Vendors::class)->create(['code' => 'AGY-001', 'name' => 'Skyline Staffing', 'kind' => 'agency'], $this->hr);
    $old = app(Vendors::class)->create(['code' => 'OLD-1', 'name' => 'Closed Agency', 'kind' => 'agency', 'status' => 'inactive'], $this->hr);
    $csv = "Employee code,Contract number,Agency (vendor) code,Starts on,Ends on,Notes\n"
        ."EMP1,SKY/2026/014,AGY-001,2026-04-01,2027-03-31,First term\n"
        ."EMP1,SKY/2026/014,AGY-001,2026-04-01,,\n"
        ."EMP1,SKY/2026/015,NOPE,2026-04-01,,\n"
        ."EMP1,SKY/2026/016,OLD-1,2026-04-01,,\n"
        ."EMP1,SKY/2026/017,,2026-04-01,2026-03-01,\n"
        ."EMP1,SKY/2026/018,,,,\n"
        ."NOPE,X-1,,2026-04-01,,\n";

    $import = di2Stage(EmployeeContractImports::TYPE, $csv);
    expect(di2Actions($import))->toBe(['create', 'error', 'error', 'error', 'error', 'error', 'error'])
        ->and(di2Errors($import, 2))->toContain('The same employee and contract number are in row 1')
        ->and(di2Errors($import, 3))->toContain("Agency (vendor) code 'NOPE' does not exist")
        ->and(di2Errors($import, 4))->toContain('The vendor OLD-1 is inactive')
        ->and(di2Errors($import, 5))->toContain('The contract cannot end before it starts')
        ->and(di2Errors($import, 6))->toContain('Starts on is required for a new contract')
        ->and(di2Errors($import, 7))->toContain("Employee code 'NOPE' does not exist");

    di2Run($import);
    $contract = EmployeeContract::query()->where('employee_id', $this->asha->id)->firstOrFail();
    expect($contract->vendor_id)->toBe($agency->id)->and($contract->end_date->toDateString())->toBe('2027-03-31')->and($contract->isCurrent())->toBeTrue()
        ->and(EmployeeTimelineEntry::query()->where('employee_id', $this->asha->id)->where('category', 'contract')->value('title'))->toBe('Contract recorded');

    // The same values again are skipped; a new end date updates the contract in place.
    expect(di2Actions(di2Stage(EmployeeContractImports::TYPE, "Employee code,Contract number,Ends on\nEMP1,sky/2026/014,2027-03-31\n")))->toBe(['skip']);
    di2Run(di2Stage(EmployeeContractImports::TYPE, "Employee code,Contract number,Ends on\nEMP1,SKY/2026/014,2026-12-31\n"));
    expect($contract->fresh()->end_date->toDateString())->toBe('2026-12-31')->and(EmployeeContract::query()->count())->toBe(1)
        ->and($old->id)->toBeInt();
});

it('keeps the service the only way to a contract and checks the right of whoever records it', function () {
    $viewer = tenantUser($this->tenant, ['employee.view']);
    expect(fn () => app(EmployeeContracts::class)->save($this->asha, ['contract_number' => 'C-1', 'start_date' => '2026-04-01'], $viewer))
        ->toThrow(App\Domain\Employment\Exceptions\ProfileChangeRefused::class);
    expect(fn () => app(EmployeeContracts::class)->save($this->asha, ['contract_number' => 'C-1'], $this->hr))->toThrow(ValidationException::class);

    Filament\Facades\Filament::getPanel('admin')->boot();
    Livewire::test(CreateEmployeeContract::class)
        ->fillForm(['employee_id' => $this->asha->id, 'contract_number' => 'C-9', 'start_date' => '2026-04-01'])
        ->call('create')->assertHasNoFormErrors();
    expect(EmployeeContract::query()->where('contract_number', 'C-9')->exists())->toBeTrue();
});

it('lists Vendor and Contract on Data import with a sample file that validates', function () {
    app(Vendors::class)->create(['code' => 'AGY-001', 'name' => 'Skyline Staffing', 'kind' => 'agency'], $this->hr);
    foreach (['workforce.vendor' => VendorImports::TYPE, 'workforce.contract' => EmployeeContractImports::TYPE] as $key => $type) {
        $process = ImportCatalogue::find($key);
        expect(ImportCatalogue::allowed($this->hr, $process))->toBeTrue();
        $sample = di2Stage($type, ImportCatalogue::csv($process, true));
        expect($sample->error_count)->toBe(0, $key.': '.collect(range(1, $sample->row_count))->map(fn ($n) => di2Errors($sample, $n))->implode(' | '));
    }
});
