<?php

namespace App\Domain\Employment\Actions;

use App\Domain\Employment\Concerns\ChangesProfileData;
use App\Domain\Employment\Models\Employee;
use App\Domain\Employment\Models\EmployeeStatutoryDetail;
use App\Domain\Employment\Services\ProfileChangeGuard;
use App\Domain\Employment\Support\ChangeOrigin;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Scopes\AccessScope;
use Illuminate\Support\Facades\DB;

/**
 * Phase 12: the one write path for an employee's statutory applicability — PF / ESIC / PT applicable,
 * PT state and tax regime on EmployeeStatutoryDetail. HR maintains these (they drive Payroll's
 * statutory treatment, which stays Payroll's); they are not offered as an employee service.
 */
final class ChangeStatutoryApplicabilityAction
{
    use ChangesProfileData;

    public const FIELDS = ['pf_applicable', 'esic_applicable', 'pt_applicable', 'tax_regime', 'pt_state_code'];

    public function __construct(private readonly ProfileChangeGuard $guard) {}

    /** @param  array<string, mixed>  $data  any of FIELDS */
    public function handle(Employee $employee, array $data, User $actor, ?string $reason = null, ?ChangeOrigin $origin = null): EmployeeStatutoryDetail
    {
        $this->guard->authorize($actor, $employee, 'employee.sensitive.update');
        $data = array_intersect_key($data, array_flip(self::FIELDS));
        $clean = $this->validated($data, [
            'pf_applicable' => ['nullable', 'boolean'], 'esic_applicable' => ['nullable', 'boolean'], 'pt_applicable' => ['nullable', 'boolean'],
            'tax_regime' => ['nullable', 'in:old,new'], 'pt_state_code' => ['nullable', 'string', 'max:16'],
        ]);

        return DB::transaction(function () use ($employee, $clean, $reason, $origin) {
            $employee = $this->lockEmployee($employee);
            $detail = EmployeeStatutoryDetail::query()->withoutGlobalScope(AccessScope::class)->where('employee_id', $employee->id)->lockForUpdate()->first() ?? new EmployeeStatutoryDetail(['employee_id' => $employee->id]);
            $operation = $detail->exists ? 'updated' : 'added';
            $detail->fill($clean)->withAuditReason($reason, $origin?->reference)->save();
            $this->recordChange($employee, 'employee.statutory_applicability_changed', 'statutory', 'Statutory applicability '.$operation, $detail, $operation, $origin);

            return $detail;
        });
    }
}
