<?php

use App\Domain\Compliance\Models\ParallelPayrollLine;
use App\Domain\Compliance\Services\ParallelPayroll;
use App\Domain\Compliance\Services\Returns\EpfReturns;
use App\Domain\Payroll\Models\PayrollEntry;
use App\Domain\Payroll\Services\PayrollRuns;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/ComplianceTestHelpers.php';

/* Phase 6.5: a controlled parallel cycle reconciles line by line, never by totals alone. */

beforeEach(function () {
    Storage::fake('local');
    $this->travelTo('2026-10-05 09:00:00');
    syncComplianceRules();
    $this->tenant = provisionTenant();
    actAsTenant($this->tenant);
    $this->actingAs(tenantUser($this->tenant, ['*']));
    ['company' => $this->company, 'establishment' => $this->establishment] = complianceCompany();
    $this->anita = statutoryEmployee(600000, $this->establishment, '100200300400');
    $this->ravi = statutoryEmployee(480000, $this->establishment, '100200300401');
    $this->run = finalizedPayroll($this->company, 2026, 9, tenantUser($this->tenant, ['payroll.*', 'employee.*']), tenantUser($this->tenant, ['payroll.*', 'employee.*']));
    ['generator' => $generator] = complianceUsers($this->tenant);
    $this->epf = app(EpfReturns::class)->generate($this->establishment, 2026, 9, $generator);
    $this->importer = tenantUser($this->tenant, ['compliance.parallel.manage']);
    $this->reviewer = tenantUser($this->tenant, ['compliance.parallel.manage']);
    $this->parallel = app(ParallelPayroll::class);

    // Reference values equal to PeopleOS unless adjusted.
    $this->reference = function (array $adjust = [], array $drop = [], bool $withReturn = true) {
        $rows = ['level,employee_code,scope,component,amount'];
        foreach (PayrollEntry::query()->with(['lines', 'employee'])->where('payroll_run_id', $this->run->id)->get() as $entry) {
            $values = ['GROSS' => (float) $entry->gross, 'NET' => (float) $entry->net_pay] + $entry->lines->groupBy('code')->map(fn ($l) => (float) $l->sum('amount'))->all();
            foreach ($values as $component => $amount) {
                $key = $entry->employee->employee_code.':'.$component;
                if (! in_array($key, $drop, true)) {
                    $rows[] = "employee,{$entry->employee->employee_code},,{$component},".($amount + ($adjust[$key] ?? 0));
                }
            }
        }
        if ($withReturn) {
            $rows[] = 'return,,EPF:MAIN,ee_share,'.$this->epf->totals['ee_share'];
        }

        return implode("\n", $rows)."\n";
    };
});

it('imports reference values only for a finalized run, with the expected file shape', function () {
    $draft = app(PayrollRuns::class)->open($this->company, 2026, 10, tenantUser($this->tenant, ['payroll.*']));
    expect(fn () => $this->parallel->import($draft, $this->importer, ($this->reference)(), 'Legacy payroll'))->toThrow(RuntimeException::class, 'finalized');
    expect(fn () => $this->parallel->import($this->run, tenantUser($this->tenant, ['payroll.*']), ($this->reference)(), 'Legacy payroll'))->toThrow(RuntimeException::class, 'compliance.parallel.manage');
    expect(fn () => $this->parallel->import($this->run, $this->importer, "a,b\n1,2\n", 'Legacy payroll'))->toThrow(RuntimeException::class, 'header');

    $first = $this->parallel->import($this->run, $this->importer, ($this->reference)(), 'Legacy payroll', 'September reference');
    $second = $this->parallel->import($this->run, $this->importer, ($this->reference)(), 'Legacy payroll');
    expect($first->fresh()->status)->toBe('abandoned')->and($second->status)->toBe('imported')->and($second->reference_file_sha256)->toHaveLength(64);
});

it('reconciles when every employee, component and return total matches', function () {
    $parallel = $this->parallel->compare($this->parallel->import($this->run, $this->importer, ($this->reference)(), 'Legacy payroll'), $this->importer);

    expect($parallel->summary['difference'] + $parallel->summary['missing_reference'] + $parallel->summary['missing_peopleos'])->toBe(0)
        ->and($parallel->summary['employees'])->toBe(2)
        ->and($parallel->summary['return_totals'])->toBe(1);
    expect(fn () => $this->parallel->reconcile($parallel, $this->importer))->toThrow(RuntimeException::class, 'imported the reference');

    $parallel = $this->parallel->reconcile($parallel, $this->reviewer);
    expect($parallel->status)->toBe('reconciled');
    expect(fn () => ParallelPayrollLine::query()->first()->update(['reason' => 'x']))->toThrow(RuntimeException::class, 'immutable');
});

it('does not accept matching totals when individual employees differ', function () {
    $a = $this->anita->employee_code;
    $r = $this->ravi->employee_code;
    // +100 for one, -100 for the other: gross totals still match.
    $parallel = $this->parallel->compare($this->parallel->import($this->run, $this->importer, ($this->reference)(["{$a}:GROSS" => 100, "{$r}:GROSS" => -100]), 'Legacy payroll'), $this->importer);

    $differences = ParallelPayrollLine::query()->where('parallel_payroll_run_id', $parallel->id)->where('status', 'difference')->get();
    expect($differences)->toHaveCount(2)
        ->and((float) $differences->sum('difference'))->toBe(0.0);
    expect(fn () => $this->parallel->reconcile($parallel, $this->reviewer))->toThrow(RuntimeException::class, '2 difference(s)');

    expect(fn () => $this->parallel->review($differences->first(), $this->importer, 'x', 'y'))->toThrow(RuntimeException::class, 'imported the reference');
    expect(fn () => $this->parallel->review($differences->first(), $this->reviewer, 'Reference includes arrears', ''))->toThrow(RuntimeException::class, 'reason and its resolution');
    foreach ($differences as $line) {
        $this->parallel->review($line, $this->reviewer, 'Legacy system moved 100 between employees in error', 'Legacy corrected; PeopleOS value confirmed');
    }
    expect($this->parallel->reconcile($parallel->fresh(), $this->reviewer)->status)->toBe('reconciled');
});

it('reports values missing on either side and requires a statutory return total', function () {
    $a = $this->anita->employee_code;
    $csv = ($this->reference)([], ["{$a}:TDS"], false)."employee,{$a},,BONUS,500\n";
    $parallel = $this->parallel->compare($this->parallel->import($this->run, $this->importer, $csv, 'Legacy payroll'), $this->importer);

    expect(ParallelPayrollLine::query()->where('parallel_payroll_run_id', $parallel->id)->where('status', 'missing_reference')->pluck('component')->all())->toContain('TDS')
        ->and(ParallelPayrollLine::query()->where('parallel_payroll_run_id', $parallel->id)->where('status', 'missing_peopleos')->value('component'))->toBe('BONUS');

    ParallelPayrollLine::query()->where('parallel_payroll_run_id', $parallel->id)->whereIn('status', ParallelPayrollLine::OPEN)->get()
        ->each(fn ($line) => $this->parallel->review($line, $this->reviewer, 'Explained', 'Resolved'));
    expect(ParallelPayrollLine::query()->where('parallel_payroll_run_id', $parallel->id)->where('level', 'return')->pluck('status')->unique()->all())->toBe(['not_compared']);
    expect(fn () => $this->parallel->reconcile($parallel->fresh(), $this->reviewer))->toThrow(RuntimeException::class, 'statutory return total');
});
