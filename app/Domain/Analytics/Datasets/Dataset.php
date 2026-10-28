<?php

namespace App\Domain\Analytics\Datasets;

use App\Domain\Identity\Models\User;
use Closure;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A reportable dataset (§84): a tenant-scoped query plus a field catalogue. Field definition:
 * ['label' => ..., 'type' => string|number|date|boolean, 'value' => fn (Model $row): mixed, 'sensitive' => bool].
 * Sensitive fields need the dataset's sensitive permission and are dropped otherwise.
 */
abstract class Dataset
{
    abstract public function key(): string;

    abstract public function label(): string;

    /** Section the dataset is listed under in the report builder and the report library. */
    public function category(): string
    {
        return 'Other';
    }

    /** Permission(s) required to use the dataset at all. */
    abstract public function permissions(): array;

    /** Permission unlocking fields flagged sensitive (null = none are sensitive). */
    public function sensitivePermission(): ?string
    {
        return null;
    }

    /** @return array<string, array<string, mixed>> */
    abstract public function fields(): array;

    abstract public function query(): Builder;

    public function allowedFor(User $user): bool
    {
        foreach ($this->permissions() as $permission) {
            if ($user->hasPermission($permission)) {
                return true;
            }
        }

        return $user->is_platform_admin;
    }

    /** @return array<string, array<string, mixed>> fields the user may read */
    public function fieldsFor(?User $user): array
    {
        // Phase 14: fail closed — without a user (console, scheduler, feeds) no sensitive or permissioned field is available.
        $sensitiveOk = $this->sensitivePermission() === null || ($user !== null && ($user->hasPermission($this->sensitivePermission()) || $user->is_platform_admin));

        // A field may also name its own permission (Phase 11: compensation fields need compensation.view).
        return array_filter($this->fields(), fn ($f) => ($sensitiveOk || empty($f['sensitive']))
            && (empty($f['permission']) || ($user !== null && ($user->is_platform_admin || $user->hasPermission($f['permission'])))));
    }

    public function value(string $field, Model $row): mixed
    {
        $definition = $this->fields()[$field] ?? null;
        if ($definition === null) {
            return null;
        }
        $value = ($definition['value'])($row);

        return $value instanceof DateTimeInterface ? $value->format('Y-m-d') : ($value instanceof \BackedEnum ? $value->value : $value);
    }

    /** @return array<string, array{label: string, type: string}> */
    public function catalogue(?User $user = null): array
    {
        return array_map(fn ($f) => ['label' => $f['label'], 'type' => $f['type']], $this->fieldsFor($user));
    }

    /**
     * The usual "who" columns for rows that hang off an employee; $employee resolves the row's employee.
     * Organisation columns are the employee's current position, as in the employees dataset.
     *
     * @return array<string, array<string, mixed>>
     */
    protected function employeeFields(Closure $employee): array
    {
        $position = fn ($row) => $employee($row)?->currentPosition;

        return [
            'employee_code' => ['label' => 'Employee code', 'type' => 'string', 'value' => fn ($row) => $employee($row)?->employee_code],
            'name' => ['label' => 'Name', 'type' => 'string', 'value' => fn ($row) => $employee($row)?->person?->full_name],
            // Code and name together, so grouping per person never merges two people who share a name.
            'employee' => ['label' => 'Employee (code · name)', 'type' => 'string', 'value' => fn ($row) => $employee($row) ? implode(' · ', array_filter([$employee($row)->employee_code, $employee($row)->person?->full_name])) : null],
            'company' => ['label' => 'Company', 'type' => 'string', 'value' => fn ($row) => $position($row)?->company?->name],
            'location' => ['label' => 'Location', 'type' => 'string', 'value' => fn ($row) => $position($row)?->location?->name],
            'department' => ['label' => 'Department', 'type' => 'string', 'value' => fn ($row) => $position($row)?->department?->name],
            'designation' => ['label' => 'Designation', 'type' => 'string', 'value' => fn ($row) => $position($row)?->designation?->name],
            'employee_status' => ['label' => 'Employee status', 'type' => 'string', 'value' => fn ($row) => $employee($row)?->lifecycle_state],
        ];
    }

    /** @return list<string> eager loads for employeeFields() under a relation path such as 'employee' or 'entry.employee' */
    protected static function employeeWith(string $path): array
    {
        return ["{$path}.person", "{$path}.currentPosition.company", "{$path}.currentPosition.location", "{$path}.currentPosition.department", "{$path}.currentPosition.designation"];
    }

    /** Whole days from today to $date (negative once it has passed). */
    protected static function daysFromToday(?DateTimeInterface $date): ?int
    {
        return $date === null ? null : (int) now()->startOfDay()->diffInDays(Carbon::parse($date)->startOfDay(), false);
    }
}
