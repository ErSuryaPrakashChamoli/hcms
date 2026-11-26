<?php

namespace App\Domain\Organisation\Resolution;

use App\Domain\Configuration\Applicability\ConfigurationType;
use App\Domain\Configuration\Applicability\ConfigurationTypes;
use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Models\User;
use App\Domain\Organisation\Models\Company;
use App\Domain\Partners\Models\Vendor;
use App\Domain\Performance\Models\Kra;
use App\Domain\Performance\Models\PerformanceCycle;
use App\Domain\Performance\Models\PerformanceLookup;
use App\Domain\Performance\Models\PerformanceUnit;
use App\Domain\Projects\Models\Customer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
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
        // Names first (2026-11-26): other lists a name and a code fully describe. Lists that carry rules (leave types, shifts,
        // salary structures, pay items, cycles, holiday calendars …) are never made from a bare name: a leave type without
        // entitlements or a shift without timings would quietly give wrong balances, attendance or pay. The review links to
        // their own screen instead.
        'skill', 'qualification', 'asset_category', 'customer', 'kra', 'perspective', 'initiative_type', 'pip_reason',
    ];

    /**
     * Names first (2026-11-26): other lists the uploads refer to, which are not configuration types (they apply to the
     * whole organisation): key => [label, model, permission to remember a name for every upload, fixed filter].
     *
     * @var array<string, array{0: string, 1: class-string<Model>, 2: ?string, 3: array<string, string>}>
     */
    public const OTHER = [
        'vendor' => ['Vendor', Vendor::class, 'partner.manage', []],
        'customer' => ['Customer', Customer::class, 'project.manage', []],
        'performance_cycle' => ['Performance cycle', PerformanceCycle::class, 'performance.manage', []],
        'kra' => ['KRA', Kra::class, 'performance.manage', []],
        'performance_unit' => ['Unit of measurement', PerformanceUnit::class, 'performance.manage', []],
        'perspective' => ['Perspective', PerformanceLookup::class, 'performance.manage', ['kind' => 'perspective']],
        'initiative_type' => ['Initiative type', PerformanceLookup::class, 'performance.manage', ['kind' => 'initiative_type']],
        'pip_reason' => ['Improvement plan reason', PerformanceLookup::class, 'performance.manage', ['kind' => 'pip_reason']],
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
        // Lists that uploads name but that are not configuration (no applicability): by name or code like the rest.
        foreach (self::OTHER as $key => [$label, $model, $permission, $where]) {
            $specs[$key] ??= new MasterSpec($key, $label, $model, ['name'], 'code', null, false, [], $permission, in_array($key, self::CREATABLE, true), $where);
        }

        return $this->specs = $specs;
    }

    /**
     * Whether the person may add a record of this master from an upload's review: the master's own create policy where it
     * has one (organisation units, designations, levels, grades), else the right that manages the list (its `.create`
     * sibling when that right is an `.update` one: people_setup.update → people_setup.create).
     */
    public function mayCreate(User $actor, MasterSpec $spec, ?int $companyId = null): bool
    {
        if (Gate::getPolicyFor($spec->model) !== null) {
            return Gate::forUser($actor)->allows('create', $spec->model);
        }
        if ($spec->permission === null) {
            return false;
        }
        $permission = str_ends_with($spec->permission, '.update') ? Str::beforeLast($spec->permission, '.').'.create' : $spec->permission;

        return $actor->hasPermission($permission, $companyId);
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
