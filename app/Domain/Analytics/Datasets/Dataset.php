<?php

namespace App\Domain\Analytics\Datasets;

use App\Domain\Identity\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * A reportable dataset (§84): a tenant-scoped query plus a field catalogue. Field definition:
 * ['label' => ..., 'type' => string|number|date|boolean, 'value' => fn (Model $row): mixed, 'sensitive' => bool].
 * Sensitive fields need the dataset's sensitive permission and are dropped otherwise.
 */
abstract class Dataset
{
    abstract public function key(): string;

    abstract public function label(): string;

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
        $sensitiveOk = $user === null || $this->sensitivePermission() === null || $user->hasPermission($this->sensitivePermission()) || $user->is_platform_admin;

        return array_filter($this->fields(), fn ($f) => $sensitiveOk || empty($f['sensitive']));
    }

    public function value(string $field, Model $row): mixed
    {
        $definition = $this->fields()[$field] ?? null;
        if ($definition === null) {
            return null;
        }
        $value = ($definition['value'])($row);

        return $value instanceof \DateTimeInterface ? $value->format('Y-m-d') : ($value instanceof \BackedEnum ? $value->value : $value);
    }

    /** @return array<string, array{label: string, type: string}> */
    public function catalogue(?User $user = null): array
    {
        return array_map(fn ($f) => ['label' => $f['label'], 'type' => $f['type']], $this->fieldsFor($user));
    }
}
