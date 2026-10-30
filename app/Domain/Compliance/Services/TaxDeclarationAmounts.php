<?php

namespace App\Domain\Compliance\Services;

use App\Domain\Compliance\Models\EmployeeTaxDeclaration;
use App\Domain\Compliance\Models\TdsEmployeeInvestment;
use Carbon\CarbonInterface;

/**
 * The section amounts TDS counts for a payment (HROne parity PY-12). Until the organisation's "verified proofs from"
 * date they are the declared totals. From that date the employee's own sections count only what payroll verified
 * (the proof amount of each verified item; pending and rejected items count nothing); keys payroll owns (80CCD2)
 * stay as entered and the metro flag stays only with verified rent.
 */
final class TaxDeclarationAmounts
{
    public function __construct(private readonly TaxSettings $settings) {}

    /** @return array{amounts: array<string, float|int>, basis: string} */
    public function for(?EmployeeTaxDeclaration $declaration, CarbonInterface $paymentDate): array
    {
        $declared = $declaration?->declarations ?? [];
        if ($declaration === null || ! $this->settings->verifiedProofsApply($paymentDate)) {
            return ['amounts' => $declared, 'basis' => 'declared'];
        }

        $amounts = [];
        $verified = TdsEmployeeInvestment::query()->where('employee_id', $declaration->employee_id)->where('financial_year', $declaration->financial_year)
            ->where('proof_status', 'verified')->get()
            ->groupBy('section')->map(fn ($rows) => round($rows->sum(fn ($r) => (float) $r->proof_amount), 2));
        foreach ($verified as $section => $sum) {
            if ($sum > 0 && $section !== '80CCD2') {
                $amounts[$section] = $sum;
            }
        }
        if (isset($declared['80CCD2'])) {
            $amounts['80CCD2'] = $declared['80CCD2'];
        }
        if (($amounts['HRA_RENT'] ?? 0) > 0 && isset($declared['METRO'])) {
            $amounts['METRO'] = $declared['METRO'];
        } else {
            unset($amounts['METRO']);
        }

        return ['amounts' => $amounts, 'basis' => 'verified_proofs'];
    }
}
