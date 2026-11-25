<?php

use App\Domain\Compensation\Models\EmployeeSalaryAssignment;
use App\Domain\Employment\Models\Employee;
use App\Domain\Employment\Models\EmployeeStatutoryDetail;
use App\Domain\Identity\Scopes\AccessScope;
use App\Domain\Organisation\Models\Establishment;
use App\Domain\Organisation\Models\Location;
use App\Domain\Payroll\Services\PayrollReadiness;
use App\Domain\People\Models\Person;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/../Workflow/WorkflowTestHelpers.php';
require_once __DIR__.'/PayrollTestHelpers.php';

/*
| Remediation I.8: payroll readiness at volume. One salaried employee is set up through the real compensation flow; N
| employees then copy that person's position, statutory details and approved salary assignment (bulk inserts, no model
| events). Every 20th leaves in the pay month (their compensation is read on their last day), every 7th has no salary,
| every 3rd works at a location of a second establishment (the others fall back to the head office).
| Off by default (minutes of set-up):
| READINESS_VOLUME=1000,10000 vendor/bin/pest tests/Feature/Payroll/ReadinessVolumeTest.php
| Each line (READINESS_VOLUME_OUT, JSON) has the time, queries, memory and a hash of the whole readiness result, so the
| same seed on different code can be compared result for result.
*/

beforeEach(function () {
    if (blank(env('READINESS_VOLUME'))) {
        $this->markTestSkipped('Set READINESS_VOLUME=1000,10000 to measure payroll readiness at volume.');
    }
    $this->travelTo('2026-11-20 10:00:00');
    syncComplianceRules();
    $this->tenant = provisionTenant();
    actAsTenant($this->tenant);
    $this->admin = tenantUser($this->tenant, ['*']);
    $this->actingAs($this->admin);
});

it('evaluates payroll readiness at volume', function (int $size) {
    fake()->seed(20261125);   // the same people (names, details) on every run, so results compare across code
    $company = payrollCompany();
    $template = salariedEmployee(480000);
    $position = (array) DB::table('employee_positions')->where('employee_id', $template->id)->first();
    $detail = EmployeeStatutoryDetail::query()->updateOrCreate(['employee_id' => $template->id], ['pan' => 'ABCDE1234F', 'uan' => '100200300400', 'pf_applicable' => true, 'esic_applicable' => false]);
    $assignment = (array) DB::table('employee_salary_assignments')->where('employee_id', $template->id)->first();
    $detailRow = (array) DB::table('employee_statutory_details')->where('id', $detail->id)->first();
    $tenantId = $this->tenant->id;
    // A second establishment, reached through a location: the head-office fallback check runs (more than one).
    $principal = Establishment::query()->where('company_id', $company->id)->firstOrFail();
    $branch = Establishment::query()->create(['company_id' => $company->id, 'legal_entity_id' => $principal->legal_entity_id, 'code' => 'MYS', 'name' => 'Mysuru works', 'state' => 'KA', 'effective_from' => '2020-01-01']);
    $location = Location::query()->create(['company_id' => $company->id, 'code' => 'MYS-L', 'name' => 'Mysuru', 'establishment_id' => $branch->id]);

    Model::withoutEvents(function () use ($size, $tenantId, $template, $position, $assignment, $detailRow, $location) {
        foreach (array_chunk(range(1, $size), 500) as $chunk) {
            [$positions, $assignments, $details] = [[], [], []];
            foreach ($chunk as $i) {
                $person = Person::factory()->create(['tenant_id' => $tenantId, 'first_name' => 'Person', 'last_name' => sprintf('R%06d', $i)]);
                $employee = Employee::factory()->create(['tenant_id' => $tenantId, 'person_id' => $person->id, 'employee_code' => sprintf('R%06d', $i),
                    'joining_date' => '2025-01-01', 'lifecycle_state' => $template->getRawOriginal('lifecycle_state'),
                    'exit_date' => $i % 20 === 0 ? '2026-11-'.str_pad((string) (1 + $i % 28), 2, '0', STR_PAD_LEFT) : null]);
                $positions[] = ['employee_id' => $employee->id, 'location_id' => $i % 3 === 0 ? $location->id : $position['location_id']] + array_diff_key($position, ['id' => 1, 'employee_id' => 1, 'location_id' => 1]);
                $details[] = ['employee_id' => $employee->id, 'pan' => null, 'uan' => null] + array_diff_key($detailRow, ['id' => 1, 'employee_id' => 1, 'pan' => 1, 'uan' => 1]);
                if ($i % 7 !== 0) {
                    $assignments[] = ['employee_id' => $employee->id, 'ctc_annual' => 300000 + ($i % 50) * 10000] + array_diff_key($assignment, ['id' => 1, 'employee_id' => 1, 'ctc_annual' => 1]);
                }
            }
            DB::table('employee_positions')->insert($positions);
            DB::table('employee_statutory_details')->insert($details);
            DB::table('employee_salary_assignments')->insert($assignments);
        }
    });
    expect(EmployeeSalaryAssignment::query()->withoutGlobalScope(AccessScope::class)->count())->toBe($size - intdiv($size, 7) + 1);

    $queries = 0;
    $patterns = [];
    DB::listen(function ($query) use (&$queries, &$patterns) {
        $queries++;
        if (filled(env('READINESS_PROFILE'))) {
            $key = preg_replace(['/\b\d+\b/', '/\?(, \?)+/', "/'[^']*'/"], ['?', '?', "'?'"], $query->sql);
            $patterns[$key] = ($patterns[$key] ?? 0) + 1;
        }
    });
    gc_collect_cycles();
    $start = hrtime(true);
    memory_reset_peak_usage();
    $base = memory_get_usage();
    $result = app(PayrollReadiness::class)->evaluate((int) $company->id, activeOnly: false);
    $seconds = round((hrtime(true) - $start) / 1e9, 2);
    $mb = round((memory_get_peak_usage() - $base) / 1048576, 1);

    $counts = collect($result['checks'])->map(fn (array $c) => $c['count'])->filter()->all();
    $hash = hash('sha256', json_encode($result));   // every check: state, count, notes, rows (each person and detail)
    expect($counts['structure_missing'] ?? 0)->toBeGreaterThanOrEqual(intdiv($size, 7));

    $line = ['size' => $size, 'driver' => DB::getDriverName(), 'seconds' => $seconds, 'queries' => $queries, 'mb' => $mb, 'counts' => $counts, 'result_sha256' => $hash];
    if (filled(env('READINESS_VOLUME_OUT'))) {
        file_put_contents((string) env('READINESS_VOLUME_OUT'), json_encode($line).PHP_EOL, FILE_APPEND);
    }
    fwrite(STDERR, json_encode($line).PHP_EOL);
    if (filled(env('READINESS_PROFILE'))) {
        arsort($patterns);
        foreach (array_slice($patterns, 0, 20, true) as $sql => $n) {
            fwrite(STDERR, str_pad((string) $n, 7).mb_substr($sql, 0, 220).PHP_EOL);
        }
    }
})->with(fn () => array_map('intval', array_filter(explode(',', (string) env('READINESS_VOLUME', '1')))));
