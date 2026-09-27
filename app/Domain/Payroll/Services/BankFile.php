<?php

namespace App\Domain\Payroll\Services;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Payroll\Models\PayrollRun;

/** Salary transfer file (CSV) for a finalized run. Account numbers are decrypted only here and the export is audited. */
final class BankFile
{
    public function __construct(private readonly AuditRecorder $audit) {}

    /** @return array<int, array<string, mixed>> */
    public function rows(PayrollRun $run): array
    {
        $run->loadMissing(['period', 'company']);

        return $run->entries()->with(['employee.person', 'employee.bankAccounts'])->where('status', '!=', 'exception')->where('net_pay', '>', 0)->get()
            ->map(function ($entry) use ($run) {
                $bank = $entry->employee->bankAccounts->firstWhere('is_primary', true);

                return [
                    'employee_code' => $entry->employee->employee_code,
                    'name' => $bank?->account_holder_name ?: $entry->employee->person?->full_name,
                    'bank' => $bank?->bank_name,
                    'ifsc' => $bank?->ifsc,
                    'account_number' => $bank?->account_number,
                    'amount' => number_format((float) $entry->net_pay, 2, '.', ''),
                    'narration' => 'Salary '.$run->period->label(),
                ];
            })->all();
    }

    public function csv(PayrollRun $run): string
    {
        $rows = $this->rows($run);
        $out = fopen('php://temp', 'r+');
        fputcsv($out, ['Employee code', 'Beneficiary name', 'Bank', 'IFSC', 'Account number', 'Amount', 'Narration']);
        foreach ($rows as $row) {
            fputcsv($out, array_values($row));
        }
        rewind($out);
        $csv = stream_get_contents($out);
        fclose($out);

        $this->audit->record(AuditAction::Export, 'payroll', $run, [], null, metadata: ['export' => 'bank_file', 'rows' => count($rows), 'amount' => round(array_sum(array_column($rows, 'amount')), 2)]);

        return $csv;
    }
}
