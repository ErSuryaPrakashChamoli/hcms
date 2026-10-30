<?php

namespace App\Domain\Payroll\Services;

use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Services\AccessScopes;
use App\Domain\Payroll\Models\CompanyBankAccount;
use App\Domain\Payroll\Models\EmployeePaymentMode;
use App\Domain\Payroll\Models\PayrollEntry;
use App\Domain\Platform\Services\SettingsRepository;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * HROne setup parity BP-13 (pay modes): how employees are paid — by bank transfer (the default, and the only mode before
 * this), cash or cheque, and from which of the company's paying accounts. Payroll still calculates the same net pay; the
 * mode only decides whether it goes in the bank file (and in which account's file) or in the cash and cheque register.
 * Reimbursement lines may be paid differently from the salary (HROne's reimbursement pay mode).
 */
final class PaymentModes
{
    public function __construct(private readonly SettingsRepository $settings) {}

    /** @return array<string, string> the pay modes the organisation uses (payroll.pay_modes), mode => label */
    public function enabled(): array
    {
        $chosen = array_values(array_filter((array) $this->settings->get('payroll.pay_modes', ['online_transfer'])));

        return array_intersect_key(EmployeePaymentMode::MODES, array_flip($chosen ?: ['online_transfer']));
    }

    public function set(Employee $employee, string $effectiveFrom, string $salaryMode, ?int $salaryAccountId, ?string $reimbursementMode, ?int $reimbursementAccountId, string $reason, User $by): EmployeePaymentMode
    {
        if (! $by->hasPermission('payroll.manage') || ! app(AccessScopes::class)->allows($by, $employee)) {
            throw new RuntimeException('Only payroll can change how an employee is paid.');
        }
        $enabled = $this->enabled();
        foreach (array_filter([$salaryMode, $reimbursementMode]) as $mode) {
            if (! isset($enabled[$mode])) {
                throw new RuntimeException((EmployeePaymentMode::MODES[$mode] ?? $mode).' is not a pay mode in use (Setup → Payroll → Pay modes in use).');
            }
        }
        foreach ([[$salaryMode, $salaryAccountId], [$reimbursementMode ?? $salaryMode, $reimbursementAccountId]] as [$mode, $accountId]) {
            if ($accountId === null) {
                continue;
            }
            if ($mode !== 'online_transfer') {
                throw new RuntimeException('A paying bank account goes with bank transfers only.');
            }
            if (! CompanyBankAccount::query()->whereKey($accountId)->where('status', 'active')->exists()) {
                throw new RuntimeException('Choose an active paying bank account.');
            }
        }
        if (trim($reason) === '') {
            throw new RuntimeException('Give the reason for the change.');
        }

        $row = EmployeePaymentMode::query()->where('employee_id', $employee->id)->whereDate('effective_from', $effectiveFrom)->first()
            ?? new EmployeePaymentMode(['employee_id' => $employee->id, 'effective_from' => $effectiveFrom]);
        $row->fill([
            'salary_mode' => $salaryMode, 'salary_bank_account_id' => $salaryAccountId,
            // Reimbursements "as the salary" are stored as null, so a later salary change carries them along.
            'reimbursement_mode' => $reimbursementMode !== null && ($reimbursementMode !== $salaryMode || $reimbursementAccountId !== $salaryAccountId) ? $reimbursementMode : null,
            'reimbursement_bank_account_id' => $reimbursementMode !== null && ($reimbursementMode !== $salaryMode || $reimbursementAccountId !== $salaryAccountId) ? $reimbursementAccountId : null,
            'reason' => trim($reason), 'created_by' => $by->id,
        ]);
        $row->withAuditReason($reason)->save();

        return $row;
    }

    /** The payment mode in force on a date (the latest on or before it); null = bank transfer. */
    public function for(int $employeeId, CarbonInterface $on): ?EmployeePaymentMode
    {
        return EmployeePaymentMode::query()->where('employee_id', $employeeId)->whereDate('effective_from', '<=', $on->toDateString())
            ->orderByDesc('effective_from')->orderByDesc('id')->first();
    }

    /**
     * The payment modes in force on a date for many employees, in one query.
     *
     * @param  iterable<int>  $employeeIds
     * @return array<int, EmployeePaymentMode> employee id => mode (employees without one are left out)
     */
    public function forMany(iterable $employeeIds, CarbonInterface $on): array
    {
        $ids = collect($employeeIds)->map(fn ($id) => (int) $id)->unique()->values();
        if ($ids->isEmpty()) {
            return [];
        }

        return EmployeePaymentMode::query()->whereIn('employee_id', $ids)->whereDate('effective_from', '<=', $on->toDateString())
            ->orderByDesc('effective_from')->orderByDesc('id')->get()
            ->unique('employee_id')->keyBy('employee_id')->all();
    }

    /** Whether the employee's salary is paid outside the bank file (cash or cheque) on a date. */
    public function paidOutsideBank(int $employeeId, CarbonInterface $on): bool
    {
        $mode = $this->for($employeeId, $on);

        return $mode !== null && $mode->salary_mode !== 'online_transfer';
    }

    /**
     * How an entry's net pay is paid: one part (the whole net, as before) unless the employee's reimbursements go another
     * way, then the reimbursement lines (never more than the net) and the rest as salary. Parts of zero are left out.
     *
     * @return list<array{part: string, mode: string, account_id: ?int, amount: float}>
     */
    public function split(PayrollEntry $entry, ?EmployeePaymentMode $mode): array
    {
        $net = round((float) $entry->net_pay, 2);
        if ($mode === null) {
            return [['part' => 'salary', 'mode' => 'online_transfer', 'account_id' => null, 'amount' => $net]];
        }
        $salary = ['mode' => (string) $mode->salary_mode, 'account_id' => $mode->salary_bank_account_id !== null ? (int) $mode->salary_bank_account_id : null];
        $reimbursement = $mode->reimbursement_mode === null ? $salary
            : ['mode' => (string) $mode->reimbursement_mode, 'account_id' => $mode->reimbursement_bank_account_id !== null ? (int) $mode->reimbursement_bank_account_id : null];
        if ($salary === $reimbursement) {
            return [['part' => 'salary', ...$salary, 'amount' => $net]];
        }
        $entry->loadMissing('lines');
        $reimbursed = min(max(0.0, $net), round((float) $entry->lines->where('type', 'reimbursement')->sum('amount'), 2));

        return array_values(array_filter([
            ['part' => 'salary', ...$salary, 'amount' => round($net - $reimbursed, 2)],
            ['part' => 'reimbursement', ...$reimbursement, 'amount' => $reimbursed],
        ], fn (array $part) => $part['amount'] > 0));
    }

    /**
     * The account a bank transfer is paid from: the one set on the payment mode (when it is one of the company's active
     * accounts), else the company's account at the employee's own bank (same IFSC bank code), else the default. Null
     * when the company keeps no paying accounts (the plain salary file, as before).
     *
     * @param  Collection<int, CompanyBankAccount>  $accounts  the company's active accounts
     */
    public function payingAccount(?int $chosen, ?string $employeeIfsc, Collection $accounts): ?CompanyBankAccount
    {
        if ($accounts->isEmpty()) {
            return null;
        }
        if ($chosen !== null && $accounts->has($chosen)) {
            return $accounts->get($chosen);
        }
        $bank = filled($employeeIfsc) ? strtoupper(substr((string) $employeeIfsc, 0, 4)) : null;
        $sameBank = $bank !== null ? $accounts->first(fn (CompanyBankAccount $a) => $a->bankIdentifier() === $bank && $a->is_default)
            ?? $accounts->first(fn (CompanyBankAccount $a) => $a->bankIdentifier() === $bank) : null;

        return $sameBank ?? $accounts->first(fn (CompanyBankAccount $a) => $a->is_default) ?? $accounts->first();
    }
}
