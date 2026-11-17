<?php

// PAYROLL CLONE ONLY (hcm_orgadmin_payroll_showcase): gives every employed person of the example organisation who has no
// salary yet a fictional salary, through the product's own four-person compensation change (propose, review, approve,
// schedule by four different people, as the demo seeder does), so a whole month can be run for the training pictures.
//   source docs/training/organisation-admin/tools/env-payroll.sh && php docs/training/organisation-admin/tools/clone-salaries.php

use App\Domain\Compensation\Services\CompensationChanges;
use App\Domain\Compensation\Contracts\CompensationOutput;
use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Models\User;
use App\Domain\Compensation\Models\SalaryStructure;
use App\Domain\Platform\Models\Tenant;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../../../../vendor/autoload.php';
$app = require __DIR__.'/../../../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

if (DB::connection()->getDatabaseName() !== 'hcm_orgadmin_payroll_showcase') {
    fwrite(STDERR, 'Refusing: this script runs only on the payroll clone.'.PHP_EOL);
    exit(1);
}
$tenants = app(TenantContext::class);
$tenant = $tenants->bypass(fn () => Tenant::query()->where('slug', 'demo')->firstOrFail());
$tenants->runAs($tenant, function () {
    $structure = SalaryStructure::query()->where('code', 'STANDARD')->firstOrFail();
    $actor = fn (string $duty) => User::query()->where('email', "compensation.{$duty}@demo.local")->firstOrFail();
    [$proposer, $reviewer, $approver, $executor] = [$actor('proposer'), $actor('reviewer'), $actor('approver'), $actor('executor')];
    $changes = app(CompensationChanges::class);
    $ctcs = [540000, 660000, 720000, 840000, 960000, 1080000, 1200000, 1500000];
    $done = 0;
    foreach (Employee::query()->with('person')->employed()->orderBy('id')->get() as $i => $employee) {
        if (app(CompensationOutput::class)->history($employee)->isNotEmpty()) {
            continue;
        }
        $from = $employee->joining_date && $employee->joining_date->lte('2026-10-01') ? $employee->joining_date->toDateString() : '2026-10-01';
        if ($employee->joining_date && $employee->joining_date->gt('2026-10-31')) {
            continue;
        }
        try {
            $change = $changes->propose($employee, ['change_type' => 'hire', 'effective_from' => $from, 'salary_structure_id' => $structure->id,
                'ctc_annual' => $ctcs[$i % count($ctcs)], 'currency' => 'INR', 'component_values' => ['CONV' => 1600], 'reason' => 'Training example salary'], $proposer);
            $changes->submit($change, $proposer);
            $changes->review($change, $reviewer);
            $changes->approve($change, $approver);
            $changes->schedule($change, $executor);
            $done++;
            echo "  {$employee->employee_code} {$employee->person?->first_name}: ".number_format($ctcs[$i % count($ctcs)])." from {$from}\n";
        } catch (Throwable $e) {
            echo "  {$employee->employee_code}: refused: ".$e->getMessage()."\n";
        }
    }
    echo "salaries given: {$done}\n";
});
