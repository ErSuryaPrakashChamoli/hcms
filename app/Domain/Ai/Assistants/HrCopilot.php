<?php

namespace App\Domain\Ai\Assistants;

use App\Domain\Ai\Services\AiAnswer;
use App\Domain\Ai\Services\PeopleQuery;
use App\Domain\Analytics\Services\WorkforceMetrics;
use App\Domain\Bgv\Models\BgvCase;
use App\Domain\Documents\Models\EmployeeDocument;
use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Models\User;
use App\Domain\Lifecycle\Enums\LifecycleState;
use App\Domain\Onboarding\Models\OnboardingTask;

/** HR Copilot (§94): pending HR work, headcount / attrition questions, and natural-language people search. */
final class HrCopilot implements Assistant
{
    public function __construct(private readonly PeopleQuery $people, private readonly WorkforceMetrics $metrics) {}

    public function key(): string
    {
        return 'hr';
    }

    public function examples(): array
    {
        return ['Employees in Delhi who joined this year', 'Whose probation ends this month?', 'What onboarding is pending?', 'Documents expiring soon', 'What is our attrition?'];
    }

    public function answer(User $user, ?Employee $employee, string $question): AiAnswer
    {
        $intent = Intents::detect($question, [
            'probation' => ['probation'],
            'onboarding' => ['onboarding', 'joiner', 'new joiners', 'joining'],
            'documents' => ['document', 'expiring', 'expiry', 'expire'],
            'bgv' => ['verification', 'bgv', 'background'],
            'metrics' => ['attrition', 'headcount', 'absenteeism', 'people cost', 'how many employees', 'cost per head', 'women', 'tenure'],
            'search' => ['employees in', 'employees who', 'who joined', 'list employees', 'find', 'show me', 'people in', 'staff in', 'reporting to', 'everyone in'],
        ]);

        return match ($intent) {
            'probation' => $this->probation($question),
            'onboarding' => $this->onboarding(),
            'documents' => $this->documents($question),
            'bgv' => $this->bgv(),
            'metrics' => $this->metrics($question),
            default => $this->search($user, $question),
        };
    }

    private function probation(string $question): AiAnswer
    {
        $until = str_contains(strtolower($question), 'week') ? now()->addWeek() : now()->endOfMonth();
        $rows = Employee::query()->with('person')->where('lifecycle_state', LifecycleState::Probation)->whereDate('probation_end_date', '<=', $until)->orderBy('probation_end_date')->get();
        $overdue = $rows->filter(fn ($e) => $e->probation_end_date?->isPast());
        if ($rows->isEmpty()) {
            return AiAnswer::text('No probations end by '.$until->toDateString().'.', 'probation');
        }
        $lines = $rows->map(fn ($e) => $e->person?->full_name.' ('.$e->employee_code.') — '.$e->probation_end_date?->toDateString().($e->probation_end_date?->isPast() ? ' OVERDUE' : ''))->all();

        return new AiAnswer("Probations ending by {$until->toDateString()} ({$rows->count()}, {$overdue->count()} overdue):\n- ".implode("\n- ", $lines), [['label' => 'Employees in probation']], [['label' => 'Employees', 'url' => url('/admin/employees')]], 'probation', false, ['rows' => $lines]);
    }

    private function onboarding(): AiAnswer
    {
        $pending = OnboardingTask::query()->with('plan.employee.person')->where('status', 'pending')->get();
        $overdue = $pending->filter(fn ($t) => $t->due_on && $t->due_on->isPast());
        $joining = Employee::query()->with('person')->whereIn('lifecycle_state', [LifecycleState::PreEmployee, LifecycleState::Preboarding])->whereDate('expected_joining_date', '<=', now()->addDays(14))->get();
        $lines = ["{$pending->count()} onboarding task(s) pending, {$overdue->count()} overdue.", "{$joining->count()} pre-employee(s) due to join within 14 days."];
        $byEmployee = $pending->groupBy(fn ($t) => $t->plan?->employee?->person?->full_name ?? 'Unassigned')->map(fn ($g, $name) => "{$name}: {$g->count()} task(s)")->take(8)->values()->all();

        return new AiAnswer(implode(' ', $lines).($byEmployee ? "\n- ".implode("\n- ", $byEmployee) : ''), [['label' => 'Onboarding plans']], [['label' => 'Onboarding', 'url' => url('/admin/onboarding-plans')]], 'onboarding', false, ['pending' => $pending->count(), 'overdue' => $overdue->count(), 'joining' => $joining->count()]);
    }

    private function documents(string $question): AiAnswer
    {
        $days = Intents::number($question, 30);
        $docs = EmployeeDocument::query()->with(['employee.person', 'type'])->whereNotNull('expires_on')->whereDate('expires_on', '<=', now()->addDays($days))->whereDate('expires_on', '>=', now())->orderBy('expires_on')->get();
        if ($docs->isEmpty()) {
            return AiAnswer::text("No employee documents expire in the next {$days} days.", 'documents');
        }
        $lines = $docs->take(10)->map(fn ($d) => ($d->employee?->person?->full_name ?? '—').': '.$d->title.' expires '.$d->expires_on->toDateString())->all();

        return new AiAnswer("{$docs->count()} document(s) expire within {$days} days:\n- ".implode("\n- ", $lines), [['label' => 'Employee documents']], [['label' => 'Documents', 'url' => url('/admin/employees')]], 'documents', false, ['count' => $docs->count()]);
    }

    private function bgv(): AiAnswer
    {
        $open = BgvCase::query()->whereIn('status', ['initiated', 'in_progress'])->count();

        return AiAnswer::text($open === 0 ? 'No background verifications are open.' : "{$open} background verification(s) are open.", 'bgv', [['label' => 'BGV cases']], [['label' => 'Verifications', 'url' => url('/admin/bgv-cases')]]);
    }

    private function metrics(string $question): AiAnswer
    {
        $q = strtolower($question);
        $keys = array_values(array_filter(['headcount', 'attrition_rate', 'absenteeism_rate', 'people_cost', 'cost_per_head', 'women_share', 'avg_tenure_months', 'joiners_30d', 'exits_30d'], fn ($k) => str_contains($q, explode('_', $k)[0]) || ($k === 'headcount' && str_contains($q, 'how many'))));
        $keys = $keys ?: ['headcount', 'attrition_rate', 'absenteeism_rate'];
        $facts = $this->metrics->all($keys);
        $lines = collect($facts)->map(fn ($m) => $m['label'].': '.($m['value'] === null ? 'not available yet' : ($m['format'] === 'percent' ? $m['value'].'%' : number_format((float) $m['value'], $m['format'] === 'currency' ? 0 : 1))).($m['hint'] ? " ({$m['hint']})" : ''))->all();

        return new AiAnswer(implode("\n", $lines), [['label' => 'Workforce metrics', 'detail' => 'Computed live from employee, exit, attendance and payroll data']], [['label' => 'Workforce Command Centre', 'url' => url('/admin/workforce-command-centre')]], 'metrics', false, ['metrics' => $lines]);
    }

    private function search(User $user, string $question): AiAnswer
    {
        $result = $this->people->search($user, $question);
        if ($result['rows'] === [] && $result['filters'] === []) {
            return AiAnswer::text('I can list people by location, department, designation, lifecycle state and joining date (e.g. "employees in Delhi who joined this year"), and report on probations, onboarding, expiring documents, verifications and workforce metrics.', 'help');
        }
        $described = collect($result['filters'])->map(fn ($f) => $f['label'])->implode(', ');
        if ($result['rows'] === []) {
            return AiAnswer::text("No employees match: {$described}.", 'people_search', [['label' => 'Employees dataset', 'detail' => $described]]);
        }
        $lines = collect($result['rows'])->take(15)->map(fn ($r) => "{$r['name']} ({$r['employee_code']}) — ".implode(', ', array_filter([$r['designation'] ?? null, $r['department'] ?? null, $r['location'] ?? null])).($r['joining_date'] ? ', joined '.$r['joining_date'] : ''))->all();
        $answer = "{$result['total']} employee(s) match {$described}:\n- ".implode("\n- ", $lines).($result['total'] > 15 ? "\n… and ".($result['total'] - 15).' more.' : '');

        return new AiAnswer($answer, [['label' => 'Employees dataset', 'detail' => $described]], [['label' => 'Open in reports', 'url' => url('/admin/reports')]], 'people_search', false, ['total' => $result['total'], 'filters' => $result['filters']]);
    }
}
