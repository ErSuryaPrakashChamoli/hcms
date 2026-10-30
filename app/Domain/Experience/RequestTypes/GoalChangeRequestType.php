<?php

namespace App\Domain\Experience\RequestTypes;

use App\Domain\Employment\Models\Employee;
use App\Domain\Experience\Enums\RequestStatus;
use App\Domain\Identity\Models\User;
use App\Domain\Performance\Models\GoalPlan;
use App\Domain\Performance\Models\PerformanceCycle;
use App\Filament\Resources\Goals\GoalResource;
use Illuminate\Database\Eloquent\Model;

/**
 * HROne parity RQ-1: change goal — goal sheets and goal changes sent for approval (Goals: add or change goals, "Submit goal
 * sheet"; a system-mapped sheet's changes become proposals the reporting manager confirms).
 */
final class GoalChangeRequestType extends BaseRequestType
{
    public function key(): string
    {
        return 'goal_change';
    }

    public function label(): string
    {
        return 'Change goal';
    }

    public function description(): string
    {
        return 'Add or change your goals and send the goal sheet for approval';
    }

    public function module(): string
    {
        return 'Performance';
    }

    public function icon(): string
    {
        return 'heroicon-o-flag';
    }

    public function keywords(): array
    {
        return ['goal', 'goals', 'goal sheet', 'change goal', 'kra', 'okr'];
    }

    public function permission(): string
    {
        return 'performance.goals';
    }

    /** Goals opens, the person may plan goals and a cycle is open for goal setting (draft, scheduled or active). */
    public function availableTo(User $user, ?Employee $employee): bool
    {
        return $this->signedIn($user) && $employee !== null && $user->can('performance.goals') && GoalResource::canAccess()
            && PerformanceCycle::query()->whereIn('status', ['draft', 'scheduled', 'active'])->exists();
    }

    public function formUrl(User $user, ?Employee $employee): ?string
    {
        return GoalResource::getUrl('index');
    }

    public function model(): ?string
    {
        return GoalPlan::class;
    }

    protected function resource(): ?string
    {
        return GoalResource::class;
    }

    protected function with(): array
    {
        return ['cycle'];
    }

    public function statusMap(): array
    {
        return [
            'pending' => RequestStatus::Pending,
            'approved' => RequestStatus::Approved,
            'rejected' => RequestStatus::Rejected,
        ];
    }

    public function statusLabel(Model $record): string
    {
        return ['pending' => 'Waiting for approval', 'approved' => 'Approved', 'rejected' => 'Rejected'][$this->rawStatus($record)] ?? ucfirst($this->rawStatus($record));
    }

    public function dateFilters(): array
    {
        return [
            'requested' => ['label' => 'Sent on', 'column' => 'submitted_at'],
            'decided' => ['label' => 'Decided on', 'column' => 'decided_at'],
        ];
    }

    public function searchColumns(): array
    {
        return ['decision_note'];
    }

    public function summary(Model $record): array
    {
        /** @var GoalPlan $record */
        return [
            'title' => $this->join('Goal sheet', $record->cycle?->name),
            'detail' => $this->join($record->goals !== null ? $record->goals.' goal(s)' : null, $this->short($record->decision_note)),
        ];
    }

    // -- approval route (RQ-2) ----------------------------------------------------------------------------------

    public function raisedEvents(): array
    {
        return ['performance.goal_plan.submitted'];
    }

    public function builtInApprovers(): string
    {
        return 'Your manager (goal sheet approval in the Approval Center)';
    }

    public function approverPermissions(): array
    {
        return ['performance.team', 'performance.manage'];
    }

    public function approvalKind(Model $record): ?string
    {
        return 'goal_plan';
    }
}
