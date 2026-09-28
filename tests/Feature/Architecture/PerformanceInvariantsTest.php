<?php

use App\Domain\Performance\Events\PerformanceEvent;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/*
 | Phase 7 §45: performance architecture invariants. Static checks of the code base; the behaviour
 | behind each is covered by tests/Feature/Performance.
 */

function performanceFiles(): array
{
    $files = [];
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path('Domain/Performance'))) as $file) {
        if ($file->isFile() && $file->getExtension() === 'php') {
            $files[Str::after($file->getPathname(), base_path().'/')] = file_get_contents($file->getPathname());
        }
    }
    ksort($files);

    return $files;
}

function performanceFilesMatching(string $pattern): array
{
    return array_keys(array_filter(performanceFiles(), fn ($code) => preg_match($pattern, $code)));
}

it('never writes payroll, salary, compensation or statutory data from performance', function () {
    expect(performanceFilesMatching('~use\s+App\\\\Domain\\\\(Payroll|Compliance|Compensation)\\\\~'))->toBe([])
        ->and(performanceFilesMatching('~(EmployeeSalaryAssignment|PayrollRun|PayrollEntry|ComplianceRule|StatutoryReturn)\b~'))->toBe([]);
});

it('keeps the statutory engine and payroll free of performance data', function () {
    $offenders = [];
    foreach (['Domain/Compliance', 'Domain/Payroll'] as $dir) {
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path($dir))) as $file) {
            if ($file->isFile() && $file->getExtension() === 'php' && preg_match('~App\\\\Domain\\\\Performance\\\\~', file_get_contents($file->getPathname()))) {
                $offenders[] = $file->getFilename();
            }
        }
    }
    expect($offenders)->toBe([]);
});

it('contains no RMS / recruitment concepts in performance code', function () {
    expect(performanceFilesMatching('~\b(RecruitmentEdge|Rms|candidate|requisition|job_application|interview|offer_letter|ATS)\b~i'))->toBe([]);
});

it('makes no automated ranking, promotion, termination or PIP decisions in performance code', function () {
    expect(performanceFilesMatching('~\b(AiGateway|AiProvider|forcedDistribution|stackRank|autoPromote|autoTerminate|terminate\(|openPipAutomatically)\b~i'))->toBe([])
        // A PIP is only ever opened by a person through ImprovementPlans::open(), never from scoring.
        ->and(performanceFilesMatching('~ImprovementPlans\)->open\(|->open\(\$[a-z]+->employee~'))->toBe([]);
});

it('derives manager scope from the relationship resolver, never a raw manager_id = me shortcut', function () {
    $shortcuts = '~where\(\s*[\'"]manager_id[\'"]\s*,\s*(auth\(\)|\$me|\$user)~';
    $filament = collect(glob(app_path('Filament/{Resources/*,Resources/*/*,Pages,Support}/*.php'), GLOB_BRACE))
        ->filter(fn ($path) => Str::contains($path, ['Performance', 'Goal', 'Appraisal', 'OneOnOne', 'Feedback', 'Improvement', 'Calibration', 'CheckIn', 'Development', 'CareerPassport']))
        ->filter(fn ($path) => preg_match($shortcuts, file_get_contents($path)) || str_contains(file_get_contents($path), '->directReports()'))
        ->values()->all();

    expect(performanceFilesMatching($shortcuts))->toBe([])
        ->and(performanceFilesMatching('~->directReports\(\)~'))->toBe([])
        ->and($filament)->toBe([]);
});

it('binds tenant context in performance jobs and carries tenant-owned subjects in events', function () {
    foreach (glob(app_path('Domain/Performance/Jobs/*.php')) as $path) {
        $code = file_get_contents($path);
        expect($code)->toContain('implements')->toContain('TenantAwareJob')->toContain('new BindTenantContext');
    }
    $event = new ReflectionClass(PerformanceEvent::class);
    expect($event->getConstructor()->getParameters()[2]->getType()->getName())->toBe(Model::class);
});

it('keeps sensitive performance data out of the API and webhooks', function () {
    $api = file_get_contents(app_path('Http/Controllers/Api/V1/PerformanceController.php'));
    foreach (['private_notes', "'notes'", 'calibration_note', 'manager_summary', "'message'", 'went_well', 'blockers', "'reason'", 'objectives', "'outcome'", 'strengths', 'improvements'] as $field) {
        expect($api)->not->toContain("\$m->{$field}")->not->toContain('=> $f->message')->not->toContain("'{$field}' =>");
    }
    expect(config('peopleos.enterprise.webhook_events'))->not->toContain('performance.calibration.decision_recorded')->not->toContain('performance.pip.opened')->not->toContain('performance.pip.completed')
        ->and(config('peopleos.enterprise.webhook_redacted_context'))->toContain('from');
});

it('pins template versions and never deletes history in performance models', function () {
    foreach (['PerformanceTemplateVersion', 'GoalCheckIn', 'CalibrationAdjustment', 'CalibrationSession', 'ImprovementPlanCheckpoint'] as $model) {
        expect(file_get_contents(app_path("Domain/Performance/Models/{$model}.php")))->toMatch('~static::deleting\(~');
    }
    expect(file_get_contents(app_path('Domain/Performance/Services/Appraisals.php')))->not->toMatch('~->scale->~')
        ->and(file_get_contents(app_path('Filament/Support/PerformanceActions.php')))->not->toMatch('~cycle->scale->~');
});
