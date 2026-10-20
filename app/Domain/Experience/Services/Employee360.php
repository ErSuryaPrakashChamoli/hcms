<?php

namespace App\Domain\Experience\Services;

use App\Domain\Alumni\Models\AlumniProfile;
use App\Domain\Attendance\Models\AttendanceRecord;
use App\Domain\Career\Models\CareerAspirationEntry;
use App\Domain\Communication\Services\Communications;
use App\Domain\Compensation\Models\EmployeeSalaryAssignment;
use App\Domain\Compensation\Services\CompensationAccess;
use App\Domain\Documents\Models\EmployeeDocument;
use App\Domain\Employment\Models\Employee;
use App\Domain\Engagement\Models\SurveyParticipation;
use App\Domain\Engagement\Models\SurveyVersion;
use App\Domain\Exit\Models\ExitCase;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Scopes\AccessScope;
use App\Domain\Learning\Models\LearningEnrolment;
use App\Domain\Leave\Models\LeaveBalance;
use App\Domain\Leave\Models\LeaveRequest;
use App\Domain\Letters\Models\Letter;
use App\Domain\Payroll\Models\Payslip;
use App\Domain\Performance\Models\Appraisal;
use App\Domain\Performance\Models\Goal;
use App\Domain\Performance\Services\PerformanceRelationships;
use App\Domain\ServiceDesk\Models\Ticket;
use App\Domain\ServiceDesk\Services\CaseAccess;
use App\Domain\Skills\Models\EmployeeSkill;
use App\Domain\Succession\Models\Successor;
use App\Domain\Talent\Models\TalentPoolMembership;
use App\Domain\Workforce\Models\Position;

/**
 * Phase 14: the Employee 360 orchestration (read) surface.
 *
 * One permission-aware summary per domain. Each section is computed only when the viewer may see
 * that domain for this employee, using the domain's own permission, access level or relationship rule:
 * - compensation access levels;
 * - case access;
 * - the configured manager relationships;
 * - engagement's identified-only rule;
 * - and so on.
 *
 * It owns no data and writes nothing. Every number comes from the owning domain's records. No
 * section carries salary amounts, bank / statutory identifiers, ratings beyond what the viewer may
 * already see, confidential notes, or anything about anonymous or confidential survey responses.
 */
final class Employee360
{
    private const LEARNING_CLOSED = ['completed', 'cancelled', 'withdrawn', 'expired', 'failed', 'rejected'];

    public function __construct(
        private readonly CompensationAccess $compensation,
        private readonly CaseAccess $cases,
        private readonly PerformanceRelationships $relationships,
        private readonly Communications $communications,
    ) {}

    /** @return list<array{key: string, label: string, owner: string, facts: array<string, string|int|null>}> */
    public function for(User $viewer, Employee $employee): array
    {
        if (! $viewer->can('view', $employee)) {
            return [];
        }
        $own = (int) $employee->user_id === (int) $viewer->id;
        $manages = $this->relationships->manages($this->relationships->forUser($viewer), $employee->id);
        $employee->loadMissing(['person', 'currentPosition.company', 'currentPosition.businessUnit', 'currentPosition.division', 'currentPosition.department', 'currentPosition.team',
            'currentPosition.location', 'currentPosition.designation', 'currentManager.manager.person']);
        $p = $employee->currentPosition;
        $sections = [];
        $add = function (string $key, string $label, string $owner, bool $allowed, callable $facts) use (&$sections) {
            if ($allowed) {
                $sections[] = ['key' => $key, 'label' => $label, 'owner' => $owner, 'facts' => $facts()];
            }
        };
        $scoped = fn (string $class) => $class::query()->withoutGlobalScope(AccessScope::class)->where('employee_id', $employee->id);

        $add('identity', 'Person', 'People', true, fn () => [
            'Employee code' => $employee->employee_code, 'Name' => $employee->person?->display_name, 'Lifecycle' => $employee->lifecycle_state?->getLabel(),
            'Joined' => $employee->joining_date?->toDateString(), 'Work email' => $employee->work_email,
        ]);
        $add('employment', 'Employment', 'Employment', true, fn () => [
            'Designation' => $p?->designation?->name, 'Manager' => $employee->currentManager?->manager?->person?->display_name,
            'Tenure (years)' => $employee->joining_date ? round($employee->joining_date->diffInDays(now()) / 365.25, 1) : null,
            'Position seat' => $p?->position_id ? AccessScope::withoutScoping(fn () => Position::query()->whereKey($p->position_id)->value('code')) : null,
            'FTE' => $p?->fte !== null ? (string) $p->fte : null,
        ]);
        $add('organisation', 'Organisation', 'Organisation', true, fn () => [
            'Path' => collect([$p?->company?->name, $p?->businessUnit?->name, $p?->division?->name, $p?->department?->name, $p?->team?->name])->filter()->implode(' › ') ?: null,
            'Location' => $p?->location?->name,
        ]);
        $add('attendance', 'Attendance (30 days)', 'Attendance', $viewer->hasPermission('attendance.view') || ($own && $viewer->hasPermission('attendance.regularise')), function () use ($scoped) {
            $rows = $scoped(AttendanceRecord::class)->where('date', '>=', now()->subDays(30)->toDateString())->selectRaw('status, count(*) as n, sum(overtime_minutes) as ot')->groupBy('status')->get();

            return ['Present days' => (int) $rows->where('status', 'present')->sum('n'), 'Absent days' => (int) $rows->where('status', 'absent')->sum('n'), 'Overtime (hours)' => round((int) $rows->sum('ot') / 60, 1)];
        });
        $add('leave', 'Leave', 'Leave', $viewer->hasPermission('leave.view') || ($own && $viewer->hasPermission('leave.apply')), fn () => [
            'Pending requests' => $scoped(LeaveRequest::class)->where('status', 'pending')->count(),
            'Days taken this year' => (float) $scoped(LeaveRequest::class)->where('status', 'approved')->where('from_date', '>=', now()->startOfYear()->toDateString())->sum('days'),
            'Balance (all types)' => (float) $scoped(LeaveBalance::class)->where('period_year', now()->year)->sum('closing'),
        ]);
        $add('payroll', 'Payroll', 'Payroll', $viewer->hasPermission('payroll.view') || ($own && $viewer->hasPermission('payroll.payslip')), function () use ($scoped) {
            $latest = $scoped(Payslip::class)->latest('generated_at')->first(['number', 'generated_at']);

            return ['Payslips' => $scoped(Payslip::class)->count(), 'Latest payslip' => $latest ? $latest->number.' ('.$latest->generated_at?->toDateString().')' : null];
        });
        $mayPerformance = $viewer->hasPermission('performance.view') || $manages || $own;
        $add('performance', 'Performance', 'Performance', $mayPerformance, function () use ($scoped, $viewer, $manages, $own) {
            $appraisal = $scoped(Appraisal::class)->latest('id')->first(['status', 'final_label']);
            $label = $appraisal && in_array($appraisal->status, ['finalized', 'acknowledged'], true) && ($viewer->hasPermission('performance.view') || $manages || $own) ? $appraisal->final_label : null;

            return ['Latest review' => $appraisal ? str_replace('_', ' ', (string) $appraisal->status) : null, 'Outcome' => $label];
        });
        $add('goals', 'Goals', 'Performance', $mayPerformance, fn () => [
            'Active goals' => $scoped(Goal::class)->whereNotIn('status', ['cancelled', 'completed'])->count(),
            'Average progress (%)' => round((float) $scoped(Goal::class)->whereNotIn('status', ['cancelled'])->avg('progress'), 1),
        ]);
        $add('learning', 'Learning', 'Learning', $viewer->hasPermission('learning.view') || ($manages && $viewer->hasPermission('learning.team')) || ($own && $viewer->hasPermission('learning.learn')), fn () => [
            'Open' => $scoped(LearningEnrolment::class)->whereNotIn('status', self::LEARNING_CLOSED)->count(),
            'Completed' => $scoped(LearningEnrolment::class)->where('status', 'completed')->count(),
            'Mandatory open' => $scoped(LearningEnrolment::class)->where('is_mandatory', true)->whereNotIn('status', self::LEARNING_CLOSED)->count(),
        ]);
        $add('skills', 'Skills', 'Skills', $viewer->hasPermission('skills.view') || $own, fn () => ['Skills on profile' => $scoped(EmployeeSkill::class)->count()]);
        $add('career', 'Career', 'Career', $viewer->hasPermission('career.view') || $own, fn () => ['Active aspirations' => $scoped(CareerAspirationEntry::class)->where('status', 'current')->count()]);
        $add('talent', 'Talent', 'Talent', $viewer->hasPermission('talent.view'), fn () => ['Talent pools' => $scoped(TalentPoolMembership::class)->whereNotNull('active_key')->count()]);
        $add('succession', 'Succession', 'Succession', $viewer->hasPermission('succession.view'), fn () => ['Named as successor' => $scoped(Successor::class)->whereNotNull('active_key')->count()]);
        $level = $this->compensation->level($viewer, $employee);
        $add('compensation', 'Compensation', 'Compensation', $level !== null, function () use ($employee) {
            $active = EmployeeSalaryAssignment::query()->withoutGlobalScope(AccessScope::class)->where('employee_id', $employee->id)
                ->effectiveOn()->latest('effective_from')->first(['effective_from']);

            return ['Current assignment since' => $active?->effective_from?->toDateString(), 'Amounts' => 'In the Compensation tab (audited)'];
        });
        $add('documents', 'Documents', 'Documents', $viewer->hasPermission('document.view') || ($own && $viewer->hasPermission('document.own')), fn () => [
            'Documents' => $scoped(EmployeeDocument::class)->where('status', '!=', 'archived')->count(),
            'Expiring in 30 days' => $scoped(EmployeeDocument::class)->whereNotNull('expires_on')->whereBetween('expires_on', [now()->toDateString(), now()->addDays(30)->toDateString()])->count(),
        ]);
        $add('letters', 'Letters', 'Letters', $viewer->hasPermission('letter.view') || $own, fn () => ['Letters' => $scoped(Letter::class)->count(), 'Issued' => $scoped(Letter::class)->where('status', 'issued')->count()]);
        $add('service', 'HR requests', 'Service Desk', $this->cases->isAgent($viewer) || $own, fn () => [
            'Open requests' => $this->cases->visible(Ticket::query(), $viewer)->where('tickets.employee_id', $employee->id)->whereNotIn('tickets.status', ['resolved', 'closed', 'cancelled', 'draft'])->count(),
        ]);
        // Engagement: identified surveys only; anonymous and confidential participation is never shown per person.
        $add('engagement', 'Engagement (identified surveys only)', 'Engagement', $viewer->hasPermission('engagement.responses'), fn () => [
            'Identified surveys answered' => AccessScope::withoutScoping(fn () => SurveyParticipation::query()->where('employee_id', $employee->id)->where('status', 'submitted')
                ->whereIn('survey_version_id', SurveyVersion::query()->select('id')->where('anonymity_mode', 'identified'))->count()),
        ]);
        $add('communication', 'Communications', 'Communication', $viewer->hasPermission('communication.manage') || $viewer->hasPermission('communication.approve') || $own, fn () => [
            'Pending acknowledgements' => $this->communications->pendingAcknowledgements($employee)->count(),
        ]);
        $add('exit', 'Exit', 'Exit', $viewer->hasPermission('exit.view'), function () use ($scoped) {
            $case = $scoped(ExitCase::class)->latest('id')->first(['status', 'last_working_day']);

            return ['Exit case' => $case ? str_replace('_', ' ', (string) $case->status) : 'None', 'Last working day' => $case?->last_working_day?->toDateString()];
        });
        $add('alumni', 'Alumni', 'Alumni', $viewer->hasPermission('alumni.view') || $viewer->hasPermission('exit.view'), fn () => [
            'Alumni profile' => $scoped(AlumniProfile::class)->exists() ? 'Yes' : 'No',
        ]);

        return $sections;
    }
}
