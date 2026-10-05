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
    /**
     * UX.19 (G13): where each reminder is acted on: the screens to try, in order (a page or a resource, optionally with
     * parameters). The first one the person may open is the link.
     */
    private const SCREENS = [
        // The person's own obligations.
        'kb_ack' => [[MyHr::class, ['tab' => 'policies']], ArticleResource::class],
        'announcement_ack' => [AnnouncementsFeed::class, [MyHr::class, ['tab' => 'communications']]],
        'learning' => [MyLearning::class, LearningEnrolmentResource::class],
        'reviews' => [AppraisalResource::class],
        'tickets' => [TicketResource::class, [MyHr::class, ['tab' => 'requests']]],
        'assets' => [AssetResource::class],
        'tasks' => [TaskInbox::class],
        'records' => [[MyHr::class, ['tab' => 'services']]],
        'tax_declaration' => [TaxDeclarationResource::class],
        // A manager's work about their team.
        'team_decisions' => [Approvals::class],
        'team_learning' => [TeamLearning::class, LearningEnrolmentResource::class],
        'assigned_tickets' => [TicketResource::class],
        'grievances' => [GrievanceResource::class],
        'one_on_ones' => [OneOnOneResource::class],
    ];

    /** @var array<int, array<string, ?string>> per user and destination, for this request */
    private array $resolved = [];

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
        $keys = array_keys(self::SCREENS);

        return array_combine($keys, array_map(fn (string $key) => $this->destination($user, $key), $keys));
    }

    /** One destination, worked out only when a reminder needs it (a person usually has a few, not fourteen). */
    public function destination(User $user, string $key): ?string
    {
        if (! array_key_exists($key, $this->resolved[$user->id] ?? [])) {
            $this->resolved[$user->id][$key] = ScreenAccess::as($user, fn () => $this->screen(...self::SCREENS[$key]));
        }

        return $this->resolved[$user->id][$key];
    }

    /** @return Collection<int, array<string, mixed>> */
    public function forEmployee(Employee $employee, User $user): Collection
    {
        $items = collect();
        $add = function (string $key, int $count, string $title, string $detail, string $severity, ?string $to = null) use (&$items, $user) {
            if ($count > 0) {
                $url = $to !== null ? $this->destination($user, $to) : null;
                $items->push(compact('key', 'count', 'title', 'detail', 'severity', 'url'));
            }
        };

        $add('kb_ack', $this->kb->pendingAcknowledgements($employee)->count(), 'Policies to acknowledge', 'Read and acknowledge the current version', 'warning', 'kb_ack');
        $add('announcement_ack', $this->comms->pendingAcknowledgements($employee)->count(), 'Announcements to acknowledge', 'Confirm you have read them', 'info', 'announcement_ack');
        $add('learning', LearningEnrolment::query()->where('employee_id', $employee->id)->where('status', 'overdue')->count(), 'Overdue learning', 'Mandatory training past its due date', 'danger', 'learning');
        $add('learning_due', LearningEnrolment::query()->where('employee_id', $employee->id)->whereIn('status', ['enrolled', 'in_progress'])->whereNotNull('due_on')->whereDate('due_on', '<=', now()->addDays(7))->count(), 'Learning due this week', 'Finish before the due date', 'warning', 'learning');
        $add('reviews', AppraisalReview::query()->where('reviewer_id', $employee->id)->where('status', 'pending')->whereHas('appraisal.cycle', fn ($q) => $q->where('status', 'active')->whereIn('current_stage', ['self_review', 'manager_review', 'peer_review']))->count(), 'Reviews to complete', 'Self, manager or peer reviews waiting for you', 'warning', 'reviews');
        // Phase 12: only requests the employee may see (never a confidential case raised about them).
        $mine = fn () => Ticket::query()->where('employee_id', $employee->id)->where('visible_to_employee', true);
        $add('tickets', $mine()->where('status', 'waiting_employee')->count(), 'HR is waiting on you', 'Reply on your open requests', 'warning', 'tickets');
        $add('tickets_resolved', $mine()->where('status', 'resolved')->count(), 'Requests resolved', 'Close them or reopen if not fixed', 'info', 'tickets');
        $add('assets', AssetAssignment::query()->where('employee_id', $employee->id)->where('status', 'active')->whereNull('acknowledged_at')->count(), 'Assets to acknowledge', 'Confirm receipt of company property', 'info', 'assets');
        $add('tasks', WorkflowTask::query()->where('assignee_id', $user->id)->where('status', TaskStatus::Pending)->count(), 'Approvals waiting', 'Workflow tasks assigned to you', 'warning', 'tasks');

        // UX.19: HR records these; the next step is to ask HR (My HR), not a dead end.
        $employee->loadMissing(['bankAccounts', 'statutoryDetail']);
        $add('bank', $employee->bankAccounts->where('is_primary', true)->isEmpty() ? 1 : 0, 'Bank account missing', 'Payroll cannot pay you without a primary bank account. Ask HR to add it', 'danger', 'records');
        $add('pan', blank($employee->statutoryDetail?->pan) ? 1 : 0, 'PAN not on file', 'Tax is deducted at the higher rate until PAN is recorded. Ask HR to add it', 'danger', 'records');
        $add('tax_declaration', EmployeeTaxDeclaration::query()->where('employee_id', $employee->id)->where('financial_year', $this->fy->label(now()))->exists() ? 0 : 1, 'Tax declaration', 'Choose your regime and declare investments for '.$this->fy->label(now()), 'info', 'tax_declaration');

        return $items->sortBy(fn ($i) => match ($i['severity']) {
            'danger' => 0, 'warning' => 1, default => 2
        })->values();
    }

    /** @return Collection<int, array<string, mixed>> */
    public function forManager(Employee $manager, User $user): Collection
    {
        $reports = $manager->directReports()->currentlyEffective()->pluck('employee_id');
        $items = collect();
        $add = function (string $key, int $count, string $title, string $detail, string $severity, ?string $to = null) use (&$items, $user) {
            if ($count > 0) {
                $url = $to !== null ? $this->destination($user, $to) : null;
                $items->push(compact('key', 'count', 'title', 'detail', 'severity', 'url'));
            }
        };

        // UX.19: a team decision is a reminder only for someone who may decide it; the decision is made in the Approval Center.
        $add('leave', $user->hasPermission('leave.approve') ? LeaveRequest::query()->whereIn('employee_id', $reports)->where('status', 'pending')->count() : 0, 'Leave approvals', 'Requests from your team', 'warning', 'team_decisions');
        $add('regularisation', $user->hasPermission('attendance.approve') ? AttendanceRegularisation::query()->whereIn('employee_id', $reports)->where('status', 'pending')->count() : 0, 'Attendance regularisations', 'Missed punches and corrections to approve', 'warning', 'team_decisions');
        $add('reviews', AppraisalReview::query()->where('reviewer_id', $manager->id)->where('type', 'manager')->where('status', 'pending')->whereHas('appraisal.cycle', fn ($q) => $q->where('status', 'active')->where('current_stage', 'manager_review'))->count(), 'Performance reviews', 'Manager reviews open for your team', 'warning', 'reviews');
        $add('tasks', WorkflowTask::query()->where('assignee_id', $user->id)->where('status', TaskStatus::Pending)->count(), 'Approvals waiting', 'Workflow tasks assigned to you', 'warning', 'tasks');
        $add('tickets', Ticket::query()->where('assignee_id', $user->id)->whereIn('status', Ticket::OPEN)->count(), 'Service desk tickets', 'Assigned to you', 'info', 'assigned_tickets');
        $add('grievances', Grievance::query()->where('assignee_id', $user->id)->whereIn('status', Grievance::OPEN)->count(), 'Grievance cases', 'Assigned to you', 'danger', 'grievances');
        $add('one_on_ones', OneOnOne::query()->where('manager_id', $manager->id)->where('status', 'scheduled')->where('scheduled_at', '<', now())->count(), 'One-on-ones to write up', 'Held but not recorded', 'info', 'one_on_ones');
        $add('learning', LearningEnrolment::query()->whereIn('employee_id', $reports)->where('status', 'overdue')->count(), 'Team learning overdue', 'Mandatory training past due in your team', 'warning', 'team_learning');

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
