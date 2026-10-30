<?php

namespace App\Domain\Analytics\Datasets;

use App\Domain\Performance\Models\FeedbackEntry;
use Illuminate\Database\Eloquent\Builder;

/**
 * HROne parity AN-27 (HROne report "Continuous feedback report"): praise, constructive feedback and feedback requests
 * given in PeopleOS, about whom, of which kind and visibility, linked to which goal or competency. For performance HR
 * (performance.view: the Continuous feedback screen shows them every entry in their access scope). The author of
 * anonymous feedback is never reported — not even to holders of performance.anonymous_identity, whose reveal stays an
 * audited action on the entry — so the author column reads "Anonymous", and the message text is a field of its own.
 */
class ContinuousFeedbackDataset extends Dataset
{
    public function key(): string
    {
        return 'continuous_feedback';
    }

    public function label(): string
    {
        return 'Continuous feedback (praise, constructive, requests)';
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
        return [
            'given_on' => ['label' => 'Date', 'type' => 'date', 'value' => fn (FeedbackEntry $f) => $f->created_at],
            'month' => ['label' => 'Month', 'type' => 'string', 'value' => fn (FeedbackEntry $f) => $f->created_at?->format('Y-m')],
            ...$this->employeeFields(fn (FeedbackEntry $f) => $f->employee),
            'type' => ['label' => 'Type', 'type' => 'string', 'value' => fn (FeedbackEntry $f) => config("peopleos.performance.feedback_types.{$f->type}", $f->type)],
            'type_code' => ['label' => 'Type (code)', 'type' => 'string', 'value' => fn (FeedbackEntry $f) => $f->type],
            // Never the author of anonymous feedback: authorLabel() reads "Anonymous" for it.
            'author' => ['label' => 'Given by', 'type' => 'string', 'value' => fn (FeedbackEntry $f) => $f->authorLabel()],
            'is_anonymous' => ['label' => 'Anonymous', 'type' => 'boolean', 'value' => fn (FeedbackEntry $f) => (bool) $f->is_anonymous],
            'requested_from' => ['label' => 'Asked of', 'type' => 'string', 'value' => fn (FeedbackEntry $f) => $f->type === 'request' ? $f->requestedFrom?->person?->full_name : null],
            'visibility' => ['label' => 'Visible to', 'type' => 'string', 'value' => fn (FeedbackEntry $f) => config("peopleos.performance.feedback_visibility.{$f->visibility}", $f->visibility)],
            'goal' => ['label' => 'Goal', 'type' => 'string', 'value' => fn (FeedbackEntry $f) => $f->goal?->title],
            'competency' => ['label' => 'Competency', 'type' => 'string', 'value' => fn (FeedbackEntry $f) => $f->competency?->name],
            'status' => ['label' => 'Status', 'type' => 'string', 'value' => fn (FeedbackEntry $f) => $f->status],
            'message' => ['label' => 'Message', 'type' => 'string', 'value' => fn (FeedbackEntry $f) => $f->message],
            'count' => ['label' => 'Entries (1 per row)', 'type' => 'number', 'value' => fn (FeedbackEntry $f) => 1],
        ];
    }

    public function query(): Builder
    {
        return FeedbackEntry::query()->with([...self::employeeWith('employee'), 'author.person', 'requestedFrom.person', 'goal', 'competency'])
            ->orderByDesc('created_at')->orderByDesc('id');
    }
}
