<?php

use App\Domain\Development\Events\DevelopmentEvent;
use App\Domain\Learning\Events\LearningEvent;
use App\Domain\Skills\Events\SkillEvent;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/*
 | Phase 8 §58: learning, skills and development architecture invariants. Static checks of the code
 | base; the behaviour behind each is covered by tests/Feature/Learning.
 */

function learningFiles(): array
{
    $files = [];
    foreach (['Domain/Learning', 'Domain/Skills', 'Domain/Development'] as $dir) {
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path($dir))) as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[Str::after($file->getPathname(), base_path().'/')] = file_get_contents($file->getPathname());
            }
        }
    }
    ksort($files);

    return $files;
}

function learningFilesMatching(string $pattern, array $except = []): array
{
    return array_values(array_diff(array_keys(array_filter(learningFiles(), fn ($code) => preg_match($pattern, $code))), $except));
}

it('never depends on RecruitmentEdge / RMS or references RMS identifiers', function () {
    expect(learningFilesMatching('~\b(RecruitmentEdge|Rms[A-Z]\w*|rms_\w+|candidate_id|requisition_id|job_application|interview_id|offer_id)\b~i'))->toBe([]);

    foreach (glob(database_path('migrations/2026_10_08_*.php')) as $migration) {
        expect(file_get_contents($migration))->not->toMatch('~rms_|candidate|requisition|recruit~i');
    }
});

it('never writes payroll, compensation or statutory data, and payroll and compliance never read learning', function () {
    expect(learningFilesMatching('~use\s+App\\\\Domain\\\\(Payroll|Compliance|Compensation)\\\\~'))->toBe([])
        ->and(learningFilesMatching('~\b(PayrollRun|PayrollEntry|EmployeeSalaryAssignment|ComplianceRule|StatutoryReturn|TdsAnnualLedger)\b~'))->toBe([]);

    $offenders = [];
    foreach (['Domain/Payroll', 'Domain/Compliance'] as $dir) {
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path($dir))) as $file) {
            if ($file->isFile() && $file->getExtension() === 'php' && preg_match('~App\\\\Domain\\\\(Learning|Skills|Development)\\\\~', file_get_contents($file->getPathname()))) {
                $offenders[] = $file->getFilename();
            }
        }
    }
    expect($offenders)->toBe([]);
});

it('reads performance only through the development-need boundary and the relationship resolver', function () {
    $allowed = '~use\s+App\\\\Domain\\\\Performance\\\\(Contracts\\\\DevelopmentNeedsReader|Services\\\\DevelopmentNeeds|Services\\\\PerformanceRelationships|Models\\\\DevelopmentNeed);~';
    $violations = [];
    foreach (learningFiles() as $path => $code) {
        preg_match_all('~use\s+App\\\\Domain\\\\Performance\\\\[^;]+;~', $code, $matches);
        foreach ($matches[0] as $import) {
            if (! preg_match($allowed, $import)) {
                $violations[] = "{$path}: {$import}";
            }
        }
    }
    expect($violations)->toBe([]);
});

it('makes no automated employment decisions from performance or learning data', function () {
    expect(learningFilesMatching('~\b(Appraisal|final_rating|calibrated_rating|ImprovementPlans|promotion_recommended|autoPromote|terminate\(|AiGateway|AiProvider|stackRank)\b~'))->toBe([]);
});

it('derives manager scope from the relationship resolver, never a direct-reports or manager_id shortcut', function () {
    $shortcut = '~->directReports\(\)|where\(\s*[\'"]manager_id[\'"]\s*,\s*(auth\(\)|\$me|\$user)~';
    $filament = collect(glob(app_path('Filament/{Resources/*,Resources/*/*,Resources/*/*/*,Pages,Support}/*.php'), GLOB_BRACE))
        ->filter(fn ($path) => Str::contains($path, ['Learning', 'Course', 'Skill', 'Development', 'Certificat', 'Training', 'Program', 'Instructor', 'Provider']))
        ->filter(fn ($path) => preg_match($shortcut, file_get_contents($path)))->values()->all();

    expect(learningFilesMatching($shortcut))->toBe([])->and($filament)->toBe([]);
});

it('keeps learning jobs tenant-aware and learning events carrying their tenant-owned subject', function () {
    foreach (glob(app_path('Domain/Learning/Jobs/*.php')) as $path) {
        $code = file_get_contents($path);
        expect($code)->toContain('TenantAwareJob')->toContain('new BindTenantContext')->toContain('ShouldBeUnique');
    }
    foreach ([LearningEvent::class, SkillEvent::class, DevelopmentEvent::class] as $event) {
        $parameter = (new ReflectionClass($event))->getConstructor()->getParameters()[2];
        expect($parameter->getName())->toBe('subject')->and($parameter->getType()->getName())->toBe(Model::class);
    }
});

it('keeps sensitive learning fields out of the API and webhook payloads', function () {
    $api = file_get_contents(app_path('Http/Controllers/Api/V1/LearningController.php'));
    foreach (['private_notes', 'verification_code\'', 'document_path\'', '->evidence', '->comments', '$p->summary', "'employee_id' =>"] as $needle) {
        expect($api)->not->toContain($needle);
    }
    expect($api)->toContain("hasScope('learning.costs')")
        ->and(config('peopleos.enterprise.webhook_events'))->not->toContain('learning.certificate.revoked')
        ->and(config('peopleos.enterprise.webhook_redacted_context'))->toContain('from', 'reason', 'note');
});

it('never deletes or rewrites learning history', function () {
    foreach ([
        'Domain/Learning/Models/CourseVersion.php', 'Domain/Learning/Models/LearningPathVersion.php', 'Domain/Learning/Models/LearningProgramVersion.php',
        'Domain/Learning/Models/LearningCompletion.php', 'Domain/Learning/Models/LearningCertificate.php', 'Domain/Learning/Models/LearningEnrolment.php',
        'Domain/Learning/Models/AssessmentAttempt.php', 'Domain/Skills/Models/SkillScaleVersion.php', 'Domain/Skills/Models/EmployeeSkill.php',
    ] as $model) {
        expect(file_get_contents(app_path($model)))->toMatch('~static::deleting\(~')->toMatch('~static::updating\(~');
    }
    // A rehire keeps the same employee, so learning history is keyed by employee_id for life.
    expect(file_get_contents(app_path('Domain/Employment/Actions/RehireEmployeeAction.php')))->not->toMatch('~Employee::(create|query\(\)->create)~');
});
