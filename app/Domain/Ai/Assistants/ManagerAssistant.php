<?php

namespace App\Domain\Ai\Assistants;

use App\Domain\Ai\Services\AiAnswer;
use App\Domain\Attendance\Models\AttendanceRecord;
use App\Domain\Employment\Models\Employee;
use App\Domain\Employment\Models\EmployeePosition;
use App\Domain\Experience\Services\NeedsAttention;
use App\Domain\Identity\Models\User;
use App\Domain\Leave\Models\LeaveRequest;
use App\Domain\Lifecycle\Enums\LifecycleState;
use App\Domain\Lifecycle\Models\EmployeeLifecycleTransition;
use App\Domain\Performance\Models\Goal;
use App\Domain\Performance\Models\OneOnOne;

/** Manager Assistant (§94): team attendance, leave, reviews, goals, pending actions — direct reports only. */
final class ManagerAssistant implements Assistant
{
    public function __construct(private readonly NeedsAttention $attention) {}

    public function key(): string
    {
        return 'manager';
    }

    public function examples(): array
    {
        return ['Who is on leave today?', 'What is pending for me?', 'How are my team goals going?', 'Who in my team has no recent one-on-one?'];
    }

    public function answer(User $user, ?Employee $employee, string $question): AiAnswer
    {
        $reports = $employee ? $employee->directReports()->currentlyEffective()->with('employee.person')->get()->pluck('employee')->filter() : collect();
        if ($reports->isEmpty()) {
            return AiAnswer::text('You have no current direct reports, so there is no team to report on.', 'no_team');
        }

        $intent = Intents::detect($question, [
            'today' => ['today', 'on leave', 'present', 'absent', 'who is in', 'attendance', 'late'],
            'pending' => ['pending', 'approve', 'approval', 'what do i need', 'waiting', 'actions', 'to do'],
            'goals' => ['goal', 'okr', 'progress', 'objective'],
            'risk' => ['risk', 'attrition', 'leave the company', 'retention', 'flight', 'likely to leave', 'promot', 'terminat', 'fire', 'underperform'],
            'one_on_one' => ['one-on-one', 'one on one', '1:1', 'check-in', 'check in'],
            // UX.16: what changed for the people who report to you (moves, promotions, lifecycle), last 30 days.
            'changes' => ['changes in my team', 'what changed', 'changes', 'changed', 'moved'],
            'team' => ['team', 'who reports', 'my people', 'headcount'],
        ]);

        return match ($intent) {
            'today' => $this->today($reports),
            'pending' => $this->pending($employee, $user),
            'goals' => $this->goals($reports),
            'risk' => $this->noPrediction(),
            'one_on_one' => $this->oneOnOnes($reports),
            'changes' => $this->changes($reports),
            default => $this->team($reports),
        };
    }

    /** UX.16: changes to your current reports in the last 30 days (positions and lifecycle), names only for your own team. */
    private function changes($reports): AiAnswer
    {
        $ids = $reports->pluck('id');
        $since = now()->subDays(30)->startOfDay();
        $moves = EmployeePosition::query()->with('employee.person')->whereIn('employee_id', $ids)->where('change_type', '!=', 'hire')
            ->whereDate('effective_from', '>=', $since)->whereDate('effective_from', '<=', now())->orderByDesc('effective_from')->get();
        $states = EmployeeLifecycleTransition::query()->with('employee.person')->whereIn('employee_id', $ids)->whereDate('effective_date', '>=', $since)->orderByDesc('effective_date')->get();
        $lines = [
            ...$moves->map(fn ($m) => ($m->employee?->display_name ?? 'Someone').': '.ucfirst(str_replace('_', ' ', (string) $m->change_type)).' from '.$m->effective_from->format('j M'))->all(),
            ...$states->map(fn ($t) => ($t->employee?->display_name ?? 'Someone').': '.($t->to_state instanceof LifecycleState ? $t->to_state->getLabel() : (string) $t->to_state).' from '.$t->effective_date->format('j M'))->all(),
        ];
        if ($lines === []) {
            return AiAnswer::text("No changes to your team's positions or lifecycle in the last 30 days.", 'changes');
        }

        return new AiAnswer("In the last 30 days:\n- ".implode("\n- ", array_slice($lines, 0, 8)), [['label' => 'Position history and lifecycle transitions for your current reports']],
            [['label' => 'My Team', 'url' => url('/admin/my-team')]], 'changes', false, ['changes' => $lines]);
    }

    private function today($reports): AiAnswer
    {
        $ids = $reports->pluck('id');
        $today = now()->toDateString();
        $onLeave = LeaveRequest::query()->with('employee.person')->whereIn('employee_id', $ids)->where('status', 'approved')->whereDate('from_date', '<=', $today)->whereDate('to_date', '>=', $today)->get();
        $records = AttendanceRecord::query()->whereIn('employee_id', $ids)->whereDate('date', $today)->get()->keyBy('employee_id');
        $present = $records->whereIn('status', ['present', 'half_day', 'wfh', 'on_duty', 'incomplete'])->count();
        $absent = $records->where('status', 'absent')->count();
        $late = $records->where('late_minutes', '>', 0)->count();
        $lines = ["{$reports->count()} in your team: {$present} present, {$onLeave->count()} on leave, {$absent} absent, {$late} late."];
        if ($onLeave->isNotEmpty()) {
            $lines[] = 'On leave: '.$onLeave->map(fn ($l) => $l->employee->person?->full_name)->implode(', ').'.';
        }

        return new AiAnswer(implode(' ', $lines), [['label' => 'Attendance and leave for '.$today]], [['label' => 'My Team', 'url' => url('/admin/my-team')]], 'today', false, compact('present', 'absent', 'late'));
    }

    private function pending(Employee $manager, User $user): AiAnswer
    {
        $items = $this->attention->forManager($manager, $user);
        if ($items->isEmpty()) {
            return AiAnswer::text('Nothing is waiting on you from your team right now.', 'pending');
        }
        $lines = $items->map(fn ($i) => "{$i['title']} ({$i['count']})")->all();

        return new AiAnswer("Waiting on you:\n- ".implode("\n- ", $lines), [['label' => 'Needs Attention (manager)']], $items->filter(fn ($i) => $i['url'])->take(4)->map(fn ($i) => ['label' => $i['title'], 'url' => $i['url']])->values()->all(), 'pending', false, ['items' => $lines]);
    }

    private function goals($reports): AiAnswer
    {
        $goals = Goal::query()->with('employee.person')->whereIn('employee_id', $reports->pluck('id'))->where('status', 'active')->get();
        if ($goals->isEmpty()) {
            return AiAnswer::text('Your team has no active goals yet.', 'goals', [], [['label' => 'Goals', 'url' => url('/admin/goals')]]);
        }
        $byPerson = $goals->groupBy('employee_id')->map(fn ($g) => sprintf('%s: %d goal(s), average %.0f%%', $g->first()->employee->person?->full_name, $g->count(), $g->avg('progress')))->values()->all();
        $atRisk = $goals->filter(fn ($g) => $g->due_date && $g->due_date->lte(now()->addDays(30)) && $g->progress < 50)->map(fn ($g) => $g->employee->person?->full_name.' — '.$g->title)->values()->all();
        $answer = 'Team goals: overall average progress '.round($goals->avg('progress'))."%.\n- ".implode("\n- ", $byPerson);
        if ($atRisk !== []) {
            $answer .= "\n\nDue within 30 days and under 50%:\n- ".implode("\n- ", $atRisk);
        }

        return new AiAnswer($answer, [['label' => 'Goals of direct reports']], [['label' => 'Goals', 'url' => url('/admin/goals')]], 'goals', false, ['by_person' => $byPerson, 'at_risk' => $atRisk]);
    }

    /**
     * Phase 14: PeopleOS does not score or predict whether anyone will leave, be promoted, or be let go,
     * and the assistant does not guess. It points the manager to facts they can act on themselves.
     */
    private function noPrediction(): AiAnswer
    {
        return AiAnswer::text('PeopleOS does not score, rank or predict whether someone will leave, be promoted or be let go, and I will not guess. I can tell you factual things you can act on: who has had no one-on-one recently, who is on leave today, what is pending for you, and how goals are going.',
            'no_prediction', [['label' => 'PeopleOS AI policy', 'detail' => 'No employee scoring or prediction']], [['label' => 'One-on-ones', 'url' => url('/admin/one-on-ones')]]);
    }

    private function oneOnOnes($reports): AiAnswer
    {
        $since = now()->subDays(90);
        $held = OneOnOne::query()->whereIn('employee_id', $reports->pluck('id'))->whereNotNull('held_at')->where('held_at', '>=', $since)->pluck('employee_id')->unique();
        $without = $reports->reject(fn (Employee $e) => $held->contains($e->id))->map(fn (Employee $e) => $e->person?->full_name)->values()->all();
        if ($without === []) {
            return AiAnswer::text('Everyone in your team has had a one-on-one in the last 90 days.', 'one_on_one', [['label' => 'One-on-ones (90 days)']]);
        }

        return new AiAnswer('No one-on-one recorded in the last 90 days with: '.implode(', ', $without).'.', [['label' => 'One-on-ones (90 days)']], [['label' => 'Schedule a one-on-one', 'url' => url('/admin/one-on-ones')]], 'one_on_one', false, ['without_one_on_one' => count($without)]);
    }

    private function team($reports): AiAnswer
    {
        $lines = $reports->map(fn (Employee $e) => $e->person?->full_name.' ('.$e->employee_code.')')->all();

        return new AiAnswer("Your team ({$reports->count()}):\n- ".implode("\n- ", $lines)."\n\nAsk me who is on leave today, what is pending, how goals are going, or who has had no recent one-on-one.", [['label' => 'Reporting relationships']], [['label' => 'My Team', 'url' => url('/admin/my-team')]], 'team', false, ['team' => $lines]);
    }
}
