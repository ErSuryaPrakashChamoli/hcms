<?php

namespace App\Domain\Payroll\Services;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Payroll\Models\CompanyBankAccount;
use App\Domain\Payroll\Models\PayrollRun;
use Illuminate\Support\Collection;

/**
 * Salary transfer file (CSV) for a finalized run. Account numbers are decrypted only here and the export is audited.
 *
 * HROne setup parity BP-13 / CA-5: employees paid in cash or by cheque are left out (they are in the cash and cheque
 * register), reimbursements paid another way are split from the salary, and when the company keeps paying accounts
 * each transfer is grouped under the account it is paid from; an account's file is written in its bank's layout. With
 * no payment modes and no paying accounts the file is exactly as before.
 */
final class BankFile
{
    public function __construct(private readonly AuditRecorder $audit, private readonly PaymentModes $modes, private readonly BankFileLayouts $layouts) {}

    /**
     * The plain file's rows: every bank transfer, or those paid from one account.
     *
     * @return array<int, array<string, mixed>>
     */
    public function rows(PayrollRun $run, ?int $accountId = null): array
    {
        return $this->plain($this->transfers($run, $accountId));
    }

    /**
     * The paying accounts this run's transfers are grouped under, with their count and total (empty when the company
     * keeps no paying accounts).
     *
     * @return array<int, array{account: CompanyBankAccount, count: int, total: float}>
     */
    public function groups(PayrollRun $run): array
    {
        $groups = [];
        foreach ($this->transfers($run) as $t) {
            if ($t['account'] === null) {
                continue;
            }
            $groups[$t['account']->id] ??= ['account' => $t['account'], 'count' => 0, 'total' => 0.0];
            $groups[$t['account']->id]['count']++;
            $groups[$t['account']->id]['total'] = round($groups[$t['account']->id]['total'] + (float) $t['amount'], 2);
        }

        return $groups;
    }

    public function csv(PayrollRun $run, ?int $accountId = null): string
    {
        $transfers = $this->transfers($run, $accountId);
        $account = $accountId !== null ? $this->accounts($run)->get($accountId) : null;
        $layout = $account !== null ? $this->layouts->active($account->bank_file_layout_code) : null;

        if ($layout !== null) {
            $rows = array_map(fn (array $t, int $i) => ['sequence' => $i + 1, 'beneficiary_name' => $t['name']] + $t, $transfers, array_keys($transfers));
            $csv = $this->layouts->render($layout, $rows, [
                'payment_date' => $run->period->payment_date ?? $run->period->end_date, 'period' => $run->period->label(), 'company' => $run->company?->legal_name ?: $run->company?->name,
                'debit_account' => $account->account_number, 'debit_ifsc' => $account->ifsc, 'debit_bank_code' => $account->bank_code, 'batch' => $run->id,
            ]);
        } else {
            $out = fopen('php://temp', 'r+');
            fputcsv($out, ['Employee code', 'Beneficiary name', 'Bank', 'IFSC', 'Account number', 'Amount', 'Narration']);
            foreach ($this->plain($transfers) as $row) {
                fputcsv($out, array_values($row));
            }
            rewind($out);
            $csv = stream_get_contents($out);
            fclose($out);
        }

        $this->audit->record(AuditAction::Export, 'payroll', $run, [], null, metadata: ['export' => 'bank_file', 'rows' => count($transfers), 'amount' => round(array_sum(array_column($transfers, 'amount')), 2)]
            + ($account !== null ? ['paying_account' => $account->label()] : [])
            + ($layout !== null ? ['layout' => $layout->code, 'layout_version' => $layout->version, 'layout_checksum' => $layout->checksum] : []));

        return $csv;
    }

    /** The file name: the account's layout decides the extension. */
    public function fileName(PayrollRun $run, ?int $accountId = null): string
    {
        $account = $accountId !== null ? $this->accounts($run)->get($accountId) : null;
        $layout = $account !== null ? $this->layouts->active($account->bank_file_layout_code) : null;
        $suffix = $account !== null ? '-'.strtolower(preg_replace('/[^A-Za-z0-9]+/', '-', $account->alias ?: $account->bank_name)).'-'.$account->account_number_last4 : '';

        return 'salary-'.$run->period->year.'-'.str_pad((string) $run->period->month, 2, '0', STR_PAD_LEFT).$suffix.'.'.($layout?->specification['extension'] ?? 'csv');
    }

    /**
     * Every bank transfer of the run, in entry order.
     *
     * @return list<array<string, mixed>>
     */
    private function transfers(PayrollRun $run, ?int $accountId = null): array
    {
        $run->loadMissing(['period', 'company']);
        $on = $run->period->end_date;

        // HROne parity PY-06: salaries on hold for the month stay out of the file until released.
        $held = app(SalaryHolds::class)->heldEmployeeIds($run);
        $entries = $run->entries()->with(['employee.person', 'employee.bankAccounts'])->where('status', '!=', 'exception')->where('net_pay', '>', 0)->whereNotIn('employee_id', $held)->get();
        $modes = $this->modes->forMany($entries->pluck('employee_id'), $on);
        $accounts = $this->accounts($run);

        $transfers = [];
        foreach ($entries as $entry) {
            $bank = $entry->employee->bankAccounts->firstWhere('is_primary', true);
            foreach ($this->modes->split($entry, $modes[$entry->employee_id] ?? null) as $part) {
                if ($part['mode'] !== 'online_transfer') {
                    continue;
                }
                $account = $this->modes->payingAccount($part['account_id'], $bank?->ifsc, $accounts);
                if ($accountId !== null && $account?->id !== $accountId) {
                    continue;
                }
                $transfers[] = [
                    'employee_code' => $entry->employee->employee_code,
                    'name' => $bank?->account_holder_name ?: $entry->employee->person?->full_name,
                    'bank' => $bank?->bank_name,
                    'ifsc' => $bank?->ifsc,
                    'account_number' => $bank?->account_number,
                    'amount' => number_format((float) $part['amount'], 2, '.', ''),
                    'narration' => ($part['part'] === 'reimbursement' ? 'Reimbursement ' : 'Salary ').$run->period->label(),
                    'account' => $account,
                    'transfer_type' => $account !== null && $account->bankIdentifier() !== null && $bank?->ifsc !== null && strtoupper(substr((string) $bank->ifsc, 0, 4)) === $account->bankIdentifier() ? 'same' : 'other',
                ];
            }
        }

        return $transfers;
    }

    /** @return array<int, array<string, mixed>> the plain file's columns, in its order */
    private function plain(array $transfers): array
    {
        return array_map(fn (array $t) => array_intersect_key($t, array_flip(['employee_code', 'name', 'bank', 'ifsc', 'account_number', 'amount', 'narration'])), $transfers);
    }

    /** @return Collection<int, CompanyBankAccount> the run company's active paying accounts, by id */
    private function accounts(PayrollRun $run): Collection
    {
        return CompanyBankAccount::query()->where('company_id', $run->company_id)->where('status', 'active')->orderByDesc('is_default')->orderBy('id')->get()->keyBy('id');
    }
}
