<?php

use App\Domain\Attendance\Imports\AttendanceSheetImports;
use App\Domain\Attendance\Imports\PunchImports;
use App\Domain\Compensation\Imports\SalaryImports;
use App\Domain\Employment\Imports\BankAccountImports;
use App\Domain\Employment\Imports\EmployeeImports;
use App\Domain\Platform\Services\FeatureFlags;
use App\Filament\Resources\AttendanceSheetImports\Pages\ListAttendanceSheetImports;
use App\Filament\Resources\PunchImports\Pages\ListPunchImports;
use App\Filament\Support\ImportActions;
use Livewire\Livewire;

/* Every bulk upload shows its format: each column, the new-or-existing choice where it applies, and an example file. */

beforeEach(function () {
    $this->tenant = provisionTenant();
    actAsTenant($this->tenant);
    app(FeatureFlags::class)->setForTenant($this->tenant, EmployeeImports::FEATURE, true, platformAdmin(), 'Data migration agreed');
    $this->actingAs(tenantUser($this->tenant, ['*']));
});

it('documents every column an upload accepts, with the choice of new or existing records and an example file', function (string $type, ?array $modes) {
    $data = ImportActions::formatData($type);
    $html = view('filament.imports.csv-format', $data)->render();
    $labels = array_column(ImportActions::service($type)::fields(), 0);

    expect($data['modes'])->toBe($modes)->and($data['sample'])->not->toBe('')
        ->and($html)->toContain('Columns')->toContain('Example file')->toContain(e($data['columns'][0][0]));
    // The example is a table (the file's own header row, then its rows), so a wide file scrolls inside its box.
    expect($data['sampleRows'])->toHaveCount(substr_count(trim($data['sample']), "\n") + 1)
        ->and($html)->toContain('>'.e($data['sampleRows'][0][0]).'</th>')->toContain('>'.e($data['sampleRows'][1][0]).'</td>')->not->toContain('<pre');
    if ($type !== AttendanceSheetImports::TYPE) {
        expect(array_column($data['columns'], 0))->toEqualCanonicalizing($labels);
    }
    if ($modes !== null) {
        expect($html)->toContain('New or existing')->toContain(e($modes['new'][0]))->toContain(e($modes['update'][0]));
    } else {
        expect($html)->not->toContain('New or existing');
    }
})->with([
    'employees' => ['employees', EmployeeImports::MODES],
    'salaries' => [SalaryImports::TYPE, SalaryImports::MODES],
    'bank details' => [BankAccountImports::TYPE, BankAccountImports::MODES],
    'monthly attendance' => [AttendanceSheetImports::TYPE, AttendanceSheetImports::MODES],
    'punches' => [PunchImports::TYPE, null],
]);

it('lists the day codes of the monthly attendance sheet and offers the punch template', function () {
    $html = view('filament.imports.csv-format', ImportActions::formatData(AttendanceSheetImports::TYPE))->render();
    foreach (array_keys(AttendanceSheetImports::CODES) as $code) {
        expect($html)->toContain(">{$code}<");
    }

    Livewire::test(ListPunchImports::class)->callAction('downloadTemplate')->assertFileDownloaded('punch-import-template.csv');
    Livewire::test(ListAttendanceSheetImports::class)->assertActionVisible('format')->assertActionVisible('downloadTemplate');
});
