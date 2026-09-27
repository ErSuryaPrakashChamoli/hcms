<?php

namespace App\Domain\Exit\Services;

use App\Domain\Exit\Events\ExitEvent;
use App\Domain\Exit\Models\ExitCase;
use App\Domain\Exit\Models\ExitInterview;
use App\Domain\Identity\Models\User;
use RuntimeException;

/** Exit interviews (§61) and their aggregate view, separating employee answers from HR inference. */
final class ExitInterviews
{
    /** @param  array<string, mixed>  $data  reason_for_leaving, ratings[dimension => 1..5], would_recommend, would_rejoin, liked_most, suggestions */
    public function submit(ExitCase $case, array $data, User $actor, bool $byEmployee): ExitInterview
    {
        if (! in_array($case->status, ['notice', 'clearance', 'settlement', 'completed'], true)) {
            throw new RuntimeException('The exit is not open.');
        }
        foreach ($data['ratings'] ?? [] as $dimension => $value) {
            if (! array_key_exists($dimension, config('peopleos.exit.interview_dimensions')) || $value < 1 || $value > 5) {
                throw new RuntimeException('Ratings are 1 to 5 on the defined dimensions.');
            }
        }

        $interview = ExitInterview::query()->updateOrCreate(['exit_case_id' => $case->id], [
            'employee_id' => $case->employee_id,
            'conducted_by' => $byEmployee ? null : $actor->id,
            'conducted_at' => now(),
            'reason_for_leaving' => $data['reason_for_leaving'] ?? null,
            'ratings' => $data['ratings'] ?? [],
            'would_recommend' => $data['would_recommend'] ?? null,
            'would_rejoin' => $data['would_rejoin'] ?? null,
            'liked_most' => $data['liked_most'] ?? null,
            'suggestions' => $data['suggestions'] ?? null,
            'source' => $byEmployee ? 'employee' : 'hr_inferred',
            'status' => 'submitted',
        ]);

        ExitEvent::dispatch('exit.interview.submitted', $case->employee()->first(), $interview, ['number' => $case->number, 'source' => $interview->source], array_filter([$case->initiated_by]));

        return $interview;
    }

    /** @return array{count: int, by_reason: array<string, array{employee: int, hr_inferred: int}>, ratings: array<string, float|null>, would_recommend: float|null, would_rejoin: float|null} */
    public function analytics(): array
    {
        $interviews = ExitInterview::query()->where('status', 'submitted')->get();
        $byReason = [];
        foreach (config('peopleos.exit.interview_reasons') as $key => $label) {
            $byReason[$key] = ['employee' => 0, 'hr_inferred' => 0];
        }
        foreach ($interviews as $i) {
            if ($i->reason_for_leaving && isset($byReason[$i->reason_for_leaving])) {
                $byReason[$i->reason_for_leaving][$i->source]++;
            }
        }
        $ratings = [];
        foreach (array_keys(config('peopleos.exit.interview_dimensions')) as $dimension) {
            $values = $interviews->where('source', 'employee')->map(fn ($i) => $i->ratings[$dimension] ?? null)->filter()->values();
            $ratings[$dimension] = $values->isEmpty() ? null : round($values->avg(), 2);
        }
        $employeeOnly = $interviews->where('source', 'employee');

        return [
            'count' => $interviews->count(),
            'employee_count' => $employeeOnly->count(),
            'by_reason' => $byReason,
            'ratings' => $ratings,
            'would_recommend' => $employeeOnly->whereNotNull('would_recommend')->isEmpty() ? null : round($employeeOnly->whereNotNull('would_recommend')->avg(fn ($i) => $i->would_recommend ? 100 : 0)),
            'would_rejoin' => $employeeOnly->whereNotNull('would_rejoin')->isEmpty() ? null : round($employeeOnly->whereNotNull('would_rejoin')->avg(fn ($i) => $i->would_rejoin ? 100 : 0)),
        ];
    }
}
