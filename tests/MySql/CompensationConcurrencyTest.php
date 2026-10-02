<?php

use App\Domain\Audit\Models\AuditEvent;
use App\Domain\Compensation\Models\CompensationChange;
use App\Domain\Compensation\Models\EmployeeSalaryAssignment;
use App\Domain\Compensation\Models\SalaryStructure;
use App\Domain\Compensation\Services\CompensationChanges;
use App\Domain\Employment\Models\EmployeeBankAccount;
use App\Domain\Identity\Scopes\AccessScope;
use App\Domain\Payroll\Models\PayrollRun;
use App\Domain\Payroll\Services\PayrollRuns;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/../Feature/Workflow/WorkflowTestHelpers.php';
require_once __DIR__.'/../Feature/Performance/PerformanceTestHelpers.php';
require_once __DIR__.'/../Feature/Payroll/PayrollTestHelpers.php';
require_once __DIR__.'/ConcurrencyHelpers.php';

/*
 | Phase 11 §34: real concurrency on MySQL for compensation. Same harness and opt-in as the Phase 8–10
 | suites (PEOPLEOS_MYSQL_CONCURRENCY_DB, name containing "concurrency"); SQLite runs skip these and
 | claim nothing about MySQL locking.
 */

beforeEach(function () {
    $database = (string) env('PEOPLEOS_MYSQL_CONCURRENCY_DB', '');
    if ($database === '' || ! str_contains($database, 'concurrency') || ! function_exists('pcntl_fork')) {
        $this->markTestSkipped('Set PEOPLEOS_MYSQL_CONCURRENCY_DB to a disposable MySQL database (name containing "concurrency") and enable pcntl.');
    }
    config(['database.connections.concurrency' => array_merge(config('database.connections.mysql'), ['database' => $database]), 'database.default' => 'concurrency', 'queue.default' => 'sync']);
    DB::purge('concurrency');
    if (! ($GLOBALS['peopleos_concurrency_migrated'] ?? false)) {
        Artisan::call('migrate:fresh', ['--database' => 'concurrency', '--force' => true]);
        $GLOBALS['peopleos_concurrency_migrated'] = true;
    }
    $this->travelTo('2026-09-21 09:00:00');
    if (! ($GLOBALS['peopleos_concurrency_rules'] ?? false)) {
        syncComplianceRules();
        $GLOBALS['peopleos_concurrency_rules'] = true;
    }
    $this->tenant = provisionTenant('Race '.uniqid());
    actAsTenant($this->tenant);
    $this->admin = tenantUser($this->tenant, ['*']);
    $this->actingAs($this->admin);
    $this->company = payrollCompany();
    $this->changes = app(CompensationChanges::class);
    $this->actors = compensationActors();
    $this->structure = SalaryStructure::query()->where('code', 'STANDARD')->firstOrFail();
    $this->employee = salariedEmployee(600000, ['task.view'], '2026-04-01');
    // A change taken to "approved" by the standard actors (proposer, reviewer, approver).
    $this->approved = function (string $from, float $ctc, string $type = 'annual_increment'): CompensationChange {
        $c = $this->changes->propose($this->employee, ['change_type' => $type, 'effective_from' => $from, 'salary_structure_id' => $this->structure->id, 'ctc_annual' => $ctc, 'reason' => 'Race'], $this->actors['proposer']);
        $this->changes->submit($c, $this->actors['proposer']);
        $this->changes->review($c, $this->actors['reviewer']);

        return $this->changes->approve($c, $this->actors['approver']);
    };
    $this->fresh = fn (int $id) => CompensationChange::query()->withoutGlobalScope(AccessScope::class)->findOrFail($id);
    $this->activeRows = fn () => EmployeeSalaryAssignment::query()->withoutGlobalScope(AccessScope::class)->where('employee_id', $this->employee->id)->where('status', 'active')->orderBy('effective_from')->get();
});

/** Compensation writes slowed down so a missing lock would let both writers through. */
function compensationSlowEvents(): array
{
    return ['eloquent.updating: '.CompensationChange::class, 'eloquent.creating: '.EmployeeSalaryAssignment::class, 'eloquent.updating: '.EmployeeSalaryAssignment::class, 'eloquent.updating: '.PayrollRun::class];
}

/** Active rows never overlap and leave no gap between consecutive rows. */
function assertContiguous($rows): void
{
    foreach ($rows->values() as $i => $row) {
        $next = $rows->values()[$i + 1] ?? null;
        if ($next) {
            expect($row->effective_to?->toDateString())->toBe($next->effective_from->copy()->subDay()->toDateString());
        } else {
            expect($row->effective_to)->toBeNull();
        }
    }
}

it('1. lets exactly one of two approvers approve the same compensation change', function () {
    $c = $this->changes->propose($this->employee, ['change_type' => 'annual_increment', 'effective_from' => '2026-10-01', 'salary_structure_id' => $this->structure->id, 'ctc_annual' => 660000, 'reason' => 'Race'], $this->actors['proposer']);
    $this->changes->submit($c, $this->actors['proposer']);
    $this->changes->review($c, $this->actors['reviewer']);
    [$a1, $a2] = [tenantUser($this->tenant, ['compensation.approve']), tenantUser($this->tenant, ['compensation.approve'])];

    $results = race([fn () => $this->changes->approve(($this->fresh)($c->id), $a1), fn () => $this->changes->approve(($this->fresh)($c->id), $a2)], slow: compensationSlowEvents());

    $winner = ($this->fresh)($c->id);
    expect(collect($results)->filter(fn ($r) => $r === 'ok'))->toHaveCount(1)
        ->and(collect($results)->first(fn ($r) => $r !== 'ok'))->toContain('that step is not available')
        ->and($winner->status)->toBe('approved')->and($winner->lock_version)->toBe(3)
        ->and(AuditEvent::query()->where('entity_type', CompensationChange::class)->where('entity_id', (string) $c->id)->where('action', 'APPROVED')->count())->toBe(1);
});

it('2. never lets two executions create overlapping compensation for one employee', function () {
    $july = ($this->approved)('2026-07-01', 650000);
    $august = ($this->approved)('2026-08-01', 700000, 'market_adjustment');
    [$e1, $e2] = [tenantUser($this->tenant, ['compensation.execute']), tenantUser($this->tenant, ['compensation.execute'])];

    $results = race([fn () => $this->changes->schedule(($this->fresh)($july->id), $e1), fn () => $this->changes->schedule(($this->fresh)($august->id), $e2)], slow: compensationSlowEvents());

    $rows = ($this->activeRows)();
    expect(collect($results)->filter(fn ($r) => $r === 'ok')->count())->toBeGreaterThanOrEqual(1);
    foreach ($results as $r) {
        expect($r === 'ok' || str_contains($r, 'has changed since this change was approved'))->toBeTrue();
    }
    assertContiguous($rows);
    expect($rows->count())->toBe(1 + collect($results)->filter(fn ($r) => $r === 'ok')->count());
});

it('5. resolves a cancellation racing the effective-date processor to exactly one outcome', function () {
    $c = ($this->approved)('2026-09-22', 660000);
    $c = $this->changes->schedule($c, $this->actors['executor']);
    $this->travelTo('2026-09-22 00:30:00');   // the change is due; the processor and an approver act at once
    $canceller = tenantUser($this->tenant, ['compensation.approve']);

    $results = race([fn () => $this->changes->cancel(($this->fresh)($c->id), $canceller, 'Withdrawn'), fn () => $this->changes->effectDue('2026-09-22')], slow: compensationSlowEvents());

    $final = ($this->fresh)($c->id);
    $row = EmployeeSalaryAssignment::query()->withoutGlobalScope(AccessScope::class)->findOrFail($c->employee_salary_assignment_id);
    $decisions = AuditEvent::query()->where('entity_type', CompensationChange::class)->where('entity_id', (string) $c->id)->whereIn('action', ['EFFECTED', 'CANCELLED'])->count();
    expect($final->status)->toBeIn(['effective', 'cancelled'])
        ->and($row->status)->toBe($final->status === 'effective' ? 'active' : 'cancelled')
        ->and($decisions)->toBe(1);
    if ($final->status === 'effective') {
        expect(collect($results)->first(fn ($r) => $r !== 'ok'))->toContain('that step is not available');
    }
    assertContiguous(($this->activeRows)());
});

it('6. never finalizes payroll on compensation that a concurrent execution changes inside the period', function () {
    EmployeeBankAccount::create(['employee_id' => $this->employee->id, 'account_holder_name' => 'X', 'bank_name' => 'HDFC', 'account_number' => '1234567890', 'ifsc' => 'HDFC0000001', 'is_primary' => true]);
    $payrollApprover = tenantUser($this->tenant, ['payroll.*', 'employee.*']);
    $runs = app(PayrollRuns::class);
    $run = $runs->approve($runs->validate($runs->calculate($runs->open($this->company, 2026, 9))), $payrollApprover);
    $change = ($this->approved)('2026-09-16', 660000, 'market_adjustment');

    $results = race([
        fn () => $this->changes->schedule(($this->fresh)($change->id), $this->actors['executor']),
        fn () => app(PayrollRuns::class)->finalize(PayrollRun::query()->findOrFail($run->id), $payrollApprover),
    ], slow: compensationSlowEvents());

    $run = PayrollRun::query()->findOrFail($run->id);
    $written = EmployeeSalaryAssignment::query()->withoutGlobalScope(AccessScope::class)->where('compensation_change_id', $change->id)->exists();
    expect(collect($results)->filter(fn ($r) => $r === 'ok'))->toHaveCount(1)
        // The invariant: a finalized period never contains compensation written after its calculation.
        ->and($run->status === 'finalized' && $written)->toBeFalse();
    $failure = collect($results)->first(fn ($r) => $r !== 'ok');
    expect($failure)->toMatch('/Compensation changed for 1 employee\(s\)|closed payroll period/');
});

it('keeps every race tenant\'s audit chain intact', function () {
    ($this->approved)('2026-10-01', 660000);
    expect(Artisan::call('peopleos:audit:verify'))->toBe(0);
});
