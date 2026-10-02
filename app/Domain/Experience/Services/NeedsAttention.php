<?php

namespace App\Domain\Experience\Services;

use App\Domain\Assets\Models\AssetAssignment;
use App\Domain\Attendance\Models\AttendanceRegularisation;
use App\Domain\Communication\Services\Communications;
use App\Domain\Compliance\Models\EmployeeTaxDeclaration;
use App\Domain\Compliance\Services\FinancialYear;
use App\Domain\Employment\Models\Employee;
use App\Domain\Grievance\Models\Grievance;
use App\Domain\Identity\Models\User;
use App\Domain\Knowledge\Services\KnowledgeBase;
use App\Domain\Learning\Models\LearningEnrolment;
use App\Domain\Leave\Models\LeaveRequest;
use App\Domain\Performance\Models\AppraisalReview;
use App\Domain\Performance\Models\OneOnOne;
use App\Domain\ServiceDesk\Models\Ticket;
use App\Domain\Workflow\Enums\TaskStatus;
use App\Domain\Workflow\Models\WorkflowTask;
use Illuminate\Support\Collection;

/**
 * The "Needs Attention" pattern (§57): one personalised list per person. Each item is
 * ['key', 'title', 'detail', 'count', 'severity' (info|warning|danger), 'url'].
 */
final class NeedsAttention
{
    public function __construct(private readonly KnowledgeBase $kb, private readonly Communications $comms, private readonly FinancialYear $fy) {}

    /** @return Collection<int, array<string, mixed>> */
    public function forEmployee(Employee $employee, User $user): Collection
    {
        $items = collect();
        $add = function (string $key, int $count, string $title, string $detail, string $severity, ?string $url = null) use (&$items) {
            if ($count > 0) {
                $items->push(compact('key', 'count', 'title', 'detail', 'severity', 'url'));
            }
        };

        $add('kb_ack', $this->kb->pendingAcknowledgements($employee)->count(), 'Policies to acknowledge', 'Read and acknowledge the current version', 'warning', $this->url('kb'));
        $add('announcement_ack', $this->comms->pendingAcknowledgements($employee)->count(), 'Announcements to acknowledge', 'Confirm you have read them', 'info', $this->url('announcements'));
        $add('learning', LearningEnrolment::query()->where('employee_id', $employee->id)->where('status', 'overdue')->count(), 'Overdue learning', 'Mandatory training past its due date', 'danger', $this->url('learning-enrolments'));
        $add('learning_due', LearningEnrolment::query()->where('employee_id', $employee->id)->whereIn('status', ['enrolled', 'in_progress'])->whereNotNull('due_on')->whereDate('due_on', '<=', now()->addDays(7))->count(), 'Learning due this week', 'Finish before the due date', 'warning', $this->url('learning-enrolments'));
        $add('reviews', AppraisalReview::query()->where('reviewer_id', $employee->id)->where('status', 'pending')->whereHas('appraisal.cycle', fn ($q) => $q->where('status', 'active')->whereIn('current_stage', ['self_review', 'manager_review', 'peer_review']))->count(), 'Reviews to complete', 'Self, manager or peer reviews waiting for you', 'warning', $this->url('appraisals'));
        // Phase 12: only requests the employee may see (never a confidential case raised about them).
        $mine = fn () => Ticket::query()->where('employee_id', $employee->id)->where('visible_to_employee', true);
        $add('tickets', $mine()->where('status', 'waiting_employee')->count(), 'HR is waiting on you', 'Reply on your open requests', 'warning', $this->url('tickets'));
        $add('tickets_resolved', $mine()->where('status', 'resolved')->count(), 'Requests resolved', 'Close them or reopen if not fixed', 'info', $this->url('tickets'));
        $add('assets', AssetAssignment::query()->where('employee_id', $employee->id)->where('status', 'active')->whereNull('acknowledged_at')->count(), 'Assets to acknowledge', 'Confirm receipt of company property', 'info', $this->url('assets'));
        $add('tasks', WorkflowTask::query()->where('assignee_id', $user->id)->where('status', TaskStatus::Pending)->count(), 'Approvals waiting', 'Workflow tasks assigned to you', 'warning', $this->url('task-inbox'));

        $employee->loadMissing(['bankAccounts', 'statutoryDetail']);
        $add('bank', $employee->bankAccounts->where('is_primary', true)->isEmpty() ? 1 : 0, 'Bank account missing', 'Payroll cannot pay you without a primary bank account', 'danger');
        $add('pan', blank($employee->statutoryDetail?->pan) ? 1 : 0, 'PAN not on file', 'Tax is deducted at the higher rate until PAN is recorded', 'danger');
        $add('tax_declaration', EmployeeTaxDeclaration::query()->where('employee_id', $employee->id)->where('financial_year', $this->fy->label(now()))->exists() ? 0 : 1, 'Tax declaration', 'Choose your regime and declare investments for '.$this->fy->label(now()), 'info', $this->url('tax-declarations'));

        return $items->sortBy(fn ($i) => match ($i['severity']) {
            'danger' => 0, 'warning' => 1, default => 2
        })->values();
    }

    /** @return Collection<int, array<string, mixed>> */
    public function forManager(Employee $manager, User $user): Collection
    {
        $reports = $manager->directReports()->currentlyEffective()->pluck('employee_id');
        $items = collect();
        $add = function (string $key, int $count, string $title, string $detail, string $severity, ?string $url = null) use (&$items) {
            if ($count > 0) {
                $items->push(compact('key', 'count', 'title', 'detail', 'severity', 'url'));
            }
        };

        $add('leave', LeaveRequest::query()->whereIn('employee_id', $reports)->where('status', 'pending')->count(), 'Leave approvals', 'Requests from your team', 'warning', $this->url('leave-requests'));
        $add('regularisation', AttendanceRegularisation::query()->whereIn('employee_id', $reports)->where('status', 'pending')->count(), 'Attendance regularisations', 'Missed punches and corrections to approve', 'warning', $this->url('attendance-regularisations'));
        $add('reviews', AppraisalReview::query()->where('reviewer_id', $manager->id)->where('type', 'manager')->where('status', 'pending')->whereHas('appraisal.cycle', fn ($q) => $q->where('status', 'active')->where('current_stage', 'manager_review'))->count(), 'Performance reviews', 'Manager reviews open for your team', 'warning', $this->url('appraisals'));
        $add('tasks', WorkflowTask::query()->where('assignee_id', $user->id)->where('status', TaskStatus::Pending)->count(), 'Approvals waiting', 'Workflow tasks assigned to you', 'warning', $this->url('task-inbox'));
        $add('tickets', Ticket::query()->where('assignee_id', $user->id)->whereIn('status', Ticket::OPEN)->count(), 'Service desk tickets', 'Assigned to you', 'info', $this->url('tickets'));
        $add('grievances', Grievance::query()->where('assignee_id', $user->id)->whereIn('status', Grievance::OPEN)->count(), 'Grievance cases', 'Assigned to you', 'danger', $this->url('grievances'));
        $add('one_on_ones', OneOnOne::query()->where('manager_id', $manager->id)->where('status', 'scheduled')->where('scheduled_at', '<', now())->count(), 'One-on-ones to write up', 'Held but not recorded', 'info', $this->url('one-on-ones'));
        $add('learning', LearningEnrolment::query()->whereIn('employee_id', $reports)->where('status', 'overdue')->count(), 'Team learning overdue', 'Mandatory training past due in your team', 'warning', $this->url('learning-enrolments'));

        return $items->sortBy(fn ($i) => match ($i['severity']) {
            'danger' => 0, 'warning' => 1, default => 2
        })->values();
    }

    private function url(string $slug): string
    {
        return url('/admin/'.$slug);
    }
}
