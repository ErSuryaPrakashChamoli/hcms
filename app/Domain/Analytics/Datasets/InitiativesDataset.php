<?php

namespace App\Domain\Analytics\Datasets;

use App\Domain\Performance\Models\Initiative;
use Illuminate\Database\Eloquent\Builder;

/**
 * HROne setup parity HP-8j (HROne report "Initiative details"): initiatives with their type, linked goal, dates,
 * completion and status, and how much feedback was asked and given. The description, outcome and feedback text stay on
 * the initiative.
 */
class InitiativesDataset extends Dataset
{
    public function key(): string
    {
        return 'initiatives';
    }

    public function label(): string
    {
        return 'Initiatives';
    }

    public function category(): string
    {
        return 'Performance & learning';
    }

    public function permissions(): array
    {
        return ['performance.initiatives_manage', 'performance.view'];
    }

    public function fields(): array
    {
        return [
            ...$this->employeeFields(fn ($i) => $i->employee),
            'title' => ['label' => 'Initiative', 'type' => 'string', 'value' => fn ($i) => $i->title],
            'initiative_type' => ['label' => 'Initiative type', 'type' => 'string', 'value' => fn ($i) => $i->type?->name],
            'goal' => ['label' => 'Linked goal', 'type' => 'string', 'value' => fn ($i) => $i->goal?->title],
            'assigned_by' => ['label' => 'Assigned by', 'type' => 'string', 'value' => fn ($i) => $i->owner?->person?->full_name ?? 'Self'],
            'start_date' => ['label' => 'Start', 'type' => 'date', 'value' => fn ($i) => $i->start_date],
            'due_date' => ['label' => 'Due date', 'type' => 'date', 'value' => fn ($i) => $i->due_date],
            'days_left' => ['label' => 'Days left', 'type' => 'number', 'value' => fn ($i) => $i->isOpen() ? self::daysFromToday($i->due_date) : null],
            'is_overdue' => ['label' => 'Overdue (1 = yes)', 'type' => 'number', 'value' => fn ($i) => $i->isOpen() && $i->due_date !== null && $i->due_date->lt(today()) ? 1 : 0],
            'progress' => ['label' => 'Completion %', 'type' => 'number', 'value' => fn ($i) => (int) $i->progress],
            'status' => ['label' => 'Status', 'type' => 'string', 'value' => fn ($i) => $i->status],
            'completed_on' => ['label' => 'Completed on', 'type' => 'date', 'value' => fn ($i) => $i->completed_at],
            'feedback_requested' => ['label' => 'Feedback requested', 'type' => 'number', 'value' => fn ($i) => (int) $i->feedback_requested_count],
            'feedback_given' => ['label' => 'Feedback given', 'type' => 'number', 'value' => fn ($i) => (int) $i->feedback_given_count],
            'count' => ['label' => 'Initiatives (1 per row)', 'type' => 'number', 'value' => fn ($i) => 1],
        ];
    }

    public function query(): Builder
    {
        return Initiative::query()->with([...self::employeeWith('employee'), 'owner.person', 'type', 'goal'])
            ->withCount(['feedback as feedback_requested_count' => fn (Builder $q) => $q->whereNotNull('requested_by'), 'feedback as feedback_given_count' => fn (Builder $q) => $q->where('status', 'given')])
            ->orderByDesc('id');
    }
}
