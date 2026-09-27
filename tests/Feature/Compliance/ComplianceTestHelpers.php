<?php

use App\Domain\Compliance\Services\StatutoryRegistrations;
use App\Domain\Employment\Models\Employee;
use App\Domain\Employment\Models\EmployeeStatutoryDetail;
use App\Domain\Identity\Models\User;
use App\Domain\Organisation\Models\Company;
use App\Domain\Organisation\Models\Establishment;
use App\Domain\Organisation\Models\LegalEntity;
use App\Domain\Organisation\Services\EstablishmentAssignments;
use App\Domain\Payroll\Models\PayrollRun;
use App\Domain\Payroll\Services\PayrollRuns;

require_once __DIR__.'/../Workflow/WorkflowTestHelpers.php';
require_once __DIR__.'/../Payroll/PayrollTestHelpers.php';

/**
 * A company with its principal establishment in Karnataka and EPF / ESIC / PT / TAN registrations.
 *
 * @return array{company: Company, entity: LegalEntity, establishment: Establishment}
 */
function complianceCompany(array $profile = []): array
{
    $company = payrollCompany($profile);
    $entity = LegalEntity::query()->where('company_id', $company->id)->sole();
    $establishment = Establishment::query()->where('company_id', $company->id)->orderBy('id')->first();
    $establishment->update(['state' => 'KA', 'effective_from' => '2020-01-01']);
    $entity->update(['effective_from' => '2020-01-01']);

    $registrations = app(StatutoryRegistrations::class);
    foreach ([['epf_establishment_code', 'KABNG0012345000', $establishment->id], ['esic_employer_code', '53000123450001001', $establishment->id], ['pt_registration_certificate', 'PTKA123456', $establishment->id], ['tan', 'BLRA12345B', null]] as [$type, $number, $establishmentId]) {
        $registrations->register(['legal_entity_id' => $entity->id, 'establishment_id' => $establishmentId, 'registration_type' => $type, 'registration_number' => $number, 'effective_from' => '2020-01-01'], 'Test registration');
    }

    return ['company' => $company, 'entity' => $entity, 'establishment' => $establishment->refresh()];
}

/** A salaried employee with statutory identifiers, assigned to the establishment. */
function statutoryEmployee(float $ctcAnnual, Establishment $establishment, ?string $uan = null, array $detail = []): Employee
{
    $employee = salariedEmployee($ctcAnnual);
    EmployeeStatutoryDetail::query()->create(['employee_id' => $employee->id, 'uan' => $uan, 'pan' => $detail['pan'] ?? null, 'esic_number' => $detail['esic_number'] ?? null]
        + collect($detail)->except(['pan', 'esic_number'])->all() + ['pf_applicable' => true, 'esic_applicable' => true, 'pt_applicable' => true]);
    app(EstablishmentAssignments::class)->assign($employee, $establishment, '2025-01-01', 'Test assignment');

    return $employee->refresh();
}

/** Open, calculate, validate, approve and finalize a payroll run. */
function finalizedPayroll(Company $company, int $year, int $month, User $preparer, User $approver): PayrollRun
{
    $runs = app(PayrollRuns::class);
    $run = $runs->calculate($runs->open($company, $year, $month, $preparer), $preparer);

    return $runs->finalize($runs->approve($runs->validate($run), $approver, 'ok'), $approver);
}

/** @return array{generator: User, approver: User, filer: User} */
function complianceUsers($tenant): array
{
    return [
        'generator' => tenantUser($tenant, ['compliance.returns.view', 'compliance.returns.generate', 'compliance.returns.export']),
        'approver' => tenantUser($tenant, ['compliance.returns.view', 'compliance.returns.approve']),
        'filer' => tenantUser($tenant, ['compliance.returns.view', 'compliance.returns.file', 'compliance.returns.export', 'compliance.reconcile', 'compliance.sensitive.view']),
    ];
}
