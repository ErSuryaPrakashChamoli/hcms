<?php

use App\Domain\Audit\Models\AuditEvent;
use App\Domain\Audit\Services\AuditIntegrityVerifier;
use App\Domain\Compliance\Jobs\GenerateEpfReturn;
use App\Domain\Compliance\Models\EpfReturnEntry;
use App\Domain\Compliance\Models\EpfReturnRevision;
use App\Domain\Compliance\Models\StatutoryReturn;
use App\Domain\Compliance\Models\StatutorySnapshot;
use App\Domain\Compliance\Services\Returns\EpfReturns;
use App\Domain\Compliance\Services\Returns\StatutoryReturns;
use App\Domain\Employment\Models\EmployeeStatutoryDetail;
use App\Domain\Identity\Services\AccessScopes;
use App\Domain\Organisation\Models\Company;
use App\Domain\Organisation\Models\Establishment;
use App\Domain\Organisation\Services\EstablishmentAssignments;
use App\Domain\Payroll\Models\PayrollEntry;
use App\Domain\Payroll\Models\SalaryStructure;
use App\Domain\Payroll\Services\PayrollRuns;
use App\Domain\Payroll\Services\Salaries;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/ComplianceTestHelpers.php';

beforeEach(function () {
    Storage::fake('local');
    $this->travelTo('2026-10-05 09:00:00');
    syncComplianceRules();
    $this->tenant = provisionTenant();
    actAsTenant($this->tenant);
    $this->admin = tenantUser($this->tenant, ['*']);
    $this->actingAs($this->admin);
    ['company' => $this->company, 'establishment' => $this->establishment] = complianceCompany();
    $this->preparer = tenantUser($this->tenant, ['payroll.*', 'employee.*']);
    $this->payrollApprover = tenantUser($this->tenant, ['payroll.*', 'employee.*']);
    ['generator' => $this->generator, 'approver' => $this->approver, 'filer' => $this->filer] = complianceUsers($this->tenant);
    $this->epf = app(EpfReturns::class);
    $this->returns = app(StatutoryReturns::class);

    $this->anita = statutoryEmployee(600000, $this->establishment, '100200300400');   // PF at the 15,000 ceiling
    $this->ravi = statutoryEmployee(240000, $this->establishment, '100200300401');    // low wage
    $this->run = finalizedPayroll($this->company, 2026, 9, $this->preparer, $this->payrollApprover);
});

it('builds the regular ECR from finalized PF lines, keeping calculated bases apart from exported integers', function () {
    $return = $this->epf->generate($this->establishment, 2026, 9, $this->generator);

    $entry = EpfReturnEntry::query()->where('employee_id', $this->anita->id)->sole();
    $pf = PayrollEntry::query()->where('payroll_run_id', $this->run->id)->where('employee_id', $this->anita->id)->sole()->lines;

    expect($return->status)->toBe('calculated')
        ->and($return->form_code)->toBe('ECR')
        ->and($return->period_key)->toBe('2026-09')
        ->and($return->totals['entries'])->toBe(2)
        ->and((float) $entry->calc_ee_share)->toBe((float) $pf->firstWhere('code', 'PF_EE')->amount)
        ->and((float) $entry->calc_epf_wages)->toBe(15000.0)
        ->and($entry->export_epf_wages)->toBe(15000)
        ->and((float) $entry->calc_eps_share + (float) $entry->calc_er_share)->toBe((float) $pf->firstWhere('code', 'PF_ER')->amount)
        ->and($entry->maskedUan())->toBe('••••••••0400')
        ->and(DB::table('epf_return_entries')->where('id', $entry->id)->value('uan'))->not->toContain('100200300400')
        ->and($return->rule_versions)->not->toBeEmpty();

    // Generating again while editable rebuilds the same return; nothing duplicates.
    $again = $this->epf->generate($this->establishment, 2026, 9, $this->generator);
    expect($again->id)->toBe($return->id)->and(EpfReturnEntry::query()->count())->toBe(2);
});

it('refuses to build from payroll that is not finalized', function () {
    expect(fn () => $this->epf->generate($this->establishment, 2026, 10, $this->generator))->toThrow(RuntimeException::class, 'No finalized payroll');
});

it('blocks approval on missing or invalid UAN, missing registration and (when enforced) unverified rules', function () {
    EmployeeStatutoryDetail::query()->where('employee_id', $this->ravi->id)->first()->update(['uan' => '12345']);
    $return = $this->returns->validate($this->epf->generate($this->establishment, 2026, 9, $this->generator), $this->generator);
    $codes = collect($return->validation)->pluck('code');

    expect($return->status)->toBe('calculated')
        ->and($codes)->toContain('invalid_uan', 'rule_unverified', 'eps_eligibility_not_modelled', 'export_format_unverified')
        ->and(collect($return->validation)->firstWhere('code', 'rule_unverified')['severity'])->toBe('warning');
    expect(fn () => $this->returns->approve($return, $this->approver))->toThrow(RuntimeException::class, 'needs validated');

    config(['peopleos.payroll.enforce_verified_rules' => true]);
    EmployeeStatutoryDetail::query()->where('employee_id', $this->ravi->id)->first()->update(['uan' => null]);
    $return = $this->returns->validate($this->epf->generate($this->establishment, 2026, 9, $this->generator), $this->generator);
    expect(collect($return->validation)->firstWhere('code', 'rule_unverified')['severity'])->toBe('blocking')
        ->and(collect($return->validation)->pluck('code'))->toContain('missing_uan');

    // Missing registration.
    $other = Establishment::query()->create(['legal_entity_id' => $this->establishment->legal_entity_id, 'code' => 'MYS', 'name' => 'Mysuru', 'state' => 'KA', 'effective_from' => '2020-01-01']);
    config(['peopleos.payroll.enforce_verified_rules' => false]);
    $emp = statutoryEmployee(300000, $other, '100200300499');
    expect(fn () => $this->epf->generate($other, 2026, 9, $this->generator))->not->toThrow(Exception::class);
    $orphan = StatutoryReturn::query()->where('establishment_id', $other->id)->sole();
    expect(collect($this->returns->validate($orphan, $this->generator)->validation)->pluck('code'))->toContain('missing_registration', 'no_members');
});

it('runs the lifecycle with separation of duties, snapshots, immutability, export and recorded filing', function () {
    $return = $this->returns->validate($this->epf->generate($this->establishment, 2026, 9, $this->generator), $this->generator);
    expect($return->status)->toBe('validated')->and($return->reconciliation_status)->toBe('balanced');

    expect(fn () => $this->returns->approve($return, $this->generator))->toThrow(RuntimeException::class, 'You do not have');
    $generatorApprover = tenantUser($this->tenant, ['compliance.returns.view', 'compliance.returns.generate', 'compliance.returns.approve']);
    $own = $this->returns->validate($this->epf->generate($this->establishment, 2026, 9, $generatorApprover), $generatorApprover);
    expect(fn () => $this->returns->approve($own, $generatorApprover))->toThrow(RuntimeException::class, 'Separation of duties');

    $return = $this->returns->approve($own, $this->approver, 'Checked against payroll');
    expect($return->status)->toBe('approved')
        ->and(StatutorySnapshot::query()->where('statutory_return_id', $return->id)->count())->toBe(2);

    // Frozen after approval.
    $entry = EpfReturnEntry::query()->where('statutory_return_id', $return->id)->first();
    expect(fn () => $entry->update(['export_ee_share' => 1]))->toThrow(RuntimeException::class, 'revision');
    expect(fn () => $return->fresh()->update(['totals' => []]))->toThrow(RuntimeException::class, 'immutable');
    expect(fn () => StatutorySnapshot::query()->first()->update(['output' => []]))->toThrow(RuntimeException::class, 'immutable');
    expect(fn () => $this->epf->generate($this->establishment, 2026, 9, $this->generator))->toThrow(RuntimeException::class, 'create a revision');

    // Export: a file, not a filing.
    $return = $this->returns->export($return, $this->generator);
    $content = $this->returns->exportContent($return, $this->filer);
    expect($return->status)->toBe('exported')
        ->and($return->export_filename)->toStartWith('UNVERIFIED-FORMAT_ECR_5000_202609_REGULAR')
        ->and($return->submitted_at)->toBeNull()
        ->and($content)->toContain('100200300400#~#')
        ->and(substr_count(trim($content), "\n") + 1)->toBe(2);

    expect(fn () => $this->returns->recordSubmission($return, $this->approver, 'TRRN1', now()))->toThrow(RuntimeException::class, 'You do not have');
    expect(fn () => $this->returns->recordSubmission($return, $this->filer, '  ', now()))->toThrow(RuntimeException::class, 'external reference');
    $return = $this->returns->recordSubmission($return, $this->filer, 'TRRN-2026-09-001', now()->subHour());
    $return = $this->returns->recordAcknowledgement($return, $this->filer, 'ACK-778899', now());
    expect($return->status)->toBe('acknowledged')->and($return->external_reference)->toBe('TRRN-2026-09-001');

    $mismatch = $this->returns->reconcileFiling($return, $this->filer, ['ee_share' => (float) $return->totals['ee_share'] + 10]);
    expect($mismatch->status)->toBe('reconciliation_required');
    $ok = $this->returns->reconcileFiling($mismatch, $this->filer, ['ee_share' => (float) $return->totals['ee_share']]);
    expect($ok->status)->toBe('reconciled');

    expect(AuditEvent::query()->where('entity_type', StatutoryReturn::class)->where('entity_id', (string) $return->id)->pluck('action')->map(fn ($a) => $a->value ?? $a)->unique()->values()->all())
        ->toContain('STATUTORY_OUTPUT_CREATED', 'STATUTORY_OUTPUT_VALIDATED', 'STATUTORY_OUTPUT_APPROVED', 'STATUTORY_OUTPUT_EXPORTED', 'STATUTORY_OUTPUT_ACCESSED', 'STATUTORY_OUTPUT_SUBMITTED', 'STATUTORY_OUTPUT_ACKNOWLEDGED', 'STATUTORY_OUTPUT_RECONCILED')
        ->and($return->actions()->pluck('action')->all())->toContain('generated', 'validated', 'approved', 'exported', 'accessed', 'submitted', 'acknowledged', 'reconciled')
        ->and(app(AuditIntegrityVerifier::class)->verify($this->tenant->id)['valid'])->toBeTrue();
});

it('applies EPFO sequencing: supplementary for new members only and revised returns with a payment attestation for downward changes', function () {
    expect(fn () => $this->epf->generate($this->establishment, 2026, 9, $this->generator, 'supplementary'))->toThrow(RuntimeException::class, 'approved regular');

    $regular = $this->returns->approve($this->returns->validate($this->epf->generate($this->establishment, 2026, 9, $this->generator), $this->generator), $this->approver);

    // Payroll is corrected: reopen, lower Ravi's salary, add a new joiner, re-finalize.
    $runs = app(PayrollRuns::class);
    $runs->reopen($this->run, 'Correction', $this->payrollApprover);
    app(Salaries::class)->assign($this->ravi, SalaryStructure::query()->where('code', 'STANDARD')->firstOrFail(), 216000, '2026-09-01', ['CONV' => 1600], 'correction', 'Correction');
    $newJoiner = statutoryEmployee(300000, $this->establishment, '100200300402');
    $this->run = finalizedPayroll($this->company, 2026, 9, $this->preparer, $this->payrollApprover);

    $supplementary = $this->epf->generate($this->establishment, 2026, 9, $this->generator, 'supplementary');
    expect(EpfReturnEntry::query()->where('statutory_return_id', $supplementary->id)->pluck('employee_id')->all())->toBe([$newJoiner->id]);
    expect(fn () => $this->epf->generate($this->establishment, 2026, 9, $this->generator, 'revised', [$this->ravi->id], 'Salary corrected'))->toThrow(RuntimeException::class, 'still in process');
    $this->returns->cancel($supplementary, $this->generator, 'File after the revision');

    expect(fn () => $this->epf->generate($this->establishment, 2026, 9, $this->generator, 'revised', [], 'x'))->toThrow(RuntimeException::class, 'select at least one');
    $revised = $this->returns->validate($this->epf->generate($this->establishment, 2026, 9, $this->generator, 'revised', [$this->ravi->id], 'Salary corrected'), $this->generator);
    $revision = EpfReturnRevision::query()->where('revised_return_id', $revised->id)->sole();
    expect($revision->direction)->toBe('downward')
        ->and($revised->status)->toBe('calculated')
        ->and(collect($revised->validation)->where('code', 'unsupported_revision')->where('severity', 'blocking'))->not->toBeEmpty();

    $this->returns->cancel($revised, $this->generator, 'Attest payment status first');
    $revised = $this->returns->validate($this->epf->generate($this->establishment, 2026, 9, $this->generator, 'revised', [$this->ravi->id], 'Salary corrected', paymentNotInitiated: true), $this->generator);
    expect($revised->status)->toBe('validated')->and($revised->sequence)->toBe(2); // sequences count per kind: the cancelled revision was #1
    $this->returns->approve($revised, $this->approver);

    expect($regular->refresh()->status)->toBe('revised')
        ->and(StatutorySnapshot::query()->where('statutory_return_id', $revised->id)->value('supersedes_id'))->not->toBeNull();
});

it('keeps returns inside the company scope and away from managers and other establishments', function () {
    $return = $this->epf->generate($this->establishment, 2026, 9, $this->generator);

    // Another company's compliance user sees nothing.
    $other = Company::factory()->create();
    $outsider = tenantUser($this->tenant, ['compliance.returns.view']);
    app(AccessScopes::class)->assign($outsider, ['company' => [$other->id]]);
    $this->actingAs($outsider);
    expect(StatutoryReturn::query()->count())->toBe(0)->and($outsider->can('view', $return))->toBeFalse();

    // A manager with a reporting line but no compliance permission is refused.
    $manager = employeeWithUser();
    $this->actingAs($manager->user);
    expect($manager->user->can('viewAny', StatutoryReturn::class))->toBeFalse()
        ->and($manager->user->can('view', $return))->toBeFalse();

    // An employee moved to another establishment is not in this establishment's return.
    $this->actingAs($this->admin);
    $pune = Establishment::query()->create(['legal_entity_id' => $this->establishment->legal_entity_id, 'code' => 'PUNE', 'name' => 'Pune', 'state' => 'MH', 'effective_from' => '2020-01-01']);
    $moved = statutoryEmployee(500000, $this->establishment, '100200300405');
    app(EstablishmentAssignments::class)->assign($moved, $pune, '2026-10-01', 'Transfer');
    finalizedPayroll($this->company, 2026, 10, $this->preparer, $this->payrollApprover);
    $this->travelTo('2026-11-05 09:00:00');
    $october = $this->epf->generate($this->establishment, 2026, 10, $this->generator);
    expect(EpfReturnEntry::query()->where('statutory_return_id', $october->id)->pluck('employee_id')->all())->not->toContain($moved->id);
});

it('generates through a tenant-aware unique queued job', function () {
    $job = new GenerateEpfReturn($this->establishment->id, 2026, 9, $this->generator->id);
    expect($job->uniqueId())->toBe("epf-return-{$this->tenant->id}-{$this->establishment->id}-2026-09-regular")
        ->and($job->tenantId())->toBe($this->tenant->id);

    dispatch($job);
    dispatch(new GenerateEpfReturn($this->establishment->id, 2026, 9, $this->generator->id));
    expect(StatutoryReturn::query()->where('return_type', 'EPF')->count())->toBe(1)
        ->and(StatutoryReturn::query()->first()->actions()->where('source', 'job')->count())->toBe(2);
});

it('reports a repeated UAN as a blocking duplicate instead of failing the build', function () {
    $this->travelTo('2026-11-05 09:00:00');
    statutoryEmployee(300000, $this->establishment, '100200300400'); // same UAN as Anita
    finalizedPayroll($this->company, 2026, 10, $this->preparer, $this->payrollApprover);

    $return = $this->returns->validate($this->epf->generate($this->establishment, 2026, 10, $this->generator), $this->generator);
    expect(EpfReturnEntry::query()->where('statutory_return_id', $return->id)->count())->toBe(3)
        ->and(collect($return->validation)->where('code', 'duplicate_uan')->where('severity', 'blocking'))->not->toBeEmpty()
        ->and($return->status)->toBe('calculated');
});
