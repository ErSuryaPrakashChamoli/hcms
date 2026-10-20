<?php

use App\Domain\Career\Events\CareerEvent;
use App\Domain\Succession\Events\SuccessionEvent;
use App\Domain\Succession\Models\SuccessionPlan;
use App\Domain\Succession\Models\Successor;
use App\Domain\Talent\Events\TalentEvent;
use App\Domain\Talent\Models\TalentAssessment;
use App\Domain\Talent\Models\TalentProfile;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/*
 | Phase 9 §44: career, talent and succession architecture invariants. Static checks of the code
 | base; the behaviour behind each is covered by tests/Feature/{Career,Talent,Succession}.
 */

function talentFiles(): array
{
    $files = [];
    foreach (['Domain/Career', 'Domain/Talent', 'Domain/Succession'] as $dir) {
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path($dir))) as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[Str::after($file->getPathname(), base_path().'/')] = withoutComments(file_get_contents($file->getPathname()));
            }
        }
    }
    ksort($files);

    return $files;
}

/** Source without comments, so a docblock that states a boundary ("never a requisition") is not a hit. */
function withoutComments(string $code): string
{
    return collect(token_get_all($code))->map(fn ($t) => is_array($t) ? (in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true) ? '' : $t[1]) : $t)->implode('');
}

function talentFilesMatching(string $pattern, ?string $within = null): array
{
    return array_keys(array_filter(talentFiles(), fn ($code, $path) => ($within === null || str_contains($path, $within)) && preg_match($pattern, $code), ARRAY_FILTER_USE_BOTH));
}

/** Filament screens and API controllers built for Phase 9. */
function talentUiFiles(): array
{
    return collect(glob(app_path('{Filament/Resources/*,Filament/Resources/*/*,Filament/Resources/Employees/RelationManagers,Filament/Pages,Filament/Support,Http/Controllers/Api/V1}/*.php'), GLOB_BRACE))
        ->filter(fn ($path) => Str::contains($path, ['Career', 'Talent', 'Succession', 'Successor', 'Readiness', 'CriticalPosition', 'RoleRequirement']))
        ->mapWithKeys(fn ($path) => [Str::after($path, base_path().'/') => withoutComments(file_get_contents($path))])->all();
}

it('keeps career, talent and succession independent of RecruitmentEdge / RMS', function () {
    expect(talentFilesMatching('~\b(RecruitmentEdge|Rms[A-Z]\w*|rms_\w+|candidate_id|requisition\w*|job_application|interview_id|offer_id|Applicant\w*|JobOpening|ats_\w+)\b~i'))->toBe([])
        ->and(collect(talentUiFiles())->filter(fn ($code) => preg_match('~RecruitmentEdge|requisition|job_application|Applicant~i', $code))->keys()->all())->toBe([]);

    foreach (glob(database_path('migrations/2026_10_09_*.php')) as $migration) {
        expect(file_get_contents($migration))->not->toMatch('~rms_|candidate|requisition|recruit|applicant~i');
    }
});

it('never writes payroll, compensation or statutory data, and payroll and compliance never read talent', function () {
    expect(talentFilesMatching('~use\s+App\\\\Domain\\\\(Payroll|Compliance|Compensation)\\\\~'))->toBe([])
        ->and(talentFilesMatching('~\b(PayrollRun|PayrollEntry|EmployeeSalaryAssignment|SalaryStructure|ComplianceRule|StatutoryReturn|TdsAnnualLedger|ctc|salary)\b~i'))->toBe([]);

    $offenders = [];
    foreach (['Domain/Payroll', 'Domain/Compliance'] as $dir) {
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path($dir))) as $file) {
            if ($file->isFile() && $file->getExtension() === 'php' && preg_match('~App\\\\Domain\\\\(Career|Talent|Succession)\\\\~', file_get_contents($file->getPathname()))) {
                $offenders[] = $file->getFilename();
            }
        }
    }
    expect($offenders)->toBe([]);
});

it('reads performance only through its contracts and the relationship resolver, and never writes it', function () {
    // CareerPath / CareerAspiration are the career models Phase 7 placed in the Performance namespace;
    // Competency is read to validate role requirements. Nothing else from Performance is imported.
    $allowed = '~use\s+App\\\\Domain\\\\Performance\\\\(Contracts\\\\CompetencyEvidenceReader|Services\\\\PerformanceRelationships|Models\\\\CareerPath|Models\\\\CareerAspiration|Models\\\\Competency|Policies\\\\PerformanceConfigPolicy);~';
    $violations = [];
    foreach (talentFiles() as $path => $code) {
        preg_match_all('~use\s+App\\\\Domain\\\\Performance\\\\[^;]+;~', $code, $matches);
        foreach ($matches[0] as $import) {
            if (! preg_match($allowed, $import)) {
                $violations[] = "{$path}: {$import}";
            }
        }
    }
    expect($violations)->toBe([])
        ->and(talentFilesMatching('~\b(Appraisal|AppraisalReview|CalibrationAdjustment|ImprovementPlan|Goal::|final_rating|calibrated_rating)\b~'))->toBe([])
        ->and(talentFilesMatching('~Competency::(query\(\)->)?(create|update|delete)|Competency::where[^;]*->(update|delete)~'))->toBe([]);
});

it('never modifies learning or skills directly: development actions go through Phase 8 development plans', function () {
    // Learning and skills models are read; the only writes go through the DevelopmentPlans service.
    expect(talentFilesMatching('~->enrol\(|LearningAssignment|Learning::class\)|new\s+LearningEnrolment|LearningEnrolment::(query\(\)->)?create|LearningCompletion::(query\(\)->)?create|LearningCertificate::(query\(\)->)?create~'))->toBe([])
        ->and(talentFilesMatching('~SkillAssessments|->declare\(|->setTarget\(|EmployeeSkill::(query\(\)->)?create~'))->toBe([])
        ->and(talentFilesMatching('~DevelopmentPlanItem::(query\(\)->)?create|DevelopmentPlan::(query\(\)->)?create~'))->toBe([])
        ->and(talentFilesMatching('~\$this->development->(create|addItem)\(~'))->toBe(['app/Domain/Succession/Services/SuccessionPlans.php']);
});

it('makes no automated or AI employment decision', function () {
    expect(talentFilesMatching('~\b(AttritionRisk|AiGateway|AiProvider|flight_?risk|promotion_recommended|autoPromote|stackRank|rankEmployees|terminate\(|should_be_promoted|nine_box_score|talent_score)\b~i'))->toBe([])
        // Nothing here changes employment: no position assignment, lifecycle transition or pay change.
        ->and(talentFilesMatching('~AssignPositionAction|LifecycleEngine|EmployeePosition::(query\(\)->)?create|->transitionTo\(|EmployeeSalaryAssignment~'))->toBe([])
        ->and(collect(talentUiFiles())->filter(fn ($code) => preg_match('~AttritionRisk|AiGateway|flight.?risk~i', $code))->keys()->all())->toBe([]);
});

it('preserves tenant context: tenant-owned models, tenant columns, tenant-aware jobs and events with a subject', function () {
    foreach (talentFiles() as $path => $code) {
        if (str_contains($path, '/Models/')) {
            expect($code)->toMatch('~use\s+[^;]*\bBelongsToTenant\b~');
        }
        if (str_contains($path, '/Jobs/')) {
            expect($code)->toContain('TenantAwareJob')->toContain('new BindTenantContext')->toContain('ShouldBeUnique');
        }
    }
    foreach (['career_tracks', 'career_path_versions', 'role_requirement_versions', 'career_profiles', 'career_aspiration_entries', 'career_goals', 'mobility_interests', 'talent_pools', 'talent_pool_memberships', 'talent_profiles', 'talent_assessment_models', 'talent_assessment_model_versions', 'talent_assessments', 'talent_review_sessions', 'talent_review_items', 'critical_positions', 'critical_position_assessments', 'succession_plans', 'successors', 'readiness_assessments', 'talent_development_actions', 'talent_reminder_logs'] as $table) {
        expect(Schema::hasColumn($table, 'tenant_id'))->toBeTrue("{$table}.tenant_id");
    }
    foreach ([CareerEvent::class, TalentEvent::class, SuccessionEvent::class] as $event) {
        $parameter = (new ReflectionClass($event))->getConstructor()->getParameters()[2];
        expect($parameter->getName())->toBe('subject')->and($parameter->getType()->getName())->toBe(Model::class);
    }
});

it('derives manager scope from the relationship resolver, never a direct-reports or manager_id shortcut', function () {
    $shortcut = '~->directReports\(\)|where\(\s*[\'"]manager_id[\'"]\s*,~';
    expect(talentFilesMatching($shortcut))->toBe([])
        ->and(collect(talentUiFiles())->filter(fn ($code) => preg_match($shortcut, $code))->keys()->all())->toBe([]);
});

it('keeps confidential talent and succession fields encrypted, hidden and out of the API and webhooks', function () {
    foreach ([TalentProfile::class, TalentAssessment::class, SuccessionPlan::class, Successor::class] as $class) {
        $model = new $class;
        expect($model->getCasts()['confidential_notes'] ?? null)->toBe('encrypted', $class)
            ->and($model->getHidden())->toContain('confidential_notes');
    }
    foreach (['CareerController', 'TalentController', 'SuccessionController'] as $controller) {
        $api = file_get_contents(app_path("Http/Controllers/Api/V1/{$controller}.php"));
        foreach (['confidential_notes', '->strengths', 'development_gaps', '->reason', '->evidence', 'rationale', '->ratings', '->aspiration', 'development_priorities', '->notes', "'employee_id' =>"] as $needle) {
            expect($api)->not->toContain($needle);
        }
    }
    expect(config('peopleos.enterprise.webhook_events'))->not->toContain('succession.successor.added', 'succession.successor.removed', 'succession.readiness.assessed', 'talent.pool.membership_changed', 'talent.assessment.completed', 'succession.plan.created')
        ->and(config('peopleos.data_classification.highly_sensitive'))->toHaveKeys([TalentProfile::class, TalentAssessment::class, SuccessionPlan::class, Successor::class]);
});

it('never deletes or rewrites career, talent or succession history', function () {
    foreach (talentFiles() as $path => $code) {
        if (str_contains($path, '/Models/') && ! str_ends_with($path, 'TalentReminderLog.php')) {
            expect($code)->toMatch('~static::deleting\(~');
        }
    }
    foreach (['Career/Models/CareerPathVersion.php', 'Career/Models/RoleRequirementVersion.php', 'Career/Models/CareerAspirationEntry.php', 'Talent/Models/TalentAssessmentModelVersion.php', 'Talent/Models/TalentPoolMembership.php', 'Talent/Models/TalentAssessment.php', 'Succession/Models/CriticalPositionAssessment.php', 'Succession/Models/ReadinessAssessment.php', 'Succession/Models/Successor.php'] as $model) {
        expect(file_get_contents(app_path("Domain/{$model}")))->toMatch('~static::updating\(~');
    }
});
