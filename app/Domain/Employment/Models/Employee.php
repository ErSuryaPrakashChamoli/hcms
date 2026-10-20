<?php

namespace App\Domain\Employment\Models;

use App\Domain\Alumni\Models\AlumniProfile;
use App\Domain\Assets\Models\AssetAssignment;
use App\Domain\Attendance\Models\AttendanceRecord;
use App\Domain\Attendance\Models\AttendanceRegularisation;
use App\Domain\Attendance\Models\WorkScheduleAssignment;
use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Bgv\Models\BgvCase;
use App\Domain\Career\Models\CareerAspirationEntry;
use App\Domain\Career\Models\CareerGoal;
use App\Domain\Communication\Models\CommunicationRecipient;
use App\Domain\Compensation\Models\CompensationChange;
use App\Domain\Compensation\Models\EmployeeSalaryAssignment;
use App\Domain\Configuration\Concerns\HasCustomFields;
use App\Domain\Development\Models\DevelopmentPlan;
use App\Domain\Documents\Models\EmployeeDocument;
use App\Domain\Exit\Models\ExitCase;
use App\Domain\Identity\Concerns\ScopedByEmployee;
use App\Domain\Identity\Models\User;
use App\Domain\Learning\Models\LearningCertificate;
use App\Domain\Learning\Models\LearningEnrolment;
use App\Domain\Leave\Models\LeaveBalance;
use App\Domain\Leave\Models\LeaveRequest;
use App\Domain\Letters\Models\Letter;
use App\Domain\Lifecycle\Enums\LifecycleState;
use App\Domain\Lifecycle\Exceptions\InvalidLifecycleTransitionException;
use App\Domain\Lifecycle\Models\EmployeeLifecycleTransition;
use App\Domain\Lifecycle\Models\EmployeeTimelineEntry;
use App\Domain\Lifecycle\Services\LifecycleEngine;
use App\Domain\Onboarding\Models\OnboardingPlan;
use App\Domain\Payroll\Models\PayrollEntry;
use App\Domain\Payroll\Models\Payslip;
use App\Domain\People\Models\Person;
use App\Domain\Performance\Models\Appraisal;
use App\Domain\Performance\Models\FeedbackEntry;
use App\Domain\Performance\Models\Goal;
use App\Domain\ServiceDesk\Models\Ticket;
use App\Domain\Skills\Models\EmployeeSkill;
use App\Domain\Succession\Models\Successor;
use App\Domain\Talent\Models\TalentPoolMembership;
use App\Domain\Workflow\Models\WorkflowInstance;
use App\Support\Tenancy\BelongsToTenant;
use Database\Factories\EmployeeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * The employment record of a Person within this tenant. Organisational placement is the
 * effective-dated EmployeePosition history; reporting lines are ReportingRelationships.
 */
#[UseFactory(EmployeeFactory::class)]
#[Fillable(['tenant_id', 'person_id', 'user_id', 'employee_code', 'lifecycle_state', 'source', 'external_reference', 'joining_date', 'expected_joining_date', 'offer_accepted_at', 'probation_end_date', 'confirmation_date', 'exit_date', 'work_email', 'work_phone', 'metadata'])]
class Employee extends Model
{
    use Auditable, BelongsToTenant, HasCustomFields, HasFactory;

    /** @use HasFactory<EmployeeFactory> */
    use ScopedByEmployee;

    protected static function booted(): void
    {
        // Contract §7: lifecycle_state changes only through LifecycleEngine (or an explicit unguarded block).
        static::updating(function (self $employee): void {
            if ($employee->isDirty('lifecycle_state') && ! LifecycleEngine::isMutating()) {
                throw InvalidLifecycleTransitionException::directMutation($employee);
            }
        });
    }

    protected function casts(): array
    {
        return [
            'lifecycle_state' => LifecycleState::class,
            'joining_date' => 'date',
            'expected_joining_date' => 'date',
            'offer_accepted_at' => 'datetime',
            'probation_end_date' => 'date',
            'confirmation_date' => 'date',
            'exit_date' => 'date',
            'metadata' => 'array',
        ];
    }

    public function auditLabel(): string
    {
        $person = $this->relationLoaded('person') ? $this->person : $this->person()->first();

        return "{$this->employee_code} · ".($person?->display_name ?? '');
    }

    protected function displayName(): Attribute
    {
        return Attribute::get(fn () => $this->person?->display_name);
    }

    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function positions(): HasMany
    {
        return $this->hasMany(EmployeePosition::class)->orderByDesc('effective_from')->orderByDesc('id');
    }

    public function currentPosition(): HasOne
    {
        return $this->hasOne(EmployeePosition::class)->ofMany(
            ['effective_from' => 'max', 'id' => 'max'],
            fn (Builder $query) => $query->effectiveOn(),
        );
    }

    public function reportingRelationships(): HasMany
    {
        return $this->hasMany(ReportingRelationship::class)->orderByDesc('effective_from');
    }

    public function currentManager(): HasOne
    {
        return $this->hasOne(ReportingRelationship::class)->ofMany(
            ['effective_from' => 'max', 'id' => 'max'],
            fn (Builder $query) => $query->where('is_primary', true)->effectiveOn(),
        );
    }

    public function directReports(): HasMany
    {
        return $this->hasMany(ReportingRelationship::class, 'manager_id');
    }

    public function statutoryDetail(): HasOne
    {
        return $this->hasOne(EmployeeStatutoryDetail::class);
    }

    public function bankAccounts(): HasMany
    {
        return $this->hasMany(EmployeeBankAccount::class)->orderByDesc('is_primary');
    }

    public function lifecycleTransitions(): HasMany
    {
        return $this->hasMany(EmployeeLifecycleTransition::class)->orderByDesc('effective_date')->orderByDesc('id');
    }

    public function timelineEntries(): HasMany
    {
        return $this->hasMany(EmployeeTimelineEntry::class)->orderByDesc('occurred_on')->orderByDesc('id');
    }

    public function onboardingPlan(): HasOne
    {
        return $this->hasOne(OnboardingPlan::class)->latestOfMany();
    }

    public function onboardingPlans(): HasMany
    {
        return $this->hasMany(OnboardingPlan::class)->latest('started_at');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(EmployeeDocument::class)->latest('id');
    }

    public function bgvCases(): HasMany
    {
        return $this->hasMany(BgvCase::class)->latest('initiated_at');
    }

    public function attendanceRecords(): HasMany
    {
        return $this->hasMany(AttendanceRecord::class)->orderByDesc('date');
    }

    public function attendanceRegularisations(): HasMany
    {
        return $this->hasMany(AttendanceRegularisation::class)->orderByDesc('date');
    }

    public function scheduleAssignments(): HasMany
    {
        return $this->hasMany(WorkScheduleAssignment::class)->orderByDesc('effective_from');
    }

    public function leaveRequests(): HasMany
    {
        return $this->hasMany(LeaveRequest::class)->orderByDesc('from_date');
    }

    /** Phase 11: the canonical compensation history (Compensation owns it; Employee 360 presentation only). */
    public function salaryAssignments(): HasMany
    {
        return $this->hasMany(EmployeeSalaryAssignment::class);
    }

    /** Phase 11: compensation proposals and their decisions (Employee 360 presentation only). */
    public function compensationChanges(): HasMany
    {
        return $this->hasMany(CompensationChange::class);
    }

    public function payrollEntries(): HasMany
    {
        return $this->hasMany(PayrollEntry::class);
    }

    public function payslips(): HasMany
    {
        return $this->hasMany(Payslip::class);
    }

    public function goals(): HasMany
    {
        return $this->hasMany(Goal::class);
    }

    public function appraisals(): HasMany
    {
        return $this->hasMany(Appraisal::class);
    }

    public function feedbackReceived(): HasMany
    {
        return $this->hasMany(FeedbackEntry::class);
    }

    public function learningEnrolments(): HasMany
    {
        return $this->hasMany(LearningEnrolment::class);
    }

    public function learningCertificates(): HasMany
    {
        return $this->hasMany(LearningCertificate::class);
    }

    /** Phase 8: skill history (one lifetime record; rehire keeps the same employee). */
    public function employeeSkills(): HasMany
    {
        return $this->hasMany(EmployeeSkill::class);
    }

    public function developmentPlans(): HasMany
    {
        return $this->hasMany(DevelopmentPlan::class);
    }

    /** Phase 9: career goals (separate from performance goals). */
    public function careerGoals(): HasMany
    {
        return $this->hasMany(CareerGoal::class);
    }

    /** Phase 9: effective-dated aspiration history. */
    public function careerAspirationEntries(): HasMany
    {
        return $this->hasMany(CareerAspirationEntry::class);
    }

    /** Phase 9: talent pool memberships (confidential; talent.view). */
    public function talentPoolMemberships(): HasMany
    {
        return $this->hasMany(TalentPoolMembership::class);
    }

    /** Phase 9: succession candidacy entries (confidential; succession.view / succession.team). */
    public function successorEntries(): HasMany
    {
        return $this->hasMany(Successor::class);
    }

    public function assetAssignments(): HasMany
    {
        return $this->hasMany(AssetAssignment::class);
    }

    public function tickets(): HasMany
    {
        return $this->hasMany(Ticket::class);
    }

    public function exitCases(): HasMany
    {
        return $this->hasMany(ExitCase::class);
    }

    public function letters(): HasMany
    {
        return $this->hasMany(Letter::class);
    }

    public function alumniProfile(): HasOne
    {
        return $this->hasOne(AlumniProfile::class);
    }

    /** Phase 14: announcements addressed to this employee (the Communication domain's snapshot rows; read-only here). */
    public function communicationRecipients(): HasMany
    {
        return $this->hasMany(CommunicationRecipient::class);
    }

    public function leaveBalances(): HasMany
    {
        return $this->hasMany(LeaveBalance::class);
    }

    public function workflowInstances(): MorphMany
    {
        return $this->morphMany(WorkflowInstance::class, 'subject')->latest('started_at');
    }

    #[Scope]
    protected function employed(Builder $query): Builder
    {
        return $query->whereIn('lifecycle_state', array_map(
            fn (LifecycleState $s) => $s->value,
            array_filter(LifecycleState::cases(), fn (LifecycleState $s) => $s->isEmployed()),
        ));
    }
}
