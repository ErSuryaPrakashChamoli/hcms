<?php

namespace App\Domain\Analytics\Datasets;

use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Models\User;
use App\Domain\Performance\Models\FeedbackNomination;
use App\Domain\Performance\Services\FeedbackRoundResults;
use Illuminate\Database\Eloquent\Builder;

/**
 * HROne parity AN-27 (HROne report "360 feedback details"): one row per employee assessed in a 360 round and source of
 * feedback (manager, functional manager, peers, subordinates, clients) — aggregates only, exactly as the round's "Export
 * results": how many were asked, answered and declined, and the average score, which is left blank for an anonymous
 * source until the policy's minimum number of people have answered. Never a single answer, a reviewer's name, question
 * averages or comment text. The numbers come from FeedbackRoundResults (the only read path for 360 answers): for HR
 * running 360 feedback (performance.feedback_360 or performance.view) in their access scope, never about themselves; a
 * run without a reader has no rows.
 */
class Feedback360ResultsDataset extends Dataset
{
    /** @var array{0: int, 1: array<string, array<string, mixed>>|null}|null the aggregates of the participant being mapped */
    private ?array $current = null;

    public function key(): string
    {
        return 'feedback_360_results';
    }

    public function label(): string
    {
        return '360 feedback results by source (aggregates only)';
    }

    public function category(): string
    {
        return 'Performance & learning';
    }

    public function permissions(): array
    {
        return ['performance.feedback_360', 'performance.view'];
    }

    public function fields(): array
    {
        $aggregate = fn (string $key) => fn (FeedbackNomination $n) => $this->source($n)[$key] ?? null;

        return [
            'round' => ['label' => 'Round', 'type' => 'string', 'value' => fn (FeedbackNomination $n) => $n->round?->name],
            'period_start' => ['label' => 'Period from', 'type' => 'date', 'value' => fn (FeedbackNomination $n) => $n->round?->period_start],
            'period_end' => ['label' => 'Period to', 'type' => 'date', 'value' => fn (FeedbackNomination $n) => $n->round?->period_end],
            'round_status' => ['label' => 'Round status', 'type' => 'string', 'value' => fn (FeedbackNomination $n) => $n->round ? config("peopleos.performance.feedback_360.round_statuses.{$n->round->status}", $n->round->status) : null],
            ...$this->employeeFields(fn (FeedbackNomination $n) => $n->employee),
            'source' => ['label' => 'Source', 'type' => 'string', 'value' => fn (FeedbackNomination $n) => config("peopleos.performance.feedback_360.relations.{$n->relation}", $n->relation)],
            'source_code' => ['label' => 'Source (code)', 'type' => 'string', 'value' => fn (FeedbackNomination $n) => $n->relation],
            'anonymous' => ['label' => 'Anonymous source', 'type' => 'boolean', 'value' => $aggregate('anonymous')],
            'asked' => ['label' => 'Asked', 'type' => 'number', 'value' => $aggregate('asked')],
            'answered' => ['label' => 'Answered', 'type' => 'number', 'value' => $aggregate('given')],
            'declined' => ['label' => 'Chose not to participate', 'type' => 'number', 'value' => $aggregate('declined')],
            'shown' => ['label' => 'Results shown', 'type' => 'boolean', 'value' => $aggregate('shown')],
            'average_score' => ['label' => 'Average score (%)', 'type' => 'number', 'value' => $aggregate('score')],
            'note' => ['label' => 'Note', 'type' => 'string', 'value' => $aggregate('note')],
            'count' => ['label' => 'Sources (1 per row)', 'type' => 'number', 'value' => fn (FeedbackNomination $n) => 1],
        ];
    }

    public function query(): Builder
    {
        $this->current = null;
        $viewer = auth()->user();
        // One row per assessed employee (nomination set) and source: the nominations grouped, never one per reviewer.
        $query = FeedbackNomination::query()
            ->selectRaw('min(feedback_nominations.id) as id, feedback_nominations.feedback_participant_id, feedback_nominations.feedback_round_id, feedback_nominations.employee_id, feedback_nominations.relation')
            ->where('feedback_nominations.status', '!=', 'cancelled')
            ->groupBy('feedback_nominations.feedback_participant_id', 'feedback_nominations.feedback_round_id', 'feedback_nominations.employee_id', 'feedback_nominations.relation')
            ->with(['participant.round', 'participant.employee', 'round', ...self::employeeWith('employee')])
            ->orderByDesc('feedback_nominations.feedback_round_id')->orderBy('feedback_nominations.employee_id')->orderBy('feedback_nominations.relation');
        if (! $viewer instanceof User) {
            return $query->whereRaw('1 = 0');
        }

        // HR never reads 360 results about themselves (FeedbackRoundResults::canSee / exportRows).
        return $query->whereNotIn('feedback_nominations.employee_id', Employee::query()->select('employees.id')->where('employees.user_id', $viewer->id));
    }

    /** @return array<string, mixed>|null the aggregate of the row's source, as FeedbackRoundResults gives it to the reader */
    private function source(FeedbackNomination $row): ?array
    {
        $viewer = auth()->user();
        if (! $viewer instanceof User || $row->participant === null) {
            return null;
        }
        $participantId = (int) $row->feedback_participant_id;
        if ($this->current === null || $this->current[0] !== $participantId) {
            $this->current = [$participantId, app(FeedbackRoundResults::class)->aggregatesFor($row->participant, $viewer)];
        }

        return $this->current[1][$row->relation] ?? null;
    }
}
