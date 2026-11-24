<?php

namespace App\Domain\Organisation\Resolution;

use App\Domain\Configuration\Applicability\ConfigurationType;
use App\Domain\Configuration\Applicability\ConfigurationTypes;
use App\Domain\Employment\Models\Employee;
use App\Domain\Organisation\Models\Company;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * The masters a typed value can refer to. Every configuration type registered in config('peopleos.configuration_types')
 * (the applicability framework's registry: departments, designations, grades, leave types, shifts, salary components, …)
 * is resolvable with no further setup, using that registry's name and code columns, owning company and permission. So
 * there is one list of masters for applicability, overrides, cloning and imports. Companies and employees are added here
 * because they are not configuration.
 */
final class MasterCatalogue
{
    public const COMPANY = 'company';

    public const EMPLOYEE = 'employee';

    /**
     * Masters a reviewer may create from an import when a value matches nothing: those whose records need only a name and
     * a code (and, for company-owned units, the company). Anything richer is created on its own screen.
     */
    public const CREATABLE = [
        'department', 'designation', 'level', 'grade', 'business_unit', 'division', 'team', 'location', 'cost_centre',
        'profit_centre', 'employment_type', 'employee_category', 'work_mode', 'job_family', 'region', 'business_region',
    ];

    /** @var array<string, MasterSpec>|null */
    private ?array $specs = null;

    public function __construct(private readonly ConfigurationTypes $types) {}

    /** @return array<string, MasterSpec> */
    public function all(): array
    {
        if ($this->specs !== null) {
            return $this->specs;
        }

        $specs = [
            self::COMPANY => new MasterSpec(self::COMPANY, 'Company', Company::class, ['name', 'legal_name'], 'code', null, false, [], 'organisation.update', false),
            self::EMPLOYEE => new MasterSpec(self::EMPLOYEE, 'Employee', Employee::class, ['name'], 'employee_code', null, false, [], null, false),
        ];
        foreach ($this->types->all() as $key => $type) {
            $specs[$key] ??= $this->fromType($type);
        }

        return $this->specs = $specs;
    }

    public function has(string $keyOrClass): bool
    {
        return $this->find($keyOrClass) !== null;
    }

    /** A master by key ('department') or model class (Department::class). */
    public function for(string $keyOrClass): MasterSpec
    {
        return $this->find($keyOrClass) ?? throw new InvalidArgumentException("\"{$keyOrClass}\" is not a master that imports can refer to.");
    }

    /** @param  class-string<Model>|string  $keyOrClass */
    public function keyFor(string $keyOrClass): string
    {
        return $this->for($keyOrClass)->key;
    }

    private function find(string $keyOrClass): ?MasterSpec
    {
        $all = $this->all();
        if (isset($all[$keyOrClass])) {
            return $all[$keyOrClass];
        }
        foreach ($all as $spec) {
            if ($spec->model === $keyOrClass) {
                return $spec;
            }
        }

        return null;
    }

    private function fromType(ConfigurationType $type): MasterSpec
    {
        $hints = array_values(array_filter(array_keys($type->references), fn (string $column) => ! str_contains($column, '.')));
        if ($type->ownerColumn !== null) {
            $hints[] = $type->ownerColumn;
        }

        return new MasterSpec(
            key: $type->key,
            label: $type->label,
            model: $type->model,
            nameColumns: [$type->nameColumn],
            codeColumn: $type->codeColumn,
            ownerColumn: $type->ownerColumn,
            applicability: true,
            hintColumns: array_values(array_unique($hints)),
            permission: $type->permission,
            creatable: in_array($type->key, self::CREATABLE, true),
        );
    }
}
