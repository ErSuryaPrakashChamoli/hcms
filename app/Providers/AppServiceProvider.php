<?php

namespace App\Providers;

use App\Domain\Ai\Models\AiInteraction;
use App\Domain\Ai\Policies\AiInteractionPolicy;
use App\Domain\Ai\Providers\AiProvider;
use App\Domain\Ai\Providers\AnthropicProvider;
use App\Domain\Ai\Providers\NullProvider;
use App\Domain\Alumni\Models\AlumniProfile;
use App\Domain\Alumni\Models\AlumniRequest;
use App\Domain\Alumni\Policies\AlumniPolicy;
use App\Domain\Analytics\Models\Dashboard;
use App\Domain\Analytics\Models\Report;
use App\Domain\Analytics\Models\ReportSchedule;
use App\Domain\Analytics\Policies\DashboardPolicy;
use App\Domain\Analytics\Policies\ReportPolicy;
use App\Domain\Assets\Models\Asset;
use App\Domain\Assets\Models\AssetAssignment;
use App\Domain\Assets\Models\AssetCategory;
use App\Domain\Assets\Models\AssetModel;
use App\Domain\Assets\Models\AssetRepair;
use App\Domain\Assets\Policies\AssetPolicy;
use App\Domain\Attendance\Contracts\LeaveDayResolver;
use App\Domain\Attendance\Models\AttendanceDevice;
use App\Domain\Attendance\Models\AttendancePunch;
use App\Domain\Attendance\Models\AttendanceRecord;
use App\Domain\Attendance\Models\AttendanceRegularisation;
use App\Domain\Attendance\Models\Holiday;
use App\Domain\Attendance\Models\HolidayCalendar;
use App\Domain\Attendance\Models\HolidayCalendarRule;
use App\Domain\Attendance\Models\Shift;
use App\Domain\Attendance\Models\ShiftBreak;
use App\Domain\Attendance\Models\WorkSchedule;
use App\Domain\Attendance\Models\WorkScheduleAssignment;
use App\Domain\Attendance\Models\WorkScheduleRule;
use App\Domain\Attendance\Policies\AttendanceConfigPolicy;
use App\Domain\Attendance\Policies\AttendanceRecordPolicy;
use App\Domain\Audit\Listeners\RecordAuthenticationEvents;
use App\Domain\Audit\Models\AuditEvent;
use App\Domain\Bgv\Models\BgvCase;
use App\Domain\Bgv\Models\BgvCheck;
use App\Domain\Bgv\Policies\BgvPolicy;
use App\Domain\Career\Models\CareerAspirationEntry;
use App\Domain\Career\Models\CareerGoal;
use App\Domain\Career\Models\CareerPathVersion;
use App\Domain\Career\Models\CareerProfile;
use App\Domain\Career\Models\CareerTrack;
use App\Domain\Career\Models\MobilityInterest;
use App\Domain\Career\Models\RoleRequirementVersion;
use App\Domain\Career\Policies\CareerArchitecturePolicy;
use App\Domain\Career\Policies\CareerRecordPolicy;
use App\Domain\Communication\Models\Announcement;
use App\Domain\Communication\Models\CommunicationPreference;
use App\Domain\Communication\Models\CommunicationRecipient;
use App\Domain\Communication\Policies\AnnouncementPolicy;
use App\Domain\Communication\Policies\CommunicationRecordPolicy;
use App\Domain\Compensation\Contracts\CompensationOutput;
use App\Domain\Compensation\Models\CompensationBudget;
use App\Domain\Compensation\Models\CompensationChange;
use App\Domain\Compensation\Models\CompensationCycle;
use App\Domain\Compensation\Models\CompensationRange;
use App\Domain\Compensation\Models\EmployeeSalaryAssignment;
use App\Domain\Compensation\Models\SalaryStructure;
use App\Domain\Compensation\Models\SalaryStructureComponent;
use App\Domain\Compensation\Models\SalaryStructureVersion;
use App\Domain\Compensation\Policies\CompensationConfigPolicy;
use App\Domain\Compensation\Policies\CompensationPlanningPolicy;
use App\Domain\Compensation\Policies\CompensationPolicy;
use App\Domain\Compensation\Services\CompensationLedger;
use App\Domain\Compliance\Models\CompanyStatutoryProfile;
use App\Domain\Compliance\Models\ComplianceEvidenceDocument;
use App\Domain\Compliance\Models\ComplianceRule;
use App\Domain\Compliance\Models\ComplianceRuleNotice;
use App\Domain\Compliance\Models\ComplianceRuleParameter;
use App\Domain\Compliance\Models\ComplianceRuleVerification;
use App\Domain\Compliance\Models\EmployeeTaxDeclaration;
use App\Domain\Compliance\Models\EpfReturnEntry;
use App\Domain\Compliance\Models\EpfReturnRevision;
use App\Domain\Compliance\Models\EpfReturnRun;
use App\Domain\Compliance\Models\EsiReturnEntry;
use App\Domain\Compliance\Models\EsiReturnRun;
use App\Domain\Compliance\Models\EstablishmentStatutoryProfile;
use App\Domain\Compliance\Models\LwfReturn;
use App\Domain\Compliance\Models\LwfReturnEntry;
use App\Domain\Compliance\Models\ParallelPayrollLine;
use App\Domain\Compliance\Models\ParallelPayrollRun;
use App\Domain\Compliance\Models\ProfessionalTaxProfile;
use App\Domain\Compliance\Models\ProfessionalTaxReturn;
use App\Domain\Compliance\Models\ProfessionalTaxReturnEntry;
use App\Domain\Compliance\Models\ProfessionalTaxRuleVersion;
use App\Domain\Compliance\Models\StatutoryExportLayout;
use App\Domain\Compliance\Models\StatutoryReconciliation;
use App\Domain\Compliance\Models\StatutoryRegistration;
use App\Domain\Compliance\Models\StatutoryReturn;
use App\Domain\Compliance\Models\StatutoryReturnAction;
use App\Domain\Compliance\Models\StatutorySnapshot;
use App\Domain\Compliance\Models\TdsAnnualLedger;
use App\Domain\Compliance\Models\TdsCertificate;
use App\Domain\Compliance\Models\TdsEmployeeInvestment;
use App\Domain\Compliance\Models\TdsFinancialYear;
use App\Domain\Compliance\Models\TdsProfile;
use App\Domain\Compliance\Models\TdsQuarterlyReturn;
use App\Domain\Compliance\Models\TdsQuarterlyReturnEntry;
use App\Domain\Compliance\Policies\ComplianceRulePolicy;
use App\Domain\Compliance\Policies\StatutoryRegistrationPolicy;
use App\Domain\Compliance\Policies\StatutoryReturnPolicy;
use App\Domain\Compliance\Policies\TaxDeclarationPolicy;
use App\Domain\Compliance\Policies\TdsPolicy;
use App\Domain\Compliance\Services\ComplianceRules;
use App\Domain\Compliance\Services\FinancialYear;
use App\Domain\Configuration\Models\ConfigurationChange;
use App\Domain\Configuration\Models\CustomField;
use App\Domain\Configuration\Models\CustomFieldValue;
use App\Domain\Configuration\Models\Form;
use App\Domain\Configuration\Models\FormSubmission;
use App\Domain\Configuration\Models\FormVersion;
use App\Domain\Configuration\Models\Policy;
use App\Domain\Configuration\Models\PolicyAssignmentRule;
use App\Domain\Configuration\Models\PolicyVersion;
use App\Domain\Configuration\Policies\ConfigurationChangePolicy;
use App\Domain\Configuration\Policies\CustomFieldPolicy;
use App\Domain\Configuration\Policies\FormPolicy;
use App\Domain\Configuration\Policies\PolicyPolicy;
use App\Domain\Development\Models\DevelopmentPlan;
use App\Domain\Development\Models\DevelopmentPlanItem;
use App\Domain\Development\Policies\DevelopmentPlanPolicy;
use App\Domain\Documents\Models\DocumentType;
use App\Domain\Documents\Models\EmployeeDocument;
use App\Domain\Documents\Policies\DocumentTypePolicy;
use App\Domain\Documents\Policies\EmployeeDocumentPolicy;
use App\Domain\Employment\Contracts\PositionAssignmentGuard;
use App\Domain\Employment\Imports\EmployeeImport;
use App\Domain\Employment\Models\Employee;
use App\Domain\Employment\Models\EmployeeBankAccount;
use App\Domain\Employment\Models\EmployeePosition;
use App\Domain\Employment\Models\EmployeeStatutoryDetail;
use App\Domain\Employment\Models\ReportingRelationship;
use App\Domain\Employment\Policies\EmployeeDataPolicy;
use App\Domain\Employment\Policies\EmployeeImportPolicy;
use App\Domain\Employment\Policies\EmployeePolicy;
use App\Domain\Employment\Policies\SensitiveEmployeeDataPolicy;
use App\Domain\Engagement\Listeners\EngagementWorkflowBridge;
use App\Domain\Engagement\Models\Audience;
use App\Domain\Engagement\Models\CampaignItem;
use App\Domain\Engagement\Models\EmployeeFeedback;
use App\Domain\Engagement\Models\EngagementCampaign;
use App\Domain\Engagement\Models\EngagementIdentity;
use App\Domain\Engagement\Models\Survey;
use App\Domain\Engagement\Models\SurveyAnswer;
use App\Domain\Engagement\Models\SurveyParticipation;
use App\Domain\Engagement\Models\SurveyQuestion;
use App\Domain\Engagement\Models\SurveyResponse;
use App\Domain\Engagement\Models\SurveyVersion;
use App\Domain\Engagement\Policies\EmployeeFeedbackPolicy;
use App\Domain\Engagement\Policies\EngagementConfigPolicy;
use App\Domain\Engagement\Policies\EngagementRecordPolicy;
use App\Domain\Engagement\Services\SurveyTasks;
use App\Domain\Enterprise\Models\ExchangeRate;
use App\Domain\Enterprise\Models\SsoConnection;
use App\Domain\Enterprise\Models\WebhookDelivery;
use App\Domain\Enterprise\Models\WebhookEndpoint;
use App\Domain\Enterprise\Policies\EnterprisePolicy;
use App\Domain\Enterprise\Services\WebhookEventBridge;
use App\Domain\Exit\Models\ExitCase;
use App\Domain\Exit\Models\ExitClearance;
use App\Domain\Exit\Models\ExitInterview;
use App\Domain\Exit\Models\FinalSettlement;
use App\Domain\Exit\Policies\ExitCasePolicy;
use App\Domain\Exit\Policies\SettlementPolicy;
use App\Domain\Experience\Contracts\SurveyTaskProvider;
use App\Domain\Experience\Services\ApprovalCenter;
use App\Domain\Experience\Services\ExperienceNavigation;
use App\Domain\Experience\Services\ExperiencePreferences;
use App\Domain\Experience\Services\ModuleCatalogue;
use App\Domain\Experience\Services\PeopleVisibility;
use App\Domain\Experience\Services\RoleLens;
use App\Domain\Grievance\Models\Grievance;
use App\Domain\Grievance\Models\GrievanceCategory;
use App\Domain\Grievance\Policies\GrievanceConfigPolicy;
use App\Domain\Grievance\Policies\GrievancePolicy;
use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Policies\AuditEventPolicy;
use App\Domain\Identity\Policies\RolePolicy;
use App\Domain\Identity\Policies\TenantFeaturePolicy;
use App\Domain\Identity\Policies\TenantPolicy;
use App\Domain\Identity\Policies\TenantSettingPolicy;
use App\Domain\Identity\Policies\UserPolicy;
use App\Domain\Identity\Services\AccessScopes;
use App\Domain\Identity\Services\AuthorizationContext;
use App\Domain\Identity\Services\CurrentEmployee;
use App\Domain\Identity\Services\PermissionRegistry;
use App\Domain\Integration\Models\ApiKey;
use App\Domain\Integration\Models\ExternalReference;
use App\Domain\Integration\Models\InboundEvent;
use App\Domain\Integration\Models\IntegrationMapping;
use App\Domain\Integration\Models\IntegrationSystem;
use App\Domain\Integration\Policies\ApiKeyPolicy;
use App\Domain\Integration\Policies\IntegrationPolicy;
use App\Domain\Knowledge\Models\Article;
use App\Domain\Knowledge\Policies\ArticlePolicy;
use App\Domain\Learning\Listeners\LearningWorkflowBridge;
use App\Domain\Learning\Models\Assessment;
use App\Domain\Learning\Models\AssessmentAttempt;
use App\Domain\Learning\Models\Course;
use App\Domain\Learning\Models\CourseVersion;
use App\Domain\Learning\Models\LearningAssignment;
use App\Domain\Learning\Models\LearningCertificate;
use App\Domain\Learning\Models\LearningCompletion;
use App\Domain\Learning\Models\LearningCost;
use App\Domain\Learning\Models\LearningEnrolment;
use App\Domain\Learning\Models\LearningEvidence;
use App\Domain\Learning\Models\LearningInstructor;
use App\Domain\Learning\Models\LearningPath;
use App\Domain\Learning\Models\LearningPathVersion;
use App\Domain\Learning\Models\LearningProgram;
use App\Domain\Learning\Models\LearningProgramParticipant;
use App\Domain\Learning\Models\LearningProgramVersion;
use App\Domain\Learning\Models\LearningProvider;
use App\Domain\Learning\Models\TrainingSession;
use App\Domain\Learning\Policies\EnrolmentPolicy;
use App\Domain\Learning\Policies\LearningConfigPolicy;
use App\Domain\Learning\Policies\LearningCostPolicy;
use App\Domain\Leave\Listeners\LeaveWorkflowBridge;
use App\Domain\Leave\Models\LeaveBalance;
use App\Domain\Leave\Models\LeaveEncashment;
use App\Domain\Leave\Models\LeaveLedgerEntry;
use App\Domain\Leave\Models\LeaveRequest;
use App\Domain\Leave\Models\LeaveType;
use App\Domain\Leave\Policies\LeaveRequestPolicy;
use App\Domain\Leave\Policies\LeaveTypePolicy;
use App\Domain\Leave\Services\AttendanceLeaveDayResolver;
use App\Domain\Letters\Models\Letter;
use App\Domain\Letters\Models\LetterTemplate;
use App\Domain\Letters\Policies\LetterPolicy;
use App\Domain\Letters\Policies\LetterTemplatePolicy;
use App\Domain\Lifecycle\Events\EmployeeLifecycleChanged;
use App\Domain\Lifecycle\Models\EmployeeLifecycleTransition;
use App\Domain\Lifecycle\Models\EmployeeTimelineEntry;
use App\Domain\Notifications\Listeners\NotificationEventBridge;
use App\Domain\Notifications\Models\NotificationDelivery;
use App\Domain\Notifications\Models\NotificationRule;
use App\Domain\Notifications\Models\NotificationTemplate;
use App\Domain\Notifications\Policies\NotificationDeliveryPolicy;
use App\Domain\Notifications\Policies\NotificationPolicy;
use App\Domain\Onboarding\Listeners\AutoStartOnboarding;
use App\Domain\Onboarding\Models\OnboardingPlan;
use App\Domain\Onboarding\Models\OnboardingTask;
use App\Domain\Onboarding\Models\OnboardingTemplate;
use App\Domain\Onboarding\Models\OnboardingTemplateItem;
use App\Domain\Onboarding\Policies\OnboardingPolicy;
use App\Domain\Onboarding\Policies\OnboardingTaskPolicy;
use App\Domain\Organisation\Models\BusinessUnit;
use App\Domain\Organisation\Models\Company;
use App\Domain\Organisation\Models\CostCentre;
use App\Domain\Organisation\Models\Department;
use App\Domain\Organisation\Models\Designation;
use App\Domain\Organisation\Models\Division;
use App\Domain\Organisation\Models\EmployeeCategory;
use App\Domain\Organisation\Models\EmployeeEstablishmentAssignment;
use App\Domain\Organisation\Models\EmploymentType;
use App\Domain\Organisation\Models\Establishment;
use App\Domain\Organisation\Models\Grade;
use App\Domain\Organisation\Models\JobFamily;
use App\Domain\Organisation\Models\LegalEntity;
use App\Domain\Organisation\Models\Level;
use App\Domain\Organisation\Models\Location;
use App\Domain\Organisation\Models\OrganisationNode;
use App\Domain\Organisation\Models\ProfitCentre;
use App\Domain\Organisation\Models\Team;
use App\Domain\Organisation\Models\WorkMode;
use App\Domain\Organisation\Policies\CompanyPolicy;
use App\Domain\Organisation\Policies\EstablishmentAssignmentPolicy;
use App\Domain\Organisation\Policies\EstablishmentPolicy;
use App\Domain\Organisation\Policies\LegalEntityPolicy;
use App\Domain\Organisation\Policies\OrganisationStructurePolicy;
use App\Domain\Organisation\Policies\PeopleSetupPolicy;
use App\Domain\Payroll\Contracts\PayrollClosureReader;
use App\Domain\Payroll\Contracts\WorkforceCostReader;
use App\Domain\Payroll\Models\PayrollAdjustment;
use App\Domain\Payroll\Models\PayrollEntry;
use App\Domain\Payroll\Models\PayrollRun;
use App\Domain\Payroll\Models\Payslip;
use App\Domain\Payroll\Models\SalaryComponent;
use App\Domain\Payroll\Policies\PayrollConfigPolicy;
use App\Domain\Payroll\Policies\PayrollRunPolicy;
use App\Domain\Payroll\Policies\PayslipPolicy;
use App\Domain\Payroll\Services\PayrollClosure;
use App\Domain\Payroll\Services\WorkforceCost;
use App\Domain\People\Models\Person;
use App\Domain\People\Models\PersonAddress;
use App\Domain\People\Models\PersonCertification;
use App\Domain\People\Models\PersonEmergencyContact;
use App\Domain\People\Models\PersonExperience;
use App\Domain\People\Models\PersonFamilyMember;
use App\Domain\People\Models\PersonQualification;
use App\Domain\People\Models\PersonSkill;
use App\Domain\People\Models\Skill;
use App\Domain\Performance\Contracts\CompetencyEvidenceReader;
use App\Domain\Performance\Contracts\DevelopmentNeedsReader;
use App\Domain\Performance\Contracts\PerformanceOutcomesReader;
use App\Domain\Performance\Models\Appraisal;
use App\Domain\Performance\Models\AppraisalReview;
use App\Domain\Performance\Models\CalibrationAdjustment;
use App\Domain\Performance\Models\CalibrationSession;
use App\Domain\Performance\Models\CareerAspiration;
use App\Domain\Performance\Models\CareerPath;
use App\Domain\Performance\Models\Competency;
use App\Domain\Performance\Models\DevelopmentNeed;
use App\Domain\Performance\Models\FeedbackEntry;
use App\Domain\Performance\Models\Goal;
use App\Domain\Performance\Models\ImprovementPlan;
use App\Domain\Performance\Models\Kra;
use App\Domain\Performance\Models\OneOnOne;
use App\Domain\Performance\Models\PerformanceCheckIn;
use App\Domain\Performance\Models\PerformanceCycle;
use App\Domain\Performance\Models\PerformanceTemplate;
use App\Domain\Performance\Models\PerformanceTemplateVersion;
use App\Domain\Performance\Models\RatingScale;
use App\Domain\Performance\Policies\CalibrationPolicy;
use App\Domain\Performance\Policies\EmployeeOwnedPolicy;
use App\Domain\Performance\Policies\PerformanceConfigPolicy;
use App\Domain\Performance\Services\CompetencyEvidence;
use App\Domain\Performance\Services\DevelopmentNeeds;
use App\Domain\Performance\Services\PerformanceOutcomes;
use App\Domain\Platform\Models\Tenant;
use App\Domain\Platform\Models\TenantFeature;
use App\Domain\Platform\Models\TenantSetting;
use App\Domain\Platform\Services\FeatureFlags;
use App\Domain\ServiceDesk\Listeners\ServiceDeskDomainListener;
use App\Domain\ServiceDesk\Listeners\ServiceDeskWorkflowBridge;
use App\Domain\ServiceDesk\Models\ServiceDefinition;
use App\Domain\ServiceDesk\Models\ServiceDefinitionVersion;
use App\Domain\ServiceDesk\Models\ServiceSlaPolicy;
use App\Domain\ServiceDesk\Models\Ticket;
use App\Domain\ServiceDesk\Models\TicketCategory;
use App\Domain\ServiceDesk\Policies\ServiceDeskConfigPolicy;
use App\Domain\ServiceDesk\Policies\TicketPolicy;
use App\Domain\Skills\Models\EmployeeSkill;
use App\Domain\Skills\Models\SkillAssessment;
use App\Domain\Skills\Models\SkillScale;
use App\Domain\Skills\Models\SkillScaleVersion;
use App\Domain\Skills\Policies\SkillConfigPolicy;
use App\Domain\Skills\Policies\SkillRecordPolicy;
use App\Domain\Succession\Listeners\SuccessionWorkflowBridge;
use App\Domain\Succession\Models\CriticalPosition;
use App\Domain\Succession\Models\CriticalPositionAssessment;
use App\Domain\Succession\Models\ReadinessAssessment;
use App\Domain\Succession\Models\SuccessionPlan;
use App\Domain\Succession\Models\Successor;
use App\Domain\Succession\Policies\SuccessionPolicy;
use App\Domain\Talent\Models\TalentAssessment;
use App\Domain\Talent\Models\TalentAssessmentModel;
use App\Domain\Talent\Models\TalentAssessmentModelVersion;
use App\Domain\Talent\Models\TalentDevelopmentAction;
use App\Domain\Talent\Models\TalentPool;
use App\Domain\Talent\Models\TalentPoolMembership;
use App\Domain\Talent\Models\TalentProfile;
use App\Domain\Talent\Models\TalentReviewItem;
use App\Domain\Talent\Models\TalentReviewSession;
use App\Domain\Talent\Policies\TalentConfigPolicy;
use App\Domain\Talent\Policies\TalentRecordPolicy;
use App\Domain\Talent\Policies\TalentReviewPolicy;
use App\Domain\Workflow\Events\WorkflowCompleted;
use App\Domain\Workflow\Listeners\WorkflowTrigger;
use App\Domain\Workflow\Models\Workflow;
use App\Domain\Workflow\Models\WorkflowInstance;
use App\Domain\Workflow\Models\WorkflowTask;
use App\Domain\Workflow\Models\WorkflowVersion;
use App\Domain\Workflow\Policies\WorkflowPolicy;
use App\Domain\Workflow\Policies\WorkflowTaskPolicy;
use App\Domain\Workforce\Listeners\WorkforceWorkflowBridge;
use App\Domain\Workforce\Models\Position;
use App\Domain\Workforce\Models\PositionChangeRequest;
use App\Domain\Workforce\Models\PositionVersion;
use App\Domain\Workforce\Models\WorkforceBudget;
use App\Domain\Workforce\Models\WorkforcePlan;
use App\Domain\Workforce\Models\WorkforcePlanLine;
use App\Domain\Workforce\Models\WorkforcePlanVersion;
use App\Domain\Workforce\Models\WorkforceScenario;
use App\Domain\Workforce\Policies\PositionPolicy;
use App\Domain\Workforce\Policies\WorkforceBudgetPolicy;
use App\Domain\Workforce\Policies\WorkforcePlanningPolicy;
use App\Domain\Workforce\Services\PositionSeats;
use App\Support\Http\DnsHostResolver;
use App\Support\Http\HostResolver;
use App\Support\Tenancy\TenantContext;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Illuminate\Log\Context\Repository as ContextRepository;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

class AppServiceProvider extends ServiceProvider
{
    /** @var list<class-string> */
    private const EXPERIENCE_SCOPED = [
        ModuleCatalogue::class, RoleLens::class,
        ExperiencePreferences::class, ExperienceNavigation::class,
        PeopleVisibility::class, ApprovalCenter::class,
        // UX.18: per-request answers that pages and the navigation used to re-query (the signed-in person's own
        // employee record; the tenant's feature flags, which hit the cache store on every check).
        CurrentEmployee::class, FeatureFlags::class,
    ];

    public function register(): void
    {
        // Experience Transformation: per-request read models (the shell asks them several times per page).
        // Scoped, and forgotten after every handled request so no answer outlives its request.
        foreach (self::EXPERIENCE_SCOPED as $service) {
            $this->app->scoped($service);
        }
        $this->app['events']->listen(RequestHandled::class, function () {
            foreach (self::EXPERIENCE_SCOPED as $service) {
                $this->app->forgetInstance($service);
            }
        });

        // Phase 12 hook, bound in Phase 13 to the engagement domain's own survey tasks.
        $this->app->bind(SurveyTaskProvider::class, SurveyTasks::class);
        $this->app->bind(LeaveDayResolver::class, AttendanceLeaveDayResolver::class);
        $this->app->bind(DevelopmentNeedsReader::class, DevelopmentNeeds::class);
        $this->app->bind(PerformanceOutcomesReader::class, PerformanceOutcomes::class);
        $this->app->bind(CompetencyEvidenceReader::class, CompetencyEvidence::class);
        // Phase 10: employment assignments that name a position are validated by the workforce module.
        $this->app->bind(PositionAssignmentGuard::class, PositionSeats::class);
        $this->app->bind(WorkforceCostReader::class, WorkforceCost::class);
        // Phase 11: Compensation is read through its contract; Payroll answers closure questions through its own.
        $this->app->bind(CompensationOutput::class, CompensationLedger::class);
        $this->app->bind(PayrollClosureReader::class, PayrollClosure::class);
        $this->app->singleton(AccessScopes::class);
        // UX.15 closure: bounded authorisation passes are per request (and per job), never shared.
        $this->app->scoped(AuthorizationContext::class);
        // Production readiness closure: debug mode can never be on in production, whatever APP_DEBUG says
        // (the validator still reports the setting so it gets fixed).
        if ($this->app->environment('production') && config('app.debug')) {
            config(['app.debug' => false]);
            $this->app->booted(fn () => Log::critical('APP_DEBUG=true was ignored in production; fix the environment.'));
        }
        // Production readiness closure: DNS for the outbound SSRF guard (tests bind a fake).
        $this->app->bind(HostResolver::class, DnsHostResolver::class);
        // Phase 5: one statutory rule cache per request / job, cleared at the start of each run calculation.
        $this->app->scoped(ComplianceRules::class);
        // One tenant context per request / job / command execution.
        $this->app->bind(FinancialYear::class, fn () => FinancialYear::make());
        $this->app->bind(AiProvider::class, fn () => match (config('peopleos.ai.provider')) {
            'anthropic' => new AnthropicProvider,
            default => new NullProvider,
        });
        $this->app->scoped(TenantContext::class);
    }

    /**
     * UX.18 visual regression: runs against a disposable *_visual_showcase database with a frozen clock, so dates,
     * greetings and relative times are stable. Inert unless all three hold: the variable is set, the app is not in
     * production, and the database is a *_visual_showcase one.
     */
    private function freezeVisualClock(): void
    {
        $frozen = config('peopleos.visual.frozen_now');
        $database = (string) config('database.connections.'.config('database.default').'.database');
        if ($frozen && ! $this->app->isProduction() && str_ends_with($database, '_visual_showcase')) {
            Carbon::setTestNow(Carbon::parse($frozen));
        }
    }

    public function boot(): void
    {
        Model::shouldBeStrict(! $this->app->isProduction());
        $this->freezeVisualClock();
        // UX.18: a saved employee record (a user linked or unlinked) clears the per-request "who am I" answer.
        Employee::saved(fn () => $this->app->make(CurrentEmployee::class)->forget());

        $this->registerPolicies();
        $this->registerGateShortcuts();
        $this->registerRateLimits();
        $this->propagateTenantToQueuedJobs();
        $this->registerObservability();
        $this->trustConfiguredProxies();

        // Phase 11: the salary models moved from Payroll to Compensation. Polymorphic references stored
        // before the move (timeline sources, configuration changes) keep resolving; new rows use the
        // neutral aliases. Stored rows are never rewritten.
        Relation::morphMap([
            'compensation.salary_assignment' => EmployeeSalaryAssignment::class,
            'compensation.salary_structure' => SalaryStructure::class,
            'compensation.salary_structure_component' => SalaryStructureComponent::class,
            'App\\Domain\\Payroll\\Models\\EmployeeSalaryAssignment' => EmployeeSalaryAssignment::class,
            'App\\Domain\\Payroll\\Models\\SalaryStructure' => SalaryStructure::class,
            'App\\Domain\\Payroll\\Models\\SalaryStructureComponent' => SalaryStructureComponent::class,
        ]);

        Event::subscribe(RecordAuthenticationEvents::class);
        Event::subscribe(WorkflowTrigger::class);
        Event::subscribe(NotificationEventBridge::class);
        Event::subscribe(WebhookEventBridge::class);
        Event::listen(EmployeeLifecycleChanged::class, AutoStartOnboarding::class);
        Event::listen(WorkflowCompleted::class, LeaveWorkflowBridge::class);
        Event::listen(WorkflowCompleted::class, LearningWorkflowBridge::class);
        Event::listen(WorkflowCompleted::class, SuccessionWorkflowBridge::class);
        Event::listen(WorkflowCompleted::class, WorkforceWorkflowBridge::class);
        // Phase 12: service request approvals (SOD re-checked) and domain outcomes of handed-off requests.
        Event::listen(WorkflowCompleted::class, ServiceDeskWorkflowBridge::class);
        Event::subscribe(ServiceDeskDomainListener::class);
        // Phase 13: survey / announcement / campaign approvals through a configured workflow (SOD re-checked).
        Event::listen(WorkflowCompleted::class, EngagementWorkflowBridge::class);
    }

    /**
     * API limits are per key. Phase 14: the limiter key is the key's public prefix (or a hash of
     * anything else presented), never the raw secret, so cache entries hold no credential.
     */
    private function registerRateLimits(): void
    {
        RateLimiter::for('api', function (Request $request) {
            $presented = (string) ($request->header('X-Api-Key') ?: $request->bearerToken() ?: '');
            $bucket = match (true) {
                $presented === '' => 'ip:'.$request->ip(),
                (bool) preg_match('/^(pk_[a-z0-9]{10})\./', $presented, $m) => 'key:'.$m[1],
                default => 'token:'.hash('sha256', $presented),
            };

            return Limit::perMinute((int) config('peopleos.api.rate_limit_per_minute'))->by($bucket);
        });
        // SaaS.2: AI questions are limited per user inside AiGateway (peopleos.ai.rate_limit_per_minute); the
        // named `ai` limiter registered here was never attached to a route and has been removed.
    }

    private function registerPolicies(): void
    {
        foreach ([SsoConnection::class, WebhookEndpoint::class, WebhookDelivery::class, ExchangeRate::class] as $model) {
            Gate::policy($model, EnterprisePolicy::class);
        }
        Gate::policy(AiInteraction::class, AiInteractionPolicy::class);
        Gate::policy(EmployeeImport::class, EmployeeImportPolicy::class);
        Gate::policy(Report::class, ReportPolicy::class);
        Gate::policy(ReportSchedule::class, ReportPolicy::class);
        Gate::policy(Dashboard::class, DashboardPolicy::class);
        Gate::policy(ExitCase::class, ExitCasePolicy::class);
        Gate::policy(ExitClearance::class, ExitCasePolicy::class);
        Gate::policy(ExitInterview::class, ExitCasePolicy::class);
        Gate::policy(FinalSettlement::class, SettlementPolicy::class);
        Gate::policy(LetterTemplate::class, LetterTemplatePolicy::class);
        Gate::policy(Letter::class, LetterPolicy::class);
        Gate::policy(AlumniProfile::class, AlumniPolicy::class);
        Gate::policy(AlumniRequest::class, AlumniPolicy::class);
        Gate::policy(Ticket::class, TicketPolicy::class);
        Gate::policy(TicketCategory::class, ServiceDeskConfigPolicy::class);
        Gate::policy(ServiceDefinition::class, ServiceDeskConfigPolicy::class);
        Gate::policy(ServiceDefinitionVersion::class, ServiceDeskConfigPolicy::class);
        Gate::policy(ServiceSlaPolicy::class, ServiceDeskConfigPolicy::class);
        Gate::policy(Grievance::class, GrievancePolicy::class);
        Gate::policy(GrievanceCategory::class, GrievanceConfigPolicy::class);
        Gate::policy(Article::class, ArticlePolicy::class);
        Gate::policy(Announcement::class, AnnouncementPolicy::class);
        // Phase 13: configuration through the engagement services; response-side records have no generic access at all.
        foreach ([Survey::class, SurveyVersion::class, SurveyQuestion::class, Audience::class, EngagementCampaign::class, CampaignItem::class] as $model) {
            Gate::policy($model, EngagementConfigPolicy::class);
        }
        foreach ([SurveyParticipation::class, SurveyResponse::class, SurveyAnswer::class, EngagementIdentity::class] as $model) {
            Gate::policy($model, EngagementRecordPolicy::class);
        }
        Gate::policy(EmployeeFeedback::class, EmployeeFeedbackPolicy::class);
        Gate::policy(CommunicationRecipient::class, CommunicationRecordPolicy::class);
        Gate::policy(CommunicationPreference::class, CommunicationRecordPolicy::class);
        // Phase 14: Integration Hub records.
        foreach ([IntegrationSystem::class, ExternalReference::class, IntegrationMapping::class, InboundEvent::class] as $model) {
            Gate::policy($model, IntegrationPolicy::class);
        }
        foreach ([Course::class, LearningPath::class, LearningAssignment::class, TrainingSession::class, Assessment::class] as $model) {
            Gate::policy($model, LearningConfigPolicy::class);
        }
        Gate::policy(LearningEnrolment::class, EnrolmentPolicy::class);
        Gate::policy(LearningCertificate::class, EnrolmentPolicy::class);
        // Phase 8 learning, skills and development.
        foreach ([LearningCompletion::class, LearningEvidence::class, LearningProgramParticipant::class, AssessmentAttempt::class] as $model) {
            Gate::policy($model, EnrolmentPolicy::class);
        }
        foreach ([LearningProvider::class, LearningInstructor::class, CourseVersion::class, LearningPathVersion::class, LearningProgram::class, LearningProgramVersion::class] as $model) {
            Gate::policy($model, LearningConfigPolicy::class);
        }
        Gate::policy(LearningCost::class, LearningCostPolicy::class);
        foreach ([EmployeeSkill::class, SkillAssessment::class] as $model) {
            Gate::policy($model, SkillRecordPolicy::class);
        }
        foreach ([SkillScale::class, SkillScaleVersion::class] as $model) {
            Gate::policy($model, SkillConfigPolicy::class);
        }
        foreach ([DevelopmentPlan::class, DevelopmentPlanItem::class] as $model) {
            Gate::policy($model, DevelopmentPlanPolicy::class);
        }
        foreach ([Asset::class, AssetCategory::class, AssetModel::class, AssetAssignment::class, AssetRepair::class] as $model) {
            Gate::policy($model, AssetPolicy::class);
        }
        foreach ([PerformanceCycle::class, RatingScale::class, Competency::class, Kra::class, PerformanceTemplate::class, PerformanceTemplateVersion::class] as $model) {
            Gate::policy($model, PerformanceConfigPolicy::class);
        }
        // Phase 9: career architecture keeps Phase 7 performance access and adds career.view / career.manage.
        foreach ([CareerPath::class, CareerTrack::class, CareerPathVersion::class, RoleRequirementVersion::class] as $model) {
            Gate::policy($model, CareerArchitecturePolicy::class);
        }
        foreach ([CareerProfile::class, CareerAspirationEntry::class, CareerGoal::class, MobilityInterest::class] as $model) {
            Gate::policy($model, CareerRecordPolicy::class);
        }
        foreach ([TalentProfile::class, TalentPoolMembership::class, TalentAssessment::class, TalentReviewItem::class, TalentDevelopmentAction::class] as $model) {
            Gate::policy($model, TalentRecordPolicy::class);
        }
        foreach ([TalentPool::class, TalentAssessmentModel::class, TalentAssessmentModelVersion::class] as $model) {
            Gate::policy($model, TalentConfigPolicy::class);
        }
        Gate::policy(TalentReviewSession::class, TalentReviewPolicy::class);
        // Phase 10: workforce planning.
        foreach ([Position::class, PositionVersion::class, PositionChangeRequest::class] as $model) {
            Gate::policy($model, PositionPolicy::class);
        }
        foreach ([WorkforcePlan::class, WorkforcePlanVersion::class, WorkforcePlanLine::class, WorkforceScenario::class] as $model) {
            Gate::policy($model, WorkforcePlanningPolicy::class);
        }
        Gate::policy(WorkforceBudget::class, WorkforceBudgetPolicy::class);
        foreach ([CriticalPosition::class, CriticalPositionAssessment::class, SuccessionPlan::class, Successor::class, ReadinessAssessment::class] as $model) {
            Gate::policy($model, SuccessionPolicy::class);
        }
        foreach ([CalibrationSession::class, CalibrationAdjustment::class] as $model) {
            Gate::policy($model, CalibrationPolicy::class);
        }
        foreach ([Goal::class, Appraisal::class, AppraisalReview::class, FeedbackEntry::class, OneOnOne::class, ImprovementPlan::class, CareerAspiration::class, PerformanceCheckIn::class, DevelopmentNeed::class] as $model) {
            Gate::policy($model, EmployeeOwnedPolicy::class);
        }
        Gate::policy(SalaryComponent::class, PayrollConfigPolicy::class);
        // Phase 11: structures and employee compensation are Compensation's.
        Gate::policy(SalaryStructure::class, CompensationConfigPolicy::class);
        Gate::policy(EmployeeSalaryAssignment::class, CompensationPolicy::class);
        Gate::policy(CompensationChange::class, CompensationPolicy::class);
        Gate::policy(SalaryStructureVersion::class, CompensationConfigPolicy::class);
        Gate::policy(CompensationRange::class, CompensationConfigPolicy::class);
        Gate::policy(CompensationBudget::class, CompensationPlanningPolicy::class);
        Gate::policy(CompensationCycle::class, CompensationPlanningPolicy::class);
        Gate::policy(PayrollAdjustment::class, PayrollConfigPolicy::class);
        Gate::policy(CompanyStatutoryProfile::class, PayrollConfigPolicy::class);
        Gate::policy(PayrollRun::class, PayrollRunPolicy::class);
        Gate::policy(PayrollEntry::class, PayrollRunPolicy::class);
        Gate::policy(Payslip::class, PayslipPolicy::class);
        Gate::policy(ComplianceRule::class, ComplianceRulePolicy::class);
        Gate::policy(ComplianceRuleVerification::class, ComplianceRulePolicy::class);
        Gate::policy(StatutoryExportLayout::class, ComplianceRulePolicy::class);
        Gate::policy(ComplianceRuleNotice::class, ComplianceRulePolicy::class);
        Gate::policy(ComplianceEvidenceDocument::class, ComplianceRulePolicy::class);
        Gate::policy(ComplianceRuleParameter::class, ComplianceRulePolicy::class);
        Gate::policy(ProfessionalTaxRuleVersion::class, ComplianceRulePolicy::class);
        Gate::policy(EmployeeTaxDeclaration::class, TaxDeclarationPolicy::class);
        Gate::policy(Tenant::class, TenantPolicy::class);
        Gate::policy(TenantSetting::class, TenantSettingPolicy::class);
        Gate::policy(TenantFeature::class, TenantFeaturePolicy::class);
        Gate::policy(User::class, UserPolicy::class);
        Gate::policy(Role::class, RolePolicy::class);
        Gate::policy(Company::class, CompanyPolicy::class);
        Gate::policy(LegalEntity::class, LegalEntityPolicy::class);
        Gate::policy(Establishment::class, EstablishmentPolicy::class);
        Gate::policy(EmployeeEstablishmentAssignment::class, EstablishmentAssignmentPolicy::class);
        Gate::policy(StatutoryRegistration::class, StatutoryRegistrationPolicy::class);
        foreach ([TdsProfile::class, TdsFinancialYear::class, TdsEmployeeInvestment::class] as $model) {
            Gate::policy($model, TdsPolicy::class);
        }
        foreach ([StatutoryReturn::class, StatutoryReturnAction::class, StatutorySnapshot::class, StatutoryReconciliation::class, EpfReturnRun::class, EpfReturnEntry::class, EpfReturnRevision::class, EsiReturnRun::class, EsiReturnEntry::class, ProfessionalTaxReturn::class, ProfessionalTaxReturnEntry::class, LwfReturn::class, LwfReturnEntry::class, TdsAnnualLedger::class, TdsQuarterlyReturn::class, TdsQuarterlyReturnEntry::class, TdsCertificate::class, ParallelPayrollRun::class, ParallelPayrollLine::class] as $model) {
            Gate::policy($model, StatutoryReturnPolicy::class);
        }
        Gate::policy(EstablishmentStatutoryProfile::class, StatutoryRegistrationPolicy::class);
        Gate::policy(ProfessionalTaxProfile::class, StatutoryRegistrationPolicy::class);
        Gate::policy(AuditEvent::class, AuditEventPolicy::class);

        foreach ([Location::class, BusinessUnit::class, Division::class, Department::class, Team::class, CostCentre::class, ProfitCentre::class, OrganisationNode::class] as $model) {
            Gate::policy($model, OrganisationStructurePolicy::class);
        }

        foreach ([Level::class, Grade::class, JobFamily::class, Designation::class, EmploymentType::class, EmployeeCategory::class, WorkMode::class, Skill::class] as $model) {
            Gate::policy($model, PeopleSetupPolicy::class);
        }

        Gate::policy(Employee::class, EmployeePolicy::class);

        foreach ([Person::class, PersonAddress::class, PersonFamilyMember::class, PersonEmergencyContact::class, PersonQualification::class, PersonExperience::class, PersonCertification::class, PersonSkill::class, EmployeePosition::class, ReportingRelationship::class, EmployeeLifecycleTransition::class, EmployeeTimelineEntry::class] as $model) {
            Gate::policy($model, EmployeeDataPolicy::class);
        }

        foreach ([EmployeeStatutoryDetail::class, EmployeeBankAccount::class] as $model) {
            Gate::policy($model, SensitiveEmployeeDataPolicy::class);
        }

        Gate::policy(CustomField::class, CustomFieldPolicy::class);
        Gate::policy(CustomFieldValue::class, CustomFieldPolicy::class);
        Gate::policy(ConfigurationChange::class, ConfigurationChangePolicy::class);

        foreach ([Form::class, FormVersion::class, FormSubmission::class] as $model) {
            Gate::policy($model, FormPolicy::class);
        }

        foreach ([Policy::class, PolicyVersion::class, PolicyAssignmentRule::class] as $model) {
            Gate::policy($model, PolicyPolicy::class);
        }

        foreach ([Workflow::class, WorkflowVersion::class, WorkflowInstance::class] as $model) {
            Gate::policy($model, WorkflowPolicy::class);
        }

        Gate::policy(WorkflowTask::class, WorkflowTaskPolicy::class);
        Gate::policy(NotificationTemplate::class, NotificationPolicy::class);
        Gate::policy(NotificationRule::class, NotificationPolicy::class);
        Gate::policy(NotificationDelivery::class, NotificationDeliveryPolicy::class);
        Gate::policy(DocumentType::class, DocumentTypePolicy::class);
        Gate::policy(EmployeeDocument::class, EmployeeDocumentPolicy::class);
        Gate::policy(OnboardingTemplate::class, OnboardingPolicy::class);
        Gate::policy(OnboardingTemplateItem::class, OnboardingPolicy::class);
        Gate::policy(OnboardingPlan::class, OnboardingPolicy::class);
        Gate::policy(OnboardingTask::class, OnboardingTaskPolicy::class);
        Gate::policy(BgvCase::class, BgvPolicy::class);
        Gate::policy(BgvCheck::class, BgvPolicy::class);
        Gate::policy(ApiKey::class, ApiKeyPolicy::class);

        foreach ([Shift::class, ShiftBreak::class, WorkSchedule::class, WorkScheduleAssignment::class, WorkScheduleRule::class, HolidayCalendar::class, Holiday::class, HolidayCalendarRule::class, AttendanceDevice::class, AttendancePunch::class] as $model) {
            Gate::policy($model, AttendanceConfigPolicy::class);
        }

        Gate::policy(AttendanceRecord::class, AttendanceRecordPolicy::class);
        Gate::policy(LeaveType::class, LeaveTypePolicy::class);

        foreach ([LeaveRequest::class, LeaveBalance::class, LeaveLedgerEntry::class, LeaveEncashment::class] as $model) {
            Gate::policy($model, LeaveRequestPolicy::class);
        }
        Gate::policy(AttendanceRegularisation::class, AttendanceRecordPolicy::class);
    }

    /**
     * Platform admins bypass every check. Bare permission keys (`company.view`) are resolved
     * straight from the user's role graph so `$user->can('company.view')` works everywhere.
     */
    private function registerGateShortcuts(): void
    {
        Gate::before(function (User $user, string $ability) {
            if ($user->isPlatformAdmin()) {
                return true;
            }

            if (str_contains($ability, '.') && app(PermissionRegistry::class)->isKnown($ability)) {
                return $user->hasPermission($ability);
            }

            return null;
        });
    }

    /**
     * Production readiness closure: trust X-Forwarded-* only from the configured load balancer(s), so a
     * TLS-terminating proxy yields https URLs, Secure cookies and valid signed links, and clients cannot
     * spoof their IP through the headers.
     */
    private function trustConfiguredProxies(): void
    {
        $proxies = trim((string) config('peopleos.http.trusted_proxies', ''));
        if ($proxies !== '') {
            TrustProxies::at($proxies === '*' ? '*' : array_values(array_filter(array_map('trim', explode(',', $proxies)))));
        }
    }

    /**
     * Phase 14 observability:
     * - every artisan command run gets a correlation id (request_id in Context), so its logs, audit rows
     *   and notifications are traceable, and notification dedupe works per run;
     * - failed queue jobs are logged with job, connection, queue and exception class (redacted channel);
     * - slow queries are logged with their SQL text only, never bindings.
     */
    private function registerObservability(): void
    {
        Event::listen(CommandStarting::class, function (): void {
            if (! Context::has('request_id')) {
                Context::add('request_id', (string) Str::ulid());
            }
        });

        Queue::failing(function (JobFailed $event): void {
            Log::error('Queue job failed', [
                'job' => $event->job->resolveName(), 'connection' => $event->connectionName, 'queue' => $event->job->getQueue(),
                'attempts' => $event->job->attempts(), 'exception' => $event->exception::class,
            ]);
        });

        $threshold = (int) config('peopleos.observability.slow_query_ms', 1000);
        if ($threshold > 0) {
            DB::listen(function (QueryExecuted $query) use ($threshold): void {
                if ($query->time >= $threshold) {
                    Log::warning('Slow query', ['ms' => round($query->time, 1), 'connection' => $query->connectionName, 'sql' => mb_substr($query->sql, 0, 2000)]);
                }
            });
        }
    }

    /**
     * TenantContext stores the tenant id as hidden Context; when a queued job (or any dehydrated
     * context) is rehydrated, bind the tenant again so scopes keep working inside the worker.
     */
    private function propagateTenantToQueuedJobs(): void
    {
        Context::hydrated(function (ContextRepository $context): void {
            $tenantId = $context->getHidden('tenant_id');

            $this->app->make(TenantContext::class)->set(
                $tenantId ? Tenant::query()->find($tenantId) : null,
            );
        });
    }
}
