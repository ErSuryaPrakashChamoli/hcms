<?php

use App\Domain\Compliance\Models\ComplianceRule;
use App\Domain\Compliance\Models\StatutoryExportLayout;
use App\Domain\Compliance\Models\StatutoryReturn;
use Illuminate\Support\Str;

/*
 | Phase 6 §39: compliance architecture invariants. Static checks of the code base; the behaviour
 | behind each is also covered by the feature tests named in docs/architecture/statutory-production-readiness.md.
 */

function complianceFiles(): array
{
    $files = [];
    foreach (['Domain/Compliance', 'Filament/Resources', 'Filament/Support', 'Filament/Pages', 'Http/Controllers/Api/V1'] as $dir) {
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path($dir))) as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $path = $file->getPathname();
                if ($dir === 'Domain/Compliance' || Str::contains($path, ['Compliance', 'Statutory', 'Epf', 'Esi', 'Tds', 'Lwf', 'ProfessionalTax', 'ExportLayout', 'RuleNotice', 'RuleVerification', 'ParallelRun'])) {
                    $files[Str::after($path, base_path().'/')] = file_get_contents($path);
                }
            }
        }
    }
    ksort($files);

    return $files;
}

function complianceFilesMatching(string $pattern, array $except = []): array
{
    return array_values(array_diff(array_keys(array_filter(complianceFiles(), fn ($code) => preg_match($pattern, $code))), $except));
}

it('keeps unverified statutory rules out of production finalization', function () {
    $finalize = file_get_contents(app_path('Domain/Payroll/Services/PayrollRuns.php'));
    $rules = file_get_contents(app_path('Domain/Compliance/Services/ComplianceRules.php'));

    expect($finalize)->toContain('$this->complianceRules->assertRunVerified($run);')
        ->and($rules)->toContain("app()->environment('production') ||")
        ->and($rules)->not->toMatch('/function enforced\(\): bool\s*\{\s*return \(bool\) config/');
});

it('freezes approved returns and verified rules and layouts, and corrects them only by new versions', function () {
    expect(StatutoryReturn::EDITABLE)->not->toContain(StatutoryReturn::APPROVED)
        ->and(StatutoryReturn::CONTENT)->toContain('totals', 'rule_versions', 'validation')
        ->and(ComplianceRule::IMMUTABLE)->toContain('parameters', 'effective_from', 'effective_to', 'checksum', 'corrects_rule_id')
        ->and(file_get_contents(app_path('Domain/Compliance/Models/StatutoryExportLayout.php')))->toContain("['code', 'version', 'name', 'specification', 'checksum', 'authority']");

    // Rule and layout versions are only ever created, never updated in place, by the services.
    expect(complianceFilesMatching('/ComplianceRule::query\(\)->(update|updateOrCreate)\(/'))->toBe([])
        ->and(complianceFilesMatching('/StatutoryExportLayout::query\(\)->(update|updateOrCreate)\(/'))->toBe([])
        ->and(class_exists(StatutoryExportLayout::class))->toBeTrue();
});

it('records filing only as a human step with an external reference, never on export', function () {
    // The only code that moves a return to SUBMITTED / ACKNOWLEDGED, or writes the portal references.
    expect(complianceFilesMatching("/'status' => StatutoryReturn::(SUBMITTED|ACKNOWLEDGED)/"))->toBe(['app/Domain/Compliance/Services/Returns/StatutoryReturns.php'])
        ->and(complianceFilesMatching("/'(external_reference|acknowledgement_reference|portal_validation_reference|portal_validation_result)' =>/", ['app/Domain/Compliance/Services/Returns/StatutoryReturns.php', 'app/Domain/Compliance/Services/Tds/TdsCertificates.php', 'app/Http/Controllers/Api/V1/ComplianceController.php', 'app/Domain/Compliance/Services/ParallelPayroll.php']))->toBe([]);

    $service = file_get_contents(app_path('Domain/Compliance/Services/Returns/StatutoryReturns.php'));
    $export = Str::between($service, 'public function export(', 'public function exportContent(');
    expect($export)->not->toContain("'status' => StatutoryReturn::SUBMITTED")->not->toContain("'submitted_at' =>")->not->toContain("'external_reference' =>")
        ->and(Str::between($service, 'public function recordSubmission(', 'public function recordAcknowledgement('))->toContain('blank(trim($externalReference))');
});

it('takes the statutory state from the establishment, never from the company', function () {
    $engine = file_get_contents(app_path('Domain/Compliance/Services/StatutoryEngine.php'));
    $contexts = file_get_contents(app_path('Domain/Compliance/Services/StatutoryContexts.php'));

    expect($engine)->toContain('$context->ptState')->toContain('$context->lwfState')
        ->not->toContain('$profile->pt_state')->not->toContain('country_code')
        ->and($contexts)->toContain('$this->assignments->resolve(')->not->toContain('country_code');
});

it('never bypasses tenant isolation or field security in compliance code', function () {
    expect(complianceFilesMatching('/withoutGlobalScopes\(\)|->bypass\(|withoutTenancy\(/'))->toBe([])
        ->and(complianceFilesMatching('/->makeVisible\(|->setHidden\(\[\]\)/'))->toBe([]);

    // Statutory identifiers are masked in every API / screen presenter unless explicitly unmasked.
    foreach (['EpfReturns.php' => 'maskedUan()', 'EsiReturns.php' => 'maskedIp()'] as $file => $mask) {
        expect(file_get_contents(app_path("Domain/Compliance/Services/Returns/{$file}")))->toContain($mask);
    }
    expect(file_get_contents(app_path('Http/Controllers/Api/V1/ComplianceController.php')))->toContain('present($entry, false)')->toContain('maskedNumber()');
});

it('keeps compliance free of RMS, attendance and leave calculation, and of a second employee identity', function () {
    expect(complianceFilesMatching('/RecruitmentEdge|\\\\Rms\\\\|\\brms\./i'))->toBe([])
        ->and(complianceFilesMatching('/App\\\\Domain\\\\(Attendance|Leave)\\\\/'))->toBe([])
        ->and(complianceFilesMatching('/(Employee|Person)::(query\(\)->)?create\(|new (Employee|Person)\(/'))->toBe([]);
});
