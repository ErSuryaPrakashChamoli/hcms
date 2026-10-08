<?php

namespace App\Domain\Employment\Concerns;

use App\Domain\Employment\Events\EmploymentEvent;
use App\Domain\Employment\Exceptions\ProfileChangeRefused;
use App\Domain\Employment\Models\Employee;
use App\Domain\Employment\Support\ChangeOrigin;
use App\Domain\Identity\Scopes\AccessScope;
use App\Domain\Lifecycle\Services\Timeline;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Phase 12: what every People / Employment profile change action does the same way — validate with
 * the same rules for every caller, lock the employee row (one change at a time per employee), and
 * record the change as a timeline entry and an employment event that carry no values.
 */
trait ChangesProfileData
{
    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $rules
     * @return array<string, mixed>
     */
    protected function validated(array $data, array $rules): array
    {
        try {
            return Validator::make($data, $rules)->validate();
        } catch (ValidationException $e) {
            throw new ProfileChangeRefused(collect($e->errors())->flatten()->first() ?? 'The change is not valid.');
        }
    }

    protected function lockEmployee(Employee $employee): Employee
    {
        return Employee::query()->withoutGlobalScope(AccessScope::class)->whereKey($employee->getKey())->lockForUpdate()->firstOrFail();
    }

    protected function recordChange(Employee $employee, string $event, string $category, string $title, Model $subject, string $operation, ?ChangeOrigin $origin): void
    {
        $origin ??= ChangeOrigin::employee360();
        app(Timeline::class)->record($employee, $category, $title, now(), null, $subject, ['operation' => $operation] + $origin->toArray());
        EmploymentEvent::dispatch($event, $employee, $subject, ['operation' => $operation] + $origin->toArray());
    }
}
