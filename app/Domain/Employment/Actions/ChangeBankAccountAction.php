<?php

namespace App\Domain\Employment\Actions;

use App\Domain\Employment\Concerns\ChangesProfileData;
use App\Domain\Employment\Exceptions\ProfileChangeRefused;
use App\Domain\Employment\Models\Employee;
use App\Domain\Employment\Models\EmployeeBankAccount;
use App\Domain\Employment\Services\ProfileChangeGuard;
use App\Domain\Employment\Support\ChangeOrigin;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Scopes\AccessScope;
use Illuminate\Support\Facades\DB;

/**
 * Phase 12: the one write path for employee bank accounts (Employment owns them; Payroll reads them).
 * Employee 360, an approved HR service request and any future API call it. It authorizes the actor
 * itself (employee.sensitive.update, organisation scope), validates with one rule set, locks the
 * employee row, refuses a duplicate account, and audits (account number masked), records a timeline
 * entry and emits employee.bank_account_changed — never the account number.
 */
final class ChangeBankAccountAction
{
    use ChangesProfileData;

    public const PERMISSION = 'employee.sensitive.update';

    public function __construct(private readonly ProfileChangeGuard $guard) {}

    /** @param  array<string, mixed>  $data */
    public function add(Employee $employee, array $data, User $actor, ?string $reason = null, ?ChangeOrigin $origin = null): EmployeeBankAccount
    {
        $this->guard->authorize($actor, $employee, self::PERMISSION);
        $clean = $this->validated($data, $this->rules(true));

        return DB::transaction(function () use ($employee, $clean, $reason, $origin) {
            $employee = $this->lockEmployee($employee);
            $this->assertNotDuplicate($employee, (string) $clean['account_number']);
            $account = new EmployeeBankAccount($clean + ['employee_id' => $employee->id]);
            $account->withAuditReason($reason, $origin?->reference)->save();
            $this->recordChange($employee, 'employee.bank_account_changed', 'bank', 'Bank account added', $account, 'added', $origin);

            return $account;
        });
    }

    /** @param  array<string, mixed>  $data  account_number may be omitted to keep it */
    public function update(EmployeeBankAccount $account, array $data, User $actor, ?string $reason = null, ?ChangeOrigin $origin = null): EmployeeBankAccount
    {
        $employee = $this->employeeOf($account);
        $this->guard->authorize($actor, $employee, self::PERMISSION);
        $clean = $this->validated($data, $this->rules(false));
        if (blank($clean['account_number'] ?? null)) {
            unset($clean['account_number']);
        }

        return DB::transaction(function () use ($account, $employee, $clean, $reason, $origin) {
            $employee = $this->lockEmployee($employee);
            if (isset($clean['account_number'])) {
                $this->assertNotDuplicate($employee, (string) $clean['account_number'], $account->id);
            }
            $account->withAuditReason($reason, $origin?->reference)->update($clean);
            $this->recordChange($employee, 'employee.bank_account_changed', 'bank', 'Bank account updated', $account, 'updated', $origin);

            return $account;
        });
    }

    public function remove(EmployeeBankAccount $account, User $actor, ?string $reason = null, ?ChangeOrigin $origin = null): void
    {
        $employee = $this->employeeOf($account);
        $this->guard->authorize($actor, $employee, self::PERMISSION);

        DB::transaction(function () use ($account, $employee, $reason, $origin) {
            $employee = $this->lockEmployee($employee);
            $account->withAuditReason($reason, $origin?->reference)->delete();
            $this->recordChange($employee, 'employee.bank_account_changed', 'bank', 'Bank account removed', $account, 'removed', $origin);
        });
    }

    /** @return array<string, mixed> */
    public function rules(bool $adding): array
    {
        return [
            'account_holder_name' => ['required', 'string', 'max:255'],
            'bank_name' => ['required', 'string', 'max:255'],
            'branch_name' => ['nullable', 'string', 'max:255'],
            'ifsc' => ['nullable', 'string', 'max:16', 'regex:/^[A-Z]{4}0[A-Z0-9]{6}$/'],
            'account_number' => [$adding ? 'required' : 'nullable', 'string', 'max:34'],
            'account_type' => ['nullable', 'in:savings,current,salary'],
            'is_primary' => ['nullable', 'boolean'],
        ];
    }

    private function assertNotDuplicate(Employee $employee, string $number, ?int $except = null): void
    {
        // A locking read: under MySQL REPEATABLE READ a plain read could use a snapshot taken before
        // the employee lock was granted and miss an account another request has just added.
        $candidates = EmployeeBankAccount::query()->withoutGlobalScope(AccessScope::class)->where('employee_id', $employee->id)
            ->where('account_number_last4', mb_substr($number, -4))->when($except, fn ($q) => $q->whereKeyNot($except))->lockForUpdate()->get();
        if ($candidates->contains(fn (EmployeeBankAccount $a) => (string) $a->account_number === $number)) {
            throw new ProfileChangeRefused('That bank account is already on file for this employee.');
        }
    }

    private function employeeOf(EmployeeBankAccount $account): Employee
    {
        return Employee::query()->withoutGlobalScope(AccessScope::class)->findOrFail($account->employee_id);
    }
}
