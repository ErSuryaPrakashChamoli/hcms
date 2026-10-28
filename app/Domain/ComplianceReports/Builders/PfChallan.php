<?php

namespace App\Domain\ComplianceReports\Builders;

use App\Domain\Compliance\Models\ComplianceRule;
use App\Domain\ComplianceReports\Support\ReportContext;
use App\Domain\ComplianceReports\Support\ReportSheet;
use App\Domain\Payroll\Models\PayrollEntry;

/**
 * PF statement and challan working for an EPF establishment: the ECR particulars per member (UAN, wages, shares,
 * NCP days) and the challan amounts by EPFO account — A/c 1 (EPF: employee share + employer's EPF difference),
 * A/c 2 (administrative charges), A/c 10 (EPS), A/c 21 (EDLI) and A/c 22 (EDLI administrative charges). Amounts are
 * the ones payroll calculated under the rule version recorded on each line; the EDLI part of the combined admin line
 * is split out with that same rule version. The ECR file itself is produced, approved and exported through
 * Compliance → EPF returns; this working paper is not a filing.
 */
final class PfChallan extends Builder
{
    /** @var array<int, ?ComplianceRule> */
    private array $rules = [];

    public function build(ReportContext $context): array
    {
        $entries = $context->data->payrollFor($context)->filter(fn (PayrollEntry $e) => $e->lines->contains('code', 'PF_EE'))->values();
        $columns = ['sl' => 'Sl.', 'uan' => 'UAN', 'code' => 'Code', 'name' => 'Member name', 'gross' => 'Gross wages', 'epf_wages' => 'EPF wages', 'eps_wages' => 'EPS wages',
            'edli_wages' => 'EDLI wages', 'ee' => 'EPF contribution (employee)', 'eps' => 'EPS contribution', 'er' => 'EPF difference (employer)', 'ncp' => 'NCP days', 'refund' => 'Refund of advances'];
        $money = ['gross', 'epf_wages', 'eps_wages', 'edli_wages', 'ee', 'eps', 'er', 'refund'];

        if ($entries->isEmpty()) {
            $message = $context->data->payrollFor($context)->isEmpty() ? $this->noPayrollMessage($context) : 'No member had PF deducted this month.';

            return [$this->sheet($context, $columns, [], ['message' => $message, 'sheet' => 'ECR working', 'warnings' => [...$this->payrollGaps($context), ...$this->registrationGaps($context, 'epf_establishment_code')]])];
        }

        $edli = 0.0;
        $adminTotal = 0.0;
        $rows = $entries->map(function (PayrollEntry $entry) use ($context, &$edli, &$adminTotal) {
            $ee = $entry->lines->firstWhere('code', 'PF_EE');
            $er = $entry->lines->firstWhere('code', 'PF_ER');
            $basis = (array) ($ee->basis ?? []);
            $rule = $this->rule($basis['rule_id'] ?? null);
            $wages = (float) ($basis['wages'] ?? 0);
            $base = (float) ($basis['base'] ?? 0);
            $edliWages = $rule ? min($wages, (float) $rule->param('edli_wage_ceiling', $wages)) : $wages;
            $edli += $rule ? round($edliWages * (float) $rule->param('edli_rate', 0), 2) : 0.0;
            $adminTotal += $this->amount($entry, 'PF_ADMIN');

            return [
                'uan' => $context->identifier($entry->employee?->statutoryDetail?->uan),
                'code' => $entry->employee?->employee_code,
                'name' => mb_strtoupper($this->name($entry->employee)),
                'gross' => round((float) $entry->gross, 2),
                'epf_wages' => $base,
                'eps_wages' => $rule ? min($base, (float) $rule->param('eps_wage_ceiling', $base)) : $base,
                'edli_wages' => $edliWages,
                'ee' => round((float) $ee->amount, 2),
                'eps' => round((float) ($er?->basis['eps'] ?? 0), 2),
                'er' => round((float) ($er?->basis['epf'] ?? 0), 2),
                'ncp' => (float) $entry->lop_days,
                'refund' => 0.0,
            ];
        })->values()->all();

        $totals = ReportSheet::sum($columns, $rows, [...$money, 'ncp']);
        $edli = round($edli, 2);
        $admin = round(max(0, $adminTotal - $edli), 2);
        $accounts = [
            ['account' => 'A/c 1', 'head' => 'EPF — employee share + employer EPF difference', 'amount' => round($totals['ee'] + $totals['er'], 2)],
            ['account' => 'A/c 2', 'head' => 'EPF administrative charges', 'amount' => $admin],
            ['account' => 'A/c 10', 'head' => 'Pension fund (EPS) — employer', 'amount' => $totals['eps']],
            ['account' => 'A/c 21', 'head' => 'EDLI contribution — employer', 'amount' => $edli],
            ['account' => 'A/c 22', 'head' => 'EDLI administrative charges', 'amount' => 0.0],
        ];
        $accounts[] = ['account' => 'Total', 'head' => 'Challan total ('.count($rows).' members)', 'amount' => round(array_sum(array_column($accounts, 'amount')), 2)];

        return [
            $this->sheet($context, $columns, $this->numbered($rows), ['money' => $money, 'totals' => $totals, 'sheet' => 'ECR working', 'registration' => 'epf_establishment_code',
                'warnings' => [...$this->payrollGaps($context), ...$this->attributionGaps($entries), ...$this->registrationGaps($context, 'epf_establishment_code'), ...$this->identifierGaps($entries, 'uan', 'UAN')],
                'rules' => $this->rules($context, $entries, ['PF_EE', 'PF_ER', 'PF_ADMIN'])]),
            $this->sheet($context, ['account' => 'Account', 'head' => 'Head', 'amount' => 'Amount (₹)'], $accounts, [
                'title' => 'PF challan summary', 'sheet' => 'Challan', 'money' => ['amount'], 'registration' => 'epf_establishment_code',
                'notes' => ['Administrative and EDLI charges are what payroll charged (line PF_ADMIN), split with the EDLI rate of the rule version used. Round each account as the EPFO portal does before paying; the ECR is generated and approved under Compliance → EPF returns.'],
            ]),
        ];
    }

    private function rule(?int $id): ?ComplianceRule
    {
        if ($id === null) {
            return null;
        }

        return array_key_exists($id, $this->rules) ? $this->rules[$id] : $this->rules[$id] = ComplianceRule::query()->find($id);
    }
}
