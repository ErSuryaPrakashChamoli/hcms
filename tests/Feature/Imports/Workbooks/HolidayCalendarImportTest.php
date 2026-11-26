<?php

use App\Domain\Attendance\Models\Holiday;
use App\Domain\Attendance\Models\HolidayCalendar;
use App\Domain\Attendance\Models\HolidayCalendarRule;
use App\Domain\Configuration\Applicability\ApplicabilitySelection;
use App\Domain\Configuration\Applicability\EntityApplicability;
use App\Domain\Employment\Imports\EmployeeImport;
use App\Domain\Employment\Imports\EmployeeImports;
use App\Domain\Employment\Imports\ImportCatalogue;
use App\Domain\Employment\Imports\ImportTypes;
use App\Domain\Employment\Imports\Workbooks\WorkbookCatalogue;
use App\Domain\Employment\Imports\Workbooks\WorkbookImports;
use App\Domain\Identity\Services\AccessScopes;
use App\Domain\Organisation\Models\Company;
use App\Domain\Platform\Services\FeatureFlags;
use App\Filament\Pages\DataImport;
use App\Filament\Pages\MasterImport;
use Illuminate\Support\Facades\Storage;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;

/*
| Holiday calendars and holidays as master uploads (the owner's approved addition to the Attendance & Time Office
| workbook), and the master upload cards: business names and the companies the person may load data for.
*/

beforeEach(function () {
    $this->travelTo('2026-11-23 10:00:00');
    Storage::fake('local');
    config(['peopleos.documents.disk' => 'local', 'peopleos.imports.workbooks.queue' => false]);
    $this->tenant = provisionTenant();
    actAsTenant($this->tenant);
    $this->admin = tenantUser($this->tenant, ['*']);
    $this->actingAs($this->admin);
    app(FeatureFlags::class)->setForTenant($this->tenant, EmployeeImports::FEATURE, true, platformAdmin(), 'Data migration agreed');
    $this->mk = Company::factory()->create(['name' => 'Markedge Technologies', 'code' => 'MK']);
    $this->fe = Company::factory()->create(['name' => 'FynnEdge Advisory', 'code' => 'FE']);
});

function holUpload(string $type, string $csv, $user): EmployeeImport
{
    $path = EmployeeImports::directory().'/'.uniqid($type).'.csv';
    Storage::disk('local')->put($path, $csv);
    $import = ImportTypes::register($type, $path, "{$type}.csv", $user, ['mode' => 'both']);
    $service = ImportTypes::service($type);
    $service->inspect($import);
    $service->map($import->refresh(), $import->mapping);

    return $service->validate($import->refresh());
}

function holRun(EmployeeImport $import, $user): EmployeeImport
{
    $service = ImportTypes::service($import->type);
    $service->approve($import->refresh(), $user, 'test');

    return $service->run($import->refresh(), $user);
}

/** @return array<int, array<string, mixed>> */
function holRows(EmployeeImport $import): array
{
    return $import->rows()->orderBy('row_number')->get()->keyBy('row_number')->map(fn ($r) => $r->only(['action', 'status', 'errors', 'match', 'result']))->all();
}

it('registers both uploads under Time Office and in the Attendance & Time Office workbook, calendars before holidays', function () {
    expect(ImportCatalogue::find('time_office.holiday_calendars')['type'])->toBe('holiday_calendars')
        ->and(ImportCatalogue::find('time_office.holidays')['type'])->toBe('holidays')
        ->and(WorkbookCatalogue::get('time_office')['label'])->toBe('Attendance & Time Office');
    $order = MasterImport::card('time_office')['sheets'];
    $titles = array_column($order, 'title');
    expect(array_search('Holiday calendars', $titles, true))->toBeLessThan(array_search('Holidays', $titles, true));
});

it('adds calendars with where they apply, then their holidays by calendar code or name, and says when no rule gives the calendar to anyone', function () {
    $calendars = holRun(holUpload('holiday_calendars', "Calendar code,Calendar name,Year,Applies to\nHOL-MH,Maharashtra 2027,2027,MK\nHOL-KA,Karnataka 2027,2027,\n", $this->admin), $this->admin);
    $mh = HolidayCalendar::query()->where('code', 'HOL-MH')->firstOrFail();
    expect($calendars->status)->toBe('imported')
        ->and($mh->year)->toBe(2027)
        ->and(app(EntityApplicability::class)->reach($mh))->toBe([$this->mk->id])
        ->and(app(EntityApplicability::class)->isExplicit(HolidayCalendar::query()->where('code', 'HOL-KA')->firstOrFail()))->toBeFalse();

    HolidayCalendarRule::query()->create(['holiday_calendar_id' => $mh->id, 'name' => 'Markedge', 'priority' => 10, 'conditions' => [], 'status' => 'active']);
    $holidays = holUpload('holidays', "Holiday calendar,Date,Holiday name,Type,Half day\n"
        ."HOL-MH,2027-01-26,Republic Day,public,no\n"
        ."Maharashtra 2027,01/05/2027,Maharashtra Day,Public holiday,\n"
        ."Karnataka 2027 (HOL-KA),2027-11-01,Rajyotsava,company,yes\n"
        ."HOL-MH,2028-01-26,Republic Day,,\n"
        ."HOL-MH,26-01-2027,Republic Day again,,\n"
        ."HOL-XX,2027-03-01,Unknown,,\n"
        ."HOL-MH,2027-03-08,Holi,festival,\n", $this->admin);
    $rows = holRows($holidays);
    expect($rows[1]['action'])->toBe('create')
        ->and($rows[2]['action'])->toBe('create')
        ->and($rows[3]['action'])->toBe('create')
        ->and($rows[4]['errors'])->toBe(['Calendar Maharashtra 2027 is for 2027; 2028-01-26 is outside it.'])
        ->and($rows[5]['errors'])->toBe(['Row 1 already gives a holiday of Maharashtra 2027 on 2027-01-26.'])
        ->and($rows[6]['errors'])->toBe(["Holiday calendar 'HOL-XX' does not exist."])
        ->and($rows[7]['errors'][0])->toStartWith('Type must be one of: public, optional, company');

    $done = holRows(holRun($holidays, $this->admin));
    expect(Holiday::query()->where('holiday_calendar_id', $mh->id)->orderBy('date')->pluck('name', 'date')->mapWithKeys(fn ($n, $d) => [substr((string) $d, 0, 10) => $n])->all())
        ->toBe(['2027-01-26' => 'Republic Day', '2027-05-01' => 'Maharashtra Day'])
        ->and(Holiday::query()->where('name', 'Rajyotsava')->firstOrFail())->type->toBe('company')->is_half_day->toBeTrue()
        ->and($done[1]['result'])->toBe('Added Republic Day on 2027-01-26 to Maharashtra 2027')
        ->and($done[3]['result'])->toContain('Karnataka 2027 has no rule yet, so it applies to nobody');

    // Again: what is on file is skipped, a changed name is an update, and nothing is ever removed.
    $again = holRows(holRun(holUpload('holidays', "Holiday calendar,Date,Holiday name\nHOL-MH,2027-01-26,Republic Day\nHOL-MH,2027-05-01,Maharashtra Din\n", $this->admin), $this->admin));
    expect($again[1]['action'])->toBe('skip')->and($again[2]['action'])->toBe('update')
        ->and(Holiday::query()->where('holiday_calendar_id', $mh->id)->count())->toBe(2)
        ->and(Holiday::query()->whereDate('date', '2027-05-01')->value('name'))->toBe('Maharashtra Din');
});

it('refuses holidays of a calendar shared beyond a company-limited uploader, and their calendars outside their access', function () {
    $shared = HolidayCalendar::query()->create(['name' => 'Group 2027', 'code' => 'HOL-GRP', 'year' => 2027]);
    $mine = HolidayCalendar::query()->create(['name' => 'Markedge 2027', 'code' => 'HOL-MKO', 'year' => 2027]);
    app(EntityApplicability::class)->apply($mine, ApplicabilitySelection::single($this->mk->id), $this->admin);
    $limited = tenantUser($this->tenant, ['employee.import', 'attendance.manage', 'attendance.view', 'configuration.share']);
    app(AccessScopes::class)->assign($limited, ['company' => [$this->mk->id]]);
    $this->actingAs($limited);

    $rows = holRows(holUpload('holidays', "Holiday calendar,Date,Holiday name\nHOL-GRP,2027-01-26,Republic Day\nHOL-MKO,2027-01-26,Republic Day\n", $limited));
    expect($rows[1]['errors'])->toBe(['Calendar Group 2027 is also used by companies outside your access, so you may not change its holidays here.'])
        ->and($rows[2]['action'])->toBe('create');

    $calendars = holRows(holUpload('holiday_calendars', "Calendar code,Calendar name,Applies to\nHOL-NEW,New,MK; FE\n", $limited));
    expect($calendars[1]['errors'])->toContain('FynnEdge Advisory is outside your access.')
        ->and($shared->fresh()->holidays()->count())->toBe(0);
});

it('loads calendars and their holidays from one workbook: holidays wait for a calendar the workbook adds', function () {
    $local = tempnam(sys_get_temp_dir(), 'hol').'.xlsx';
    $writer = new Writer;
    $writer->openToFile($local);
    $writer->getCurrentSheet()->setName('Holidays');
    foreach ([['Holiday calendar', 'Date', 'Holiday name'], ['HOL-WB', '2027-01-26', 'Republic Day'], ['HOL-WB', '2027-08-15', 'Independence Day']] as $row) {
        $writer->addRow(Row::fromValues($row));
    }
    $writer->addNewSheetAndMakeItCurrent()->setName('Holiday calendars');
    foreach ([['Calendar code', 'Calendar name', 'Year'], ['HOL-WB', 'Workbook 2027', '2027']] as $row) {
        $writer->addRow(Row::fromValues($row));
    }
    $writer->close();
    $path = EmployeeImports::directory().'/'.uniqid('hol').'.xlsx';
    Storage::disk('local')->put($path, file_get_contents($local));
    @unlink($local);
    $import = app(WorkbookImports::class)->check(ImportTypes::register(WorkbookCatalogue::type('time_office'), $path, 'holidays.xlsx', $this->admin, ['mode' => 'both']));

    $sheets = collect(WorkbookImports::sheetSummaries($import))->keyBy('title');
    expect($import->status)->toBe('validated')->and((int) $import->error_count)->toBe(0)
        ->and($sheets['Holidays']['waiting'])->toBe(2)
        ->and(array_keys($sheets['Holidays']['waits_for']))->toBe(['Holiday calendars']);

    // MySQL keeps JSON object keys shortest first ("Holidays" before "Holiday calendars"): run order never comes from
    // the stored order. Stored backwards, the worksheets still list in run order.
    $import->update(['options' => ['sheets' => array_reverse($import->options['sheets'], true)] + $import->options]);
    expect(array_column(WorkbookImports::sheetSummaries($import->refresh()), 'title'))->toBe(['Holiday calendars', 'Holidays'])
        ->and(array_keys(WorkbookImports::sheetStates($import)))->toBe(['Holiday calendars', 'Holidays']);

    app(WorkbookImports::class)->approve($import, $this->admin, 'Holidays go-live');
    app(WorkbookImports::class)->start($import->refresh(), $this->admin);
    expect($import->refresh()->status)->toBe('imported')
        ->and(collect(WorkbookImports::sheetSummaries($import))->pluck('outcome', 'title')->all())->toBe(['Holiday calendars' => 'completed', 'Holidays' => 'completed'])
        ->and(HolidayCalendar::query()->where('code', 'HOL-WB')->firstOrFail()->holidays()->count())->toBe(2);
});

it('shows on each master upload card the companies the person may load data for', function () {
    $everyone = collect((new DataImport)->masterWorkbooks())->keyBy('key');
    expect($everyone['organisation']['companies'])->toContain('FynnEdge Advisory')->toContain('Markedge Technologies')
        ->and($everyone['organisation']['label'])->toBe('Organisation Master');
    Livewire\Livewire::test(DataImport::class)->assertSee('Markedge Technologies')->assertDontSee('every company');

    $limited = tenantUser($this->tenant, ['employee.import', 'organisation.create', 'organisation.view']);
    app(AccessScopes::class)->assign($limited, ['company' => [$this->mk->id]]);
    $this->actingAs($limited);
    expect(MasterImport::companiesLine())->toBe('Markedge Technologies');
});
