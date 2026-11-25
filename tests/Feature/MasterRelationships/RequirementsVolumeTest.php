<?php

use App\Domain\Configuration\Models\Policy;
use App\Domain\Configuration\Models\PolicyAssignmentRule;
use App\Domain\Configuration\Services\Policies;
use App\Domain\Employment\Models\Employee;
use App\Domain\Employment\Requirements\EmploymentRequirements;
use App\Domain\Employment\Requirements\EmploymentRequirementsPreflight;
use App\Domain\Employment\Requirements\PositionRequirements;
use App\Domain\Organisation\Models\Company;
use App\Domain\Organisation\Models\Department;
use App\Domain\Organisation\Models\Designation;
use App\Domain\Organisation\Models\Grade;
use App\Domain\Organisation\Models\Level;
use App\Domain\People\Models\Person;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/*
| Employment data requirements at volume: the pre-flight (every employee the policy reaches, every rule on) and the
| batch check payroll readiness uses, for 1,000 and 10,000 employees; since remediation I.13 also the queued pre-flight
| (a recorded run through the job, with its progress updates), measured end to end, up to 100,000. Above 10,000 the
| readiness batch is not measured (its caller loads every employee at once). Off by default (minutes of set-up):
| REQUIREMENTS_VOLUME=1000,10000 vendor/bin/pest tests/Feature/MasterRelationships/RequirementsVolumeTest.php
| Results are appended as JSON lines to REQUIREMENTS_VOLUME_OUT (also against MySQL with DB_CONNECTION=mysql).
*/

beforeEach(function () {
    if (blank(env('REQUIREMENTS_VOLUME'))) {
        $this->markTestSkipped('Set REQUIREMENTS_VOLUME=1000,10000 to measure the employment data requirements at volume.');
    }
    $this->travelTo('2026-11-24 10:00:00');
    $this->tenant = provisionTenant();
    actAsTenant($this->tenant);
    $this->admin = tenantUser($this->tenant, ['*']);
    $this->actingAs($this->admin);
});

it('runs the pre-flight and the readiness batch check at volume', function (int $size) {
    $company = Company::factory()->create(['name' => 'Markedge Technologies', 'code' => 'MK']);
    $levels = collect(range(1, 5))->map(fn ($i) => Level::query()->create(['name' => "L{$i}", 'code' => "VL{$i}"]));
    $grades = $levels->map(fn ($level, $i) => Grade::query()->create(['name' => 'T'.($i + 1), 'code' => 'VT'.($i + 1), 'level_id' => $level->id]));
    $departments = collect(range(1, 10))->map(fn ($i) => Department::query()->create(['name' => "Department {$i}", 'code' => "VD{$i}"]));
    $designations = collect(range(1, 20))->map(fn ($i) => Designation::query()->create(['name' => "Designation {$i}", 'code' => "VDS{$i}",
        'department_id' => $departments[$i % 10]->id, 'level_id' => $levels[$i % 5]->id, 'grade_id' => $grades[$i % 5]->id]));

    // People without model events (no audit rows): set-up only, the measured code reads them as usual.
    $tenantId = $this->tenant->id;
    Model::withoutEvents(function () use ($size, $tenantId, $company, $grades, $levels, $departments, $designations) {
        foreach (array_chunk(range(1, $size), 500) as $chunk) {
            $positions = [];
            foreach ($chunk as $i) {
                $person = Person::factory()->create(['tenant_id' => $tenantId]);
                $employee = Employee::factory()->create(['tenant_id' => $tenantId, 'person_id' => $person->id, 'employee_code' => sprintf('V%06d', $i), 'joining_date' => '2025-01-01']);
                // Every other person has no grade; every third a grade outside their level; the rest complete.
                $positions[] = ['tenant_id' => $tenantId, 'employee_id' => $employee->id, 'company_id' => $company->id, 'department_id' => $departments[$i % 10]->id,
                    'designation_id' => $designations[$i % 20]->id, 'level_id' => $levels[$i % 5]->id, 'grade_id' => $i % 2 === 0 ? null : $grades[($i + ($i % 3 === 0 ? 1 : 0)) % 5]->id,
                    'change_type' => 'hire', 'effective_from' => '2025-01-01', 'created_at' => now(), 'updated_at' => now()];
            }
            DB::table('employee_positions')->insert($positions);
        }
    });

    $policy = Policy::query()->create(['type' => EmploymentRequirements::TYPE, 'name' => 'Volume rules', 'code' => 'VOL']);
    app(Policies::class)->draft($policy, ['required_on_hire' => ['grade_id'], 'required_on_change' => ['grade_id', 'department_id']]);
    app(Policies::class)->publish($policy, '2026-01-01');
    PolicyAssignmentRule::query()->create(['policy_type' => EmploymentRequirements::TYPE, 'policy_id' => $policy->id, 'name' => 'Everyone', 'priority' => 100, 'conditions' => []]);

    $queries = 0;
    DB::listen(function () use (&$queries) {
        $queries++;
    });
    $measure = function (Closure $work) use (&$queries): array {
        gc_collect_cycles();
        [$before, $start] = [$queries, hrtime(true)];
        memory_reset_peak_usage();
        $base = memory_get_usage();
        $result = $work();

        return [$result, round((hrtime(true) - $start) / 1e9, 2), $queries - $before, round((memory_get_peak_usage() - $base) / 1048576, 1)];
    };

    [$report, $preflightSeconds, $preflightQueries, $preflightMb] = $measure(fn () => app(EmploymentRequirementsPreflight::class)->run($policy));
    [$results, $batchSeconds, $batchQueries, $batchMb] = $size <= 10000
        ? $measure(fn () => app(PositionRequirements::class)->assessMany(Employee::query()->with('person')->get(), today()))
        : [null, null, null, null];
    // I.13: the queued pre-flight end to end (the sync queue runs the job at once): claim, batches with progress, result.
    config(['peopleos.employment_requirements.preflight_queue_threshold' => 0, 'queue.default' => 'sync']);
    [$run, $queuedSeconds, $queuedQueries, $queuedMb] = $measure(fn () => app(EmploymentRequirementsPreflight::class)->record($policy, $this->admin)->fresh());

    expect($report['employees'])->toBe($size)
        ->and($report['rules']['required']['violations'])->toBe(intdiv($size, 2))
        ->and($results === null || count($results) === $size)->toBeTrue()
        ->and($run->only(['mode', 'status']))->toBe(['mode' => 'queued', 'status' => 'completed'])
        ->and($run->processed)->toBe($size)
        // The stored report is a JSON column (MySQL reorders object keys): every key and value, not their order.
        ->and($run->report['rules'])->toEqual($report['rules']);

    $line = ['size' => $size, 'driver' => DB::getDriverName(), 'preflight_seconds' => $preflightSeconds, 'preflight_queries' => $preflightQueries, 'preflight_mb' => $preflightMb,
        'batch_seconds' => $batchSeconds, 'batch_queries' => $batchQueries, 'batch_mb' => $batchMb,
        'queued_seconds' => $queuedSeconds, 'queued_queries' => $queuedQueries, 'queued_mb' => $queuedMb,
        'violations' => array_map(fn (array $r) => $r['violations'], $report['rules'])];
    if (filled(env('REQUIREMENTS_VOLUME_OUT'))) {
        file_put_contents((string) env('REQUIREMENTS_VOLUME_OUT'), json_encode($line).PHP_EOL, FILE_APPEND);
    }
    fwrite(STDERR, json_encode($line).PHP_EOL);
})->with(fn () => array_map('intval', array_filter(explode(',', (string) env('REQUIREMENTS_VOLUME', '1')))));
