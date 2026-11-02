<?php

namespace App\Domain\Loans\Imports;

use App\Domain\Configuration\Services\PolicyResolver;
use App\Domain\Employment\Imports\EmployeeImport;
use App\Domain\Employment\Imports\EmployeeImportRow;
use App\Domain\Employment\Imports\RowImports;
use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Services\AccessScopes;
use App\Domain\Loans\Models\Loan;
use App\Domain\Loans\Services\Loans;
use App\Domain\Payroll\Imports\PayrollImportCells;

/**
 * Data import DI-4 (Payroll → Loans): loans and salary advances raised on employees' behalf through Loans::request —
 * the loan screen's "Raise on behalf" — checked against each employee's loan policy. A row may ask to be approved at
 * once (status approved): the person running the upload approves it through Loans::approve, as on the loan screen
 * (payroll in scope, never their own loan; an interest rate other than the policy's only when the policy lets the
 * approver change it; a loan beyond the policy's limits stays requested for the approver). A loan paid out before or
 * outside PeopleOS gives "Paid out on" and is marked paid out (it is then never paid by payroll); instalments are
 * recovered by payroll from the recovery month. Rows only add loans; the same open loan is skipped.
 */
final class LoanImports extends RowImports
{
    use PayrollImportCells;

    public const TYPE = 'loans';

    public static function fields(): array
    {
        return [
            'employee_code' => ['Employee code', true], 'loan_type' => ['Loan type', true], 'amount' => ['Amount', true], 'installments' => ['Instalments', true],
            'recovery_from' => ['Recovery from (YYYY-MM)', true], 'interest_rate' => ['Interest % a year', false], 'reason' => ['Reason', true],
            'guarantors' => ['Guarantor codes', false], 'status' => ['Status (requested/approved)', false], 'paid_out_on' => ['Paid out on', false],
        ];
    }

    public static function intents(): bool
    {
        return false; // each row raises one more loan
    }

    public function label(): string
    {
        return 'Loan upload';
    }

    protected function module(): string
    {
        return 'payroll';
    }

    protected function entityType(): string
    {
        return Loan::class;
    }

    protected function aliases(): array
    {
        return ['type' => 'loan_type', 'principal' => 'amount', 'loan_amount' => 'amount', 'emis' => 'installments', 'instalments' => 'installments', 'months' => 'installments',
            'first_due_month' => 'recovery_from', 'recovery_month' => 'recovery_from', 'interest' => 'interest_rate', 'guarantor' => 'guarantors', 'disbursed_on' => 'paid_out_on'];
    }

    protected function check(array $r, array &$context, EmployeeImportRow $row, EmployeeImport $import): array
    {
        [$employeeId, $errors] = $this->employeeOf($r, $context);
        $type = $this->type($r['loan_type'] ?? null);
        if ($type === null) {
            $errors[] = "Loan type '".($r['loan_type'] ?? '')."' is not one of: ".implode(', ', array_keys((array) config('peopleos.loans.types'))).'.';
        }
        $amount = $this->money($r['amount'] ?? null);
        if ($amount === null || $amount <= 0) {
            $errors[] = 'Amount must be a number above zero.';
        }
        if (! ctype_digit((string) ($r['installments'] ?? '')) || (int) $r['installments'] < 1) {
            $errors[] = 'Instalments must be a whole number, 1 or more.';
        }
        $month = $this->month($r['recovery_from'] ?? null);
        if ($month === null || $month < now()->format('Y-m')) {
            $errors[] = 'Recovery from must be this month or later, as YYYY-MM.';
        }
        $status = $this->status($r['status'] ?? null);
        if ($status === null) {
            $errors[] = 'Status must be requested or approved (blank = requested).';
        }
        $interest = isset($r['interest_rate']) ? $this->money(rtrim($r['interest_rate'], '%')) : null;
        if (isset($r['interest_rate']) && ($interest === null || $interest < 0 || $interest > 100)) {
            $errors[] = 'Interest % a year must be a number from 0 to 100.';
        } elseif ($interest !== null && $status === 'requested') {
            $errors[] = 'An interest rate is set when the loan is approved: give status approved, or leave it blank for the policy rate.';
        }
        if (isset($r['paid_out_on']) && ($this->date($r['paid_out_on']) === null || $this->date($r['paid_out_on']) > now()->toDateString())) {
            $errors[] = 'Paid out on must be a date (YYYY-MM-DD), today or earlier.';
        } elseif (isset($r['paid_out_on']) && $status !== 'approved') {
            $errors[] = 'Paid out on needs status approved: only an approved loan is paid out.';
        }
        $guarantors = [];
        foreach (array_filter(array_map('trim', preg_split('/[;,]/', (string) ($r['guarantors'] ?? '')))) as $code) {
            $id = $context['employees'][mb_strtolower($code)] ?? null;
            if ($id === null || $id === $employeeId) {
                $errors[] = $id === null ? "Guarantor code '{$code}' does not exist." : 'An employee cannot stand guarantor for their own loan.';
            } else {
                $guarantors[] = $id;
            }
        }
        if (trim((string) ($r['reason'] ?? '')) === '') {
            $errors[] = 'Give a reason for the loan.';
        }
        if ($errors !== []) {
            return ['action' => 'error', 'errors' => $errors, 'employee_id' => $employeeId];
        }

        $employee = Employee::query()->findOrFail($employeeId);
        $uploader = $this->uploader($import);
        if ($uploader === null || ! app(AccessScopes::class)->allows($uploader, $employee)) {
            return ['action' => 'error', 'errors' => ['The employee is outside your organisation scope.'], 'employee_id' => $employeeId];
        }
        if ($status === 'approved' && (int) $employee->user_id === (int) $uploader->id) {
            return ['action' => 'error', 'errors' => ['You cannot approve your own loan; leave the status blank so someone else approves it.'], 'employee_id' => $employeeId];
        }
        $policy = app(PolicyResolver::class)->resolve('loan', $employee, now());
        $allowed = array_values(array_filter((array) ($policy?->setting('allowed_types') ?? [])));
        if ($policy !== null && $policy->setting('enabled', true) === false) {
            return ['action' => 'error', 'errors' => ['Loans are not offered under this employee\'s loan policy.'], 'employee_id' => $employeeId];
        }
        if ($allowed !== [] && ! in_array($type, $allowed, true)) {
            return ['action' => 'error', 'errors' => [config("peopleos.loans.types.{$type}").' is not offered under this employee\'s loan policy.'], 'employee_id' => $employeeId];
        }
        if ($interest !== null && round($interest, 3) !== round((float) ($policy?->setting('interest_rate') ?? 0), 3) && ! in_array($policy?->setting('interest_editable'), [true, 1, '1', 'true', 'yes', 'on'], true)) {
            return ['action' => 'error', 'errors' => ['The loan policy does not let the approver change the interest rate; leave it blank for the policy rate.'], 'employee_id' => $employeeId];
        }

        $open = Loan::query()->where('employee_id', $employeeId)->where('loan_type', $type)->where('principal', $amount)->where('first_due_month', $month)
            ->whereIn('status', ['pending', 'approved', 'active'])->exists();
        if ($open) {
            return ['action' => 'skip', 'employee_id' => $employeeId, 'match' => ['reason' => 'The same loan is already open for this employee.']];
        }
        if (($duplicate = $this->seenBefore("{$employeeId}|{$type}|{$amount}|{$month}", $context, $row->row_number, 'loan')) !== null) {
            return ['action' => 'error', 'errors' => [$duplicate], 'employee_id' => $employeeId];
        }

        return ['action' => 'create', 'employee_id' => $employeeId];
    }

    protected function apply(array $r, EmployeeImportRow $row, EmployeeImport $import, User $user): string
    {
        $loans = app(Loans::class);
        $employee = Employee::query()->findOrFail($row->employee_id);
        $codes = array_filter(array_map(fn ($c) => mb_strtolower(trim($c)), preg_split('/[;,]/', (string) ($r['guarantors'] ?? ''))));
        $guarantors = $codes === [] ? collect() : Employee::query()->get()->filter(fn (Employee $e) => in_array(mb_strtolower((string) $e->employee_code), $codes, true))
            ->sortBy(fn (Employee $e) => array_search(mb_strtolower((string) $e->employee_code), array_values($codes), true))->values();

        $loan = $loans->request($employee, $this->type($r['loan_type']), (float) $this->money($r['amount']), (int) $r['installments'], trim($r['reason']), $user,
            $guarantors->first(), $this->month($r['recovery_from']), $guarantors->slice(1)->values()->all());
        if ($this->status($r['status'] ?? null) !== 'approved') {
            return "Loan {$loan->number} sent for approval";
        }
        if ($loan->exceptionSummary() !== null) {
            return "Loan {$loan->number} sent for approval: it is beyond the loan policy, so the approver decides on the loan screen";
        }
        $changes = isset($r['interest_rate']) ? ['interest_rate' => (float) $this->money(rtrim($r['interest_rate'], '%'))] : [];
        $loan = $loans->approve($loan, 'Approved in '.$this->label()." #{$import->getKey()}", $user, $changes);
        if (isset($r['paid_out_on']) && $loan->status === 'approved') {
            $loan = $loans->markDisbursed($loan, $this->date($r['paid_out_on']), 'Paid out before or outside PeopleOS ('.$this->label()." #{$import->getKey()})");
        }

        return "Loan {$loan->number} {$loan->status}: EMI ".number_format((float) $loan->emi, 2).' from '.$loan->first_due_month;
    }

    private function type(?string $value): ?string
    {
        $given = mb_strtolower(trim((string) $value));
        foreach ((array) config('peopleos.loans.types', []) as $key => $label) {
            if ($given === $key || $given === mb_strtolower($label)) {
                return $key;
            }
        }

        return null;
    }

    private function status(?string $value): ?string
    {
        return match (mb_strtolower(trim((string) $value))) {
            '', 'requested', 'request', 'pending' => 'requested',
            'approved', 'approve' => 'approved',
            default => null,
        };
    }

    public function format(): array
    {
        [$code] = $this->sampleCodes(1);

        return [
            'columns' => [
                ['Employee code', 'Yes', "The employee's code.", $code],
                ['Loan type', 'Yes', 'A loan type key or name from the list below, offered by the employee\'s loan policy.', 'salary_advance'],
                ['Amount', 'Yes', 'The amount lent; within the policy\'s largest amount.', '50000'],
                ['Instalments', 'Yes', 'Number of monthly instalments; within the policy.', '10'],
                ['Recovery from (YYYY-MM)', 'Yes', 'The first payroll month an instalment is recovered; this month or later.', now()->addMonthNoOverflow()->format('Y-m')],
                ['Interest % a year', 'No', 'Only with status approved, and only when the loan policy lets the approver change the rate; blank = the policy\'s rate.', ''],
                ['Reason', 'Yes', 'Why the loan is given.', 'Medical emergency'],
                ['Guarantor codes', 'No', 'Employee codes of guarantors, separated by ";", when the policy asks for them.', ''],
                ['Status (requested/approved)', 'No', 'requested (blank): sent for approval on the loan screen. approved: you approve it now, as on the loan screen.', 'requested'],
                ['Paid out on', 'No', 'With status approved: the date it was paid out before or outside PeopleOS (payroll then never pays it out).', ''],
            ],
            'codes' => (array) config('peopleos.loans.types'),
            'codes_title' => 'Loan types',
            'notes' => [
                'Each row raises one loan under the employee\'s loan policy (amount, instalments, open loans, guarantors, service); the same open loan is skipped.',
                'Approving needs payroll in your scope and is never for your own loan. A loan beyond the policy stays requested for the approver to decide.',
                'Instalments are recovered by payroll from the recovery month. A loan paid by payroll is paid out with the next run unless "Paid out on" is given.',
            ],
        ];
    }

    protected function examples(): array
    {
        [$first, $second] = $this->sampleCodes(2);
        $month = now()->addMonthNoOverflow()->format('Y-m');

        return [
            [$first, 'salary_advance', '30000', '6', $month, '', 'Medical emergency', '', 'requested', ''],
            [$second, 'personal', '60000', '12', $month, '', 'Personal loan taken before go-live', '', 'approved', now()->subMonth()->startOfMonth()->toDateString()],
        ];
    }
}
