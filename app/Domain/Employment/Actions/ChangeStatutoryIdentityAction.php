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
 * Phase 12: the one write path for an employee's statutory identifiers — PAN, Aadhaar reference, UAN,
 * PF number, ESIC number (Employment's EmployeeStatutoryDetail; highly sensitive, masked in audit).
 * Applicability flags and the tax regime are a separate action (ChangeStatutoryApplicabilityAction).
 * Only the fields given change; the action authorizes, validates, locks the employee, audits and
 * emits employee.statutory_identity_changed without values.
 */
final class ChangeStatutoryIdentityAction
{
    use ChangesProfileData;

    public const FIELDS = ['pan', 'aadhaar_reference', 'uan', 'pf_number', 'esic_number'];

    public function __construct(private readonly ProfileChangeGuard $guard) {}

    /** @param  array<string, mixed>  $data  any of FIELDS */
    public function handle(Employee $employee, array $data, User $actor, ?string $reason = null, ?ChangeOrigin $origin = null): EmployeeStatutoryDetail
    {
        $this->guard->authorize($actor, $employee, 'employee.sensitive.update');
        $data = array_intersect_key($data, array_flip(self::FIELDS));
        $clean = $this->validated($data, [
            'pan' => ['nullable', 'string', 'size:10', 'regex:/^[A-Z]{5}[0-9]{4}[A-Z]$/'],
            'aadhaar_reference' => ['nullable', 'string', 'max:16'],
            'uan' => ['nullable', 'string', 'max:12'],
            'pf_number' => ['nullable', 'string', 'max:32'],
            'esic_number' => ['nullable', 'string', 'max:32'],
        ]);

        return DB::transaction(function () use ($employee, $clean, $reason, $origin) {
            $employee = $this->lockEmployee($employee);
            $detail = EmployeeStatutoryDetail::query()->withoutGlobalScope(AccessScope::class)->where('employee_id', $employee->id)->lockForUpdate()->first() ?? new EmployeeStatutoryDetail(['employee_id' => $employee->id, 'pf_applicable' => true, 'pt_applicable' => true]);
            $operation = $detail->exists ? 'updated' : 'added';
            $detail->fill($clean)->withAuditReason($reason, $origin?->reference)->save();
            $this->recordChange($employee, 'employee.statutory_identity_changed', 'statutory', 'Statutory identifiers '.$operation, $detail, $operation, $origin);

            return $detail;
        });
    }
}
