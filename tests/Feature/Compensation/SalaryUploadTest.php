<?php

use App\Domain\Audit\Models\AuditEvent;
use App\Domain\Compensation\Imports\SalaryImports;
use App\Domain\Compensation\Models\CompensationChange;
use App\Domain\Compensation\Models\CompensationCycle;
use App\Domain\Compensation\Models\EmployeeSalaryAssignment;
use App\Domain\Compensation\Models\SalaryStructure;
use App\Domain\Compensation\Services\CompensationChanges;
use App\Domain\Compensation\Services\CompensationCycles;
use App\Domain\Employment\Actions\HireEmployeeAction;
use App\Domain\Employment\Imports\EmployeeImport;
use App\Domain\Employment\Imports\EmployeeImports;
use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Scopes\AccessScope;
use App\Domain\Lifecycle\Enums\LifecycleState;
use App\Filament\Resources\SalaryImports\Pages\ViewSalaryImport;
use App\Filament\Resources\SalaryImports\SalaryImportResource;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

require_once __DIR__.'/../Workflow/WorkflowTestHelpers.php';
require_once __DIR__.'/../Payroll/PayrollTestHelpers.php';

/* Salary uploads: a file becomes a draft compensation cycle; pay changes only through the cycle's four-person chain. */

beforeEach(function () {
    $this->travelTo('2026-09-21 09:00:00');
    Storage::fake('local');
    config(['peopleos.documents.disk' => 'local']);
    syncComplianceRules();
    $this->tenant = provisionTenant();
    actAsTenant($this->tenant);
    $this->company = payrollCompany();
    $this->actors = compensationActors();
    $this->uploader = tenantUser($this->tenant, ['compensation.cycles', 'compensation.propose', 'compensation.view']);
    $this->actingAs($this->uploader);
    $this->imports = app(SalaryImports::class);

    $this->newJoiner = employeeWithUser(null, ['task.view']);
    forceLifecycle($this->newJoiner, LifecycleState::Active, ['joining_date' => '2025-01-01']);
    $this->paid = salariedEmployee(600000, ['task.view'], '2026-04-01');
    $this->other = employeeWithUser(null, ['task.view']);
    forceLifecycle($this->other, LifecycleState::Active, ['joining_date' => '2025-01-01']);
});

function stageSalaryCsv(string $csv, string $name = 'salaries.csv'): EmployeeImport
{
    $path = EmployeeImports::directory().'/'.uniqid().'.csv';
    Storage::disk('local')->put($path, $csv);

    return app(SalaryImports::class)->register($path, $name, auth()->user());
}

function validatedSalaryUpload(string $csv, array $options = []): EmployeeImport
{
    $imports = app(SalaryImports::class);
    $import = $imports->inspect(stageSalaryCsv($csv));

    return $imports->validate($imports->map($import, $import->mapping, $options));
}

it('turns a salary file into a draft cycle that changes pay only after review, approval and execution by other people', function () {
    $own = app(HireEmployeeAction::class)->handle(['first_name' => 'Up', 'last_name' => 'Loader'], ['joining_date' => '2025-01-01', 'user_id' => $this->uploader->id], ['company_id' => $this->company->id]);
    forceLifecycle($own, LifecycleState::Active);
    $csv = "Employee code,Salary structure code,Annual CTC,Effective from,CONV\n"
        ."{$this->newJoiner->employee_code},STANDARD,\"6,00,000\",2026-10-01,1600\n"
        ."{$this->paid->employee_code},standard,720000,2026-10-01,1600\n"
        ."NOBODY,STANDARD,500000,2026-10-01,\n"
        ."{$this->newJoiner->employee_code},STANDARD,650000,2026-10-01,\n"
        ."{$this->other->employee_code},STANDARD,abc,2026-10-01,\n"
        ."{$own->employee_code},STANDARD,900000,2026-10-01,\n"
        ."{$this->other->employee_code},NOPE,500000,2026-10-01,\n";

    $import = $this->imports->inspect(stageSalaryCsv($csv));
    expect($import->type)->toBe('salaries')->and($import->options['company_id'])->toBe($this->company->id)
        ->and($import->mapping)->toMatchArray(['Employee code' => 'employee_code', 'Salary structure code' => 'structure_code', 'Annual CTC' => 'ctc_annual', 'Effective from' => 'effective_from', 'CONV' => 'component.CONV']);

    $import = $this->imports->validate($this->imports->map($import, $import->mapping));
    $rows = $import->rows()->get()->keyBy('row_number');
    expect($import->create_count)->toBe(2)->and($import->error_count)->toBe(5)
        ->and($rows[1]->action)->toBe('create')->and($rows[2]->action)->toBe('create')
        ->and($rows[3]->errors[0])->toContain("'NOBODY' does not exist")
        ->and($rows[4]->errors[0])->toContain('also appears in row 1')
        ->and($rows[5]->errors[0])->toContain("'abc' is not a number")
        ->and($rows[6]->errors[0])->toContain('their own compensation')
        ->and($rows[7]->errors[0])->toContain("structure 'NOPE' does not exist");

    $import = $this->imports->run($this->imports->approve($import, $this->uploader, 'FY27 salaries'), $this->uploader);
    $cycle = CompensationCycle::query()->findOrFail($import->options['compensation_cycle_id']);
    $lines = CompensationChange::query()->withoutGlobalScope(AccessScope::class)->where('compensation_cycle_id', $cycle->id)->get()->keyBy('employee_id');
    expect($import->status)->toBe('imported')->and($import->create_count)->toBe(2)
        ->and($cycle->cycle_type)->toBe(CompensationCycle::UPLOAD)->and($cycle->status)->toBe('draft')->and($cycle->employee_count)->toBe(2)
        ->and((int) $cycle->prepared_by)->toBe($this->uploader->id)->and($cycle->effective_from->toDateString())->toBe('2026-10-01')
        ->and($lines[$this->newJoiner->id]->change_type)->toBe('hire')->and((float) $lines[$this->newJoiner->id]->ctc_annual)->toBe(600000.0)
        ->and($lines[$this->newJoiner->id]->component_values)->toEqual(['CONV' => 1600])
        ->and($lines[$this->paid->id]->change_type)->toBe('revision')->and($lines[$this->paid->id]->source)->toBe('import')
        ->and($import->rows()->where('row_number', 1)->value('result'))->toContain("in cycle {$cycle->code}");
    expect(EmployeeSalaryAssignment::query()->withoutGlobalScope(AccessScope::class)->where('employee_id', $this->newJoiner->id)->exists())->toBeFalse()
        ->and(AuditEvent::query()->where('operation_id', $import->operation_id)->where('action', 'BULK_OPERATION')->exists())->toBeTrue();

    // An upload cycle is never populated, and the uploader cannot push it through alone.
    $cycles = app(CompensationCycles::class);
    expect(fn () => $cycles->populate($cycle, $this->uploader))->toThrow(RuntimeException::class, 'not populated');
    $cycles->submit($cycle->refresh(), $this->uploader);
    expect(fn () => $cycles->review($cycle->refresh(), $this->uploader))->toThrow(RuntimeException::class);
    $cycles->review($cycle->refresh(), $this->actors['reviewer']);
    $cycles->approve($cycle->refresh(), $this->actors['approver']);
    $cycles->execute($cycle->refresh(), $this->actors['executor']);

    $inForce = fn (Employee $e) => EmployeeSalaryAssignment::query()->withoutGlobalScope(AccessScope::class)->active()->where('employee_id', $e->id)->effectiveOn('2026-10-15')->value('ctc_annual');
    expect((float) $inForce($this->newJoiner))->toBe(600000.0)->and((float) $inForce($this->paid))->toBe(720000.0);

    // The same file again: identical rows are already in force; a different amount on the same date needs a correction.
    $again = validatedSalaryUpload("Employee code,Salary structure code,Annual CTC,Effective from,CONV\n{$this->newJoiner->employee_code},STANDARD,600000,2026-10-01,1600\n{$this->paid->employee_code},STANDARD,730000,2026-10-01,1600\n");
    $rows = $again->rows()->get()->keyBy('row_number');
    expect($rows[1]->action)->toBe('skip')->and($rows[1]->match['reason'])->toContain('Already in force')
        ->and($rows[2]->action)->toBe('error')->and($rows[2]->errors[0])->toContain('set Change type to correction');
    $corrected = validatedSalaryUpload("Employee code,Salary structure code,Annual CTC,Effective from,CONV,Change type\n{$this->paid->employee_code},STANDARD,730000,2026-10-01,1600,Correction\n");
    expect($corrected->create_count)->toBe(1);
});

it('uses the default date and company chosen at mapping, and refuses rows that would block the cycle', function () {
    $csv = "Code,CTC\n{$this->newJoiner->employee_code},480000\n{$this->paid->employee_code},700000\n";
    $import = $this->imports->inspect(stageSalaryCsv($csv));
    expect(fn () => $this->imports->map($import, ['Code' => 'employee_code', 'CTC' => 'ctc_annual']))->toThrow(RuntimeException::class, 'Required fields are not mapped: Salary structure code');

    $import = $this->imports->inspect(stageSalaryCsv("Code,Structure,CTC\n{$this->newJoiner->employee_code},STANDARD,480000\n{$this->paid->employee_code},STANDARD,700000\n"));
    expect(fn () => $this->imports->map($import, $import->mapping))->toThrow(RuntimeException::class, 'default effective date');

    // A pending decision for the employee would make the line stale at approval.
    $pending = app(CompensationChanges::class);
    $change = $pending->propose($this->paid, ['change_type' => 'annual_increment', 'effective_from' => '2026-11-01', 'salary_structure_id' => SalaryStructure::query()->where('code', 'STANDARD')->value('id'), 'ctc_annual' => 650000, 'reason' => 'Increment'], $this->actors['proposer']);
    $pending->submit($change, $this->actors['proposer']);

    $import = $this->imports->validate($this->imports->map($import, $import->mapping, ['effective_from' => '2026-10-01']));
    $rows = $import->rows()->get()->keyBy('row_number');
    expect($rows[1]->action)->toBe('create')->and($rows[2]->action)->toBe('error')->and($rows[2]->errors[0])->toContain("{$change->reference} for this employee is still open");

    $line = $this->imports->run($this->imports->approve($import, $this->uploader), $this->uploader);
    expect(CompensationChange::query()->withoutGlobalScope(AccessScope::class)->where('compensation_cycle_id', $line->options['compensation_cycle_id'])->value('effective_from')->toDateString())->toBe('2026-10-01');
});

it('keeps each salary file to its uploader and behind compensation.cycles + compensation.propose', function () {
    $import = validatedSalaryUpload("Employee code,Salary structure code,Annual CTC\n{$this->newJoiner->employee_code},STANDARD,480000\n", ['effective_from' => '2026-10-01']);
    $this->get(SalaryImportResource::getUrl('index'))->assertOk()->assertSee('salaries.csv');
    $this->get(SalaryImportResource::getUrl('view', ['record' => $import]))->assertOk()->assertSee('salaries.csv');

    $colleague = tenantUser($this->tenant, ['compensation.cycles', 'compensation.propose', 'compensation.view']);
    $this->actingAs($colleague)->get(SalaryImportResource::getUrl('index'))->assertOk()->assertDontSee('salaries.csv');
    $this->actingAs($colleague)->get(SalaryImportResource::getUrl('view', ['record' => $import]))->assertNotFound();
    expect(fn () => $this->imports->approve($import, $colleague))->toThrow(RuntimeException::class, 'Only the person who uploaded');

    $proposer = tenantUser($this->tenant, ['compensation.propose', 'employee.import']);
    $this->actingAs($proposer)->get(SalaryImportResource::getUrl('index'))->assertForbidden();
    expect(fn () => stageSalaryCsv("Employee code\nX\n"))->toThrow(RuntimeException::class, 'compensation.cycles');

    // Upload cycles are made from files only.
    expect(fn () => app(CompensationCycles::class)->create(['code' => 'X', 'name' => 'X', 'cycle_type' => CompensationCycle::UPLOAD, 'company_id' => $this->company->id, 'effective_from' => '2026-10-01'], $this->uploader))
        ->toThrow(RuntimeException::class, 'prepared from a file');
});

it('maps and validates a salary upload from its page', function () {
    $import = $this->imports->inspect(stageSalaryCsv("Employee code,Salary structure code,Annual CTC\n{$this->newJoiner->employee_code},STANDARD,480000\n"));

    Livewire::test(ViewSalaryImport::class, ['record' => $import->getRouteKey()])
        ->callAction('map', ['mapping' => ['0' => 'employee_code', '1' => 'structure_code', '2' => 'ctc_annual'], 'company_id' => $this->company->id, 'effective_from' => '2026-10-01'])
        ->assertHasNoActionErrors()
        ->callAction('validate');

    expect($import->refresh()->status)->toBe('validated')->and($import->create_count)->toBe(1)->and($import->options['effective_from'])->toBe('2026-10-01');
});

it('assigns a first CTC or revises an existing one as the uploader chooses, skipping the others with the reason', function () {
    $csv = "Employee code,Salary structure code,Annual CTC,Effective from\n{$this->newJoiner->employee_code},STANDARD,480000,2026-10-01\n{$this->paid->employee_code},STANDARD,720000,2026-10-01\n";

    $new = validatedSalaryUpload($csv, ['mode' => 'new']);
    $rows = $new->rows()->get()->keyBy('row_number');
    expect($new->create_count)->toBe(1)->and($rows[1]->action)->toBe('create')
        ->and($rows[2]->action)->toBe('skip')->and($rows[2]->match['reason'])->toContain('already has a CTC on file');

    $existing = validatedSalaryUpload($csv, ['mode' => 'update']);
    $rows = $existing->rows()->get()->keyBy('row_number');
    expect($existing->create_count)->toBe(1)->and($rows[1]->action)->toBe('skip')->and($rows[1]->match['reason'])->toContain('has no CTC on file yet')
        ->and($rows[2]->action)->toBe('create');

    $import = $this->imports->run($this->imports->approve($existing, $this->uploader), $this->uploader);
    expect(CompensationChange::query()->withoutGlobalScope(AccessScope::class)->where('compensation_cycle_id', $import->options['compensation_cycle_id'])->pluck('employee_id')->all())->toBe([$this->paid->id]);
});
