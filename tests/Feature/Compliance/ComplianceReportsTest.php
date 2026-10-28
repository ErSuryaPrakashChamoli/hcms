<?php

use App\Domain\Attendance\Models\AttendanceRecord;
use App\Domain\Audit\Models\AuditEvent;
use App\Domain\Compliance\Models\EstablishmentStatutoryProfile;
use App\Domain\ComplianceReports\Models\ComplianceReportPack;
use App\Domain\ComplianceReports\Services\ComplianceReports;
use App\Domain\ComplianceReports\Support\ReportCatalogue;
use App\Domain\ComplianceReports\Support\ReportPeriod;
use App\Domain\Identity\Services\AccessScopes;
use App\Domain\Leave\Models\LeaveRequest;
use App\Domain\Leave\Models\LeaveType;
use App\Domain\Organisation\Models\Establishment;
use App\Filament\Pages\ComplianceReportsPage;
use Filament\Forms\Components\Select;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

require_once __DIR__.'/ComplianceTestHelpers.php';

beforeEach(function () {
    Storage::fake('local');
    $this->travelTo('2026-10-05 09:00:00');
    syncComplianceRules();
    $this->tenant = provisionTenant();
    actAsTenant($this->tenant);
    $this->admin = tenantUser($this->tenant, ['*']);
    $this->actingAs($this->admin);
    ['company' => $this->company, 'entity' => $this->entity, 'establishment' => $this->bengaluru] = complianceCompany();
    $this->pune = Establishment::query()->create(['legal_entity_id' => $this->entity->id, 'code' => 'PUNE', 'name' => 'Pune', 'state' => 'MH', 'effective_from' => '2020-01-01']);
    $this->preparer = tenantUser($this->tenant, ['payroll.*', 'employee.*']);
    $this->approver = tenantUser($this->tenant, ['payroll.*', 'employee.*']);
    $this->hr = tenantUser($this->tenant, ['compliance.reports.view', 'compliance.reports.generate']);

    $this->blr = statutoryEmployee(240000, $this->bengaluru, '100200300410', ['esic_number' => '3100112233', 'pan' => 'ABCPE1234K']);   // gross 20,000: ESI covered
    $this->blr->person->update(['gender' => 'female']);
    $this->pun = statutoryEmployee(600000, $this->pune, '100200300411');
    $this->pun->person->update(['gender' => 'male']);
    $this->reports = app(ComplianceReports::class);
});

it('models months, quarters, half-years and years on the financial and calendar basis', function () {
    $q2 = ReportPeriod::fromKey('FY:2026:Q2');
    expect($q2->start->toDateString())->toBe('2026-07-01')->and($q2->end->toDateString())->toBe('2026-09-30')
        ->and($q2->label())->toBe('Q2 FY 2026-27 (Jul 2026 – Sep 2026)')
        ->and(array_map(fn ($m) => $m->key(), $q2->months()))->toBe(['M:2026-07', 'M:2026-08', 'M:2026-09'])
        ->and(ReportPeriod::fromKey('FY:2026:H2')->end->toDateString())->toBe('2027-03-31')
        ->and(ReportPeriod::fromKey('CY:2026:H1')->end->toDateString())->toBe('2026-06-30')
        ->and(ReportPeriod::fromKey('FY:2026')->label())->toBe('FY 2026-27 (Apr 2026 – Mar 2027)')
        ->and(ReportPeriod::containing('quarterly', 'financial', '2027-02-10')->key())->toBe('FY:2026:Q4')
        ->and(ReportPeriod::containing('half_yearly', 'calendar', '2026-11-02')->key())->toBe('CY:2026:H2');

    // What falls due inside a period: a financial half-year holds the calendar half-year that ends in June.
    expect(array_map(fn ($p) => $p->key(), ReportPeriod::fromKey('FY:2026:H1')->periodsEndingWithin('half_yearly', 'calendar')))->toBe(['CY:2026:H1'])
        ->and(array_map(fn ($p) => $p->key(), ReportPeriod::fromKey('FY:2026')->periodsEndingWithin('quarterly', 'financial')))->toBe(['FY:2026:Q1', 'FY:2026:Q2', 'FY:2026:Q3', 'FY:2026:Q4'])
        ->and(array_keys(ReportPeriod::options('monthly', 'financial', '2026-10-05', 3)))->toBe(['M:2026-10', 'M:2026-09', 'M:2026-08']);
});

it('computes due dates, including the March and fourth-quarter exceptions', function () {
    $catalogue = app(ReportCatalogue::class);
    expect($catalogue->find('pf_challan')->dueOn(ReportPeriod::month(2026, 9))->toDateString())->toBe('2026-10-15')
        ->and($catalogue->find('tds_deposit')->dueOn(ReportPeriod::month(2026, 9))->toDateString())->toBe('2026-10-07')
        ->and($catalogue->find('tds_deposit')->dueOn(ReportPeriod::month(2027, 3))->toDateString())->toBe('2027-04-30')
        ->and($catalogue->find('tds_quarterly')->dueOn(ReportPeriod::fromKey('FY:2026:Q2'))->toDateString())->toBe('2026-10-31')
        ->and($catalogue->find('tds_quarterly')->dueOn(ReportPeriod::fromKey('FY:2026:Q4'))->toDateString())->toBe('2027-05-31')
        ->and($catalogue->find('bonus_register')->dueOn(ReportPeriod::fromKey('FY:2025'))->toDateString())->toBe('2026-11-30');
});

it('wires every catalogue builder and covers every state and union territory', function () {
    $catalogue = app(ReportCatalogue::class);
    foreach ($catalogue->all()->filter->isGenerated() as $definition) {
        expect($catalogue->builder($definition))->not->toBeNull();
    }
    expect(count($catalogue->states()))->toBe(36);
});

it('plans what falls due for each establishment, state and legal entity, with the reason when it does not apply', function () {
    $plan = collect($this->reports->plan($this->company, ReportPeriod::month(2026, 9)));
    $keys = $plan->map(fn ($i) => $i['definition']->key.'@'.$i['target']['label']);

    expect($keys)->toContain('muster_roll@'.$this->bengaluru->name.' (KA)', 'wage_register@Pune (MH)', 'tds_deposit@'.$this->entity->legal_name, 'salary_variance@'.$this->company->name)
        ->and($keys->filter(fn ($k) => str_starts_with($k, 'tds_quarterly')))->toBeEmpty(); // quarterly reports are not due in a monthly pack

    // Pune has no statutory profile: the legacy company profile decides (PF applies), so the PF challan is planned.
    expect($plan->first(fn ($i) => $i['definition']->key === 'pf_challan' && $i['target']['id'] === $this->pune->id)['applicable'])->toBeTrue();

    $quarter = collect($this->reports->plan($this->company, ReportPeriod::fromKey('FY:2026:Q2')));
    $muster = $quarter->first(fn ($i) => $i['definition']->key === 'muster_roll');
    expect(array_map(fn ($p) => $p->key(), $muster['periods']))->toBe(['M:2026-07', 'M:2026-08', 'M:2026-09'])
        ->and($quarter->contains(fn ($i) => $i['definition']->key === 'tds_quarterly'))->toBeTrue()
        ->and(collect($this->reports->plan($this->company, ReportPeriod::fromKey('FY:2026:Q2'), ['include_shorter' => false]))->contains(fn ($i) => $i['definition']->key === 'muster_roll'))->toBeFalse();
});

it('builds the wage register, PF and ESI challans from finalized payroll only', function () {
    $period = ReportPeriod::month(2026, 9);
    $item = fn (string $key, int $establishmentId) => $key.'|establishment|'.$establishmentId;

    $this->bengaluru->update(['appropriate_government' => 'central']);
    $draft = $this->reports->single($this->company, $period, $item('wage_register', $this->bengaluru->id), $this->hr);
    expect(sheetHas(xlsxSheets($draft['content'])['Form IV'], 'No finalized payroll for September 2026.'))->toBeTrue();

    finalizedPayroll($this->company, 2026, 9, $this->preparer, $this->approver);

    $wages = xlsxSheets($this->reports->single($this->company, $period, $item('wage_register', $this->bengaluru->id), $this->hr)['content']);
    $components = $wages['Components'];
    $wages = $wages['Form IV'];
    expect(sheetHas($wages, $this->blr->employee_code))->toBeTrue()
        ->and(sheetHas($wages, $this->pun->employee_code))->toBeFalse() // Pune is another establishment
        ->and(sheetHas($wages, 'Form I (= OSH Form XIII; earlier Form A)'))->toBeFalse()
        ->and(sheetHas($wages, 'Form IV (= OSH Form XV)'))->toBeTrue()
        ->and(sheetHas($wages, 'Official primary source verified (checked 2026-10-27)'))->toBeTrue()
        ->and(sheetHas($components, 'Basic'))->toBeTrue();

    $pf = xlsxSheets($this->reports->single($this->company, $period, $item('pf_challan', $this->bengaluru->id), $this->hr)['content']);
    expect(array_keys($pf))->toBe(['ECR working', 'Challan'])
        ->and(sheetHas($pf['ECR working'], '••••0410'))->toBeTrue()   // UAN masked
        ->and(sheetHas($pf['ECR working'], '100200300410'))->toBeFalse()
        ->and(sheetHas($pf['Challan'], 'A/c 1'))->toBeTrue()
        ->and(sheetHas($pf['Challan'], 'A/c 10'))->toBeTrue();

    $esi = xlsxSheets($this->reports->single($this->company, $period, $item('esi_challan', $this->bengaluru->id), $this->hr)['content']);
    expect(sheetHas(reset($esi), '••••2233'))->toBeTrue();
});

it('writes leave codes into the muster roll and leaves days outside employment blank', function () {
    $type = LeaveType::query()->where('code', 'CL')->sole();
    $leave = LeaveRequest::query()->create(['employee_id' => $this->blr->id, 'leave_type_id' => $type->id, 'from_date' => '2026-08-04', 'to_date' => '2026-08-04', 'days' => 1, 'dates' => [['date' => '2026-08-04', 'days' => 1]], 'reason' => 'Personal', 'status' => 'approved', 'reviewed_at' => now()]);
    AttendanceRecord::query()->create(['employee_id' => $this->blr->id, 'date' => '2026-08-03', 'status' => 'present', 'overtime_approved_minutes' => 90]);
    AttendanceRecord::query()->create(['employee_id' => $this->blr->id, 'date' => '2026-08-04', 'status' => 'leave', 'leave_request_id' => $leave->id]);
    AttendanceRecord::query()->create(['employee_id' => $this->blr->id, 'date' => '2026-08-05', 'status' => 'absent']);

    $muster = xlsxSheets($this->reports->single($this->company, ReportPeriod::month(2026, 8), 'muster_roll|establishment|'.$this->bengaluru->id, $this->hr)['content']);
    $row = collect(reset($muster))->first(fn ($r) => in_array($this->blr->employee_code, $r, true));
    $heading = collect(reset($muster))->first(fn ($r) => in_array('3 Mon', $r, true));
    $cell = fn (string $column) => $row[array_search($column, $heading, true)];

    expect($cell('3 Mon'))->toBe('P')->and($cell('4 Tue'))->toBe('CL')->and($cell('5 Wed'))->toBe('A')->and($cell('6 Thu'))->toBe('—')
        ->and((float) $cell('Paid leave'))->toBe(1.0)->and((float) $cell('Total overtime hours'))->toBe(1.5)->and((float) $cell('Total days worked'))->toBe(1.0);
});

it('generates every report of a period in one ZIP, downloadable only by its requester, and audits it', function () {
    finalizedPayroll($this->company, 2026, 9, $this->preparer, $this->approver);
    $this->actingAs($this->hr);

    $pack = $this->reports->request($this->company, ReportPeriod::month(2026, 9), $this->hr);
    expect($pack->status)->toBe('completed')->and($pack->report_count)->toBeGreaterThan(5);

    $zip = new ZipArchive;
    $path = tempnam(sys_get_temp_dir(), 'pack');
    file_put_contents($path, Storage::disk('local')->get($pack->path));
    $zip->open($path);
    $names = collect(range(0, $zip->numFiles - 1))->map(fn ($i) => $zip->getNameIndex($i));
    expect($names)->toContain('00 Index.xlsx', 'Establishments/MAIN - '.$this->bengaluru->name.'/Register of wages, overtime, advances, fines and deductions.xlsx', 'Company/Salary variance report.xlsx');
    $index = xlsxSheets($zip->getFromName('00 Index.xlsx'));
    expect(array_keys($index))->toBe(['Reports', 'Configuration', 'Rules', 'Checklist', 'About'])
        ->and(sheetHas($index['Checklist'], 'Wage slips to every employee'))->toBeTrue();
    $zip->close();
    unlink($path);

    expect(collect($pack->manifest)->pluck('status')->unique()->all())->each->toBeIn(['generated', 'empty', 'not_applicable'])
        ->and(fn () => $this->reports->download($pack, $this->admin))->toThrow(RuntimeException::class)
        ->and($this->reports->download($pack, $this->hr)['name'])->toEndWith('.zip')
        ->and(AuditEvent::query()->where('entity_type', $pack->getMorphClass())->where('entity_id', (string) $pack->id)->whereIn('action', ['EXPORT', 'DOWNLOAD'])->count())->toBe(2);
});

it('refuses people without the permission and unmasked identifiers without the sensitive permission', function () {
    $viewer = tenantUser($this->tenant, ['compliance.reports.view']);
    expect(fn () => $this->reports->request($this->company, ReportPeriod::month(2026, 9), $viewer))->toThrow(RuntimeException::class)
        ->and(fn () => $this->reports->request($this->company, ReportPeriod::month(2026, 9), $this->hr, ['unmasked' => true]))->toThrow(RuntimeException::class, 'unmasked');

    $this->actingAs(tenantUser($this->tenant, ['payroll.view']));
    $this->get(ComplianceReportsPage::getUrl())->assertForbidden();
});

it('keeps an establishment-scoped requester inside their establishment', function () {
    finalizedPayroll($this->company, 2026, 9, $this->preparer, $this->approver);
    app(AccessScopes::class)->assign($this->hr, ['establishment' => [$this->pune->id]], 'Pune payroll only');
    $this->actingAs($this->hr);

    $targets = collect($this->reports->plan($this->company, ReportPeriod::month(2026, 9)))->pluck('target.label')->unique();
    expect($targets)->toContain('Pune (MH)')->not->toContain($this->bengaluru->name.' (KA)');

    $pack = $this->reports->request($this->company, ReportPeriod::month(2026, 9), $this->hr);
    $variance = collect($pack->manifest)->firstWhere('key', 'salary_variance');
    expect($variance['status'])->toBe('generated');
    $zip = new ZipArchive;
    $path = tempnam(sys_get_temp_dir(), 'pack');
    file_put_contents($path, Storage::disk('local')->get($pack->path));
    $zip->open($path);
    $sheets = xlsxSheets($zip->getFromName('Company/Salary variance report.xlsx'));
    expect(sheetHas($sheets['By employee'], $this->pun->employee_code))->toBeTrue()
        ->and(sheetHas($sheets['By employee'], $this->blr->employee_code))->toBeFalse();
    $zip->close();
    unlink($path);
});

it('shows the page with what falls due and generates a single report from it', function () {
    finalizedPayroll($this->company, 2026, 9, $this->preparer, $this->approver);
    $this->actingAs($this->hr);

    Livewire::test(ComplianceReportsPage::class)
        ->assertOk()
        ->assertSee('Register of wages')
        ->assertSee('PF statement and challan (ECR working)')
        ->assertSee('Kept outside PeopleOS')
        ->callAction('downloadReport', arguments: ['item' => 'wage_register|establishment|'.$this->bengaluru->id])
        ->assertFileDownloaded();

    Livewire::test(ComplianceReportsPage::class)->callAction('generateAll');
    expect(ComplianceReportPack::query()->where('requested_by', $this->hr->id)->value('status'))->toBe('completed');
});

it('lists every state and union territory in the state filter, enabling only those the company operates in', function () {
    $this->actingAs($this->hr);

    Livewire::test(ComplianceReportsPage::class)
        ->assertFormFieldExists('states', fn (Select $field) => array_keys($field->getOptions()['Where the company operates']) === ['KA', 'MH']
            && count($field->getOptions(), COUNT_RECURSIVE) - count($field->getOptions()) === 36
            && ! $field->isOptionDisabled('KA', 'Karnataka') && ! $field->isOptionDisabled('MH', 'Maharashtra')
            && $field->isOptionDisabled('DL', 'Delhi'));
});

it('builds a whole financial year at once — every builder, every frequency — without a failing report', function () {
    finalizedPayroll($this->company, 2026, 8, $this->preparer, $this->approver);
    finalizedPayroll($this->company, 2026, 9, $this->preparer, $this->approver);
    $this->actingAs($this->hr);

    $pack = $this->reports->request($this->company, ReportPeriod::fromKey('FY:2026'), $this->hr);
    $manifest = collect($pack->manifest);
    $keys = $manifest->pluck('key')->map(fn ($k) => explode(':', $k)[0])->unique();

    expect($pack->status)->toBe('completed')
        ->and($manifest->where('status', 'failed')->values()->all())->toBe([])
        ->and($keys->all())->toContain('muster_roll', 'wage_register', 'pf_challan', 'tds_quarterly', 'esi_contribution_period', 'annual_return', 'bonus_register', 'gratuity_register', 'tds_annual', 'posh_annual_report', 'employment_return')
        // Monthly reports carry one sheet per month of the year; calendar-year reports fall in the financial year that holds December.
        ->and($manifest->firstWhere('key', 'muster_roll')['periods'])->toContain('April 2026', 'March 2027')
        ->and($manifest->firstWhere('key', 'annual_return')['periods'])->toBe('Calendar 2026 (Jan 2026 – Dec 2026)');
});

it('adds each state\'s shops and establishments registers, with its thresholds, and builds combined muster-cum-wages registers', function () {
    finalizedPayroll($this->company, 2026, 9, $this->preparer, $this->approver);
    AttendanceRecord::query()->create(['employee_id' => $this->blr->id, 'date' => '2026-09-01', 'status' => 'present']);
    $plan = collect($this->reports->plan($this->company, ReportPeriod::month(2026, 9)));
    $item = fn (string $key, int $establishmentId) => $plan->first(fn ($i) => $i['definition']->key === $key && $i['target']['id'] === $establishmentId);

    expect($item('se_muster_wages:KA', $this->bengaluru->id)['applicable'])->toBeTrue()
        ->and($item('se_muster_wages:KA', $this->pune->id))->toBeNull()                       // Karnataka forms only for Karnataka
        ->and($item('se_muster_wages:MH', $this->pune->id)['reason'])->toContain('Applies from 20 employees')->toContain('PeopleOS counts 1'); // Maharashtra's Act starts at 20 workers since 1 Oct 2025

    $formT = xlsxSheets($this->reports->single($this->company, ReportPeriod::month(2026, 9), 'se_muster_wages:KA|establishment|'.$this->bengaluru->id, $this->hr)['content']);
    $rows = reset($formT);
    $heading = collect($rows)->first(fn ($r) => in_array('1 Tue', $r, true));
    $row = collect($rows)->first(fn ($r) => in_array($this->blr->employee_code, $r, true));

    expect(sheetHas($rows, 'Form T'))->toBeTrue()
        ->and($heading)->toContain('Total days worked', 'Earned — basic', 'Net payment')
        ->and($row[array_search('1 Tue', $heading, true)])->toBe('P')
        ->and((float) $row[array_search('Net payment', $heading, true)])->toBeGreaterThan(0.0);

    // Telangana establishments coded TS (as the holiday library codes them) get the Telangana registers.
    $hyderabad = Establishment::query()->create(['legal_entity_id' => $this->entity->id, 'code' => 'HYD', 'name' => 'Hyderabad', 'state' => 'TS', 'effective_from' => '2020-01-01']);
    expect(collect($this->reports->plan($this->company, ReportPeriod::month(2026, 9)))->contains(fn ($i) => $i['definition']->key === 'se_wages:TG' && $i['target']['id'] === $hyderabad->id))->toBeTrue();
});

it('reports professional tax and labour welfare fund state by state, each on its own frequency', function () {
    foreach (['EPF', 'ESI', 'PT', 'LWF', 'TDS'] as $statute) {
        EstablishmentStatutoryProfile::query()->create(['establishment_id' => $this->pune->id, 'statute' => $statute, 'applicable' => true, 'effective_from' => '2020-01-01']);
    }
    $senior = statutoryEmployee(600000, $this->bengaluru, '100200300412'); // gross above ₹25,000: Karnataka PT ₹200
    finalizedPayroll($this->company, 2026, 6, $this->preparer, $this->approver);

    $monthly = collect($this->reports->plan($this->company, ReportPeriod::month(2026, 6)))->pluck('id');
    expect($monthly)->toContain('pt_statement:KA|state|KA', 'pt_statement:MH|state|MH')
        ->not->toContain('lwf_statement:MH|state|MH'); // half-yearly: not in a monthly pack

    $ka = xlsxSheets($this->reports->single($this->company, ReportPeriod::month(2026, 6), 'pt_statement:KA|state|KA', $this->hr)['content']);
    expect(sheetHas($ka['PT deducted'], $senior->employee_code))->toBeTrue()
        ->and(sheetHas($ka['PT deducted'], $this->pun->employee_code))->toBeFalse()
        ->and(sheetHas($ka['Slab summary'], 200))->toBeTrue();

    // A financial half-year holds the calendar half-year that ends in June: Maharashtra's June contribution.
    $half = collect($this->reports->plan($this->company, ReportPeriod::fromKey('FY:2026:H1')))->firstWhere('id', 'lwf_statement:MH|state|MH');
    expect(array_map(fn ($p) => $p->key(), $half['periods']))->toBe(['CY:2026:H1'])
        ->and($half['due'])->toStartWith('15 Jul 2026');
    $lwfSheets = xlsxSheets($this->reports->single($this->company, ReportPeriod::fromKey('FY:2026:H1'), 'lwf_statement:MH|state|MH', $this->hr)['content']);
    $lwf = reset($lwfSheets);
    $row = collect($lwf)->first(fn ($r) => in_array($this->pun->employee_code, $r, true));
    expect($row)->toContain('Jun 2026')->and(in_array(25, $row))->toBeTrue()->and(in_array(75, $row))->toBeTrue();

    // A state with no rule in the pack says so instead of an unexplained empty statement.
    Establishment::query()->create(['legal_entity_id' => $this->entity->id, 'code' => 'PAT', 'name' => 'Patna', 'state' => 'BR', 'effective_from' => '2020-01-01']);
    $bihar = xlsxSheets($this->reports->single($this->company, ReportPeriod::fromKey('FY:2025'), 'pt_statement:BR|state|BR', $this->hr)['content']);
    expect(sheetHas($bihar['PT deducted'], 'The statutory rule pack has no professional tax rule for Bihar, so payroll does not deduct it there yet.'))->toBeTrue();
});
