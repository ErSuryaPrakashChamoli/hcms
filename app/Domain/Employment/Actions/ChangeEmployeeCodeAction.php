<?php

namespace App\Domain\Employment\Actions;

use App\Domain\Employment\Exceptions\EmployeeCodeTakenException;
use App\Domain\Employment\Exceptions\ProfileChangeRefused;
use App\Domain\Employment\Models\Employee;
use App\Domain\Employment\Services\EmployeeCodeGenerator;
use App\Domain\Employment\Services\ProfileChangeGuard;
use App\Domain\Employment\Support\ChangeOrigin;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Scopes\AccessScope;
use App\Domain\Lifecycle\Services\Timeline;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * Data import DI-2 (Workforce → Employee code): the one write path that changes an employee's code. The new code follows
 * the hire form's rule (letters, digits, dashes and underscores, at most 32) and must be free in the whole tenant,
 * whatever the caller's scope, compared without case as MySQL's unique index compares (EmployeeCodeTakenException, as
 * when hiring). The change is audited on the employee (before → after, with the reason) and recorded on the timeline.
 * Needs employee.update within organisation scope. Nothing else changes: records refer to the employee, not the code.
 */
final class ChangeEmployeeCodeAction
{
    public const PERMISSION = 'employee.update';

    public function __construct(
        private readonly ProfileChangeGuard $guard,
        private readonly EmployeeCodeGenerator $codes,
        private readonly Timeline $timeline,
    ) {}

    /** Why this employee cannot take this code (format, or held by another employee of the tenant), or null. */
    public function problem(Employee $employee, string $code): ?string
    {
        $code = trim($code);
        if (($problem = $this->formatProblem($code)) !== null) {
            return $problem;
        }
        $holder = $this->codes->holder($code);

        return $holder !== null && (int) $holder->getKey() !== (int) $employee->getKey() ? (new EmployeeCodeTakenException($code))->getMessage() : null;
    }

    public function handle(Employee $employee, string $code, User $actor, ?string $reason = null, ?ChangeOrigin $origin = null): Employee
    {
        $this->guard->authorize($actor, $employee, self::PERMISSION, $origin);
        $code = trim($code);
        if (($problem = $this->formatProblem($code)) !== null) {
            throw new ProfileChangeRefused($problem);
        }

        return DB::transaction(function () use ($employee, $code, $reason, $origin) {
            $locked = Employee::query()->withoutGlobalScope(AccessScope::class)->whereKey($employee->getKey())->lockForUpdate()->firstOrFail();
            $old = (string) $locked->employee_code;
            if ($old === $code) {
                return $locked;
            }
            // Checked again under the lock; the unique index on (tenant, code) is the last word under contention.
            $holder = $this->codes->holder($code);
            if ($holder !== null && (int) $holder->getKey() !== (int) $locked->getKey()) {
                throw new EmployeeCodeTakenException($code);
            }
            // Attendance devices enrolled with the old code keep finding the employee: with no device user id of its own,
            // the old code becomes it (unless another employee already uses that id). Punches match the device user id first.
            $attributes = ['employee_code' => $code];
            $device = $locked->getAttributes()['device_user_id'] ?? null;
            $keepsDevice = ($device === null || $device === '')
                && ! Employee::query()->withoutGlobalScope(AccessScope::class)->where('device_user_id', $old)->whereKeyNot($locked->getKey())->exists();
            if ($keepsDevice) {
                $attributes['device_user_id'] = $old;
            }
            $locked->withAuditReason($reason, $origin?->reference)->update($attributes);
            $this->timeline->record($locked, 'employee_code', 'Employee code changed', now(), "{$old} → {$code}".($keepsDevice ? "\nAttendance devices: {$old} is kept as the device user id." : '').($reason ? "\nReason: {$reason}" : ''), $locked,
                ['operation' => 'updated', 'before' => $old, 'after' => $code] + ($origin?->toArray() ?? []));

            return $locked;
        });
    }

    /** The hire form's rule for a code typed by hand. */
    private function formatProblem(string $code): ?string
    {
        $validator = Validator::make(['code' => $code], ['code' => ['required', 'string', 'max:32', 'alpha_dash']], [], ['code' => 'new employee code']);

        return $validator->fails() ? $validator->errors()->first() : null;
    }
}
