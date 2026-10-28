<?php

use App\Domain\Attendance\Models\AttendanceRecord;
use App\Domain\Audit\Models\AuditEvent;
use App\Domain\Payroll\Jobs\CalculatePayrollRun;
use App\Domain\Payroll\Models\PayrollEntry;
use App\Domain\Payroll\Models\PayrollEntryLine;
use App\Domain\Payroll\Models\PayrollPeriod;
use App\Domain\Payroll\Models\PayrollRun;
use App\Domain\Payroll\Models\Payslip;
use App\Domain\Payroll\Services\PayrollRuns;
use App\Filament\Resources\PayrollRuns\Pages\ViewPayrollRun;
use Livewire\Livewire;

require_once __DIR__.'/../Workflow/WorkflowTestHelpers.php';
require_once __DIR__.'/PayrollTestHelpers.php';

// Finalized payroll is history: never reopened, recalculated, changed or deleted, through the finalize permission or any
// other path, until decision C12-D1 says how a wrong finalized month is settled. Only an approved run that is not
// finalized yet returns to draft, decided on the locked row, and it then needs an independent approval again.
beforeEach(function () {
    $this->travelTo('2026-09-21 09:00:00');
    syncComplianceRules();
    $this->tenant = provisionTenant();
    actAsTenant($this->tenant);
    $this->preparer = tenantUser($this->tenant, ['payroll.*', 'employee.*']);
    $this->approver = tenantUser($this->tenant, ['payroll.*', 'employee.*']);
    $this->actingAs($this->preparer);
    $this->company = payrollCompany();
    $this->runs = app(PayrollRuns::class);
    $employee = salariedEmployee(600000);
    AttendanceRecord::create(['employee_id' => $employee->id, 'date' => '2026-09-01', 'status' => 'present', 'processed_at' => now()]);
    $this->approved = fn () => $this->runs->approve($this->runs->validate($this->runs->calculate($this->runs->open($this->company, 2026, 9, $this->preparer), $this->preparer)), $this->approver, 'ok');
    $this->finalized = fn () => $this->runs->finalize(($this->approved)(), $this->approver);
    // Everything finalized payroll consists of: the run, its entries and lines, payslips, attendance locks, the period.
    $this->history = fn (PayrollRun $run) => [
        PayrollRun::query()->whereKey($run->id)->first(['status', 'totals', 'rule_versions', 'calculation_version', 'approved_by', 'finalized_by', 'finalized_at'])->toArray(),
        PayrollEntry::query()->where('payroll_run_id', $run->id)->orderBy('id')->get(['id', 'employee_id', 'gross', 'net_pay', 'inputs'])->toArray(),
        PayrollEntryLine::query()->whereIn('payroll_entry_id', PayrollEntry::query()->where('payroll_run_id', $run->id)->select('id'))->orderBy('id')->get(['id', 'code', 'amount'])->toArray(),
        Payslip::query()->orderBy('id')->get(['id', 'number', 'snapshot'])->toArray(),
        AttendanceRecord::query()->orderBy('id')->get(['id', 'is_locked'])->toArray(),
        PayrollPeriod::query()->whereKey($run->payroll_period_id)->value('status'),
    ];
});

it('never reopens a finalized, unpaid month, whoever asks: everything it issued stays, and each attempt is audited', function () {
    $run = ($this->finalized)();
    $before = ($this->history)($run);
    expect($before[3])->toHaveCount(1)->and($before[4][0]['is_locked'])->toBeTrue()->and($before[5])->toBe('closed');

    foreach ([$this->approver, $this->preparer, tenantUser($this->tenant, ['*'])] as $actor) {
        expect(fn () => $this->runs->reopen($run, 'Missed an adjustment', $actor))->toThrow(RuntimeException::class, 'A finalized run cannot be reopened');
    }

    expect(($this->history)($run))->toBe($before)
        ->and(AuditEvent::query()->where('action', 'PAYROLL_REOPENED')->exists())->toBeFalse()
        ->and(AuditEvent::query()->where('module', 'payroll')->where('action', 'REJECTED')->orderBy('id')->get()->map(fn ($e) => [$e->metadata['attempt'], $e->metadata['status'], $e->reason])->all())
        ->toBe(array_fill(0, 3, ['reopen', 'finalized', 'Missed an adjustment']));

    // The screen offers no reopen for it, even to someone holding every payroll permission.
    $this->actingAs($this->approver);
    Livewire::test(ViewPayrollRun::class, ['record' => $run->id])->assertActionHidden('reopen');
});

it('never reopens a paid month either', function () {
    $run = $this->runs->markPaid(($this->finalized)(), '2026-09-30', $this->approver);
    $before = ($this->history)($run);

    expect(fn () => $this->runs->reopen($run, 'Paid twice', $this->approver))->toThrow(RuntimeException::class, 'A paid run cannot be reopened')
        ->and(($this->history)($run))->toBe($before);

    $this->actingAs($this->approver);
    Livewire::test(ViewPayrollRun::class, ['record' => $run->id])->assertActionHidden('reopen');
});

it('never recalculates finalized or paid payroll, from the service or the queued job', function () {
    $run = ($this->finalized)();
    $before = ($this->history)($run);

    expect(fn () => $this->runs->calculate($run, $this->preparer))->toThrow(RuntimeException::class, 'cannot be recalculated');
    (new CalculatePayrollRun($run->id, $this->preparer->id))->handle($this->runs);
    expect(($this->history)($run))->toBe($before)
        ->and(fn () => $this->runs->open($this->company, 2026, 9, $this->preparer))->toThrow(RuntimeException::class, 'stays as issued');

    $paid = $this->runs->markPaid($run->refresh(), '2026-09-30', $this->approver);
    expect(fn () => $this->runs->calculate($paid, $this->preparer))->toThrow(RuntimeException::class, 'cannot be recalculated');
});

it('refuses every direct change to finalized payroll: results, payslips, the run and the month', function () {
    $run = ($this->finalized)();
    $before = ($this->history)($run);
    $entry = PayrollEntry::query()->where('payroll_run_id', $run->id)->sole();
    $line = $entry->lines()->first();
    $payslip = Payslip::query()->sole();
    $period = PayrollPeriod::query()->find($run->payroll_period_id);

    expect(fn () => $entry->update(['net_pay' => 1]))->toThrow(RuntimeException::class, 'cannot be changed')
        ->and(fn () => $entry->delete())->toThrow(RuntimeException::class, 'cannot be changed')
        ->and(fn () => $line->update(['amount' => 1]))->toThrow(RuntimeException::class, 'cannot be changed')
        ->and(fn () => $line->delete())->toThrow(RuntimeException::class, 'cannot be changed')
        ->and(fn () => $payslip->delete())->toThrow(RuntimeException::class, 'kept as issued')
        ->and(fn () => $run->refresh()->update(['status' => 'draft', 'finalized_at' => null]))->toThrow(RuntimeException::class, 'cannot be reopened or changed')
        ->and(fn () => $run->refresh()->update(['totals' => ['net' => 0]]))->toThrow(RuntimeException::class, 'cannot be reopened or changed')
        ->and(fn () => $run->refresh()->delete())->toThrow(RuntimeException::class, 'cannot be deleted')
        ->and(fn () => $period->update(['status' => 'open']))->toThrow(RuntimeException::class, 'cannot be reopened');
    expect(($this->history)($run))->toBe($before);

    // What finalized payroll still takes: an employee opening their payslip, and being marked paid (then nothing).
    $payslip->update(['viewed_at' => now()]);
    expect($this->runs->markPaid($run->refresh(), '2026-09-30', $this->approver)->status)->toBe('paid')
        ->and(fn () => $run->refresh()->update(['paid_at' => now()]))->toThrow(RuntimeException::class, 'cannot be reopened or changed')
        ->and(fn () => $run->refresh()->update(['status' => 'finalized']))->toThrow(RuntimeException::class, 'cannot be reopened or changed');
});

it('decides a return to draft on the locked row, so a finalization that came first is never overwritten from a stale copy', function () {
    $run = ($this->approved)();
    $stale = PayrollRun::query()->find($run->id); // read while approved, e.g. a page opened before someone else finalized

    $this->runs->finalize($run, $this->approver);

    expect($stale->status)->toBe('approved')
        ->and(fn () => $this->runs->reopen($stale, 'Rule superseded', $this->approver))->toThrow(RuntimeException::class, 'A finalized run cannot be reopened')
        ->and(PayrollRun::query()->whereKey($run->id)->value('status'))->toBe('finalized')
        ->and(Payslip::query()->count())->toBe(1)
        ->and(PayrollPeriod::query()->whereKey($run->payroll_period_id)->value('status'))->toBe('closed');
});

it('returns an approved, unfinalized run to draft with a reason, and the recalculation audits the results it replaced', function () {
    $run = ($this->approved)();
    $net = $run->total('net');

    expect(fn () => $this->runs->reopen($run, ' ', $this->approver))->toThrow(RuntimeException::class, 'reason');
    $run = $this->runs->reopen($run, 'PT rule superseded', $this->approver);
    expect($run->status)->toBe('draft')->and($run->approved_by)->toBeNull()
        ->and(AuditEvent::query()->where('action', 'PAYROLL_REOPENED')->sole()->reason)->toBe('PT rule superseded')
        ->and(fn () => $this->runs->reopen($run, 'Again', $this->approver))->toThrow(RuntimeException::class, 'Only an approved run');

    $this->runs->calculate($run, $this->preparer);
    $calculations = AuditEvent::query()->where('action', 'PAYROLL_CALCULATED')->orderBy('id')->get();
    expect($calculations)->toHaveCount(2)
        ->and($calculations[0]->metadata)->not->toHaveKey('replaced') // the first calculation replaced nothing
        ->and($calculations[1]->metadata['replaced']['entries'])->toBe(1)
        ->and((float) $calculations[1]->metadata['replaced']['net'])->toBe($net);

    // It needs an independent approval again: the person who prepared it still cannot approve it.
    $run = $this->runs->validate($run->refresh());
    expect(fn () => $this->runs->approve($run, $this->preparer))->toThrow(RuntimeException::class, 'cannot approve')
        ->and($this->runs->approve($run, $this->approver, 'ok')->status)->toBe('approved');
});
