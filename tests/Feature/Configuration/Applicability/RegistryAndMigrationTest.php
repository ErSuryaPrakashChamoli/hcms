<?php

use App\Domain\Audit\Services\AuditIntegrityVerifier;
use App\Domain\Configuration\Applicability\ApplicabilitySelection;
use App\Domain\Configuration\Applicability\ConfigurationTypes;
use App\Domain\Configuration\Applicability\EntityApplicability;
use App\Domain\Configuration\Overrides\ConfigurationOverrides;
use App\Domain\Employment\Actions\HireEmployeeAction;
use App\Domain\Offboarding\Support\DataInventory;
use App\Domain\Organisation\Models\Company;
use App\Domain\Organisation\Models\Department;
use App\Domain\Organisation\Models\Designation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| The registry every module plugs into is consistent with the schema and the permission catalogue (so a misregistered type
| fails here, not in production), and the migration is safe: it changes no existing table, and rolling it back leaves
| every existing record working as before (acceptance 13.5, Part 11).
*/

beforeEach(function () {
    $this->travelTo('2026-11-23 10:00:00');
    $this->tenant = provisionTenant();
    actAsTenant($this->tenant);
    $this->admin = tenantUser($this->tenant, ['*']);
    $this->actingAs($this->admin);
});

it('registers only real models, columns, permissions, references and usages', function () {
    $types = app(ConfigurationTypes::class);
    $keys = collect(config('peopleos.permissions'))->flatMap(fn ($group) => array_keys($group))->values()->all();

    expect(count($types->all()))->toBeGreaterThanOrEqual(50);
    foreach ($types->all() as $type) {
        expect(class_exists($type->model))->toBeTrue("{$type->key}: {$type->model}");
        $table = (new $type->model)->getTable();
        expect(Schema::hasTable($table))->toBeTrue("{$type->key}: table {$table}")
            ->and(Schema::hasColumn($table, $type->nameColumn))->toBeTrue("{$type->key}: name column")
            ->and($type->codeColumn === null || Schema::hasColumn($table, $type->codeColumn))->toBeTrue("{$type->key}: code column")
            ->and($type->ownerColumn === null || Schema::hasColumn($table, $type->ownerColumn))->toBeTrue("{$type->key}: owner column")
            ->and(in_array($type->permission, $keys, true))->toBeTrue("{$type->key}: permission {$type->permission}");
        foreach ($type->references as $column => $target) {
            expect(Schema::hasColumn($table, $column))->toBeTrue("{$type->key}: reference column {$column}")
                ->and($types->has($target))->toBeTrue("{$type->key}: reference target {$target}");
        }
        foreach ($type->usages as $usage) {
            expect(Schema::hasColumns($usage['table'], [$usage['column'], $usage['company']]))->toBeTrue("{$type->key}: usage {$usage['table']}.{$usage['column']}");
        }
        if (is_array($type->overrides)) {
            foreach (array_keys($type->overrides) as $column) {
                expect(Schema::hasColumn($table, $column))->toBeTrue("{$type->key}: override column {$column}");
            }
        }
    }
    expect(in_array('configuration.share', $keys, true))->toBeTrue()
        ->and(in_array('configuration.override', $keys, true))->toBeTrue();
});

it('classifies its tables in the data inventory (exported and deleted with the organisation)', function () {
    $inventory = collect(DataInventory::TABLES)->flatten()->all();

    expect($inventory)->toContain('configuration_applicability', 'configuration_applicability_companies', 'configuration_overrides');
});

it('rolls back without touching existing records: they work exactly as before', function () {
    $mk = Company::factory()->create(['code' => 'MK']);
    $fe = Company::factory()->create(['code' => 'FE']);
    $hr = Department::factory()->create(['code' => 'HR', 'company_id' => $mk->id]);
    $designation = Designation::factory()->create(['code' => 'ENG']);
    app(EntityApplicability::class)->apply($designation, ApplicabilitySelection::single($mk->id), $this->admin);
    $employee = app(HireEmployeeAction::class)->handle(['first_name' => 'Asha', 'last_name' => 'Roll'], ['joining_date' => '2026-11-23'],
        ['company_id' => $mk->id, 'department_id' => $hr->id, 'designation_id' => $designation->id]);
    $snapshot = fn () => [
        DB::table('departments')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all(),
        DB::table('designations')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all(),
        DB::table('employee_positions')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all(),
    ];
    $before = $snapshot();

    $migration = require database_path('migrations/2027_02_06_200001_create_configuration_applicability_tables.php');
    $migration->down();
    EntityApplicability::flushMemo();
    ConfigurationOverrides::flushMemo();

    expect(Schema::hasTable('configuration_applicability'))->toBeFalse()
        ->and(Schema::hasTable('configuration_overrides'))->toBeFalse()
        ->and($snapshot())->toBe($before);

    $migration->up();
    EntityApplicability::flushMemo();
    // Back on the earlier meaning: the designation applies everywhere again, the department to its company.
    expect(app(EntityApplicability::class)->reach($designation))->toBeNull()
        ->and(app(EntityApplicability::class)->reach($hr))->toBe([$mk->id])
        ->and($employee->positions()->value('designation_id'))->toBe($designation->id)
        ->and(app(AuditIntegrityVerifier::class)->verify($this->tenant->id)['valid'])->toBeTrue();
});
