<?php

namespace App\Domain\Analytics\Datasets;

use App\Domain\Performance\Models\FeedbackParticipant;
use Illuminate\Database\Eloquent\Builder;

/**
 * HROne parity AN-27 (HROne reports "360 feedback status", "360 Nomination status"): one row per employee assessed in a
 * 360 round — the round and its window, who nominates, where the nomination set stands (to nominate, waiting for
 * approval, approved, sent back) and how many reviewers were asked, have answered, declined or are still to answer.
 * Counts only (FeedbackRoundResults::statusCounts gives the same to anyone who may see the set): never who was asked or
 * what anyone answered. For HR running 360 feedback (performance.feedback_360 or performance.view, as the rounds
 * screen) in their access scope.
 */
class Feedback360StatusDataset extends Dataset
{
    public function key(): string
    {
        return 'feedback_360_status';
    }

    public function label(): string
    {
        return '360 feedback status and nominations';
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
        $count = fn (string $key) => fn (FeedbackParticipant $p) => (int) $p->getAttribute($key);

        return [
            'round' => ['label' => 'Round', 'type' => 'string', 'value' => fn (FeedbackParticipant $p) => $p->round?->name],
            'period_start' => ['label' => 'Period from', 'type' => 'date', 'value' => fn (FeedbackParticipant $p) => $p->round?->period_start],
            'period_end' => ['label' => 'Period to', 'type' => 'date', 'value' => fn (FeedbackParticipant $p) => $p->round?->period_end],
            'window_closes_on' => ['label' => 'Window closes on', 'type' => 'date', 'value' => fn (FeedbackParticipant $p) => $p->round?->window_closes_on],
            'round_status' => ['label' => 'Round status', 'type' => 'string', 'value' => fn (FeedbackParticipant $p) => $p->round ? config("peopleos.performance.feedback_360.round_statuses.{$p->round->status}", $p->round->status) : null],
            ...$this->employeeFields(fn (FeedbackParticipant $p) => $p->employee),
            'nominator' => ['label' => 'Nominated by', 'type' => 'string', 'value' => fn (FeedbackParticipant $p) => match ((string) $p->nominator) {
                'role' => $p->nominatorRole?->name ?? 'Role',
                'manager' => 'Reporting manager'.($p->nominatorUser ? ' ('.$p->nominatorUser->name.')' : ''),
                default => 'The employee',
            }],
            'nomination_status' => ['label' => 'Nomination status', 'type' => 'string', 'value' => fn (FeedbackParticipant $p) => $p->statusLabel()],
            'nomination_status_code' => ['label' => 'Nomination status (code)', 'type' => 'string', 'value' => fn (FeedbackParticipant $p) => $p->nomination_status],
            'submitted_on' => ['label' => 'Nominations submitted on', 'type' => 'date', 'value' => fn (FeedbackParticipant $p) => $p->submitted_at],
            'decided_on' => ['label' => 'Nominations decided on', 'type' => 'date', 'value' => fn (FeedbackParticipant $p) => $p->decided_at],
            'nominated' => ['label' => 'Reviewers nominated', 'type' => 'number', 'value' => $count('nominated_count')],
            'asked' => ['label' => 'Reviewers asked', 'type' => 'number', 'value' => $count('asked_count')],
            'given' => ['label' => 'Feedback given', 'type' => 'number', 'value' => $count('given_count')],
            'declined' => ['label' => 'Chose not to participate', 'type' => 'number', 'value' => $count('declined_count')],
            'pending' => ['label' => 'Still to answer', 'type' => 'number', 'value' => $count('pending_count')],
            'completion' => ['label' => 'Answered (% of asked)', 'type' => 'number', 'value' => fn (FeedbackParticipant $p) => (int) $p->getAttribute('asked_count') === 0 ? null
                : round(100 * ((int) $p->getAttribute('given_count') + (int) $p->getAttribute('declined_count')) / (int) $p->getAttribute('asked_count'), 1)],
            'count' => ['label' => 'Employees (1 per row)', 'type' => 'number', 'value' => fn (FeedbackParticipant $p) => 1],
        ];
    }

    public function query(): Builder
    {
        $status = fn (array $statuses) => fn (Builder $q) => $q->whereIn('status', $statuses);

        return FeedbackParticipant::query()->with(['round', ...self::employeeWith('employee'), 'nominatorUser', 'nominatorRole'])
            ->withCount([
                'nominations as nominated_count' => fn (Builder $q) => $q->where('status', '!=', 'cancelled'),
                'nominations as asked_count' => $status(['requested', 'submitted', 'declined']),
                'nominations as given_count' => $status(['submitted']),
                'nominations as declined_count' => $status(['declined']),
                'nominations as pending_count' => $status(['requested']),
            ])
            ->orderByDesc('feedback_round_id')->orderBy('id');
    }
}
