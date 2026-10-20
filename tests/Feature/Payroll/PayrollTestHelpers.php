<?php

use App\Domain\Compliance\Models\CompanyStatutoryProfile;
use App\Domain\Compliance\Services\ComplianceRules;
use App\Domain\Employment\Models\Employee;
use App\Domain\Lifecycle\Enums\LifecycleState;
use App\Domain\Organisation\Models\Company;
use App\Support\Tenancy\TenantContext;

/** Loads the India pack (platform data, tenant-independent). */
function syncComplianceRules(): void
{
    app(TenantContext::class)->bypass(fn () => app(ComplianceRules::class)->sync());
}

/** The tenant's first company with a statutory profile (Karnataka PT, no LWF by default). */
function payrollCompany(array $profile = []): Company
{
    $company = Company::query()->first() ?? Company::factory()->create();
    CompanyStatutoryProfile::query()->updateOrCreate(['company_id' => $company->id], $profile + ['pt_state' => 'KA'] + CompanyStatutoryProfile::defaults());

    return $company;
}

/** An active employee with the STANDARD structure and the given annual CTC (conveyance fixed at 1,600). */
function salariedEmployee(float $ctcAnnual, array $userPermissions = ['task.view'], string $from = '2025-01-01', array $componentValues = ['CONV' => 1600], ?Employee $manager = null): Employee
{
    $employee = employeeWithUser($manager, $userPermissions);
    forceLifecycle($employee, LifecycleState::Active, ['joining_date' => '2025-01-01']);
    compensate($employee, $ctcAnnual, $from, $componentValues, 'hire', 'test');

    return $employee->refresh();
}
