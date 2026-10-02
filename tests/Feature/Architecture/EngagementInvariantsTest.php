<?php

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Audit\Exceptions\ImmutableAuditRecordException;
use App\Domain\Audit\Models\AuditEvent;
use App\Domain\Communication\Services\CommunicationPreferences;
use App\Domain\Engagement\Exceptions\EngagementRuleViolation;
use App\Domain\Engagement\Models\EmployeeFeedback;
use App\Domain\Engagement\Models\EngagementIdentity;
use App\Domain\Engagement\Models\SurveyAnswer;
use App\Domain\Engagement\Models\SurveyParticipation;
use App\Domain\Engagement\Models\SurveyQuestion;
use App\Domain\Engagement\Models\SurveyResponse;
use App\Domain\Engagement\Services\EngagementAnalytics;
use App\Domain\Engagement\Services\Feedback;
use App\Domain\Engagement\Services\SurveyResponses;
use App\Domain\Engagement\Services\Surveys;
use App\Domain\Enterprise\Models\WebhookDelivery;
use App\Support\Tenancy\BelongsToTenant;
use App\Support\Tenancy\Jobs\BindTenantContext;
use App\Support\Tenancy\Jobs\TenantAwareJob;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

require_once __DIR__.'/../Workflow/WorkflowTestHelpers.php';
require_once __DIR__.'/../Engagement/EngagementTestHelpers.php';

/*
 | Phase 13 §64 architecture invariants (1–24). Static checks read the Engagement and Communication
 | code with comments stripped (a docblock that names a boundary is not a hit). Behaviour in depth:
 | tests/Feature/Engagement (the anonymity matrix, privacy, communication, campaigns).
 */

function engagementSource(string $path): string
{
    return collect(token_get_all(file_get_contents($path)))->map(fn ($t) => is_array($t) ? (in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true) ? '' : $t[1]) : $t)->implode('');
}

/** @return array<string, string> relative path => source (comments stripped) */
function engagementFiles(string ...$dirs): array
{
    $files = [];
    foreach ($dirs ?: ['Domain/Engagement', 'Domain/Communication'] as $dir) {
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path($dir))) as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[Str::after($file->getPathname(), base_path().'/')] = engagementSource($file->getPathname());
            }
        }
    }

    return $files;
}

/** Files that write one of the classes directly (create / insert / upsert / new / update on the class). */
function engagementDirectWrites(array $classes, array $files): array
{
    $alternation = implode('|', array_map('preg_quote', $classes));
    $pattern = '/\b(?:'.$alternation.')::(?:query\(\)->)?(?:[a-zA-Z]+\([^;]*\)->)*(?:create|forceCreate|insert|insertOrIgnore|upsert|updateOrCreate|firstOrCreate|updateOrInsert|update|delete|increment)\(|new\s+(?:'.$alternation.')\s*\(/';

    return array_keys(array_filter($files, fn (string $src) => preg_match($pattern, $src) === 1));
}

function engagementModels(string $domain): array
{
    return collect(glob(app_path("Domain/{$domain}/Models/*.php")))->map(fn ($p) => "App\\Domain\\{$domain}\\Models\\".basename($p, '.php'))->all();
}

beforeEach(function () {
    $this->travelTo('2026-10-05 09:00:00');
    $this->tenant = provisionTenant();
    actAsTenant($this->tenant);
    $this->actors = engagementActors();
});

it('1–2 · scopes every Engagement and Communication model to a tenant', function () {
    $unscoped = collect([...engagementModels('Engagement'), ...engagementModels('Communication')])
        ->reject(fn ($c) => in_array(BelongsToTenant::class, class_uses_recursive($c), true))->values()->all();
    expect($unscoped)->toBe([]);
});

it('3 · freezes a survey version from submission on (settings and questions); corrections are new versions', function () {
    engagementTeam(5);
    $version = openEngagementSurvey($this->actors);
    expect(fn () => $version->update(['anonymity_mode' => 'identified']))->toThrow(RuntimeException::class)
        ->and(fn () => $version->update(['audience_criteria' => ['employee_ids' => [1]]]))->toThrow(RuntimeException::class)
        ->and(fn () => SurveyQuestion::query()->where('survey_version_id', $version->id)->first()->update(['prompt' => 'x']))->toThrow(RuntimeException::class)
        ->and(fn () => SurveyQuestion::query()->where('survey_version_id', $version->id)->first()->delete())->toThrow(RuntimeException::class)
        ->and(fn () => $version->delete())->toThrow(RuntimeException::class)
        ->and(app(Surveys::class)->checksum($version->fresh()))->toBe($version->fresh()->checksum);
});

it('4 · pins every response and answer to the exact version and that version\'s questions', function () {
    $staff = engagementTeam(5);
    $version = openEngagementSurvey($this->actors);
    app(SurveyResponses::class)->submit($version, $staff[0]->user, ['mood' => '4', 'tools' => ['wiki'], 'comment' => 'x']);
    $fks = collect(Schema::getForeignKeys('survey_answers'))->pluck('foreign_table', 'columns.0')->all();
    expect($fks)->toMatchArray(['survey_version_id' => 'survey_versions', 'response_id' => 'survey_responses', 'question_id' => 'survey_questions'])
        ->and(collect(Schema::getForeignKeys('survey_responses'))->pluck('foreign_table', 'columns.0')->all())->toMatchArray(['survey_version_id' => 'survey_versions']);
    foreach (SurveyAnswer::query()->with('question')->get() as $answer) {
        expect($answer->question->survey_version_id)->toBe($version->id)->and($answer->survey_version_id)->toBe($version->id);
    }
});

it('5 · keeps anonymous content free of any identity link and out of automatic auditing', function () {
    expect(Schema::getColumnListing('survey_responses'))->not->toContain('participation_id', 'user_id', 'created_at', 'updated_at')
        ->and(Schema::getColumnListing('survey_answers'))->not->toContain('employee_id', 'created_at')
        ->and(Schema::getColumnListing('employee_feedback'))->not->toContain('user_id', 'created_at', 'group_key');
    foreach ([SurveyResponse::class, SurveyAnswer::class, EmployeeFeedback::class, SurveyParticipation::class, EngagementIdentity::class] as $class) {
        expect(in_array(Auditable::class, class_uses_recursive($class), true))->toBeFalse();
    }
    $submit = engagementSource(app_path('Domain/Engagement/Services/SurveyResponses.php'));
    expect($submit)->toContain("'employee_id' => \$identified ? \$employee->id : null")->toContain('anonymous: ! $identified');
});

it('6 · reads survey content only through the privacy services (no other path to responses or answers)', function () {
    $allowed = ['app/Domain/Engagement/Services/SurveyResponses.php', 'app/Domain/Engagement/Services/EngagementAnalytics.php', 'app/Domain/Engagement/Services/ConfidentialIdentities.php'];
    $pattern = '/\b(SurveyResponse|SurveyAnswer)::|DB::table\([\'"]survey_(responses|answers)|from\([\'"]survey_(responses|answers)/';
    $touching = [];
    foreach (['Domain', 'Filament', 'Http', 'Console'] as $dir) {
        foreach (engagementFiles($dir) as $path => $src) {
            if (! str_contains($path, '/Models/') && preg_match($pattern, $src) === 1) {
                $touching[] = $path;
            }
        }
    }
    expect(array_values(array_diff($touching, $allowed)))->toBe([]);
});

it('7 · makes complementary suppression hold for any group sizes (property check)', function () {
    $analytics = app(EngagementAnalytics::class);
    mt_srand(13);
    for ($run = 0; $run < 500; $run++) {
        $k = mt_rand(3, 7);
        $counts = [];
        foreach (range(1, mt_rand(1, 6)) as $g) {
            $counts["g{$g}"] = mt_rand(0, 12);
        }
        $total = array_sum($counts) + mt_rand(0, 3); // respondents without a group key
        if ($total < $k) {
            continue; // below k overall nothing is shown at all (results() returns before grouping)
        }
        $shown = $analytics->visible($counts, $total, $k);
        $remainder = $total - array_sum(array_intersect_key($counts, array_flip($shown)));
        foreach ($shown as $key) {
            expect($counts[$key])->toBeGreaterThanOrEqual($k);
        }
        expect($remainder === 0 || $remainder >= $k)->toBeTrue();
    }
});

it('8 · releases free text only overall, above the text threshold, never with handles for anonymous surveys, never via the API', function () {
    $src = engagementSource(app_path('Domain/Engagement/Services/EngagementAnalytics.php'));
    expect($src)->toContain('$this->textK()')->toContain("\$version->anonymity_mode === 'confidential' && \$user->hasPermission('engagement.confidential_identity')")->toContain('shuffle(');
    $api = engagementSource(app_path('Http/Controllers/Api/V1/EngagementController.php'));
    expect($api)->toContain("reject(fn (\$q) => \$q['type'] === 'text')")->not->toContain('comments(');
});

it('9–11 · duplicates neither the Service Desk, the Knowledge Base nor the notification infrastructure', function () {
    $files = engagementFiles();
    $migrations = file_get_contents(database_path('migrations/2026_10_13_100001_engagement_foundation.php')).file_get_contents(database_path('migrations/2026_10_13_100002_communication_extension.php'));
    expect(engagementDirectWrites(['Ticket', 'Grievance', 'TicketComment', 'Article', 'ArticleVersion', 'NotificationDelivery', 'DatabaseNotification'], $files))->toBe([])
        ->and(preg_match("/Schema::create\('(tickets?|cases?|grievances?|articles?|policies|notifications?|notification_deliveries|deliveries)'/", $migrations))->toBe(0)
        ->and(array_keys(array_filter($files, fn ($src) => preg_match('/Mail::|->notify\(|Notification::send\(|implements\s+Channel\b/', $src) === 1)))->toBe([])
        ->and(engagementSource(app_path('Domain/Engagement/Services/Feedback.php')))->toContain('openGeneric(')->toContain('->raise(')
        ->and(engagementSource(app_path('Domain/Communication/Services/CommunicationDelivery.php')))->toContain('$this->notifier->send(');
});

it('12–16 · never mutates Performance, Compensation, Payroll, statutory, Learning or Career / Talent / Succession records', function () {
    $foreign = ['Appraisal', 'Goal', 'FeedbackEntry', 'ImprovementPlan', 'AppraisalRating', 'EmployeeSalaryAssignment', 'CompensationChange', 'PayrollRun', 'PayrollEntry', 'Payslip',
        'PayrollAdjustment', 'ComplianceRule', 'ComplianceRuleVerification', 'ComplianceRuleNotice', 'LearningEnrolment', 'LearningAssignment', 'LearningCompletion',
        'CareerProfile', 'TalentPoolMembership', 'SuccessionPlan', 'Successor', 'Employee', 'Person', 'EmployeePosition', 'ReportingRelationship'];
    $files = engagementFiles();
    expect(engagementDirectWrites($foreign, $files))->toBe([])
        ->and(array_keys(array_filter($files, fn ($src) => preg_match('/Domain\\\\(Performance|Compensation|Payroll|Compliance|Learning|Career|Talent|Succession|Development)\\\\(Services|Actions)\\\\/', $src) === 1)))->toBe([]);
});

it('17 · has no RecruitmentEdge / RMS dependency', function () {
    $pattern = '/RecruitmentEdge|App\\\\Domain\\\\(Recruitment|Rms)\\\\|use [^;]*\\\\(Candidate|Requisition|JobApplication|Interview)[A-Za-z]*;|\\b(Candidate|Requisition|JobApplication)::/';
    expect(array_keys(array_filter(engagementFiles(), fn ($src) => preg_match($pattern, $src) === 1)))->toBe([]);
});

it('18 · binds every queued job to its tenant and runs the schedules without overlap on one server', function () {
    $jobs = collect([...glob(app_path('Domain/Engagement/Jobs/*.php')), ...glob(app_path('Domain/Communication/Jobs/*.php'))])
        ->map(fn ($p) => str_replace(['/', '.php'], ['\\', ''], 'App/'.Str::after($p, app_path().'/')));
    expect($jobs)->toHaveCount(4);
    foreach ($jobs as $job) {
        expect(is_subclass_of($job, TenantAwareJob::class))->toBeTrue()
            ->and(engagementSource((new ReflectionClass($job))->getFileName()))->toContain('new BindTenantContext');
    }
    $console = file_get_contents(base_path('routes/console.php'));
    expect($console)->toContain("Schedule::command('peopleos:engagement:process')->everyFifteenMinutes()->withoutOverlapping()->onOneServer()")
        ->toContain("Schedule::command('peopleos:communication:process')->everyFiveMinutes()->withoutOverlapping()->onOneServer()")
        ->and(BindTenantContext::class)->toBeString();
});

it('19 · makes campaign creation and launch idempotent (key, unique index, locked status transition)', function () {
    $indexes = collect(Schema::getIndexes('engagement_campaigns'));
    expect($indexes->firstWhere('name', 'engagement_campaigns_idempotency'))->toMatchArray(['unique' => true, 'columns' => ['tenant_id', 'idempotency_key']]);
    $src = engagementSource(app_path('Domain/Engagement/Services/Campaigns.php'));
    expect($src)->toContain("lockForUpdate()->firstOrFail();\n                if (\$current->status !== 'scheduled')");
});

it('20 · makes survey submission idempotent at the database: one participation per person, one response per person and period, one per key', function () {
    $staff = engagementTeam(5);
    $identified = openEngagementSurvey($this->actors, ['anonymity_mode' => 'identified']);
    app(SurveyResponses::class)->submit($identified, $staff[0]->user, ['mood' => '4'], 'k1');
    app(SurveyResponses::class)->submit($identified, $staff[0]->user, ['mood' => '4'], 'k1');
    app(SurveyResponses::class)->submit($identified, $staff[0]->user, ['mood' => '2'], 'k2');
    expect(SurveyResponse::query()->count())->toBe(1);
    $unique = fn (string $table) => collect(Schema::getIndexes($table))->where('unique', true)->pluck('columns')->all();
    expect($unique('survey_participations'))->toContain(['survey_version_id', 'employee_id'])
        ->and($unique('survey_responses'))->toContain(['survey_version_id', 'employee_id', 'period_key', 'active_key'], ['survey_version_id', 'employee_id', 'idempotency_key'])
        ->and($unique('engagement_reminder_logs'))->toContain(['tenant_id', 'reminder', 'subject_type', 'subject_id', 'bucket'])
        ->and($unique('communication_recipients'))->toContain(['announcement_id', 'employee_id']);
});

it('21 · keeps the audit trail append-only', function () {
    $staff = engagementTeam(5);
    $version = openEngagementSurvey($this->actors);
    app(SurveyResponses::class)->submit($version, $staff[0]->user, ['mood' => '4']);
    $event = AuditEvent::query()->where('action', 'SURVEY_RESPONSE_SUBMITTED')->firstOrFail();
    expect(fn () => $event->update(['reason' => 'tampered']))->toThrow(ImmutableAuditRecordException::class)
        ->and(fn () => $event->delete())->toThrow(ImmutableAuditRecordException::class)
        ->and(array_keys(array_filter(engagementFiles(), fn ($src) => preg_match('/AuditEvent::(query\(\)->)?(where|update|delete)|audit_events/', $src) === 1)))->toBe([]);
});

it('22 · keeps answers, comments and feedback text out of audit records and webhooks', function () {
    $staff = engagementTeam(5);
    $version = openEngagementSurvey($this->actors, ['anonymity_mode' => 'identified']);
    app(SurveyResponses::class)->submit($version, $staff[0]->user, ['mood' => '4', 'comment' => 'SECRET-ANSWER-TEXT']);
    app(Feedback::class)->submit($staff[1]->user, 'anonymous', 'other', 'SECRET-FEEDBACK-TEXT');
    $audit = AuditEvent::query()->with('fieldChanges')->get()->toJson();
    expect($audit)->not->toContain('SECRET-ANSWER-TEXT')->not->toContain('SECRET-FEEDBACK-TEXT')
        ->and(WebhookDelivery::query()->get()->toJson())->not->toContain('SECRET')
        ->and(collect(config('peopleos.enterprise.webhook_events'))->filter(fn ($e) => preg_match('/^(survey|engagement|feedback|campaign|communication)\..*(response|answer|feedback|comment)|^feedback\./', $e))->all())->toBe([]);
});

it('23 · enforces manager scope: only the manager\'s own team group, only when the survey allows it', function () {
    $manager = engagementStaff(null, null, ['engagement.participate', 'engagement.team_results']);
    $other = engagementStaff(null, null, ['engagement.participate', 'engagement.team_results']);
    $team = engagementTeam(6, null, $manager);
    $others = engagementTeam(5, null, $other);
    $hidden = openEngagementSurvey($this->actors, ['breakdown_dimension' => 'manager'], null, 'HIDDEN');
    $shared = openEngagementSurvey($this->actors, ['breakdown_dimension' => 'manager', 'result_visibility' => ['hr' => true, 'managers' => true]], null, 'SHARED');
    $analytics = app(EngagementAnalytics::class);
    expect($analytics->access($hidden, $manager->user->fresh()))->toBeNull()
        ->and($analytics->access($shared, $manager->user->fresh()))->toBe('manager')
        ->and($analytics->access($shared, tenantUser($this->tenant, ['engagement.team_results'])))->toBeNull();
    foreach ([...$team, ...$others] as $e) {
        app(SurveyResponses::class)->submit($shared, $e->user, ['mood' => '3']);
    }
    app(Surveys::class)->close($shared, $this->actors['preparer']);
    expect(collect($analytics->results($shared->refresh(), $manager->user->fresh())['groups'])->pluck('key')->all())->toBe(["manager:{$manager->id}"]);
});

it('24 · limits self-service to the employee\'s own records', function () {
    $staff = engagementTeam(5);
    $outsider = engagementStaff();
    $version = openEngagementSurvey($this->actors);
    $late = engagementStaff();
    expect(fn () => app(SurveyResponses::class)->submit($version, $late->user, ['mood' => '3']))->toThrow(EngagementRuleViolation::class, 'not invited')
        ->and(fn () => app(CommunicationPreferences::class)->set($staff[0], 'newsletter', false, false, $staff[1]->user))->toThrow(EngagementRuleViolation::class)
        ->and(app(SurveyResponses::class)->mySurveys($staff[0]->user)->pluck('participation.employee_id')->unique()->all())->toBe([$staff[0]->id]);
    app(Feedback::class)->submit($staff[0]->user, 'identified', 'other', 'Mine only please');
    expect(app(Feedback::class)->mine($staff[1]->user)->count())->toBe(0)
        ->and(fn () => app(Feedback::class)->inbox($staff[1]->user))->toThrow(EngagementRuleViolation::class)
        ->and($outsider->user->can('viewAny', EmployeeFeedback::class))->toBeFalse();
});
