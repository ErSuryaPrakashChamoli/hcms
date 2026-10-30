<?php

namespace App\Domain\Analytics\Datasets;

use App\Domain\Identity\Scopes\AccessScope;
use App\Domain\Performance\Models\OneOnOne;
use Illuminate\Database\Eloquent\Builder;

/**
 * HROne parity AN-27 (HROne report "1 on 1 details"): one-on-ones with who they were with, who scheduled them, the
 * agenda, when they were held and how many follow-up actions are done, and the feedback cards given after them. For
 * performance HR (performance.view: the One-on-ones screen shows them every meeting in their access scope). The shared
 * notes, the manager's private notes and the feedback comments never reach a report.
 */
class OneOnOnesDataset extends Dataset
{
    public function key(): string
    {
        return 'one_on_ones';
    }

    public function label(): string
    {
        return 'One-on-ones (1-on-1 meetings)';
    }

    public function category(): string
    {
        return 'Performance & learning';
    }

    public function permissions(): array
    {
        return ['performance.view'];
    }

    public function fields(): array
    {
        $items = fn (OneOnOne $o) => collect((array) ($o->action_items ?? []))->filter(fn ($i) => is_array($i));

        return [
            'scheduled_on' => ['label' => 'Scheduled for', 'type' => 'date', 'value' => fn (OneOnOne $o) => $o->scheduled_at],
            'month' => ['label' => 'Month', 'type' => 'string', 'value' => fn (OneOnOne $o) => $o->scheduled_at?->format('Y-m')],
            ...$this->employeeFields(fn (OneOnOne $o) => $o->employee),
            'with' => ['label' => 'With (manager / host)', 'type' => 'string', 'value' => fn (OneOnOne $o) => $o->manager?->person?->full_name],
            'with_code' => ['label' => 'With (employee code)', 'type' => 'string', 'value' => fn (OneOnOne $o) => $o->manager?->employee_code],
            'initiated_by' => ['label' => 'Scheduled by', 'type' => 'string', 'value' => fn (OneOnOne $o) => (int) $o->initiatorId() === (int) $o->employee_id ? $o->employee?->person?->full_name : $o->manager?->person?->full_name],
            'requested_by_employee' => ['label' => 'Requested by the employee', 'type' => 'boolean', 'value' => fn (OneOnOne $o) => (int) $o->initiatorId() === (int) $o->employee_id],
            'agenda' => ['label' => 'Agenda', 'type' => 'string', 'value' => fn (OneOnOne $o) => $o->agenda],
            'status' => ['label' => 'Status', 'type' => 'string', 'value' => fn (OneOnOne $o) => $o->status],
            'held_on' => ['label' => 'Held on', 'type' => 'date', 'value' => fn (OneOnOne $o) => $o->held_at],
            'action_items' => ['label' => 'Follow-up actions', 'type' => 'number', 'value' => fn (OneOnOne $o) => $items($o)->count()],
            'action_items_open' => ['label' => 'Follow-up actions open', 'type' => 'number', 'value' => fn (OneOnOne $o) => $items($o)->reject(fn (array $i) => ! empty($i['done']))->count()],
            'feedback_given' => ['label' => 'Feedback cards given', 'type' => 'number', 'value' => fn (OneOnOne $o) => (int) $o->getAttribute('feedback_count')],
            'feedback_rating' => ['label' => 'Average feedback rating (1–5)', 'type' => 'number', 'value' => fn (OneOnOne $o) => $o->getAttribute('feedback_rating') === null ? null : round((float) $o->getAttribute('feedback_rating'), 2)],
            'count' => ['label' => 'One-on-ones (1 per row)', 'type' => 'number', 'value' => fn (OneOnOne $o) => 1],
        ];
    }

    public function query(): Builder
    {
        return OneOnOne::query()->with([...self::employeeWith('employee'), 'manager.person'])
            // A meeting in scope counts all of its feedback cards, whoever gave them.
            ->withCount(['feedback as feedback_count' => fn (Builder $q) => $q->withoutGlobalScope(AccessScope::class)])
            ->withAvg(['feedback as feedback_rating' => fn (Builder $q) => $q->withoutGlobalScope(AccessScope::class)], 'rating')
            ->orderByDesc('scheduled_at')->orderByDesc('id');
    }
}
