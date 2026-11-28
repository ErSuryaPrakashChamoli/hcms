<?php

use App\Domain\Employment\Imports\EmployeeImports;
use App\Domain\Employment\Imports\ImportCatalogue;
use App\Domain\Employment\Imports\ImportTypes;
use App\Domain\Employment\Imports\RowImportTypes;
use App\Domain\Employment\Imports\Workbooks\WorkbookCatalogue;
use App\Domain\Employment\Models\Employee;
use App\Domain\Organisation\Models\Company;
use App\Domain\People\Actions\ChangeAddressAction;
use App\Domain\People\Imports\AddressImports;
use App\Domain\People\Imports\EmergencyContactImports;
use App\Domain\People\Imports\IdentityDocumentImports;
use App\Domain\People\Models\PersonAddress;
use App\Domain\People\Models\PersonEmergencyContact;
use App\Domain\People\Models\PersonIdentityDocument;
use App\Domain\Platform\Services\FeatureFlags;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/Di2Helpers.php';

/*
| Owner, 2026-11-27: whatever the Employee 360 Profile shows can be uploaded, separately (Data import → Workforce) and in
| the Employee Master workbook. Statutory details already had both (Payroll → Employee statutory; Payroll & Compensation
| workbook › Statutory details). Added: Addresses, Emergency contacts and Government IDs uploads (each through its tab's
| change action; ID numbers sealed in staging), and blood group, celebrated birthday and wedding anniversary on the
| Employee / Personal details uploads.
*/

beforeEach(function () {
    $this->travelTo('2026-11-27 10:00:00');
    Storage::fake('local');
    config(['peopleos.documents.disk' => 'local']);
    $this->tenant = provisionTenant();
    actAsTenant($this->tenant);
    $this->hr = tenantUser($this->tenant, ['*']);
    $this->actingAs($this->hr);
    Company::factory()->create(['code' => 'ACME']);
    $this->asha = di2Hire('EMP1', 'Asha');
    $this->ravi = di2Hire('EMP2', 'Ravi');
});

it('offers every Profile area as an upload of its own and in a master workbook, statutory included', function () {
    foreach (['addresses' => 'employee.update', 'emergency_contacts' => 'employee.update', 'identity_documents' => 'employee.sensitive.update'] as $type => $right) {
        expect(RowImportTypes::has($type))->toBeTrue($type)->and(ImportTypes::module($type))->toBe('workforce')
            ->and(RowImportTypes::allowed($this->hr, $type))->toBeTrue($type)
            ->and(RowImportTypes::allowed(tenantUser($this->tenant, ['employee.import']), $type))->toBeFalse($type)
            ->and(RowImportTypes::allowed(tenantUser($this->tenant, ['employee.import', $right]), $type))->toBeTrue($type);
    }
    expect(RowImportTypes::allowed(tenantUser($this->tenant, ['employee.import', 'employee.update']), 'identity_documents'))->toBeFalse();

    foreach (['workforce.addresses', 'workforce.emergency_contacts', 'workforce.government_ids', 'workforce.bank', 'workforce.family', 'workforce.nominees',
        'workforce.education', 'workforce.work_experience', 'workforce.health_insurance', 'workforce.other_details', 'workforce.employee_advance', 'payroll.employee_statutory'] as $key) {
        expect(ImportCatalogue::built(ImportCatalogue::find($key)))->toBeTrue($key);
    }
    $people = array_column(WorkbookCatalogue::sheets('employees'), 'process', null);
    $payroll = array_column(WorkbookCatalogue::sheets('payroll'), 'process', null);
    expect(collect($people)->pluck('key')->all())->toContain('workforce.addresses', 'workforce.emergency_contacts', 'workforce.government_ids', 'workforce.bank', 'workforce.family')
        ->and(collect($payroll)->pluck('key')->all())->toContain('payroll.employee_statutory')
        ->and(ImportCatalogue::find('payroll.employee_statutory')['about'])->toContain('PAN')->toContain('Aadhaar')->not->toContain('LWF')
        ->and(array_keys(ImportCatalogue::fields(ImportCatalogue::find('workforce.personal_details'))))->toContain('person.blood_group', 'person.birthday', 'person.wedding_anniversary');

    // The sample files are valid uploads.
    foreach (['workforce.addresses' => AddressImports::TYPE, 'workforce.emergency_contacts' => EmergencyContactImports::TYPE] as $key => $type) {
        expect(di2Stage($type, ImportCatalogue::csv(ImportCatalogue::find($key), true))->error_count)->toBe(0, $key);
    }
});

it('adds and updates addresses through the address action, states by name, one row per address type', function () {
    $current = app(ChangeAddressAction::class)->add($this->ravi, ['type' => 'current', 'country_code' => 'IN', 'address_line_1' => '1 Old Road', 'city' => 'Pune'], $this->hr);
    $csv = "Employee code,Address type,Address line 1,Address line 2,City,State,PIN code,Country\n"
        ."EMP1,Present,\"12, MG Road\",Indiranagar,Bengaluru,Karnataka,560038,India\n"
        ."EMP1,Permanent,4 Station Road,,Jaipur,RJ,302006,\n"
        ."EMP2,current,,,Mumbai,Maharashtra,,\n"
        ."EMP1,Present,Another,,,,,\n"
        ."EMP1,Holiday home,Somewhere,,,,,\n"
        ."EMP2,Permanent,,,,,,Atlantis\n";
    $import = di2Stage(AddressImports::TYPE, $csv);

    expect(di2Actions($import))->toBe(['create', 'create', 'update', 'error', 'error', 'error'])
        ->and(di2Errors($import, 4))->toContain('current address is in row 1')
        ->and(di2Errors($import, 5))->toContain("Address type 'Holiday home' is not one of")
        ->and(di2Errors($import, 6))->toContain("Country 'Atlantis' is not a country")
        ->and(di2Row($import, 3)->match['changes'])->toBe(['city', 'state_code']);

    di2Run($import);
    $present = PersonAddress::query()->where('person_id', $this->asha->person_id)->where('type', 'current')->sole();
    expect($present->only(['address_line_1', 'address_line_2', 'city', 'state_code', 'postal_code', 'country_code']))
        ->toBe(['address_line_1' => '12, MG Road', 'address_line_2' => 'Indiranagar', 'city' => 'Bengaluru', 'state_code' => 'KA', 'postal_code' => '560038', 'country_code' => 'IN'])
        ->and(PersonAddress::query()->where('person_id', $this->asha->person_id)->where('type', 'permanent')->value('state_code'))->toBe('RJ')
        ->and($current->fresh()->only(['address_line_1', 'city', 'state_code']))->toBe(['address_line_1' => '1 Old Road', 'city' => 'Mumbai', 'state_code' => 'MH'])
        ->and(di2Timeline($this->asha))->toContain('Address added');

    // Existing only: an address on file is updated, a new one is left out.
    $again = di2Stage(AddressImports::TYPE, "Employee code,Address type,Address line 1,City\nEMP1,current,\"12, MG Road\",Bangalore\nEMP2,permanent,9 Lake View,Pune\n", 'update');
    expect(di2Actions($again))->toBe(['update', 'skip']);
});

it('adds and updates emergency contacts by name, phones checked with their country code', function () {
    $csv = "Employee code,Contact name,Relation,Phone country code,Phone,E-mail\n"
        ."EMP1,Kiran Rao,Spouse,91,9876543210,kiran@example.com\n"
        ."EMP1,Mohan Rao,Father,,080-2345678,\n"
        ."EMP1,kiran rao,,,,\n"
        ."EMP2,Uma Rao,Sister,91,12,\n"
        ."EMP2,Gita Rao,Mother,,,\n";
    $import = di2Stage(EmergencyContactImports::TYPE, $csv);

    expect(di2Actions($import))->toBe(['create', 'create', 'error', 'error', 'error'])
        ->and(di2Errors($import, 3))->toContain('The same contact is in row 1')
        ->and(di2Errors($import, 4))->not->toBe('')
        ->and(di2Errors($import, 5))->toContain('Phone is required to add a contact');

    di2Run($import);
    $contacts = PersonEmergencyContact::query()->where('person_id', $this->asha->person_id)->orderBy('priority')->get();
    expect($contacts->pluck('name')->all())->toBe(['Kiran Rao', 'Mohan Rao'])
        ->and($contacts[0]->phone)->toBe('+919876543210')->and($contacts[0]->priority)->toBe(1)->and($contacts[1]->priority)->toBe(2);

    $update = di2Stage(EmergencyContactImports::TYPE, "Employee code,Contact name,Alternate phone\nEMP1,KIRAN RAO,0801111111\n");
    expect(di2Actions($update))->toBe(['update']);
    di2Run($update);
    expect($contacts[0]->fresh()->only(['name', 'alternate_phone', 'phone']))->toBe(['name' => 'Kiran Rao', 'alternate_phone' => '0801111111', 'phone' => '+919876543210']);
});

it('adds and updates government IDs with the numbers sealed in staging and never shown in full', function () {
    $csv = "Employee code,ID type,Number,Issued on,Valid until,Issuing country,Place of issue\n"
        ."EMP1,Passport,K1234567,2019-04-10,2029-04-09,India,Bengaluru\n"
        ."EMP1,Voter ID,ABC1234567,,,,\n"
        ."EMP1,PAN,ABCDE1234F,,,,\n"
        ."EMP2,Driving license,,,,,\n"
        ."EMP2,Passport,K1234567,2026-01-01,2025-01-01,,\n";
    $import = di2Stage(IdentityDocumentImports::TYPE, $csv);
    $rows = $import->rows()->orderBy('row_number')->get();

    expect(di2Actions($import))->toBe(['create', 'create', 'error', 'error', 'error'])
        ->and(di2Errors($import, 3))->toContain('PAN, Aadhaar and UAN are statutory details')->not->toContain('ABCDE1234F')
        ->and(di2Errors($import, 4))->toContain('Number is required to add an ID')
        ->and(di2Errors($import, 5))->toContain('Valid until must be after the issue date')
        ->and($rows[0]->data)->toBe([])->and($rows[0]->match['label'])->toContain('Passport')->toContain('••••4567')->not->toContain('K1234567')
        ->and((string) DB::table('employee_import_rows')->where('id', $rows[0]->id)->value('sealed_data'))->not->toContain('K1234567');

    di2Run($import);
    $passport = PersonIdentityDocument::query()->where('person_id', $this->asha->person_id)->where('kind', 'passport')->sole();
    expect($passport->number)->toBe('K1234567')->and($passport->issuing_country)->toBe('IN')->and($passport->expires_on->toDateString())->toBe('2029-04-09')
        ->and(PersonIdentityDocument::query()->where('person_id', $this->asha->person_id)->where('kind', 'voter_id')->value('number'))->toBe('ABC1234567')
        ->and($import->rows()->whereNotNull('sealed_data')->count())->toBe(0);
    Storage::disk('local')->assertMissing($import->path);

    // A blank number keeps the one on file; the dates change.
    $renewal = di2Stage(IdentityDocumentImports::TYPE, "Employee code,ID type,Valid until\nEMP1,passport,2030-04-09\n", 'update');
    expect(di2Actions($renewal))->toBe(['update']);
    di2Run($renewal);
    expect($passport->fresh()->number)->toBe('K1234567')->and($passport->fresh()->expires_on->toDateString())->toBe('2030-04-09');
});

it('takes blood group, the celebrated birthday and the wedding anniversary on the employee upload', function () {
    app(FeatureFlags::class)->setForTenant($this->tenant, EmployeeImports::FEATURE, true, platformAdmin(), 'Data migration agreed');
    $service = app(EmployeeImports::class);
    $path = EmployeeImports::directory().'/'.uniqid().'.csv';
    Storage::disk('local')->put($path, "Employee code,Blood group,Birthday celebrated on,Wedding anniversary\nEMP1,B positive,14 May,02-12\nEMP2,Z+,31-02,someday\n");
    $import = $service->inspect($service->register($path, 'people.csv', $this->hr));
    $import = $service->validate($service->map($import, $import->mapping, ['mode' => 'update']));
    $rows = $import->rows()->get()->keyBy('row_number');

    expect($rows[1]->action)->toBe('update')
        ->and($rows[2]->action)->toBe('error')->and(implode(' ', $rows[2]->errors))->toContain('Blood group must be one of')
        ->toContain('Birthday celebrated on must be a day and month')->toContain('Wedding anniversary must be a day and month');

    $service->run($service->approve($import, $this->hr), $this->hr);
    expect($this->asha->person->refresh()->only(['blood_group', 'birthday_day', 'birthday_month', 'wedding_anniversary_day', 'wedding_anniversary_month']))
        ->toBe(['blood_group' => 'B+', 'birthday_day' => 14, 'birthday_month' => 5, 'wedding_anniversary_day' => 2, 'wedding_anniversary_month' => 12]);

    // Employees › Export writes them in the upload's own format, so the file goes back unchanged.
    app(FeatureFlags::class)->setForTenant($this->tenant, EmployeeImports::EXPORT_FEATURE, true, platformAdmin(), 'Export agreed');
    $lines = array_map(fn (string $line) => str_getcsv($line, escape: ''), explode("\n", trim($service->export(Employee::query()->whereKey($this->asha->id), $this->hr))));
    $exported = array_combine($lines[0], $lines[1]);
    expect($exported['Blood group'])->toBe('B+')->and($exported['Birthday celebrated on'])->toBe('14-05')->and($exported['Wedding anniversary'])->toBe('02-12');
});
