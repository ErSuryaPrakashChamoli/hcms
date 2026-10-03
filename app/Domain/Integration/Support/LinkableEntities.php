<?php

namespace App\Domain\Integration\Support;

use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Scopes\AccessScope;
use App\Domain\Integration\Exceptions\IntegrationRejected;
use App\Domain\Organisation\Models\BusinessUnit;
use App\Domain\Organisation\Models\Company;
use App\Domain\Organisation\Models\Department;
use App\Domain\Organisation\Models\Designation;
use App\Domain\Organisation\Models\EmploymentType;
use App\Domain\Organisation\Models\Grade;
use App\Domain\Organisation\Models\Location;
use App\Domain\People\Models\Person;
use App\Domain\Workforce\Models\Position;
use Illuminate\Database\Eloquent\Model;

/**
 * Phase 14: the PeopleOS records an external reference or mapping may point to, by stable alias.
 * Records are found by PeopleOS id or PeopleOS code (employee code, organisation code) inside the
 * bound tenant; system context, so organisation scope does not hide records from integration lookups.
 */
final class LinkableEntities
{
    /** alias => [model class, code column or null] */
    public const TYPES = [
        'employee' => [Employee::class, 'employee_code'],
        'person' => [Person::class, null],
        'company' => [Company::class, 'code'],
        'business_unit' => [BusinessUnit::class, 'code'],
        'department' => [Department::class, 'code'],
        'location' => [Location::class, 'code'],
        'designation' => [Designation::class, 'code'],
        'grade' => [Grade::class, 'code'],
        'employment_type' => [EmploymentType::class, 'code'],
        'position' => [Position::class, 'code'],
    ];

    public function classFor(string $alias): string
    {
        return self::TYPES[$alias][0] ?? throw new IntegrationRejected("Unknown entity type [{$alias}].", 'unknown_entity_type');
    }

    public function aliasFor(Model $model): string
    {
        foreach (self::TYPES as $alias => [$class]) {
            if ($model instanceof $class) {
                return $alias;
            }
        }
        throw new IntegrationRejected('That record cannot carry an external reference.', 'unknown_entity_type');
    }

    public function find(string $alias, int $id): ?Model
    {
        $class = $this->classFor($alias);

        return AccessScope::withoutScoping(fn () => $class::query()->find($id));
    }

    /** By PeopleOS code (preferred for integrations) or numeric PeopleOS id. */
    public function findByCodeOrId(string $alias, string|int $value): ?Model
    {
        [$class, $column] = self::TYPES[$alias] ?? throw new IntegrationRejected("Unknown entity type [{$alias}].", 'unknown_entity_type');

        return AccessScope::withoutScoping(function () use ($class, $column, $value) {
            if ($column !== null) {
                $byCode = $class::query()->where($column, (string) $value)->first();
                if ($byCode !== null) {
                    return $byCode;
                }
            }

            return is_numeric($value) ? $class::query()->find((int) $value) : null;
        });
    }
}
