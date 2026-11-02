<?php

namespace App\Domain\Exit\Services;

use App\Domain\Employment\Models\Employee;
use App\Domain\Exit\Models\ExitCase;
use App\Domain\Exit\Support\NoticeShortfall;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Scopes\AccessScope;
use App\Domain\Payroll\Contracts\PaidPayReader;
use App\Domain\Payroll\Contracts\SalaryHoldReader;
use App\Domain\Platform\Services\SettingsRepository;

/**
 * HROne parity WO-11 (§23.6.1 #14, "Review relieving details"): what HR checks before relieving someone, on the exit case.
 *
 * - Last payroll processed: the latest finalized or paid payroll month of the employee (PaidPayReader — never payroll
 *   tables from Exit), its net pay only for people who settle exits (exit.settle) or read payroll (payroll.view).
 * - Salary on hold: the months not yet released (SalaryHoldReader), amounts as above.
 * - Notice: the period, days served, relaxation and shortfall (NoticeShortfall) and the recovery rule in force.
 * - Settlement month: the month chosen, else the month of the last working day.
 * - Hand-over: what is still to hand over (ExitHandover::unresolved — a warning, never a block).
 *
 * Read only; nothing here changes the exit.
 */
final class RelievingReview
{
    public function __construct(
        private readonly PaidPayReader $paid,
        private readonly SalaryHoldReader $holds,
        private readonly ExitHandover $handover,
    ) {}

    /** @return array{last_payroll: ?string, last_net: ?float, holds: list<array{pay_month: string, reason: string, held_on: ?string, net: ?float}>, notice: ?array<string, int>, recovery_rule: string, settlement_month: string, settlement_month_chosen: bool, handover: list<string>, amounts: bool} */
    public function for(ExitCase $case, User $viewer): array
    {
        $employee = Employee::query()->withoutGlobalScope(AccessScope::class)->find($case->employee_id);
        $amounts = $viewer->hasPermission('exit.settle') || $viewer->hasPermission('payroll.view');
        $latest = $employee !== null ? $this->paid->latestPaid($employee) : null;
        $holds = $employee !== null ? $this->holds->openHolds($employee) : [];
        $rule = NoticeShortfall::rule($case)['rule'];
        $chosen = ($case->getAttributes()['settlement_month'] ?? null) !== null;

        return [
            'last_payroll' => $latest['period'] ?? null,
            'last_net' => $amounts ? ($latest['net'] ?? null) : null,
            'holds' => $amounts ? $holds : array_map(fn (array $h) => ['net' => null] + $h, $holds),
            'notice' => NoticeShortfall::for($case),
            'recovery_rule' => match ($rule) {
                'none' => 'Not recovered (exit policy)',
                'policy' => 'Recovered on the exit policy\'s rule',
                default => (bool) app(SettingsRepository::class)->get('exit.notice_recovery', true) ? 'Recovered on gross pay ÷ 30 (organisation setting)' : 'Not recovered (organisation setting)',
            },
            'settlement_month' => ($chosen ? $case->settlement_month : $case->last_working_day)->format('F Y'),
            'settlement_month_chosen' => $chosen,
            'handover' => $this->handover->unresolved($case),
            'amounts' => $amounts,
        ];
    }

    /** @return list<string> the panel as lines (the exit case view) */
    public function lines(ExitCase $case, User $viewer): array
    {
        $r = $this->for($case, $viewer);
        $money = fn (?float $amount) => $amount !== null ? ' · net '.number_format($amount, 2) : '';
        $lines = ['Last payroll processed: '.($r['last_payroll'] ?? 'none yet').$money($r['last_net'])];
        $lines[] = $r['holds'] === [] ? 'Salary on hold: none'
            : 'Salary on hold: '.implode('; ', array_map(fn (array $h) => $h['pay_month'].' since '.($h['held_on'] ?? '—').$money($h['net']), $r['holds']));
        if ($r['notice'] !== null) {
            $n = $r['notice'];
            $lines[] = "Notice: {$n['notice_days']} day(s); served {$n['served_days']}; relaxed {$n['relaxation_days']}; shortfall {$n['shortfall_days']} day(s) — ".$r['recovery_rule'];
        }
        $lines[] = 'Settlement month: '.$r['settlement_month'].($r['settlement_month_chosen'] ? '' : ' (the month of the last working day)');
        $lines[] = $r['handover'] === [] ? 'Hand-over: nothing left' : 'Hand-over (warning, not a block): '.implode('; ', $r['handover']);

        return $lines;
    }
}
