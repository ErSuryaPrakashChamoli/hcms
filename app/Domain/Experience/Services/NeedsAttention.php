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
use App\Filament\Pages\AnnouncementsFeed;
use App\Filament\Pages\Approvals;
use App\Filament\Pages\MyHr;
use App\Filament\Pages\MyLearning;
use App\Filament\Pages\TaskInbox;
use App\Filament\Pages\TeamLearning;
use App\Filament\Resources\Appraisals\AppraisalResource;
use App\Filament\Resources\Articles\ArticleResource;
use App\Filament\Resources\Assets\AssetResource;
use App\Filament\Resources\Grievances\GrievanceResource;
use App\Filament\Resources\LearningEnrolments\LearningEnrolmentResource;
use App\Filament\Resources\OneOnOnes\OneOnOneResource;
use App\Filament\Resources\TaxDeclarations\TaxDeclarationResource;
use App\Filament\Resources\Tickets\TicketResource;
use Filament\Resources\Resource;
use Illuminate\Support\Collection;

/**
 * The "Needs Attention" pattern (§57): one personalised list per person. Each item is
 * ['key', 'title', 'detail', 'count', 'severity' (info|warning|danger), 'url'].
 *
 * UX.19 (G13): an item links to the screen where its work is done, and only when the person may open that screen
 * (destinations()). A team decision is a reminder only for someone who may decide it.
 */
final class NeedsAttention
{
    /** @var array<int, array<string, ?string>> per user, for this request */
    private array $destinations = [];

    public function __construct(private readonly KnowledgeBase $kb, private readonly Communications $comms, private readonly FinancialYear $fy) {}

    /**
     * UX.19 (G13): where each reminder is acted on, for this person. Each entry is the first screen in its list that
     * they may open, by the screen's own canAccess() (the check it runs when opened). Null means nowhere they may
     * go, so the reminder carries no link. Links are worked out on every request, so a permission or scope change moves
     * or removes them at once; the screen still authorises itself.
     *
     * @return array<string, ?string>
     */
    public function destinations(User $user): array
    {
        return $this->destinations[$user->id] ??= ScreenAccess::as($user, fn () => [
            // The person's own obligations.
            'kb_ack' => $this->screen([MyHr::class, ['tab' => 'policies']], ArticleResource::class),
            'announcement_ack' => $this->screen(AnnouncementsFeed::class, [MyHr::class, ['tab' => 'communications']]),
            'learning' => $this->screen(MyLearning::class, LearningEnrolmentResource::class),
            'reviews' => $this->screen(AppraisalResource::class),
            'tickets' => $this->screen(TicketResource::class, [MyHr::class, ['tab' => 'requests']]),
            'assets' => $this->screen(AssetResource::class),
            'tasks' => $this->screen(TaskInbox::class),
            'records' => $this->screen([MyHr::class, ['tab' => 'services']]),
            'tax_declaration' => $this->screen(TaxDeclarationResource::class),
            // A manager's work about their team.
            'team_decisions' => $this->screen(Approvals::class),
            'team_learning' => $this->screen(TeamLearning::class, LearningEnrolmentResource::class),
            'assigned_tickets' => $this->screen(TicketResource::class),
            'grievances' => $this->screen(GrievanceResource::class),
            'one_on_ones' => $this->screen(OneOnOneResource::class),
        ]);
    }

    /** @return Collection<int, array<string, mixed>> */
    public function forEmployee(Employee $employee, User $user): Collection
    {
        $items = collect();
        $to = $this->destinations($user);
        $add = function (string $key, int $count, string $title, string $detail, string $severity, ?string $url = null) use (&$items) {
            if ($count > 0) {
                $items->push(compact('key', 'count', 'title', 'detail', 'severity', 'url'));
            }
        };

        $add('kb_ack', $this->kb->pendingAcknowledgements($employee)->count(), 'Policies to acknowledge', 'Read and acknowledge the current version', 'warning', $to['kb_ack'] ?? null);
        $add('announcement_ack', $this->comms->pendingAcknowledgements($employee)->count(), 'Announcements to acknowledge', 'Confirm you have read them', 'info', $to['announcement_ack'] ?? null);
        $add('learning', LearningEnrolment::query()->where('employee_id', $employee->id)->where('status', 'overdue')->count(), 'Overdue learning', 'Mandatory training past its due date', 'danger', $to['learning'] ?? null);
        $add('learning_due', LearningEnrolment::query()->where('employee_id', $employee->id)->whereIn('status', ['enrolled', 'in_progress'])->whereNotNull('due_on')->whereDate('due_on', '<=', now()->addDays(7))->count(), 'Learning due this week', 'Finish before the due date', 'warning', $to['learning'] ?? null);
        $add('reviews', AppraisalReview::query()->where('reviewer_id', $employee->id)->where('status', 'pending')->whereHas('appraisal.cycle', fn ($q) => $q->where('status', 'active')->whereIn('current_stage', ['self_review', 'manager_review', 'peer_review']))->count(), 'Reviews to complete', 'Self, manager or peer reviews waiting for you', 'warning', $to['reviews'] ?? null);
        // Phase 12: only requests the employee may see (never a confidential case raised about them).
        $mine = fn () => Ticket::query()->where('employee_id', $employee->id)->where('visible_to_employee', true);
        $add('tickets', $mine()->where('status', 'waiting_employee')->count(), 'HR is waiting on you', 'Reply on your open requests', 'warning', $to['tickets'] ?? null);
        $add('tickets_resolved', $mine()->where('status', 'resolved')->count(), 'Requests resolved', 'Close them or reopen if not fixed', 'info', $to['tickets'] ?? null);
        $add('assets', AssetAssignment::query()->where('employee_id', $employee->id)->where('status', 'active')->whereNull('acknowledged_at')->count(), 'Assets to acknowledge', 'Confirm receipt of company property', 'info', $to['assets'] ?? null);
        $add('tasks', WorkflowTask::query()->where('assignee_id', $user->id)->where('status', TaskStatus::Pending)->count(), 'Approvals waiting', 'Workflow tasks assigned to you', 'warning', $to['tasks'] ?? null);

        // UX.19: HR records these; the next step is to ask HR (My HR), not a dead end.
        $employee->loadMissing(['bankAccounts', 'statutoryDetail']);
        $add('bank', $employee->bankAccounts->where('is_primary', true)->isEmpty() ? 1 : 0, 'Bank account missing', 'Payroll cannot pay you without a primary bank account. Ask HR to add it', 'danger', $to['records'] ?? null);
        $add('pan', blank($employee->statutoryDetail?->pan) ? 1 : 0, 'PAN not on file', 'Tax is deducted at the higher rate until PAN is recorded. Ask HR to add it', 'danger', $to['records'] ?? null);
        $add('tax_declaration', EmployeeTaxDeclaration::query()->where('employee_id', $employee->id)->where('financial_year', $this->fy->label(now()))->exists() ? 0 : 1, 'Tax declaration', 'Choose your regime and declare investments for '.$this->fy->label(now()), 'info', $to['tax_declaration'] ?? null);

        return $items->sortBy(fn ($i) => match ($i['severity']) {
            'danger' => 0, 'warning' => 1, default => 2
        })->values();
    }

    /** @return Collection<int, array<string, mixed>> */
    public function forManager(Employee $manager, User $user): Collection
    {
        $reports = $manager->directReports()->currentlyEffective()->pluck('employee_id');
        $items = collect();
        $to = $this->destinations($user);
        $add = function (string $key, int $count, string $title, string $detail, string $severity, ?string $url = null) use (&$items) {
            if ($count > 0) {
                $items->push(compact('key', 'count', 'title', 'detail', 'severity', 'url'));
            }
        };

        // UX.19: a team decision is a reminder only for someone who may decide it; the decision is made in the Approval Center.
        $add('leave', $user->hasPermission('leave.approve') ? LeaveRequest::query()->whereIn('employee_id', $reports)->where('status', 'pending')->count() : 0, 'Leave approvals', 'Requests from your team', 'warning', $to['team_decisions'] ?? null);
        $add('regularisation', $user->hasPermission('attendance.approve') ? AttendanceRegularisation::query()->whereIn('employee_id', $reports)->where('status', 'pending')->count() : 0, 'Attendance regularisations', 'Missed punches and corrections to approve', 'warning', $to['team_decisions'] ?? null);
        $add('reviews', AppraisalReview::query()->where('reviewer_id', $manager->id)->where('type', 'manager')->where('status', 'pending')->whereHas('appraisal.cycle', fn ($q) => $q->where('status', 'active')->where('current_stage', 'manager_review'))->count(), 'Performance reviews', 'Manager reviews open for your team', 'warning', $to['reviews'] ?? null);
        $add('tasks', WorkflowTask::query()->where('assignee_id', $user->id)->where('status', TaskStatus::Pending)->count(), 'Approvals waiting', 'Workflow tasks assigned to you', 'warning', $to['tasks'] ?? null);
        $add('tickets', Ticket::query()->where('assignee_id', $user->id)->whereIn('status', Ticket::OPEN)->count(), 'Service desk tickets', 'Assigned to you', 'info', $to['assigned_tickets'] ?? null);
        $add('grievances', Grievance::query()->where('assignee_id', $user->id)->whereIn('status', Grievance::OPEN)->count(), 'Grievance cases', 'Assigned to you', 'danger', $to['grievances'] ?? null);
        $add('one_on_ones', OneOnOne::query()->where('manager_id', $manager->id)->where('status', 'scheduled')->where('scheduled_at', '<', now())->count(), 'One-on-ones to write up', 'Held but not recorded', 'info', $to['one_on_ones'] ?? null);
        $add('learning', LearningEnrolment::query()->whereIn('employee_id', $reports)->where('status', 'overdue')->count(), 'Team learning overdue', 'Mandatory training past due in your team', 'warning', $to['team_learning'] ?? null);

        return $items->sortBy(fn ($i) => match ($i['severity']) {
            'danger' => 0, 'warning' => 1, default => 2
        })->values();
    }

    /**
     * The first screen the person may open (run inside ScreenAccess::as): a page or a resource (its list), optionally
     * with parameters.
     *
     * @param  class-string|array{0: class-string, 1: array<string, mixed>}  ...$screens
     */
    private function screen(string|array ...$screens): ?string
    {
        foreach ($screens as $screen) {
            [$class, $parameters] = is_array($screen) ? $screen : [$screen, []];
            $url = rescue(fn () => $class::canAccess() ? (is_subclass_of($class, Resource::class) ? $class::getUrl('index', $parameters) : $class::getUrl($parameters)) : null, null, false);
            if ($url !== null) {
                return $url;
            }
        }

        return null;
    }
}
