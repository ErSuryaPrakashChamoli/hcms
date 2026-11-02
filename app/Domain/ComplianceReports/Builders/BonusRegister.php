<?php

namespace App\Domain\ComplianceReports\Builders;

use App\Domain\Compliance\Models\ComplianceRule;
use App\Domain\Compliance\Services\ComplianceRules;
use App\Domain\ComplianceReports\Support\ReportContext;
use App\Domain\ComplianceReports\Support\ReportPeriod;
use App\Domain\ComplianceReports\Support\ReportSheet;
use App\Domain\Payroll\Support\BonusBasis;
use Carbon\CarbonImmutable;

/**
 * Bonus register for an accounting year (Code on Wages, 2019, Chapter IV): per employee the days paid, the wages
 * earned (basic + DA, the components classified as such in payroll), the bonus paid through payroll in the year and
 * the months allowed for payment after it, and — from the BONUS statutory rule version for the year — eligibility,
 * the wages for bonus after the calculation ceiling, and the minimum and maximum bonus.
 *
 * Each employee gets one status, with the reason:
 *  ELIGIBLE / INELIGIBLE         — decided under a VERIFIED, active rule version;
 *  PENDING_DETERMINATION         — the facts PeopleOS holds do not settle it (wages crossed the eligibility ceiling
 *                                  during the year; the minimum wage that may raise the calculation ceiling is not held);
 *  INSUFFICIENT_DATA             — no finalized payroll, or no basic / DA classified, for the year;
 *  LEGALLY_UNVERIFIED            — the rule version is proposed or not verified: the determination is shown as provisional.
 * Nothing is manufactured: without a rule version, eligibility and bonus amounts stay blank.
 */
final class BonusRegister extends Builder
{
    public const STATUSES = [
        'ELIGIBLE' => 'Eligible', 'INELIGIBLE' => 'Ineligible', 'PENDING_DETERMINATION' => 'Pending determination',
        'INSUFFICIENT_DATA' => 'Insufficient data', 'LEGALLY_UNVERIFIED' => 'Legally unverified',
    ];

    public function build(ReportContext $context): array
    {
        $period = $context->period;
        ['active' => $active, 'proposed' => $proposed] = app(ComplianceRules::class)->resolveWithProposed('BONUS', $period->end);
        $rule = $active ?? $proposed;
        $verified = $rule !== null && $rule->isVerified() && ! $rule->isProposed();
        $paymentMonths = (int) ($rule?->param('payment_within_months') ?? $context->definition->options['payment_within_months'] ?? 8);
        $lastPaymentMonth = min($period->end->startOfMonth()->addMonths($paymentMonths), CarbonImmutable::now()->startOfMonth());

        $employees = [];
        $entries = collect();
        $monthRules = [];
        foreach ($period->months() as $month) {
            // Each month is measured under the version in force at its end: an accounting year can straddle a change
            // of law (FY 2025-26: the Payment of Bonus Act to 20 Nov 2025, the Code on Wages from 21 Nov 2025).
            $resolved = app(ComplianceRules::class)->resolveWithProposed('BONUS', $month->end);
            $monthRule = $resolved['active'] ?? $resolved['proposed'] ?? $rule;
            if ($monthRule !== null) {
                $monthRules[$monthRule->getKey()] = $monthRule;
            }
            foreach ($context->data->payrollFor($context, $month) as $entry) {
                $entries->push($entry);
                $key = (int) $entry->employee_id;
                $employees[$key] ??= BonusBasis::row($entry->employee);
                // HROne parity PO-22: one calculation shared with the bonus run (a month's wages above the calculation
                // ceiling count at the ceiling, prorated as the wages were).
                BonusBasis::addMonth($employees[$key], $entry, $monthRule, $month->start->format('Y-m'));
            }
        }

        for ($month = $period->start->startOfMonth(); $month->lte($lastPaymentMonth); $month = $month->addMonth()) {
            foreach ($context->data->payrollFor($context, ReportPeriod::month($month->year, $month->month)) as $entry) {
                $paid = BonusBasis::paid($entry);
                if ($paid > 0 && isset($employees[(int) $entry->employee_id])) {
                    $employees[(int) $entry->employee_id]['paid'] += $paid;
                    $employees[(int) $entry->employee_id]['paid_on'][] = $this->date($entry->run?->paid_at ?? $entry->run?->period?->payment_date);
                }
            }
        }

        $rows = [];
        foreach ($employees as $row) {
            [$determination, $reason] = BonusBasis::determine($row, $rule);
            $computable = $rule !== null && in_array($determination, [BonusBasis::ELIGIBLE, BonusBasis::PENDING], true);
            $minimum = $computable ? BonusBasis::minimum($row['bonus_wages'], $rule) : null;
            $status = $verified ? $determination : ($rule === null ? 'LEGALLY_UNVERIFIED' : ($determination === 'INSUFFICIENT_DATA' ? 'INSUFFICIENT_DATA' : 'LEGALLY_UNVERIFIED'));

            $rows[] = [
                'code' => $row['employee']?->employee_code,
                'name' => $this->name($row['employee']),
                'relative' => $this->relativeName($row['employee']?->person),
                'designation' => $row['employee'] ? $this->designation($context, $row['employee']) : '',
                'months' => $row['months'],
                'days' => round($row['days'], 2),
                'wages' => round($row['wages'], 2),
                'status' => self::STATUSES[$status],
                'determination' => $rule === null ? '' : self::STATUSES[$determination],
                'reason' => $rule === null ? 'No BONUS statutory rule version applies to this year, so eligibility and bonus are not computed.' : $reason,
                'bonus_wages' => $computable ? round($row['bonus_wages'], 2) : null,
                'minimum' => $minimum,
                'maximum' => $computable ? BonusBasis::maximum($row['bonus_wages'], $rule) : null,
                'paid' => round($row['paid'], 2),
                'paid_on' => implode(', ', array_unique($row['paid_on'])),
                'shortfall' => $minimum !== null ? round(max(0, $minimum - $row['paid']), 2) : null,
            ];
        }
        usort($rows, fn ($a, $b) => strcmp((string) $a['code'], (string) $b['code']));

        $columns = ['sl' => 'Sl.', 'code' => 'Code', 'name' => 'Name', 'relative' => 'Father\'s name', 'designation' => 'Designation', 'months' => 'Months paid',
            'days' => 'Days paid in the year', 'wages' => 'Wages earned (basic + DA)', 'status' => 'Status', 'determination' => 'Provisional determination', 'reason' => 'Reason',
            'bonus_wages' => 'Wages for bonus (after ceiling)', 'minimum' => 'Minimum bonus', 'maximum' => 'Maximum bonus', 'paid' => 'Bonus paid', 'paid_on' => 'Date(s) paid', 'shortfall' => 'Short of minimum'];
        $money = ['wages', 'bonus_wages', 'minimum', 'maximum', 'paid', 'shortfall'];
        $ruleNote = match (true) {
            $rule === null => 'No BONUS rule version applies to this year: eligibility, wages for bonus and the minimum and maximum bonus are not computed. Bonus paid is what payroll paid as a bonus-classified earning.',
            $verified => "Computed with {$rule->label()} (verified).",
            default => "Computed provisionally with {$rule->label()} ({$rule->status}, {$rule->verification_status}): it is not verified, so every determination is legally unverified.",
        };

        return [$this->sheet($context, $columns, $this->numbered($rows), [
            'money' => $money,
            'totals' => $rows === [] ? null : ReportSheet::sum($columns, $rows, $money),
            'message' => $this->noPayrollMessage($context->forPeriod(ReportPeriod::month($period->end->year, $period->end->month))),
            'warnings' => [...$this->payrollGaps($context), ...$this->attributionGaps($entries)],
            'rules' => collect($rule === null ? $monthRules : $monthRules + [$rule->getKey() => $rule])->map(fn (ComplianceRule $r) => ['id' => $r->getKey(), 'code' => 'BONUS', 'state' => null, 'version' => $r->version,
                'verification_status' => $r->isProposed() ? 'proposed' : $r->verification_status, 'status_at_calculation' => null, 'label' => $r->label().' ('.($r->isProposed() ? 'proposed, not in force' : $r->verification_status).')'])->all(),
            'notes' => [
                $ruleNote,
                ...(count($monthRules) > 1 ? ['The year straddles a change of law: each month was measured under the version in force at its end ('.collect($monthRules)->map(fn ($r) => $r->label().' from '.$r->effective_from->toDateString())->implode('; ').'). Which law governs the year\'s bonus needs legal review.'] : []),
                'Days paid stand in for days worked (the Code counts lay-off, leave with wages and maternity leave as worked). Wages are the components classified basic and DA in payroll; check that the 50% wage rule of the Code does not add other components. Disqualification (s.29), adjustments (ss.37–38), the allocable surplus and set-on / set-off are not in PeopleOS.',
                "Bonus paid includes bonus-classified earnings paid in the year and in the {$paymentMonths} months after it.",
            ],
        ])];
    }
}
