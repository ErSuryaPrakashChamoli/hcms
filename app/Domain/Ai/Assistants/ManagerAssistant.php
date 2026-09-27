<?php

namespace App\Domain\Ai\Assistants;

use App\Domain\Ai\Services\AiAnswer;
use App\Domain\Ai\Services\AttritionRisk;
use App\Domain\Attendance\Models\AttendanceRecord;
use App\Domain\Employment\Models\Employee;
use App\Domain\Experience\Services\NeedsAttention;
use App\Domain\Identity\Models\User;
use App\Domain\Leave\Models\LeaveRequest;
use App\Domain\Performance\Models\Goal;

/** Manager Assistant (§94): team attendance, leave, reviews, goals, pending actions — direct reports only. */
final class ManagerAssistant implements Assistant
{
    public function __construct(private readonly NeedsAttention $attention, private readonly AttritionRisk $risk) {}

    public function key(): string
    {
        return 'manager';
    }

    public function examples(): array
    {
        return ['Who is on leave today?', 'What is pending for me?', 'How are my team goals going?', 'Who in my team is at risk?'];
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
            'risk' => ['risk', 'attrition', 'leave the company', 'retention', 'flight'],
            'team' => ['team', 'who reports', 'my people', 'headcount'],
        ]);

        return match ($intent) {
            'today' => $this->today($reports),
            'pending' => $this->pending($employee, $user),
            'goals' => $this->goals($reports),
            'risk' => $this->risk($reports),
            default => $this->team($reports),
        };
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

    private function risk($reports): AiAnswer
    {
        $scores = $reports->map(fn (Employee $e) => $this->risk->score($e))->sortByDesc('score')->values();
        $flagged = $scores->filter(fn ($s) => $s['band'] !== 'low');
        if ($flagged->isEmpty()) {
            return new AiAnswer('No one in your team shows elevated attrition-risk signals right now. This is a system-generated inference from tenure, pay revisions, ratings, learning, one-on-ones, absences, feedback and grievances — not a prediction about any individual.', [['label' => 'Attrition-risk signals (inference)']], [], 'risk', true);
        }
        $lines = $flagged->map(fn ($s) => sprintf('%s — %s risk: %s', $s['name'], $s['band'], implode('; ', $s['signals'])))->all();

        return new AiAnswer("Team members with elevated signals (system-generated inference, to prompt a conversation — not a decision):\n- ".implode("\n- ", $lines), [['label' => 'Attrition-risk signals (inference)', 'detail' => 'Heuristic points; see Workforce Intelligence']], [['label' => 'Schedule a one-on-one', 'url' => url('/admin/one-on-ones')]], 'risk', true, ['flagged' => $lines]);
    }

    private function team($reports): AiAnswer
    {
        $lines = $reports->map(fn (Employee $e) => $e->person?->full_name.' ('.$e->employee_code.')')->all();

        return new AiAnswer("Your team ({$reports->count()}):\n- ".implode("\n- ", $lines)."\n\nAsk me who is on leave today, what is pending, how goals are going, or who might be at risk.", [['label' => 'Reporting relationships']], [['label' => 'My Team', 'url' => url('/admin/my-team')]], 'team', false, ['team' => $lines]);
    }
}
