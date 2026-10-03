<?php

namespace App\Domain\Experience\Services;

use App\Domain\Employment\Models\Employee;
use App\Domain\Employment\Models\EmployeePosition;
use App\Domain\Exit\Models\ExitCase;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Scopes\AccessScope;
use App\Domain\Lifecycle\Enums\LifecycleState;
use App\Domain\Lifecycle\Models\EmployeeLifecycleTransition;
use App\Domain\Lifecycle\Models\EmployeeTimelineEntry;
use App\Domain\Lifecycle\Support\TimelineCategories;
use App\Domain\Onboarding\Models\OnboardingPlan;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * UX: the journey visualisation (§15). Ten stages, Preboarding → Alumni, drawn from data that already
 * exists: lifecycle transitions, the lifecycle state, position changes, the onboarding plan, the exit
 * case and the employee timeline. Nothing is stored. Events inside a stage pass the same per-category
 * visibility as the Employee 360 timeline (TimelineCategories::hiddenFor); exit details need exit.view.
 */
final class JourneyMap
{
    public const STAGES = [
        'preboarding' => 'Preboarding', 'onboarding' => 'Onboarding', 'joined' => 'Joined', 'probation' => 'Probation', 'confirmed' => 'Confirmed',
        'active' => 'Active', 'growth' => 'Growth', 'transition' => 'Transition', 'exit' => 'Exit', 'alumni' => 'Alumni',
    ];

    /** Lifecycle state → stage. */
    private const STATE_STAGE = [
        'pre_employee' => 'preboarding', 'preboarding' => 'preboarding', 'onboarding' => 'onboarding', 'joined' => 'joined', 'probation' => 'probation',
        'confirmed' => 'confirmed', 'active' => 'active', 'on_leave' => 'active', 'suspended' => 'active', 'notice_period' => 'transition',
        'exited' => 'exit', 'alumni' => 'alumni',
    ];

    /**
     * @return array{current: string, stages: list<array{key: string, label: string, state: string, date: ?CarbonInterface, summary: ?string, events: list<array{date: ?CarbonInterface, title: string, category: string, detail: ?string}>}>}
     */
    public function for(User $viewer, Employee $employee): array
    {
        // The person's own journey, or a record the viewer may already open (EmployeePolicy::view).
        if (! $viewer->can('view', $employee) && (int) $employee->user_id !== (int) $viewer->id) {
            return ['current' => 'active', 'stages' => []];
        }
        $state = $employee->lifecycle_state instanceof LifecycleState ? $employee->lifecycle_state->value : (string) $employee->lifecycle_state;
        $current = self::STATE_STAGE[$state] ?? 'active';
        $order = array_keys(self::STAGES);

        // When each stage was entered, from lifecycle transitions (the authoritative history).
        $entered = [];
        $transitions = EmployeeLifecycleTransition::query()->where('employee_id', $employee->id)->orderBy('effective_date')->orderBy('id')->get();
        foreach ($transitions as $t) {
            $stage = self::STATE_STAGE[$t->to_state instanceof LifecycleState ? $t->to_state->value : (string) $t->to_state] ?? null;
            if ($stage !== null && ! isset($entered[$stage])) {
                $entered[$stage] = $t->effective_date ? Carbon::parse($t->effective_date) : null;
            }
        }
        $entered['joined'] ??= $employee->joining_date;
        if ($employee->confirmation_date) {
            $entered['confirmed'] ??= $employee->confirmation_date;
        }

        // Growth: promotions and transfers after joining.
        $moves = EmployeePosition::query()->withoutGlobalScope(AccessScope::class)->where('employee_id', $employee->id)
            ->whereIn('change_type', ['promotion', 'transfer', 'reassignment'])->orderBy('effective_from')->get(['change_type', 'effective_from', 'designation_id', 'department_id']);
        if ($moves->isNotEmpty()) {
            $entered['growth'] = $moves->first()->effective_from;
        }

        $hidden = TimelineCategories::hiddenFor($viewer, $employee);
        $timeline = EmployeeTimelineEntry::query()->withoutGlobalScope(AccessScope::class)->where('employee_id', $employee->id)
            ->whereNotIn('category', $hidden)->orderBy('occurred_on')->orderBy('id')->limit(300)->get();

        $onboarding = OnboardingPlan::query()->withoutGlobalScope(AccessScope::class)->where('employee_id', $employee->id)->latest('started_at')->first();
        $exit = $viewer->hasPermission('exit.view') || (int) $employee->user_id === (int) $viewer->id
            ? ExitCase::query()->withoutGlobalScope(AccessScope::class)->where('employee_id', $employee->id)->latest('id')->first() : null;

        $currentIndex = array_search($current, $order, true);
        $stages = [];
        foreach (self::STAGES as $key => $label) {
            $index = array_search($key, $order, true);
            $reached = isset($entered[$key]) || $index <= $currentIndex;
            $stateName = $key === $current ? 'current' : ($index < $currentIndex ? ($reached ? 'done' : 'skipped') : 'upcoming');
            if ($key === 'growth') {
                $stateName = $moves->isNotEmpty() ? ($currentIndex <= array_search('growth', $order, true) ? 'current' : 'done') : ($currentIndex > array_search('growth', $order, true) ? 'skipped' : 'upcoming');
            }
            $stages[] = ['key' => $key, 'label' => $label, 'state' => $stateName, 'date' => $entered[$key] ?? null, 'summary' => null, 'events' => []];
        }

        // Place each visible event in the stage whose window contains it.
        $windows = $this->windows($stages);
        foreach ($timeline as $entry) {
            $stageKey = $this->stageFor($entry->occurred_on, $entry->category, $windows, $current);
            $this->push($stages, $stageKey, $entry->occurred_on, $entry->title, $entry->category,
                TimelineCategories::showsDescription($viewer, $entry->category) ? $entry->description : null);
        }
        foreach ($moves as $move) {
            $this->push($stages, 'growth', $move->effective_from, ucfirst((string) $move->change_type), 'position', null);
        }

        foreach ($stages as &$stage) {
            $stage['summary'] = match ($stage['key']) {
                'onboarding' => $onboarding ? ucfirst(str_replace('_', ' ', (string) $onboarding->status)).($onboarding->progress !== null ? ' · '.(int) $onboarding->progress.'% complete' : '') : null,
                'probation' => $employee->probation_end_date ? ($employee->probation_end_date->isPast() && $current === 'probation' ? 'Decision overdue since ' : 'Ends ').$employee->probation_end_date->format('d M Y') : null,
                'confirmed' => $employee->confirmation_date ? 'Confirmed '.$employee->confirmation_date->format('d M Y') : null,
                'growth' => $moves->isNotEmpty() ? $moves->count().' '.($moves->count() === 1 ? 'move' : 'moves').' since joining' : null,
                'transition', 'exit' => $exit ? ucfirst(str_replace('_', ' ', (string) $exit->type)).($exit->last_working_day ? ' · last day '.$exit->last_working_day->format('d M Y') : '') : null,
                'joined' => $employee->joining_date ? 'Joined '.$employee->joining_date->format('d M Y') : ($employee->expected_joining_date ? 'Expected '.$employee->expected_joining_date->format('d M Y') : null),
                default => null,
            };
            usort($stage['events'], fn ($a, $b) => ($b['date']?->timestamp ?? 0) <=> ($a['date']?->timestamp ?? 0));
            $stage['events'] = array_slice($stage['events'], 0, 12);
        }

        return ['current' => $current, 'stages' => $stages];
    }

    /** @return array<string, array{0: ?CarbonInterface, 1: ?CarbonInterface}> */
    private function windows(array $stages): array
    {
        $dated = array_values(array_filter($stages, fn ($s) => $s['date'] !== null && $s['key'] !== 'growth'));
        usort($dated, fn ($a, $b) => $a['date']->timestamp <=> $b['date']->timestamp);
        $windows = [];
        foreach ($dated as $i => $s) {
            $windows[$s['key']] = [$s['date'], $dated[$i + 1]['date'] ?? null];
        }

        return $windows;
    }

    private function stageFor(?CarbonInterface $date, string $category, array $windows, string $current): string
    {
        if ($category === 'onboarding') {
            return 'onboarding';
        }
        if ($category === 'exit') {
            return $current === 'alumni' ? 'exit' : ($current === 'exit' ? 'exit' : 'transition');
        }
        if ($category === 'position') {
            return 'growth';
        }
        $match = $current;
        foreach ($windows as $key => [$from, $to]) {
            if ($date !== null && $from !== null && $date->gte($from) && ($to === null || $date->lt($to))) {
                $match = $key;
            }
        }

        return $match;
    }

    private function push(array &$stages, string $key, ?CarbonInterface $date, string $title, string $category, ?string $detail): void
    {
        foreach ($stages as &$stage) {
            if ($stage['key'] === $key) {
                $stage['events'][] = ['date' => $date, 'title' => $title, 'category' => TimelineCategories::label($category), 'detail' => $detail];

                return;
            }
        }
    }
}
