<?php

use App\Domain\Payroll\Models\PayrollEntry;
use App\Domain\Payroll\Models\PayrollEntryLine;
use App\Domain\Payroll\Models\PayrollRun;
use App\Domain\Payroll\Services\PayrollCalculator;
use App\Domain\Payroll\Services\PayrollRuns;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/../Workflow/WorkflowTestHelpers.php';
require_once __DIR__.'/PayrollTestHelpers.php';

/* Phase 6 §34 / Phase 7: older engine versions (2.0, 2.1) → current engine (payroll-2.2). */

beforeEach(function () {
    $this->travelTo('2026-09-21 09:00:00');
    syncComplianceRules();
    $this->tenant = provisionTenant();
    actAsTenant($this->tenant);
    $this->preparer = tenantUser($this->tenant, ['payroll.*', 'employee.*']);
    $this->approver = tenantUser($this->tenant, ['payroll.*', 'employee.*']);
    $this->actingAs($this->preparer);
    $this->company = payrollCompany();
    salariedEmployee(600000);
    $this->runs = app(PayrollRuns::class);
});

it('calculates on the current engine (payroll-2.2) and records the establishment context', function () {
    $run = $this->runs->calculate($this->runs->open($this->company, 2026, 9, $this->preparer), $this->preparer);
    $entry = PayrollEntry::query()->where('payroll_run_id', $run->id)->sole();

    expect(PayrollCalculator::VERSION)->toBe('payroll-2.2')
        ->and($run->calculation_version)->toBe('payroll-2.2')
        ->and($entry->inputs['statutory_context'])->toHaveKeys(['establishment_id', 'establishment_source', 'profile_source', 'pt_state_source'])
        ->and($entry->establishment_id)->not->toBeNull();
});

it('refuses to finalize an approved run from an older engine, reopens it to draft and finalizes after recalculation on the current engine', function () {
    $run = $this->runs->approve($this->runs->validate($this->runs->calculate($this->runs->open($this->company, 2026, 9, $this->preparer), $this->preparer)), $this->approver, 'ok');
    DB::table('payroll_runs')->where('id', $run->id)->update(['calculation_version' => 'payroll-2.0']);

    expect(fn () => $this->runs->finalize($run->refresh(), $this->approver))->toThrow(RuntimeException::class, 'recalculate with payroll-2.2');
    expect(fn () => $this->runs->calculate($run->refresh(), $this->preparer))->toThrow(RuntimeException::class, 'Reopen it first');
    expect(fn () => $this->runs->reopen($run->refresh(), ' ', $this->approver))->toThrow(RuntimeException::class, 'reason');

    $run = $this->runs->reopen($run->refresh(), 'Engine moved to payroll-2.2', $this->approver);
    expect($run->status)->toBe('draft')->and($run->approved_by)->toBeNull();

    $run = $this->runs->calculate($run, $this->preparer);
    expect($run->calculation_version)->toBe('payroll-2.2');
    expect($this->runs->finalize($this->runs->approve($this->runs->validate($run), $this->approver, 'ok'), $this->approver)->status)->toBe('finalized');
});

it('keeps a finalized historical run immutable whatever its engine version', function () {
    $run = $this->runs->finalize($this->runs->approve($this->runs->validate($this->runs->calculate($this->runs->open($this->company, 2026, 9, $this->preparer), $this->preparer)), $this->approver, 'ok'), $this->approver);
    DB::table('payroll_runs')->where('id', $run->id)->update(['calculation_version' => 'payroll-2.0']);
    $run = PayrollRun::query()->find($run->id);

    expect(fn () => $this->runs->calculate($run, $this->preparer))->toThrow(RuntimeException::class, 'Reopen it first');
    expect(fn () => PayrollEntry::query()->where('payroll_run_id', $run->id)->first()->update(['net_pay' => 1]))->toThrow(RuntimeException::class);
    expect(fn () => PayrollEntryLine::query()->whereIn('payroll_entry_id', PayrollEntry::query()->where('payroll_run_id', $run->id)->pluck('id'))->first()->update(['amount' => 1]))->toThrow(RuntimeException::class);
    expect($run->refresh()->calculation_version)->toBe('payroll-2.0')->and($run->status)->toBe('finalized');
});
