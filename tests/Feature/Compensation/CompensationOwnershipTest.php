<?php

use App\Domain\Audit\Models\AuditEvent;
use App\Domain\Compensation\Contracts\CompensationOutput;
use App\Domain\Compensation\Models\CompensationChange;
use App\Domain\Compensation\Models\EmployeeSalaryAssignment;
use App\Domain\Compensation\Models\SalaryStructure;
use App\Domain\Compensation\Services\CompensationAccess;
use App\Domain\Compensation\Services\CompensationChanges;
use App\Domain\Employment\Models\EmployeeBankAccount;
use App\Domain\Exit\Services\Exits;
use App\Domain\Exit\Services\FinalSettlements;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Scopes\AccessScope;
use App\Domain\Identity\Services\AccessScopes;
use App\Domain\Letters\Services\Letters;
use App\Domain\Organisation\Models\Company;
use App\Domain\Payroll\Models\PayrollEntry;
use App\Domain\Payroll\Models\PayrollPeriod;
use App\Domain\Payroll\Services\PayrollCalculator;
use App\Domain\Payroll\Services\PayrollRuns;
use App\Filament\Resources\Employees\Pages\ViewEmployee;
use App\Filament\Resources\Employees\RelationManagers\CompensationRelationManager;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Livewire\Livewire;

require_once __DIR__.'/../Workflow/WorkflowTestHelpers.php';
require_once __DIR__.'/../Performance/PerformanceTestHelpers.php';
require_once __DIR__.'/../Payroll/PayrollTestHelpers.php';

/*
 | Phase 11.1 ownership refactor (ADR in docs/architecture/compensation.md): employee_salary_assignments
 | stays the single canonical compensation history, Compensation is its domain owner and sole write
 | authority, and Payroll, Letters and Exit read it only through the CompensationOutput contract.
 */

beforeEach(function () {
    $this->travelTo('2026-09-21 09:00:00');
    syncComplianceRules();
    $this->tenant = provisionTenant();
    actAsTenant($this->tenant);
    $this->hr = tenantUser($this->tenant, ['*']);
    $this->actingAs($this->hr);
    $this->company = payrollCompany();
    $this->changes = app(CompensationChanges::class);
    $this->output = app(CompensationOutput::class);
    $this->actors = compensationActors();
    $this->structure = SalaryStructure::query()->where('code', 'STANDARD')->firstOrFail();
    $this->employee = salariedEmployee(600000, ['task.view'], '2026-04-01');
    $this->propose = fn (array $data = [], $employee = null) => $this->changes->propose($employee ?? $this->employee, $data + ['change_type' => 'annual_increment', 'effective_from' => '2026-10-01', 'salary_structure_id' => $this->structure->id, 'ctc_annual' => 660000, 'reason' => 'Increment'], $this->actors['proposer']);
    $this->approved = function (CompensationChange $change): CompensationChange {
        $this->changes->submit($change, $this->actors['proposer']);
        $this->changes->review($change, $this->actors['reviewer']);

        return $this->changes->approve($change, $this->actors['approver']);
    };
    $this->rows = fn ($employee = null) => EmployeeSalaryAssignment::query()->withoutGlobalScope(AccessScope::class)->where('employee_id', ($employee ?? $this->employee)->id)->orderBy('effective_from')->orderBy('id')->get();
});

/** Strip comments so docblocks that state a boundary are not hits. */
function compensationOwnershipCode(string $path): string
{
    return collect(token_get_all((string) file_get_contents($path)))->reject(fn ($t) => is_array($t) && in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true))->map(fn ($t) => is_array($t) ? $t[1] : $t)->implode('');
}

/** @return list<string> app files (relative) whose code matches the pattern */
function compensationOwnershipFilesMatching(string $pattern, string $dir = 'app'): array
{
    return collect(File::allFiles(base_path($dir)))->filter(fn ($f) => $f->getExtension() === 'php' && preg_match($pattern, compensationOwnershipCode($f->getPathname())))
        ->map(fn ($f) => str_replace(base_path().'/', '', $f->getPathname()))->sort()->values()->all();
}

it('1. lets Payroll read approved compensation through the contract, with its fingerprint', function () {
    $period = PayrollPeriod::for($this->company, 2026, 9);
    $c = app(PayrollCalculator::class)->calculate($this->employee, $period);
    $row = ($this->rows)()->sole();

    expect($c->compensation->compensation->assignmentId)->toBe($row->id)
        ->and($c->inputs['ctc_annual'])->toBe(600000.0)
        ->and($c->inputs['compensation']['contract'])->toBe(CompensationOutput::VERSION)
        ->and($c->inputs['compensation']['fingerprint'])->toBe($this->output->fingerprints([$this->employee->id], $period->start_date, $period->end_date)[$this->employee->id])
        ->and(collect($c->lines)->firstWhere('code', 'BASIC')['amount'])->toBe(20000.0);   // 40% of 50,000 monthly CTC, unchanged engine
});

it('1b. never lets Payroll consume draft, submitted, approved-but-unexecuted, rejected or cancelled compensation', function () {
    $period = PayrollPeriod::for($this->company, 2026, 9);
    $draft = ($this->propose)(['effective_from' => '2026-09-01', 'ctc_annual' => 900000, 'change_type' => 'market_adjustment']);
    $rejected = ($this->propose)(['effective_from' => '2026-09-05', 'ctc_annual' => 910000]);
    $this->changes->submit($rejected, $this->actors['proposer']);
    $this->changes->reject($rejected, $this->actors['reviewer'], 'Not in budget');
    $approved = ($this->approved)(($this->propose)(['effective_from' => '2026-09-10', 'ctc_annual' => 920000]));
    $cancelled = ($this->propose)(['effective_from' => '2026-09-15', 'ctc_annual' => 930000]);
    $this->changes->cancel($cancelled, $this->actors['proposer'], 'Withdrawn');

    $c = app(PayrollCalculator::class)->calculate($this->employee, $period);
    expect(collect($c->inputs['segments'])->pluck('ctc_annual')->all())->toBe([600000.0])
        ->and($draft->refresh()->status)->toBe('draft')->and($approved->refresh()->status)->toBe('approved')
        ->and(($this->rows)())->toHaveCount(1);
});

it('2. refuses every direct write to the canonical table, from Payroll or anywhere else', function () {
    $row = ($this->rows)()->sole();
    // Its creation was audited with the amounts and the reason masked (§21).
    $created = AuditEvent::query()->where('entity_type', EmployeeSalaryAssignment::class)->where('entity_id', (string) $row->id)->where('action', 'CREATE')->with('fieldChanges')->sole();
    expect($created->fieldChanges->whereIn('field', ['ctc_annual', 'component_values', 'reason'])->every(fn ($c) => $c->is_sensitive && $c->after === config('peopleos.audit.mask')))->toBeTrue()
        ->and($created->fieldChanges->whereIn('field', ['ctc_annual', 'component_values', 'reason']))->toHaveCount(3);

    expect(fn () => EmployeeSalaryAssignment::query()->create(['employee_id' => $this->employee->id, 'salary_structure_id' => $this->structure->id, 'ctc_annual' => 1, 'currency' => 'INR', 'change_type' => 'hire', 'effective_from' => '2027-01-01']))->toThrow(RuntimeException::class, 'approved compensation change')
        ->and(fn () => $row->update(['ctc_annual' => 999999]))->toThrow(RuntimeException::class, 'approved compensation change')
        ->and(fn () => $row->delete())->toThrow(RuntimeException::class, 'never deleted')
        ->and((float) $row->refresh()->ctc_annual)->toBe(600000.0);

    // Static boundary: Payroll names neither the assignment model nor Compensation's writers.
    expect(compensationOwnershipFilesMatching('~\b(EmployeeSalaryAssignment|AssignmentWriter|CompensationChanges|SalaryStructure|SalaryStructureComponent)\b~', 'app/Domain/Payroll'))->toBe([])
        ->and(compensationOwnershipFilesMatching('~employee_salary_assignments~', 'app/Domain/Payroll'))->toBe([]);
});

it('3. makes Compensation the sole write authority: only the executor writes, only the change engine calls it', function () {
    expect(compensationOwnershipFilesMatching('~EmployeeSalaryAssignment::(query\(\)->)?(create|insert|upsert|forceCreate|updateOrCreate|firstOrCreate)\b|DB::table\([\'"]employee_salary_assignments~'))
        ->toBe(['app/Domain/Compensation/Services/AssignmentWriter.php'])
        ->and(compensationOwnershipFilesMatching('~\bAssignmentWriter\b~'))->toBe([
            'app/Domain/Compensation/Models/EmployeeSalaryAssignment.php',   // the guard asks whether it is writing
            'app/Domain/Compensation/Services/AssignmentWriter.php',
            'app/Domain/Compensation/Services/CompensationChanges.php',
        ])
        ->and(class_exists('App\\Domain\\Payroll\\Services\\Salaries'))->toBeFalse()
        ->and(class_exists('App\\Domain\\Payroll\\Models\\EmployeeSalaryAssignment'))->toBeFalse();
});

it('4. gives Employee 360 no way around the approval boundary: the tab only proposes', function () {
    $before = ($this->rows)()->count();
    Livewire::test(CompensationRelationManager::class, ['ownerRecord' => $this->employee, 'pageClass' => ViewEmployee::class])
        ->assertOk()->assertDontSee('Assign / revise salary')
        ->callTableAction('propose', data: ['change_type' => 'annual_increment', 'effective_from' => '2026-10-01', 'salary_structure_id' => $this->structure->id, 'ctc_annual' => 700000, 'currency' => 'INR', 'reason' => 'Increment', 'submit' => true])
        ->assertHasNoTableActionErrors();

    $change = CompensationChange::query()->where('employee_id', $this->employee->id)->where('proposed_by', $this->hr->id)->sole();
    expect($change->status)->toBe('submitted')->and((int) $change->proposed_by)->toBe($this->hr->id)
        ->and(($this->rows)()->count())->toBe($before)
        ->and($this->output->on($this->employee, '2026-10-15')->ctcAnnual)->toBe(600000.0);
});

it('5. never deletes future compensation when an earlier change is executed later (§11 example)', function () {
    // 01-Apr-2026 → 600,000 (setup); 01-Jan-2027 → 720,000 executed first; then 01-Jul-2026 → 660,000.
    $january = compensate($this->employee, 720000, '2027-01-01', [], 'annual_increment');
    $januarySnapshot = $january->only(['id', 'ctc_annual', 'effective_from', 'effective_to', 'status']);
    $july = compensate($this->employee, 660000, '2026-07-01', [], 'market_adjustment');

    $rows = ($this->rows)();
    expect($rows)->toHaveCount(3)
        ->and($rows->map(fn ($r) => [$r->effective_from->toDateString(), $r->effective_to?->toDateString(), (float) $r->ctc_annual, $r->status])->all())->toBe([
            ['2026-04-01', '2026-06-30', 600000.0, 'active'],
            ['2026-07-01', '2026-12-31', 660000.0, 'active'],
            ['2027-01-01', null, 720000.0, 'active'],
        ])
        ->and($january->refresh()->only(['id', 'ctc_annual', 'effective_from', 'effective_to', 'status']))->toEqual($januarySnapshot)
        ->and($july->compensation_change_id)->not->toBeNull()
        // Past, current (today is 2026-09-21) and future dates each resolve to the compensation then in force.
        ->and($this->output->on($this->employee, '2026-05-01')->ctcAnnual)->toBe(600000.0)
        ->and($this->output->on($this->employee, now())->ctcAnnual)->toBe(660000.0)
        ->and($this->output->on($this->employee, '2027-02-01')->ctcAnnual)->toBe(720000.0);

    // A new proposal dated after the future row changes nothing until it is approved and executed.
    $later = ($this->propose)(['effective_from' => '2027-06-01', 'ctc_annual' => 800000]);
    $this->changes->submit($later, $this->actors['proposer']);
    expect(($this->rows)()->map(fn ($r) => [$r->id, $r->effective_to?->toDateString(), $r->status])->all())->toBe($rows->map(fn ($r) => [$r->id, $r->effective_to?->toDateString(), $r->status])->all());
});

it('6. keeps history reconstructable: every date resolves to the compensation then in force, corrections included', function () {
    compensate($this->employee, 660000, '2026-07-01', [], 'annual_increment');
    $wrong = compensate($this->employee, 700000, '2026-09-01', [], 'market_adjustment');
    $fixed = compensate($this->employee, 690000, '2026-09-01', [], 'correction', 'Typo in the market adjustment');

    expect($this->output->on($this->employee, '2026-06-30')->ctcAnnual)->toBe(600000.0)
        ->and($this->output->on($this->employee, '2026-07-01')->ctcAnnual)->toBe(660000.0)
        ->and($this->output->on($this->employee, '2026-08-31')->ctcAnnual)->toBe(660000.0)
        ->and($this->output->on($this->employee, '2026-09-01')->ctcAnnual)->toBe(690000.0)
        ->and($this->output->on($this->employee, '2026-03-31'))->toBeNull()
        ->and($this->output->history($this->employee)->map(fn ($s) => $s->ctcAnnual)->all())->toBe([600000.0, 660000.0, 690000.0])
        ->and($wrong->refresh()->status)->toBe('superseded')->and((int) $wrong->superseded_by_id)->toBe($fixed->id)
        ->and(($this->rows)())->toHaveCount(4);   // the superseded row stays as history
});

it('7. rejects overlapping effective dates; boundaries are inclusive and contiguous (§12)', function () {
    compensate($this->employee, 660000, '2026-07-01', [], 'annual_increment');
    compensate($this->employee, 690000, '2026-10-01', [], 'market_adjustment');

    // Same start date without a correction: refused at execution, nothing written.
    $clash = ($this->approved)(($this->propose)(['effective_from' => '2026-07-01', 'ctc_annual' => 650000]));
    expect(fn () => $this->changes->schedule($clash, $this->actors['executor']))->toThrow(RuntimeException::class, 'already starts on 2026-07-01')
        ->and($clash->refresh()->status)->toBe('approved');

    $active = ($this->rows)()->where('status', 'active')->values();
    expect($active->map(fn ($r) => [$r->effective_from->toDateString(), $r->effective_to?->toDateString()])->all())->toBe([['2026-04-01', '2026-06-30'], ['2026-07-01', '2026-09-30'], ['2026-10-01', null]])
        ->and($this->output->on($this->employee, '2026-06-30')->ctcAnnual)->toBe(600000.0)
        ->and($this->output->on($this->employee, '2026-07-01')->ctcAnnual)->toBe(660000.0)
        ->and($this->output->on($this->employee, '2026-09-30')->ctcAnnual)->toBe(660000.0)
        ->and($this->output->on($this->employee, '2026-10-01')->ctcAnnual)->toBe(690000.0);
    foreach ($active as $i => $row) {
        if ($next = $active[$i + 1] ?? null) {
            expect($row->effective_to->addDay()->toDateString())->toBe($next->effective_from->toDateString());
        }
    }

    // The database refuses a second active row for the same employee and start date.
    expect(fn () => DB::table('employee_salary_assignments')->insert(['tenant_id' => $this->tenant->id, 'employee_id' => $this->employee->id, 'salary_structure_id' => $this->structure->id, 'ctc_annual' => 1, 'currency' => 'INR', 'change_type' => 'hire', 'status' => 'active', 'active_key' => 1, 'effective_from' => (new EmployeeSalaryAssignment)->fromDateTime(Carbon::parse('2026-07-01'))]))
        ->toThrow(QueryException::class);
});

it('8. never lets one person hold two duties on a change, or act on their own compensation', function () {
    $all = ['compensation.propose', 'compensation.review', 'compensation.approve', 'compensation.execute'];
    $p = tenantUser($this->tenant, $all);
    $r = tenantUser($this->tenant, $all);
    $a = tenantUser($this->tenant, $all);
    $change = $this->changes->propose($this->employee, ['change_type' => 'annual_increment', 'effective_from' => '2026-11-01', 'salary_structure_id' => $this->structure->id, 'ctc_annual' => 640000, 'reason' => 'x'], $p);

    expect(fn () => $this->changes->submit($change, $r))->toThrow(RuntimeException::class, 'Only the proposer submits');
    $this->changes->submit($change, $p);
    expect(fn () => $this->changes->review($change, $p))->toThrow(RuntimeException::class, 'proposer of a compensation change cannot review');
    $this->changes->review($change, $r);
    expect(fn () => $this->changes->approve($change, $p))->toThrow(RuntimeException::class, 'proposer of a compensation change cannot approve')
        ->and(fn () => $this->changes->approve($change, $r))->toThrow(RuntimeException::class, 'reviewer of a compensation change cannot approve');
    $this->changes->approve($change, $a);
    foreach (['proposer' => $p, 'reviewer' => $r, 'approver' => $a] as $duty => $user) {
        expect(fn () => $this->changes->schedule($change, $user))->toThrow(RuntimeException::class, "{$duty} of a compensation change cannot execute");
    }
    expect($change->refresh()->status)->toBe('approved')->and(($this->rows)())->toHaveCount(1);

    // Nobody acts on their own pay, whatever their permissions.
    $self = employeeWithUser(null, $all);
    expect(fn () => $this->changes->propose($self, ['change_type' => 'annual_increment', 'effective_from' => '2026-11-01', 'salary_structure_id' => $this->structure->id, 'ctc_annual' => 1, 'reason' => 'x'], User::query()->findOrFail($self->user_id)))
        ->toThrow(RuntimeException::class, 'their own compensation');
});

it('9. gives Letters the compensation in force on the letter date', function () {
    compensate($this->employee, 720000, '2026-07-01', [], 'annual_increment');
    compensate($this->employee, 900000, '2026-12-01', [], 'promotion');   // scheduled, not yet in force

    expect(app(Letters::class)->context($this->employee->refresh())['employee']['ctc_annual'])->toBe('720,000.00')
        ->and(app(Letters::class)->context($this->employee)['employee']['ctc_monthly'])->toBe('60,000.00');
});

it('10. gives Exit / F&F the compensation in force on the last working day, not a later raise', function () {
    compensate($this->employee, 900000, '2026-11-01', [], 'promotion');
    EmployeeBankAccount::create(['employee_id' => $this->employee->id, 'account_holder_name' => 'X', 'bank_name' => 'HDFC', 'account_number' => '1234567890', 'ifsc' => 'HDFC0000001', 'is_primary' => true]);
    $exits = app(Exits::class);
    $case = $exits->initiate($this->employee, 'resignation', 'Moving on', '2026-09-21', '2026-10-01', 10, $this->hr);
    $exits->startClearance($case);
    foreach ($case->clearances()->get() as $stage) {
        $exits->markNotApplicable($stage, $this->hr, 'n/a');
    }
    $settlement = app(FinalSettlements::class)->calculate($case->refresh(), $this->hr);
    $basic = $settlement->lines->firstWhere('code', 'SAL_BASIC');

    expect($basic)->not->toBeNull()->and((float) $basic->basis['full_month'])->toBe(20000.0)   // 600,000 / 12 × 40%, not 900,000
        ->and($settlement->inputs['salary_period'])->toBe('Oct 2026');
});

it('11. keeps compensation tenant-isolated', function () {
    $tenantB = provisionTenant('B');
    $row = ($this->rows)()->sole();
    actAsTenant($tenantB);
    $userB = tenantUser($tenantB, ['*']);

    expect(EmployeeSalaryAssignment::query()->whereKey($row->id)->exists())->toBeFalse()
        ->and(CompensationChange::query()->count())->toBe(0)
        ->and(fn () => app(CompensationChanges::class)->propose($this->employee, ['change_type' => 'annual_increment', 'effective_from' => '2026-11-01', 'salary_structure_id' => $this->structure->id, 'ctc_annual' => 1, 'reason' => 'x'], $userB))->toThrow(RuntimeException::class);
    expect($this->output->history($this->employee->id))->toHaveCount(0);
    actAsTenant($this->tenant);
});

it('12. enforces organisation scope on reads and on every step', function () {
    $other = Company::factory()->create();
    $scoped = tenantUser($this->tenant, ['compensation.propose', 'compensation.view', 'compensation.review', 'compensation.approve', 'compensation.execute']);
    app(AccessScopes::class)->assign($scoped, ['company' => [$other->id]], 'Other company only');
    $change = ($this->propose)();
    $this->changes->submit($change, $this->actors['proposer']);

    expect(fn () => $this->changes->propose($this->employee, ['change_type' => 'annual_increment', 'effective_from' => '2026-11-01', 'salary_structure_id' => $this->structure->id, 'ctc_annual' => 1, 'reason' => 'x'], $scoped))->toThrow(RuntimeException::class, 'outside your organisation scope')
        ->and(fn () => $this->changes->review($change, $scoped))->toThrow(RuntimeException::class, 'outside your organisation scope')
        ->and(app(CompensationAccess::class)->level($scoped, $this->employee))->toBeNull();

    $this->actingAs($scoped);
    expect(EmployeeSalaryAssignment::query()->where('employee_id', $this->employee->id)->exists())->toBeFalse()
        ->and(CompensationRelationManager::canViewForRecord($this->employee, ViewEmployee::class))->toBeFalse();
});

it('13. leaves payroll and statutory configuration untouched by any compensation step', function () {
    $fingerprint = fn () => [
        DB::table('salary_components')->orderBy('id')->get()->map(fn ($r) => json_encode($r))->implode('|'),
        app(TenantContext::class)->bypass(fn () => DB::table('compliance_rules')->orderBy('id')->get()->map(fn ($r) => json_encode($r))->implode('|')),
        DB::table('company_statutory_profiles')->orderBy('id')->get()->map(fn ($r) => json_encode($r))->implode('|'),
        DB::table('payroll_runs')->count(), DB::table('payroll_entries')->count(), DB::table('payroll_periods')->count(),
    ];
    $before = $fingerprint();
    compensate($this->employee, 660000, '2026-10-01', [], 'annual_increment');
    $c = ($this->approved)(($this->propose)(['effective_from' => '2026-12-01', 'ctc_annual' => 700000]));
    $this->changes->schedule($c, $this->actors['executor']);
    $this->changes->cancel($c->refresh(), $this->actors['approver'], 'Budget cut');
    $this->changes->effectDue('2026-10-01');

    expect($fingerprint())->toBe($before);
});

it('14. refuses to finalize payroll calculated on compensation that changed afterwards, and accepts it after recalculation', function () {
    EmployeeBankAccount::create(['employee_id' => $this->employee->id, 'account_holder_name' => 'X', 'bank_name' => 'HDFC', 'account_number' => '1234567890', 'ifsc' => 'HDFC0000001', 'is_primary' => true]);
    $approver = tenantUser($this->tenant, ['payroll.*', 'employee.*']);
    $runs = app(PayrollRuns::class);
    $run = $runs->approve($runs->validate($runs->calculate($runs->open($this->company, 2026, 9))), $approver);

    compensate($this->employee, 660000, '2026-09-16', [], 'market_adjustment');   // inside the calculated, unfinalized period
    expect(fn () => $runs->finalize($run->refresh(), $approver))->toThrow(RuntimeException::class, 'Compensation changed for 1 employee(s)');

    $run = $runs->reopen($run->refresh(), 'Compensation changed', $approver);
    $run = $runs->finalize($runs->approve($runs->validate($runs->calculate($run)), $approver), $approver);
    $entry = PayrollEntry::query()->where('payroll_run_id', $run->id)->sole();
    expect($run->status)->toBe('finalized')->and($entry->inputs['segments'])->toHaveCount(2)
        // Payroll is now closed through September: a compensation change dated inside it is refused.
        ->and(fn () => compensate($this->employee, 700000, '2026-09-20', [], 'correction'))->toThrow(RuntimeException::class, 'closed payroll period');
});

it('cancels a scheduled change before it takes effect without deleting anything, and makes due changes effective once', function () {
    $later = compensate($this->employee, 720000, '2027-01-01', [], 'annual_increment');
    $c = ($this->approved)(($this->propose)(['effective_from' => '2026-11-01', 'ctc_annual' => 660000]));
    $c = $this->changes->schedule($c, $this->actors['executor']);
    expect($c->status)->toBe('scheduled')->and($this->output->on($this->employee, '2026-11-15')->ctcAnnual)->toBe(660000.0);

    $this->changes->cancel($c, $this->actors['approver'], 'Budget cut');
    expect($c->refresh()->status)->toBe('cancelled')
        ->and(($this->rows)()->firstWhere('compensation_change_id', $c->id)->status)->toBe('cancelled')
        ->and($this->output->on($this->employee, '2026-11-15')->ctcAnnual)->toBe(600000.0)
        ->and(($this->rows)()->first()->effective_to->toDateString())->toBe('2026-12-31')
        ->and($later->refresh()->status)->toBe('active');

    // The effective-date processor makes the January change effective once, on its date.
    $january = CompensationChange::query()->whereKey($later->compensation_change_id)->sole();
    expect($january->status)->toBe('scheduled')
        ->and($this->changes->effectDue('2026-12-31'))->toBe(0)
        ->and($this->changes->effectDue('2027-01-01'))->toBe(1)
        ->and($this->changes->effectDue('2027-01-02'))->toBe(0)
        ->and($january->refresh()->status)->toBe('effective')->and($january->effected_by)->toBeNull();
});
