<?php

use App\Domain\Compliance\Exceptions\ProductionGateBlocked;
use App\Domain\Compliance\Models\ComplianceRule;
use App\Domain\Compliance\Models\ComplianceRuleNotice;
use App\Domain\Compliance\Models\StatutoryExportLayout;
use App\Domain\Compliance\Models\StatutoryRegistration;
use App\Domain\Compliance\Services\ComplianceReadiness;
use App\Domain\Compliance\Services\ComplianceRules;
use App\Domain\Compliance\Services\ExportLayouts;
use App\Domain\Compliance\Services\RecordVerifications;
use App\Domain\Compliance\Services\RequiredRules;
use App\Domain\Compliance\Services\Returns\EpfReturns;
use App\Domain\Compliance\Services\Returns\StatutoryReturns;
use App\Domain\Compliance\Services\RuleVerifications;
use App\Domain\Compliance\Services\StatutoryRegistrations;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/ComplianceTestHelpers.php';

/*
 | Phase 6 §18: the production gate. Every condition blocks on its own; only a fully verified
 | configuration (test evidence standing in for real official documents) lets a return progress.
 */

beforeEach(function () {
    Storage::fake('local');
    $this->travelTo('2026-09-05 09:00:00');
    syncComplianceRules();
    $this->tenant = provisionTenant();
    actAsTenant($this->tenant);
    $this->admin = tenantUser($this->tenant, ['*']);
    $this->actingAs($this->admin);
    ['company' => $this->company, 'entity' => $this->entity, 'establishment' => $this->establishment] = complianceCompany();
    $this->employee = statutoryEmployee(600000, $this->establishment, '100200300400');
    finalizedPayroll($this->company, 2026, 8, tenantUser($this->tenant, ['payroll.*', 'employee.*']), tenantUser($this->tenant, ['payroll.*', 'employee.*']));
    ['generator' => $this->generator, 'approver' => $this->approver, 'filer' => $this->filer] = complianceUsers($this->tenant);
    $this->returns = app(StatutoryReturns::class);
    $this->gate = app(ComplianceReadiness::class);
    [$this->maker, $this->checker] = [platformAdmin(), platformAdmin()];

    $this->verifyEverything = function () {
        $bypass = fn (callable $fn) => app(TenantContext::class)->bypass($fn);
        $rules = app(RuleVerifications::class);
        $bypass(function () use ($rules) {
            // EPF v1 is affected by the ceiling notice; test resolution with a test v2 from 17 Sep.
            // Both EPF notices (ceiling; September treatment) are closed by a test version from 17 Sep.
            $v1 = ComplianceRule::query()->where('code', 'EPF')->where('version', 1)->sole();
            $test = $rules->publishCorrection($v1, ['wage_ceiling' => 25000] + $v1->payload(), '2026-09-17', null, 'Test correction', $this->maker);
            foreach (ComplianceRuleNotice::query()->where('code', 'EPF')->where('status', 'open')->get() as $notice) {
                $rules->resolveNotice($notice, $test, $this->maker, 'Test resolution');
            }
            $rules->submit($v1, ['source_url' => 'https://www.epfindia.gov.in/test.pdf', 'source_title' => 'Test', 'effective_date' => '2014-09-01', 'retrieved_at' => '2026-09-01', 'requirement_text' => 'Test', 'mapping' => array_fill_keys(array_keys($v1->payload()), 'Test clause')], $this->maker);
            $rules->attachEvidence($v1, '%PDF test', 't.pdf', '2026-09-01', null, $this->maker);
            $rules->verify($v1, $this->checker, 'Test verification');
            $layout = StatutoryExportLayout::query()->where('code', 'EPF_ECR')->sole();
            app(ExportLayouts::class)->submit($layout, $this->maker, 'https://www.epfindia.gov.in/ecr.pdf', 'Test spec', '2026-09-01', '%PDF spec', 's.pdf');
            app(ExportLayouts::class)->verify($layout, $this->checker, 'Test verification');
        });
        app(ComplianceRules::class)->forget();
        $orgMaker = tenantUser($this->tenant, ['legal_entity.update', 'establishment.update']);
        $orgChecker = tenantUser($this->tenant, ['legal_entity.verify', 'establishment.verify', 'compliance.registrations.manage']);
        foreach ([$this->entity->fresh(), $this->establishment->fresh()] as $record) {
            app(RecordVerifications::class)->submit($record, $orgMaker, 'Test certificate');
            app(RecordVerifications::class)->verify($record->fresh(), $orgChecker, 'Test verification');
        }
        app(StatutoryRegistrations::class)->verify(StatutoryRegistration::query()->where('registration_type', 'epf_establishment_code')->sole(), $orgChecker, 'Test allotment letter');
    };
    $this->approvedReturn = fn () => $this->returns->approve($this->returns->validate(app(EpfReturns::class)->generate($this->establishment, 2026, 8, $this->generator), $this->generator), $this->approver);
});

it('blocks export under enforcement on draft rules, unverified structure and layouts, and says which checks fail', function () {
    $return = ($this->approvedReturn)();
    config(['peopleos.payroll.enforce_verified_rules' => true]);

    try {
        $this->returns->export($return, $this->generator);
        $this->fail('The gate should block.');
    } catch (ProductionGateBlocked $e) {
        expect(collect($e->checks)->pluck('check')->all())->toBe(['legal_entity_verified', 'establishment_verified', 'registration_verified', 'rules_verified', 'evidence_complete', 'export_layout_verified']);
    }
    expect($return->refresh()->status)->toBe('approved')->and($return->export_path)->toBeNull();
});

it('lets a fully verified configuration progress to export, and then records the file as valid', function () {
    ($this->verifyEverything)();
    config(['peopleos.payroll.enforce_verified_rules' => true]);
    $return = ($this->approvedReturn)();

    $return = $this->returns->export($return, $this->generator);
    $gate = $this->gate->forReturn($return);
    expect($return->status)->toBe('exported')
        ->and($return->export_filename)->not->toStartWith('UNVERIFIED-FORMAT_')
        ->and($gate['ready'])->toBeTrue()
        ->and(collect($gate['checks'])->every(fn ($c) => $c['passed']))->toBeTrue();
});

it('blocks on each missing condition on its own', function (string $break, string $check) {
    ($this->verifyEverything)();
    $return = ($this->approvedReturn)();

    match ($break) {
        'registration' => app(StatutoryRegistrations::class)->update(StatutoryRegistration::query()->where('registration_type', 'epf_establishment_code')->sole(), ['jurisdiction' => 'Other office'], 'Changed'),
        'establishment' => $this->establishment->fresh()->update(['state' => 'MH']),
        'assignment' => DB::table('payroll_entries')->where('employee_id', $this->employee->id)->update(['inputs' => json_encode(['statutory_context' => ['establishment_source' => 'company_primary']])]),
        'reconciliation' => DB::table('statutory_returns')->where('id', $return->id)->update(['reconciliation_status' => 'exceptions']),
        'payroll' => DB::table('payroll_runs')->update(['status' => 'draft']),
        'notice' => DB::table('compliance_rule_notices')->insert(['jurisdiction' => 'IN', 'code' => 'EPF', 'affects_versions' => '[1]', 'effective_date' => '2026-08-01', 'title' => 'Test notice', 'summary' => 'x', 'references' => '[]', 'retrieved_at' => '2026-09-01', 'checksum' => str_repeat('a', 64), 'status' => 'open', 'created_at' => now(), 'updated_at' => now()]),
    };
    app(ComplianceRules::class)->forget();
    app()->forgetInstance(ComplianceReadiness::class);

    $failed = collect(app(ComplianceReadiness::class)->forReturn($return->fresh(), false)['checks'])->reject(fn ($c) => $c['passed'])->pluck('check')->all();
    expect($failed)->toContain($check);
})->with([
    'unverified registration' => ['registration', 'registration_verified'],
    'unverified establishment' => ['establishment', 'establishment_verified'],
    'employee not explicitly assigned' => ['assignment', 'employees_assigned'],
    'failed reconciliation' => ['reconciliation', 'reconciled'],
    'payroll no longer finalized' => ['payroll', 'payroll_finalized'],
    'open regulatory notice' => ['notice', 'rules_verified'],
]);

it('scopes required rules to the tenant\'s active establishments, profiles and states', function () {
    $required = app(RequiredRules::class)->on('2026-09-30');

    expect($required->pluck('statute')->sort()->values()->all())->toBe(['EPF', 'ESI', 'PT', 'TDS'])  // legacy profile: no LWF
        ->and($required->firstWhere('statute', 'PT')['state'])->toBe('KA')
        ->and($required->firstWhere('statute', 'EPF')['status'])->toBe('notice_open')
        ->and($required->firstWhere('statute', 'ESI')['status'])->toBe('review')
        ->and($required->firstWhere('statute', 'PT')['status'])->toBe('draft');
});
