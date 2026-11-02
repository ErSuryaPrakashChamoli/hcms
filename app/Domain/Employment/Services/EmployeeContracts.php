<?php

namespace App\Domain\Employment\Services;

use App\Domain\Employment\Models\Employee;
use App\Domain\Employment\Models\EmployeeContract;
use App\Domain\Employment\Support\ChangeOrigin;
use App\Domain\Identity\Models\User;
use App\Domain\Lifecycle\Services\Timeline;
use App\Domain\Partners\Models\Vendor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use InvalidArgumentException;

/**
 * Contracts of contract staff (Workforce → Contract): the agency (an active vendor) that supplies the person, the contract
 * number and its dates. The only write path, for the screen and the upload alike: the change needs employee.update for the
 * employee (ProfileChangeGuard: permission and organisation scope), is validated, audited, and a new contract goes on the
 * employee's timeline. Contracts are never deleted; an ended one keeps its end date.
 */
final class EmployeeContracts
{
    public const PERMISSION = 'employee.update';

    public function __construct(private readonly ProfileChangeGuard $guard, private readonly Timeline $timeline) {}

    /**
     * What is wrong with these values for this employee (field => messages), without saving anything.
     *
     * @param  array<string, mixed>  $data  contract_number, vendor_id, start_date, end_date, notes
     * @return array<string, list<string>>
     */
    public function problems(Employee $employee, array $data, ?EmployeeContract $contract = null): array
    {
        return $this->validator($employee, $data, $contract)->errors()->toArray();
    }

    /** @param array<string, mixed> $data contract_number, vendor_id, start_date, end_date, notes (a contract's values not given are kept) */
    public function save(Employee $employee, array $data, User $by, ?EmployeeContract $contract = null, ?string $reason = null, ?ChangeOrigin $origin = null): EmployeeContract
    {
        $this->guard->authorize($by, $employee, self::PERMISSION, $origin);
        if ($contract !== null && (int) $contract->employee_id !== (int) $employee->getKey()) {
            throw new InvalidArgumentException('This contract belongs to another employee.');
        }
        $values = $this->validator($employee, $data, $contract)->validate();

        return DB::transaction(function () use ($employee, $values, $contract, $reason, $origin) {
            $contract ??= new EmployeeContract(['employee_id' => $employee->getKey()]);
            $isNew = ! $contract->exists;
            $contract->fill($values)->withAuditReason($reason, $origin?->reference)->save();
            if ($isNew) {
                $vendor = $contract->vendor_id ? Vendor::query()->find($contract->vendor_id)?->name : null;
                $this->timeline->record($employee, 'contract', 'Contract recorded', $contract->start_date,
                    trim("{$contract->contract_number}".($vendor ? " with {$vendor}" : '').', '.$contract->start_date->format('j M Y').' – '.($contract->end_date?->format('j M Y') ?? 'open')),
                    $contract, ['operation' => 'created'] + ($origin?->toArray() ?? []));
            }

            return $contract;
        });
    }

    private function validator(Employee $employee, array $data, ?EmployeeContract $contract): \Illuminate\Validation\Validator
    {
        $data = array_intersect_key($data, array_flip(['contract_number', 'vendor_id', 'start_date', 'end_date', 'notes']));
        if ($contract !== null) {
            $data += ['contract_number' => $contract->contract_number, 'vendor_id' => $contract->vendor_id, 'start_date' => $contract->start_date?->toDateString(),
                'end_date' => $contract->end_date?->toDateString(), 'notes' => $contract->notes];
        }

        $validator = Validator::make($data, [
            'contract_number' => ['required', 'string', 'max:64'],
            'vendor_id' => ['nullable', 'integer'],
            'start_date' => ['required', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ], ['end_date.after_or_equal' => 'The contract cannot end before it starts.'], ['contract_number' => 'contract number']);

        $validator->after(function ($validator) use ($employee, $data, $contract): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }
            $vendorId = $data['vendor_id'] ?? null;
            if ($vendorId !== null) {
                $vendor = Vendor::query()->find($vendorId);
                if ($vendor === null) {
                    $validator->errors()->add('vendor_id', 'The vendor does not exist.');
                } elseif (! $vendor->isActive() && (int) $vendorId !== (int) ($contract?->vendor_id ?? 0)) {
                    $validator->errors()->add('vendor_id', "The vendor {$vendor->code} is inactive.");
                }
            }
            $taken = EmployeeContract::query()->withoutGlobalScopes()->where('tenant_id', $employee->tenant_id)->where('employee_id', $employee->getKey())
                ->whereRaw('lower(contract_number) = ?', [mb_strtolower((string) $data['contract_number'])])
                ->when($contract?->exists, fn ($q) => $q->whereKeyNot($contract->getKey()))->exists();
            if ($taken) {
                $validator->errors()->add('contract_number', "The employee already has contract {$data['contract_number']}.");
            }
        });

        return $validator;
    }
}
