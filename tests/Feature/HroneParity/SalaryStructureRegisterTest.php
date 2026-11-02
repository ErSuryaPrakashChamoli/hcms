<?php

use App\Domain\Audit\Models\AuditEvent;
use App\Domain\Compensation\Contracts\CompensationOutput;
use App\Domain\Compensation\Models\CompensationChange;
use App\Domain\Compensation\Models\EmployeeSalaryAssignment;
use App\Domain\Compensation\Models\SalaryStructure;
use App\Domain\Compensation\Services\CompensationChanges;
use App\Domain\Compensation\Services\SalaryAssignments;
use App\Domain\Compliance\Models\ComplianceRule;
use App\Domain\Compliance\Models\EmployeeTaxDeclaration;
use App\Domain\Compliance\Services\ComplianceRules;
use App\Domain\Compliance\Services\StatutoryEngine;
use App\Domain\Employment\Models\Employee;
use App\Domain\Employment\Models\EmployeePosition;
use App\Domain\Employment\Models\EmployeeStatutoryDetail;
use App\Domain\Experience\BulkOperations\AssignSalaryOperation;
use App\Domain\Experience\Models\BulkRun;
use App\Domain\Experience\Models\BulkRunItem;
use App\Domain\Experience\Services\BulkOperations;
use App\Domain\Experience\Services\BulkRuns;
use App\Domain\Identity\Models\UserAccessScope;
use App\Domain\Identity\Scopes\AccessScope;
use App\Domain\Letters\Services\LetterContext;
use App\Domain\Letters\Services\Letters;
use App\Domain\Lifecycle\Enums\LifecycleState;
use App\Domain\Organisation\Models\Department;
use App\Domain\Payroll\Models\PayrollEntry;
use App\Domain\Payroll\Models\PayrollPeriod;
use App\Domain\Payroll\Models\PayrollRun;
use App\Domain\Payroll\Services\SalaryProposalPreview;
use App\Domain\Payroll\Services\StructureFormulas;
use App\Filament\Pages\PayrollDesk;
use App\Filament\Pages\SalaryStructureRegister;
use App\Filament\Resources\CompensationChanges\Pages\ManageCompensationChanges;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;

require_once __DIR__.'/../Workflow/WorkflowTestHelpers.php';
require_once __DIR__.'/../Payroll/PayrollTestHelpers.php';

/*
| HROne parity PO-2 (matrix 25 §25.2.1 #1–#5, §25.2.2 #1, #12, #18, #21–#23, #30, §25.2.3 #1): the employee salary
| structure register, "Assign salary" for one or many employees (proposals only), the breakup preview with a read-only
| statutory estimate from verified rules, "Print breakup", and the salary structure requests' cards and filters.
*/

beforeEach(function () {
    // Hired and paid since 2025; the requests below are made in October 2026.
    $this->travelTo('2026-09-01 10:00:00');
    syncComplianceRules();
    $this->tenant = provisionTenant();
    actAsTenant($this->tenant);
    $this->hr = tenantUser($this->tenant, ['*']);
    $this->actingAs($this->hr);
    Filament::getPanel('admin')->boot();
    $this->company = payrollCompany();
    $this->standard = SalaryStructure::query()->where('code', 'STANDARD')->firstOrFail();

    $this->assigned = salariedEmployee(600000);
    $this->pending = salariedEmployee(480000);
    $this->rejected = salariedEmployee(360000);
    $this->approved = salariedEmployee(300000);
    $this->leaver = salariedEmployee(240000);
    forceLifecycle($this->leaver, LifecycleState::Exited, ['exit_date' => '2026-08-31']);

    $this->travelTo('2026-10-15 10:00:00');
    $this->joiner = employeeWithUser();
    forceLifecycle($this->joiner, LifecycleState::Active);
    $actors = compensationActors();
    $changes = app(CompensationChanges::class);
    $propose = fn (Employee $e, float $ctc) => $changes->submit($changes->propose($e, ['change_type' => 'revision', 'effective_from' => '2026-11-01', 'salary_structure_id' => $this->standard->id,
        'ctc_annual' => $ctc, 'component_values' => ['CONV' => 1600], 'reason' => 'Annual increment'], $actors['proposer']), $actors['proposer']);
    $this->pendingChange = $propose($this->pending, 520000);
    $this->rejectedChange = $changes->reject($propose($this->rejected, 400000), $actors['reviewer'], 'Not this cycle');
    $this->approvedChange = $propose($this->approved, 330000);
    $changes->review($this->approvedChange, $actors['reviewer']);
    $changes->approve($this->approvedChange, $actors['approver']);
});

/** The assign wizard's answers for one employee. */
function po2Assign(array $overrides = []): array
{
    return $overrides + ['change_type' => 'revision', 'effective_from' => '2026-11-01', 'salary_structure_id' => 'keep', 'ctc_mode' => 'amount', 'currency' => 'INR', 'reason' => 'Annual increment', 'submit' => true];
}

// ---- The register ----------------------------------------------------------------------------------------------------

it('lists each employee once with the state, structure and CTC in force, and the Not assigned preset', function () {
    EmployeeTaxDeclaration::query()->create(['employee_id' => $this->assigned->id, 'financial_year' => '2026-27', 'regime' => 'old', 'declarations' => [], 'status' => 'draft']);

    $page = Livewire::test(SalaryStructureRegister::class)->assertOk()
        ->assertCanSeeTableRecords([$this->assigned, $this->joiner, $this->pending, $this->rejected, $this->approved])
        ->assertCanNotSeeTableRecords([$this->leaver])
        ->assertTableColumnStateSet('salary_state', 'Assigned', $this->assigned)
        ->assertTableColumnStateSet('salary_state', 'Not assigned', $this->joiner)
        ->assertTableColumnStateSet('salary_state', 'Pending', $this->pending)
        ->assertTableColumnStateSet('salary_state', 'Rejected', $this->rejected)
        ->assertTableColumnStateSet('salary_state', 'Approved', $this->approved)
        ->assertTableColumnStateSet('structure_in_force', 'Standard structure', $this->assigned)
        ->assertTableColumnStateSet('ctc_in_force', 600000.0, $this->assigned)
        ->assertTableColumnStateSet('ctc_in_force', 360000.0, $this->rejected)
        ->assertTableColumnStateSet('request_status', 'Submitted', $this->pending)
        ->assertTableColumnStateSet('request_source', 'Proposed by a person', $this->pending)
        ->assertTableColumnStateSet('tax_regime', 'Old regime', $this->assigned)
        ->assertTableColumnStateSet('tax_regime', 'New (no declaration)', $this->pending);
    expect($page->instance()->stateCounts())->toBe(['assigned' => 1, 'not_assigned' => 1, 'pending' => 1, 'approved' => 1, 'rejected' => 1]);

    // Filters: state, salary in force, source, request date, regime, pay group.
    $page->filterTable('state', ['pending', 'rejected'])->assertCanSeeTableRecords([$this->pending, $this->rejected])->assertCanNotSeeTableRecords([$this->assigned, $this->joiner, $this->approved])
        ->resetTableFilters()->filterTable('salary_in_force', 'no')->assertCanSeeTableRecords([$this->joiner])->assertCanNotSeeTableRecords([$this->assigned, $this->pending, $this->rejected, $this->approved])
        ->resetTableFilters()->filterTable('requested', ['from' => '2026-10-01', 'until' => '2026-10-31'])->assertCanSeeTableRecords([$this->pending, $this->rejected, $this->approved])->assertCanNotSeeTableRecords([$this->assigned, $this->joiner])
        ->resetTableFilters()->filterTable('source', ['import'])->assertCanNotSeeTableRecords([$this->assigned, $this->pending])
        ->resetTableFilters()->filterTable('tax_regime', ['old'])->assertCanSeeTableRecords([$this->assigned])->assertCanNotSeeTableRecords([$this->pending, $this->joiner])
        ->resetTableFilters()->filterTable('tax_regime', ['undeclared'])->assertCanSeeTableRecords([$this->pending, $this->joiner])->assertCanNotSeeTableRecords([$this->assigned])
        ->resetTableFilters()->filterTable('salary_structure', [$this->standard->id])->assertCanSeeTableRecords([$this->assigned, $this->pending])->assertCanNotSeeTableRecords([$this->joiner]);

    // HROne's "NO SALARY STRUCTURE": employees without salary in force, and a state card applying its filter.
    Livewire::withQueryParams(['view' => 'not_assigned'])->test(SalaryStructureRegister::class)
        ->assertCanSeeTableRecords([$this->joiner])->assertCanNotSeeTableRecords([$this->assigned, $this->pending, $this->rejected, $this->approved]);
    Livewire::withQueryParams([]);
    Livewire::test(SalaryStructureRegister::class)->call('showState', 'approved')
        ->assertCanSeeTableRecords([$this->approved])->assertCanNotSeeTableRecords([$this->assigned, $this->joiner]);

    // The payroll desk links the register and counts the employees without a salary structure.
    $item = collect(collect((new PayrollDesk)->deskSections())->firstWhere('label', 'Salary structure')['items'])->firstWhere('label', 'Employees without a salary structure');
    expect($item['count'])->toBe(1)->and($item['url'])->toContain('view=not_assigned');
});

it('exports the filtered register as an audited CSV for compensation.export holders only', function () {
    Livewire::test(SalaryStructureRegister::class)->filterTable('state', ['assigned', 'not_assigned'])
        ->callAction('downloadCsv')->assertFileDownloaded('employee-salary-structures-2026-10-15.csv');
    $event = AuditEvent::query()->where('action', 'EXPORT')->where('module', 'compensation')->latest('id')->firstOrFail();
    expect($event->metadata)->toMatchArray(['export' => 'employee_salary_structures', 'rows' => 2])->and($event->metadata['filters'])->toContain('state')
        ->and($event->actor_id)->toBe($this->hr->id);

    $this->actingAs(tenantUser($this->tenant, ['compensation.view']));
    Livewire::test(SalaryStructureRegister::class)->assertActionHidden('downloadCsv')
        ->assertTableActionHidden('assign', $this->joiner)->assertTableBulkActionHidden('assignSelected');

    // compensation.view is the register's right.
    $this->actingAs(tenantUser($this->tenant, ['payroll.view', 'payroll.manage', 'employee.view']));
    expect(SalaryStructureRegister::canAccess())->toBeFalse();
    $this->get(SalaryStructureRegister::getUrl())->assertForbidden();
});

it('keeps the register inside the reader\'s access scope and the tenant', function () {
    $department = Department::factory()->create();
    EmployeePosition::query()->where('employee_id', $this->pending->id)->update(['department_id' => $department->id]);
    $scoped = tenantUser($this->tenant, ['compensation.view', 'compensation.propose', 'bulk.run', 'employee.view']);
    UserAccessScope::query()->create(['user_id' => $scoped->id, 'dimension' => 'department', 'scope_id' => $department->id]);
    $this->actingAs($scoped);

    $page = Livewire::test(SalaryStructureRegister::class)->assertCanSeeTableRecords([$this->pending])
        ->assertCanNotSeeTableRecords([$this->assigned, $this->joiner, $this->rejected, $this->approved]);
    expect(array_sum($page->instance()->stateCounts()))->toBe(1);

    // A bulk run naming someone outside the scope refuses them.
    $run = app(BulkRuns::class)->start($scoped, SalaryStructureRegister::REGISTER, AssignSalaryOperation::KEY, [$this->assigned->id],
        AssignSalaryOperation::input(po2Assign(['ctc_mode' => 'percent', 'increase_percent' => 5])));
    expect(BulkRunItem::query()->where('bulk_run_id', $run->id)->sole()->only(['outcome', 'message']))->toBe(['outcome' => 'refused', 'message' => 'Not in your access, or no longer exists.'])
        ->and(CompensationChange::query()->withoutGlobalScope(AccessScope::class)->where('employee_id', $this->assigned->id)->count())->toBe(1);

    // Another tenant's employees never appear.
    $this->actingAs($this->hr);
    $other = provisionTenant('Other');
    actAsTenant($other);
    $stranger = salariedEmployee(300000);
    actAsTenant($this->tenant);
    Livewire::test(SalaryStructureRegister::class)->assertCanNotSeeTableRecords([$stranger]);
});

// ---- Assign salary --------------------------------------------------------------------------------------------------

it('revises by a percentage of the CTC in force, rounded as compensation cycles round', function () {
    expect(SalaryAssignments::revise(600000, 10))->toBe(660000.0)
        ->and(SalaryAssignments::revise(333333.33, 3.33))->toBe(round(333333.33 * 1.0333, 2))
        ->and(SalaryAssignments::revise(480000, -2.5))->toBe(468000.0);

    $assignments = app(SalaryAssignments::class);
    $proposal = $assignments->check($this->assigned, po2Assign(['ctc_mode' => 'percent', 'increase_percent' => 7.5, 'currency' => 'USD']), $this->hr);
    expect($proposal['ctc_annual'])->toBe(645000.0)->and($proposal['increase_percent'])->toBe(7.5)
        // A percentage revises the CTC in its own currency, on the structure in force, with its fixed amounts.
        ->and($proposal['currency'])->toBe('INR')->and($proposal['salary_structure_id'])->toBe($this->standard->id)
        ->and($proposal['component_values'])->toBe(['CONV' => 1600.0]);

    // No CTC in force to revise, and "keep" without a structure in force, are refused with the reason.
    expect(fn () => $assignments->check($this->joiner, po2Assign(['ctc_mode' => 'percent', 'increase_percent' => 5, 'salary_structure_id' => $this->standard->id]), $this->hr))
        ->toThrow(RuntimeException::class, 'No compensation is in force on 1 Nov 2026 to revise by a percentage')
        ->and(fn () => $assignments->check($this->joiner, po2Assign(['ctc_annual' => 400000]), $this->hr))
        ->toThrow(RuntimeException::class, 'No salary structure is in force on 1 Nov 2026 to keep')
        ->and(fn () => $assignments->check($this->assigned, po2Assign(['ctc_mode' => 'percent', 'increase_percent' => -100]), $this->hr))
        ->toThrow(RuntimeException::class, 'percentage above -100');

    // The wizard records the percentage on the proposal.
    Livewire::test(SalaryStructureRegister::class)
        ->callTableAction('assign', $this->assigned, data: po2Assign(['ctc_mode' => 'percent', 'increase_percent' => 10]))
        ->assertHasNoTableActionErrors()->assertNotified('Salary proposed and submitted for review');
    $change = CompensationChange::query()->where('employee_id', $this->assigned->id)->where('change_type', 'revision')->sole();
    expect((float) $change->ctc_annual)->toBe(660000.0)->and((float) $change->increase_percent)->toBe(10.0)->and($change->status)->toBe('submitted');
});

it('previews the breakup exactly as StructureFormulas::preview works it out for the employee', function () {
    $assignments = app(SalaryAssignments::class);
    $proposal = $assignments->check($this->assigned, po2Assign(['ctc_mode' => 'percent', 'increase_percent' => 10]), $this->hr);
    $segment = $assignments->segment($this->assigned, $proposal);
    $result = app(SalaryProposalPreview::class)->for($this->assigned, $segment);

    $version = $this->standard->versionOn('2026-11-01');
    expect(collect($result['lines'])->map(fn ($l) => [$l['component']->code, $l['formula_override'], $l['sort_order']])->all())
        ->toBe(collect($version->formulaLines())->map(fn ($l) => [$l['component']->code, $l['formula_override'], $l['sort_order']])->all())
        ->and($result['inputs'])->toMatchArray(['ctc_annual' => 660000.0, 'values' => ['CONV' => 1600.0], 'days' => 30, 'pf_applies' => true, 'pf_restrict_to_ceiling' => true])
        ->and($result['preview'])->toBe(app(StructureFormulas::class)->preview($result['lines'], $segment->parameters, $result['inputs']))
        ->and($result['preview']['totals']['ctc_monthly'])->toBe(55000.0)
        ->and(collect($result['preview']['rows'])->firstWhere('code', 'BASIC')['monthly'])->toBe(22000.0)
        // The wage-share guardrail the structure preview has (advisory), and the payment mode (bank transfer by default).
        ->and(collect($result['checks'])->firstWhere('check', 'guardrail')['state'])->toBe('attention')
        ->and($result['pay_mode'])->toBe(['label' => 'Bank transfer (the default)', 'set' => false]);

    // The wizard's preview step shows the same breakup.
    $data = Livewire::test(SalaryStructureRegister::class)->instance()->previewData($this->assigned, po2Assign(['ctc_mode' => 'percent', 'increase_percent' => 10]));
    expect($data['failure'])->toBeNull()->and($data['result']['preview'])->toBe($result['preview'])->and($data['current']->ctcAnnual)->toBe(600000.0);
    // A refusal shows instead of a breakup.
    $refused = Livewire::test(SalaryStructureRegister::class)->instance()->previewData($this->pending, po2Assign(['ctc_mode' => 'keep']));
    expect($refused['failure'])->toContain('This proposal would be refused: A compensation change is already pending');
});

it('estimates statutory deductions read-only, only from verified rules, and says "No verified rule" otherwise', function () {
    $counts = fn () => [PayrollRun::query()->count(), PayrollEntry::query()->count(), PayrollPeriod::query()->count(), CompensationChange::query()->count(),
        EmployeeSalaryAssignment::query()->count(), DB::table('employee_statutory_details')->count()];
    $before = $counts();
    $assignments = app(SalaryAssignments::class);
    $proposal = $assignments->check($this->assigned, po2Assign(['ctc_mode' => 'percent', 'increase_percent' => 10]), $this->hr);
    $segment = $assignments->segment($this->assigned, $proposal);

    // Today no rule version is verified: nothing is estimated, and each scheme says so.
    $result = app(SalaryProposalPreview::class)->for($this->assigned, $segment);
    $estimate = $result['estimate'];
    expect($estimate['month'])->toBe('Nov 2026')
        ->and(collect($estimate['schemes'])->only(['EPF', 'PT'])->pluck('state')->unique()->values()->all())->toBe(['no_rule'])
        ->and($estimate['schemes']['EPF']['text'])->toBe('No verified rule: not estimated.')
        ->and($estimate['schemes']['PT']['text'])->toBe('No verified rule for KA: not estimated.')
        ->and($estimate['employee'])->toBe([])->and($estimate['employer'])->toBe([])->and($estimate['complete'])->toBeFalse()
        ->and($estimate['take_home'])->toBe(round($result['preview']['totals']['gross'] - $result['preview']['totals']['deductions'], 2))
        ->and(collect($result['checks'])->firstWhere('check', 'wage_share')['state'])->toBe('no_rule')
        ->and(collect($result['checks'])->firstWhere('check', 'minimum_wage')['text'])->toContain('No verified minimum wage rule');

    // A verified EPF rule version (a new one: the open notices on the September 2026 ceiling change name v1 and v2):
    // provident fund is estimated by the statutory engine with that rule's figures.
    $v2 = ComplianceRule::query()->where('code', 'EPF')->where('version', 2)->firstOrFail();
    $v3 = ComplianceRule::query()->create(['jurisdiction' => 'IN', 'code' => 'EPF', 'state' => null, 'name' => 'EPF test version', 'version' => 3, 'effective_from' => '2026-10-01',
        'parameters' => $v2->parameters, 'status' => ComplianceRule::ACTIVE]);
    DB::table('compliance_rules')->where('id', $v3->id)->update(['verification_status' => ComplianceRule::VERIFIED]);
    app(ComplianceRules::class)->forget();
    $result = app(SalaryProposalPreview::class)->for($this->assigned, $segment);
    $estimate = $result['estimate'];
    $pfWages = (float) collect($result['preview']['rows'])->firstWhere('code', 'BASIC')['monthly'];
    $expected = app(StatutoryEngine::class)->contributionsOn($pfWages, 0, '2026-11-30', true)['pf'];
    expect($estimate['schemes']['EPF']['state'])->toBe('estimated')->and($estimate['schemes']['EPF']['rule'])->toBe($v3->refresh()->label())
        ->and(collect($estimate['employee'])->firstWhere('code', 'PF_EE')['amount'])->toBe($expected['employee'])
        ->and(collect($estimate['employer'])->firstWhere('code', 'PF_ER')['amount'])->toBe($expected['employer'])
        ->and($estimate['schemes']['PT']['state'])->toBe('no_rule')->and($estimate['complete'])->toBeFalse()
        ->and(collect($estimate['employee'])->pluck('code')->all())->not->toContain('PT')->not->toContain('TDS');

    // Nothing was written: no run, entry, period (the month is an unsaved stand-in), change, compensation or statutory row.
    expect($counts())->toBe($before)->and(PayrollPeriod::query()->where('year', 2026)->where('month', 11)->exists())->toBeFalse();
});

it('creates a pending proposal through CompensationChanges and never approves or applies it', function () {
    $assignmentsBefore = EmployeeSalaryAssignment::query()->count();
    Livewire::test(SalaryStructureRegister::class)
        ->callTableAction('assign', $this->joiner, data: po2Assign([
            'change_type' => 'hire', 'salary_structure_id' => $this->standard->id, 'ctc_annual' => 420000, 'component_values' => ['CONV' => 1600], 'reason' => 'Joining salary',
            // Step 1: the statutory details, through the Employee 360 action (same right, audited with the reason).
            'change_statutory' => true, 'pf_applicable' => true, 'esic_applicable' => false, 'pt_applicable' => true, 'tax_regime' => 'new', 'pt_state_code' => 'KA', 'statutory_reason' => 'Joining forms',
        ]))
        ->assertHasNoTableActionErrors()->assertNotified('Salary proposed and submitted for review');

    $change = CompensationChange::query()->where('employee_id', $this->joiner->id)->sole();
    expect($change->only(['status', 'source', 'change_type', 'proposed_by', 'reviewed_by', 'approved_by', 'scheduled_by', 'employee_salary_assignment_id']))
        ->toBe(['status' => 'submitted', 'source' => 'manual', 'change_type' => 'hire', 'proposed_by' => $this->hr->id, 'reviewed_by' => null, 'approved_by' => null, 'scheduled_by' => null, 'employee_salary_assignment_id' => null])
        ->and((float) $change->ctc_annual)->toBe(420000.0)
        ->and(EmployeeSalaryAssignment::query()->count())->toBe($assignmentsBefore)
        ->and(EmployeeSalaryAssignment::query()->where('employee_id', $this->joiner->id)->exists())->toBeFalse();
    $detail = EmployeeStatutoryDetail::query()->where('employee_id', $this->joiner->id)->sole();
    expect($detail->only(['pf_applicable', 'esic_applicable', 'pt_applicable', 'tax_regime', 'pt_state_code']))
        ->toBe(['pf_applicable' => true, 'esic_applicable' => false, 'pt_applicable' => true, 'tax_regime' => 'new', 'pt_state_code' => 'KA'])
        ->and(AuditEvent::query()->where('entity_type', EmployeeStatutoryDetail::class)->where('entity_id', $detail->id)->where('reason', 'Joining forms')->exists())->toBeTrue();

    // The register now shows it pending; a second proposal is refused while it is open.
    Livewire::test(SalaryStructureRegister::class)->assertTableColumnStateSet('salary_state', 'Pending', $this->joiner)
        ->callTableAction('assign', $this->joiner, data: po2Assign(['change_type' => 'hire', 'salary_structure_id' => $this->standard->id, 'ctc_annual' => 450000]))
        ->assertNotified('Salary not proposed');
    expect(CompensationChange::query()->where('employee_id', $this->joiner->id)->count())->toBe(1);

    // A draft when "Submit for review now" is off; the usual approval chain (other people) decides it.
    Livewire::test(SalaryStructureRegister::class)->callTableAction('assign', $this->assigned, data: po2Assign(['ctc_annual' => 650000, 'submit' => false]))
        ->assertNotified('Salary proposal saved as a draft');
    $draft = CompensationChange::query()->where('employee_id', $this->assigned->id)->where('change_type', 'revision')->sole();
    expect($draft->status)->toBe('draft');
    $actors = compensationActors();
    expect(fn () => app(CompensationChanges::class)->review($change, $this->hr))->toThrow(RuntimeException::class, 'The proposer of a compensation change cannot review it.');
    app(CompensationChanges::class)->review($change, $actors['reviewer']);
    expect($change->refresh()->status)->toBe('under_review');
});

it('assigns salary to selected employees through the bulk framework with per-employee refusals', function () {
    $me = employeeWithUser(null, ['compensation.view', 'compensation.propose', 'bulk.run', 'employee.view']);
    forceLifecycle($me, LifecycleState::Active);
    compensate($me, 500000, '2025-01-01');
    $this->actingAs($me->user);
    expect(app(BulkOperations::class)->find(AssignSalaryOperation::KEY))->toBeInstanceOf(AssignSalaryOperation::class);

    Livewire::test(SalaryStructureRegister::class)
        ->callTableBulkAction('assignSelected', [$this->assigned, $this->joiner, $this->pending, $me], data: po2Assign(['ctc_mode' => 'percent', 'increase_percent' => 10]))
        ->assertHasNoTableBulkActionErrors();

    $run = BulkRun::query()->where('operation', AssignSalaryOperation::KEY)->sole();
    $items = BulkRunItem::query()->where('bulk_run_id', $run->id)->get()->keyBy('record_id');
    expect($run->status)->toBe('completed')->and([$run->done, $run->refused, $run->failed])->toBe([1, 3, 0])
        ->and($items[$this->assigned->id]->outcome)->toBe('done')
        ->and($items[$this->assigned->id]->message)->toContain('submitted for review')->not->toContain('660,000')->not->toContain('660000')
        ->and($items[$this->joiner->id]->message)->toBe('No salary structure is in force on 1 Nov 2026 to keep; choose a structure.')
        ->and($items[$this->pending->id]->message)->toContain('A compensation change is already pending for this employee')
        ->and($items[$me->id]->message)->toBe('No one proposes their own compensation.');

    // One proposal for the employee done, in the run's audit operation, waiting for review — nothing approved or applied.
    $change = CompensationChange::query()->where('employee_id', $this->assigned->id)->where('change_type', 'revision')->sole();
    expect($change->status)->toBe('submitted')->and((float) $change->ctc_annual)->toBe(660000.0)->and($change->proposed_by)->toBe($me->user_id)
        ->and(CompensationChange::query()->whereIn('employee_id', [$this->joiner->id, $me->id])->where('change_type', 'revision')->exists())->toBeFalse()
        ->and(AuditEvent::query()->where('operation_id', $run->refresh()->operation_id)->where('entity_type', CompensationChange::class)->exists())->toBeTrue();

    // Without bulk.run the bulk action is not offered.
    $this->actingAs(tenantUser($this->tenant, ['compensation.view', 'compensation.propose']));
    Livewire::test(SalaryStructureRegister::class)->assertTableBulkActionHidden('assignSelected')->assertTableActionVisible('assign', $this->joiner);
});

it('prints the breakup as the salary annexure in the letter studio layout, audited', function () {
    $assignments = app(SalaryAssignments::class);
    $state = po2Assign(['ctc_mode' => 'percent', 'increase_percent' => 10]);
    Livewire::test(SalaryStructureRegister::class)->call('printProposal', $this->assigned->id, $state)
        ->assertFileDownloaded(Str::slug('salary-breakup-'.$this->assigned->employee_code.'-2026-11-01').'.html');
    $event = AuditEvent::query()->where('action', 'EXPORT')->where('module', 'compensation')->latest('id')->firstOrFail();
    expect($event->metadata)->toMatchArray(['export' => 'salary_breakup', 'employee_id' => $this->assigned->id, 'proposal' => true, 'effective_from' => '2026-11-01']);

    $segment = $assignments->segment($this->assigned, $assignments->check($this->assigned, $state, $this->hr));
    $page = app(Letters::class)->breakup($this->assigned, $segment, $this->hr);
    $annexure = app(LetterContext::class)->salaryFor($this->assigned, $segment, $segment->from)['annexure'];
    expect($page['mime'])->toBe('text/html')->and($page['content'])->toContain($annexure->toHtml())
        ->toContain('Cost to company (CTC)')->toContain('660,000.00')->toContain('Proposed salary, not yet approved')->toContain('<header>');

    // The compensation in force prints the same annexure its letters print (both as of today).
    $letter = app(LetterContext::class)->build($this->assigned)['salary']['annexure'];
    $inForce = app(CompensationOutput::class)->forPayroll($this->assigned, today(), today())->last();
    expect(app(Letters::class)->breakup($this->assigned, $inForce, $this->hr, null, false)['content'])
        ->toContain($letter->toHtml())->not->toContain('Proposed salary');
    // A request's breakup is its own proposal, on its effective date.
    $hire = CompensationChange::query()->where('employee_id', $this->assigned->id)->where('change_type', 'hire')->sole();
    expect($assignments->segmentForChange($hire)->from->toDateString())->toBe('2025-01-01')
        ->and($assignments->segmentForChange($hire)->compensation->ctcAnnual)->toBe(600000.0);

    // On the salary structure requests: "Print breakup" for a request.
    Livewire::test(ManageCompensationChanges::class)->callTableAction('printBreakup', $this->pendingChange)
        ->assertFileDownloaded(Str::slug('salary-breakup-'.$this->pending->employee_code.'-2026-11-01').'.html');
});

// ---- Salary structure requests ------------------------------------------------------------------------------------

it('gives the salary structure requests their Approved / Pending / Rejected cards and HROne\'s filters', function () {
    $hires = CompensationChange::query()->where('change_type', 'hire')->get()->all();
    EmployeeTaxDeclaration::query()->create(['employee_id' => $this->pending->id, 'financial_year' => '2026-27', 'regime' => 'old', 'declarations' => [], 'status' => 'submitted']);

    // "All" first: the list opens as before.
    $list = Livewire::test(ManageCompensationChanges::class)->assertCanSeeTableRecords([$this->pendingChange, $this->rejectedChange, $this->approvedChange, ...$hires]);
    $tabs = $list->instance()->getTabs();
    expect(array_keys($tabs))->toBe(['all', 'pending', 'approved', 'rejected'])
        ->and((int) $tabs['pending']->getBadge())->toBe(1)->and((int) $tabs['rejected']->getBadge())->toBe(1)->and((int) $tabs['approved']->getBadge())->toBe(count($hires) + 1);
    $list->set('activeTab', 'pending')->assertCanSeeTableRecords([$this->pendingChange])->assertCanNotSeeTableRecords([$this->rejectedChange, $this->approvedChange])
        ->set('activeTab', 'rejected')->assertCanSeeTableRecords([$this->rejectedChange])->assertCanNotSeeTableRecords([$this->pendingChange])
        ->set('activeTab', 'approved')->assertCanSeeTableRecords([$this->approvedChange, ...$hires])->assertCanNotSeeTableRecords([$this->pendingChange, $this->rejectedChange]);

    Livewire::test(ManageCompensationChanges::class)
        ->filterTable('request_status', 'rejected')->assertCanSeeTableRecords([$this->rejectedChange])->assertCanNotSeeTableRecords([$this->pendingChange, $this->approvedChange])
        ->resetTableFilters()->filterTable('source', ['import'])->assertCanNotSeeTableRecords([$this->pendingChange, ...$hires])
        ->resetTableFilters()->filterTable('source', ['manual'])->assertCanSeeTableRecords([$this->pendingChange, ...$hires])
        ->resetTableFilters()->filterTable('tax_regime', ['old'])->assertCanSeeTableRecords([$this->pendingChange])->assertCanNotSeeTableRecords([$this->rejectedChange, $this->approvedChange])
        ->resetTableFilters()->filterTable('requested', ['from' => '2026-10-01', 'until' => '2026-10-31'])->assertCanSeeTableRecords([$this->pendingChange, $this->rejectedChange, $this->approvedChange])
        ->assertCanNotSeeTableRecords($hires)
        ->assertTableColumnStateSet('tax_regime', 'Old regime', $this->pendingChange);

    Livewire::withQueryParams(['view' => 'rejected_requests'])->test(ManageCompensationChanges::class)
        ->assertCanSeeTableRecords([$this->rejectedChange])->assertCanNotSeeTableRecords([$this->pendingChange]);
    Livewire::withQueryParams([]);
});
