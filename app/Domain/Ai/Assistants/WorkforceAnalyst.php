<?php

namespace App\Domain\Ai\Assistants;

use App\Domain\Ai\Services\AiAnswer;
use App\Domain\Ai\Services\AttritionRisk;
use App\Domain\Analytics\Services\WorkforceMetrics;
use App\Domain\Employment\Models\Employee;
use App\Domain\Exit\Models\ExitCase;
use App\Domain\Identity\Models\User;

/** Workforce Analyst (§94): trends, cost, attrition risk, skills, capacity — narrative over deterministic metrics. */
final class WorkforceAnalyst implements Assistant
{
    public function __construct(private readonly WorkforceMetrics $metrics, private readonly AttritionRisk $risk) {}

    public function key(): string
    {
        return 'workforce';
    }

    public function examples(): array
    {
        return ['Give me a workforce summary', 'How is headcount trending?', 'Which skills are at risk?', 'Who is at risk of leaving?', 'What is our capacity risk?'];
    }

    public function answer(User $user, ?Employee $employee, string $question): AiAnswer
    {
        $intent = Intents::detect($question, [
            'trend' => ['trend', 'trending', 'over time', 'last 12', 'growth', 'joiners', 'exits'],
            'skills' => ['skill', 'critical', 'capability', 'expert'],
            'risk' => ['at risk', 'attrition risk', 'leaving', 'retention', 'flight'],
            'cost' => ['cost', 'spend', 'payroll'],
            'capacity' => ['capacity', 'exits in progress', 'notice', 'leaving soon', 'backfill'],
        ]);

        return match ($intent) {
            'trend' => $this->trend(),
            'skills' => $this->skills(),
            'risk' => $this->risk(),
            'cost' => $this->cost(),
            'capacity' => $this->capacity(),
            default => $this->summary(),
        };
    }

    private function summary(): AiAnswer
    {
        $m = $this->metrics->all(['headcount', 'joiners_30d', 'exits_30d', 'attrition_rate', 'absenteeism_rate', 'people_cost', 'high_performers', 'learning_completion']);
        $fmt = fn ($x) => $x['value'] === null ? 'n/a' : ($x['format'] === 'percent' ? $x['value'].'%' : number_format((float) $x['value'], $x['format'] === 'currency' ? 0 : 0));
        $answer = sprintf('Headcount is %s (%s joined, %s left in 30 days). Twelve-month attrition is %s and absenteeism %s. The last finalized payroll cost %s. %s high performer(s) in the latest cycle; mandatory learning completion is %s.',
            $fmt($m['headcount']), $fmt($m['joiners_30d']), $fmt($m['exits_30d']), $fmt($m['attrition_rate']), $fmt($m['absenteeism_rate']), $fmt($m['people_cost']), $fmt($m['high_performers']), $fmt($m['learning_completion']));

        return new AiAnswer($answer, [['label' => 'Workforce metrics (live)']], [['label' => 'Workforce Command Centre', 'url' => url('/admin/workforce-command-centre')]], 'summary', false, ['metrics' => collect($m)->map(fn ($x) => $x['value'])->all()]);
    }

    private function trend(): AiAnswer
    {
        $h = $this->metrics->series('headcount', 12)['series']['Headcount'];
        $j = array_sum($this->metrics->series('joiners', 12)['series']['Joiners']);
        $e = array_sum($this->metrics->series('exits', 12)['series']['Exits']);
        $delta = end($h) - $h[0];
        $answer = sprintf('Over the last 12 months headcount moved from %d to %d (%s%d), with %d joiner(s) and %d exit(s). Net movement is %s.', $h[0], end($h), $delta >= 0 ? '+' : '', $delta, $j, $e, $j - $e >= 0 ? 'positive' : 'negative');

        return new AiAnswer($answer, [['label' => 'Monthly headcount, joiners and exits']], [['label' => 'Workforce Command Centre', 'url' => url('/admin/workforce-command-centre')]], 'trend', false, ['headcount' => $h, 'joiners' => $j, 'exits' => $e]);
    }

    private function skills(): AiAnswer
    {
        $skills = $this->metrics->criticalSkills(6);
        if ($skills === []) {
            return AiAnswer::text('No skills are recorded on employee profiles yet, so I cannot assess skill risk.', 'skills');
        }
        $lines = array_map(fn ($s) => sprintf('%s — %d holder(s), %d advanced/expert%s', $s['skill'], $s['holders'], $s['experts'], $s['experts'] === 0 ? ' (no deep expertise)' : ($s['experts'] === 1 ? ' (single point of failure)' : '')), $skills);

        return new AiAnswer("Skills with the thinnest expert coverage:\n- ".implode("\n- ", $lines), [['label' => 'Person skills by proficiency']], [['label' => 'Career paths & skills', 'url' => url('/admin/career-paths')]], 'skills', true, ['skills' => $lines]);
    }

    private function risk(): AiAnswer
    {
        $top = $this->risk->rank(8)->filter(fn ($s) => $s['band'] !== 'low');
        if ($top->isEmpty()) {
            return new AiAnswer('No employees currently show medium or high attrition-risk signals. This is a system-generated inference, not a prediction.', [['label' => 'Attrition-risk signals (inference)']], [], 'risk', true);
        }
        $lines = $top->map(fn ($s) => sprintf('%s (%s) — %s: %s', $s['name'], $s['department'] ?? '—', $s['band'], implode('; ', $s['signals'])))->all();

        return new AiAnswer("Highest attrition-risk signals (system-generated inference to prompt conversations; not for automated decisions):\n- ".implode("\n- ", $lines), [['label' => 'Attrition-risk signals', 'detail' => 'Points per signal from config peopleos.ai.attrition_risk']], [['label' => 'Workforce Intelligence', 'url' => url('/admin/workforce-intelligence')]], 'risk', true, ['flagged' => $lines]);
    }

    private function cost(): AiAnswer
    {
        $series = $this->metrics->series('people_cost', 6)['series'];
        $values = array_values($series)[0];
        $m = $this->metrics->all(['people_cost', 'cost_per_head']);
        $answer = sprintf('The last finalized payroll cost %s (cost per head %s). Over the last six months monthly people cost was: %s.', $m['people_cost']['value'] === null ? 'n/a' : number_format((float) $m['people_cost']['value']), $m['cost_per_head']['value'] === null ? 'n/a' : number_format((float) $m['cost_per_head']['value']), implode(', ', array_map(fn ($v) => number_format($v), $values)));

        return new AiAnswer($answer, [['label' => 'Finalized payroll runs']], [['label' => 'Payroll control room', 'url' => url('/admin/payroll-control-room')]], 'cost', false, ['series' => $values]);
    }

    private function capacity(): AiAnswer
    {
        $leaving = ExitCase::query()->with(['employee.person', 'employee.currentPosition.department'])->whereIn('status', ExitCase::OPEN)->orderBy('last_working_day')->get();
        if ($leaving->isEmpty()) {
            return AiAnswer::text('No exits are in progress, so there is no near-term capacity loss to plan for.', 'capacity');
        }
        $byDept = $leaving->groupBy(fn ($c) => $c->employee?->currentPosition?->department?->name ?? 'Unassigned')->map->count();
        $lines = $leaving->take(10)->map(fn ($c) => ($c->employee?->person?->full_name ?? '—').' — last day '.$c->last_working_day->toDateString())->all();

        return new AiAnswer("{$leaving->count()} exit(s) in progress; backfill needs by department: ".$byDept->map(fn ($n, $d) => "{$d} ({$n})")->implode(', ').".\n- ".implode("\n- ", $lines), [['label' => 'Open exit cases']], [['label' => 'Exit cases', 'url' => url('/admin/exit-cases')]], 'capacity', false, ['by_department' => $byDept->all()]);
    }
}
