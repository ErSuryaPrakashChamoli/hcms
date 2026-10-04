<?php

namespace App\Domain\Experience\Services;

use App\Domain\Employment\Models\Employee;
use App\Domain\Employment\Models\EmployeePosition;
use App\Domain\Employment\Models\ReportingRelationship;
use App\Domain\Exit\Models\ExitCase;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Scopes\AccessScope;
use App\Domain\Learning\Models\LearningEnrolment;
use App\Domain\Leave\Models\LeaveRequest;
use App\Domain\Lifecycle\Enums\LifecycleState;
use App\Domain\Lifecycle\Models\EmployeeTimelineEntry;
use App\Domain\Lifecycle\Support\TimelineCategories;
use App\Domain\Onboarding\Models\OnboardingTask;
use Carbon\CarbonInterface;
use Throwable;

/**
 * UX.15: the Employee 360 as a person workspace. One lifetime record (joined, tenure, employment
 * records including rehires), what is happening now, what happens next, relationships of every type,
 * recent changes and the per-domain summaries, for a viewer who may already open this Employee 360.
 *
 * It reads only; every block is gated by the rule that already governs it:
 * - the 360 itself needs EmployeePolicy::view (nothing is returned otherwise);
 * - domain summaries are Employee360's (each domain's own permission or relationship rule);
 * - leave and learning dates appear only when that domain's summary is visible to the viewer;
 * - exit dates need exit.view (or the person themselves); onboarding tasks need onboarding.view;
 * - recent changes pass TimelineCategories::hiddenFor, exactly as the 360 timeline and the journey.
 */
final class PersonWorkspace
{
    public function __construct(private readonly Employee360 $summaries) {}

    /** @return array<string, mixed> */
    public function for(User $viewer, Employee $employee): array
    {
        if (! $viewer->can('view', $employee)) {
            return [];
        }
        $own = (int) $employee->user_id === (int) $viewer->id;
        $sections = collect($this->summaries->for($viewer, $employee))->keyBy('key');
        $state = $employee->lifecycle_state instanceof LifecycleState ? $employee->lifecycle_state : null;

        return [
            'lifetime' => $this->lifetime($employee),
            'now' => $this->now($employee, $state),
            'next' => $this->safe(fn () => $this->next($viewer, $employee, $state, $sections->keys()->all(), $own), []),
            'relationships' => $this->safe(fn () => $this->relationships($employee), ['up' => [], 'down' => [], 'reports' => 0]),
            'changes' => $this->safe(fn () => $this->changes($viewer, $employee), []),
            'journey' => $this->safe(fn () => app(JourneyMap::class)->for($viewer, $employee), ['current' => 'active', 'stages' => []]),
            'sections' => $sections,
        ];
    }

    /** One person, one lifetime record: a rehire continues the same record. */
    private function lifetime(Employee $e): array
    {
        $rehires = EmployeePosition::query()->withoutGlobalScope(AccessScope::class)->where('employee_id', $e->id)->where('change_type', 'rehire')->count();
        $employed = $e->lifecycle_state instanceof LifecycleState && $e->lifecycle_state->isEmployed();

        return [
            'joined' => $e->joining_date,
            'tenure' => $e->joining_date && $employed ? $e->joining_date->diffForHumans(now(), ['parts' => 2, 'syntax' => CarbonInterface::DIFF_ABSOLUTE]) : null,
            'employments' => 1 + $rehires,
            'rehired' => $rehires > 0,
            'exited' => $e->exit_date,
        ];
    }

    /** What is true right now about the person's working life. */
    private function now(Employee $e, ?LifecycleState $state): array
    {
        $probation = null;
        if ($state === LifecycleState::Probation && $e->probation_end_date) {
            $days = (int) now()->startOfDay()->diffInDays($e->probation_end_date->copy()->startOfDay(), false);
            $probation = ['ends' => $e->probation_end_date, 'days' => $days, 'overdue' => $days < 0];
        }

        return [
            'state' => $state?->getLabel(),
            'state_key' => $state?->value,
            'probation' => $probation,
            'confirmed' => $e->confirmation_date,
        ];
    }

    /**
     * What happens next, soonest first, from the systems of record the viewer may already see.
     *
     * @param  list<string>  $visible  Employee360 sections the viewer may see
     * @return list<array{date: ?CarbonInterface, title: string, detail: ?string, tone: string}>
     */
    private function next(User $viewer, Employee $e, ?LifecycleState $state, array $visible, bool $own): array
    {
        $items = [];
        if ($state === LifecycleState::Probation && $e->probation_end_date) {
            $overdue = $e->probation_end_date->isPast();
            $items[] = ['date' => $e->probation_end_date, 'title' => $overdue ? 'Probation decision is overdue' : 'Probation ends', 'detail' => 'Confirm, extend or end probation. Confirmation is not recorded yet.', 'tone' => $overdue ? 'danger' : 'warning'];
        }
        if (in_array('leave', $visible, true)) {
            LeaveRequest::query()->withoutGlobalScope(AccessScope::class)->with('leaveType')->where('employee_id', $e->id)->whereIn('status', ['approved', 'pending'])
                ->whereDate('to_date', '>=', now()->toDateString())->orderBy('from_date')->limit(3)->get()
                ->each(function (LeaveRequest $r) use (&$items) {
                    $items[] = ['date' => $r->from_date, 'title' => ($r->leaveType?->name ?? 'Leave').($r->status === 'pending' ? ' (waiting for approval)' : ''),
                        'detail' => $r->from_date->format('j M').($r->to_date->isSameDay($r->from_date) ? '' : ' to '.$r->to_date->format('j M')), 'tone' => $r->status === 'pending' ? 'warning' : 'info'];
                });
        }
        if ($own || $viewer->hasPermission('onboarding.view') || $viewer->hasPermission('onboarding.manage')) {
            $open = OnboardingTask::query()->withoutGlobalScope(AccessScope::class)->whereHas('plan', fn ($q) => $q->withoutGlobalScope(AccessScope::class)->where('employee_id', $e->id))
                ->where('status', 'pending')->where('is_mandatory', true)->orderBy('due_on')->get(['id', 'title', 'type', 'due_on']);
            if ($open->isNotEmpty()) {
                $docs = $open->where('type', 'document')->count();
                $items[] = ['date' => $open->first()->due_on, 'title' => $open->count().' required onboarding '.($open->count() === 1 ? 'step is' : 'steps are').' open',
                    'detail' => $docs > 0 ? $docs.' '.($docs === 1 ? 'is a document' : 'are documents').' to provide' : $open->first()->title, 'tone' => $open->first()->due_on?->isPast() ? 'danger' : 'warning'];
            }
        }
        if (in_array('learning', $visible, true)) {
            LearningEnrolment::query()->withoutGlobalScope(AccessScope::class)->with('course')->where('employee_id', $e->id)->whereIn('status', LearningEnrolment::OPEN)
                ->whereNotNull('due_on')->orderBy('due_on')->limit(2)->get()
                ->each(function (LearningEnrolment $l) use (&$items) {
                    $items[] = ['date' => $l->due_on, 'title' => ($l->course?->title ?? 'Learning').' due', 'detail' => $l->is_mandatory ? 'Mandatory' : null, 'tone' => $l->due_on->isPast() ? 'danger' : 'info'];
                });
        }
        if ($own || $viewer->hasPermission('exit.view')) {
            $exit = ExitCase::query()->withoutGlobalScope(AccessScope::class)->where('employee_id', $e->id)->whereIn('status', ExitCase::OPEN)->latest('id')->first();
            if ($exit && $exit->last_working_day) {
                $items[] = ['date' => $exit->last_working_day, 'title' => 'Last working day', 'detail' => 'Clearance, handover and settlement should be on track.', 'tone' => 'warning'];
            }
        }
        usort($items, fn ($a, $b) => ($a['date']?->timestamp ?? PHP_INT_MAX) <=> ($b['date']?->timestamp ?? PHP_INT_MAX));

        return array_slice($items, 0, 6);
    }

    /**
     * Relationships of every configured type: who this person works with above (line, functional, dotted,
     * HRBP, mentor, buddy, project, secondary) and who works with them.
     *
     * @return array{up: list<array{id: int, name: string, type: string, label: string}>, down: list<array{id: int, name: string, type: string, label: string}>, reports: int}
     */
    private function relationships(Employee $e): array
    {
        $labels = config('peopleos.people.reporting_types', []);
        $map = fn (ReportingRelationship $r, string $who) => ($p = $r->{$who}) === null ? null
            : ['id' => $p->id, 'name' => (string) $p->display_name, 'type' => $r->type, 'label' => $labels[$r->type] ?? ucfirst((string) $r->type)];
        $up = ReportingRelationship::query()->withoutGlobalScope(AccessScope::class)->with('manager.person')->where('employee_id', $e->id)->currentlyEffective()
            ->orderByDesc('is_primary')->get()->map(fn ($r) => $map($r, 'manager'))->filter()->values()->all();
        $downRows = ReportingRelationship::query()->withoutGlobalScope(AccessScope::class)->with('employee.person')->where('manager_id', $e->id)->currentlyEffective()->orderByDesc('is_primary')->get();

        return [
            'up' => $up,
            'down' => $downRows->take(12)->map(fn ($r) => $map($r, 'employee'))->filter()->values()->all(),
            'reports' => $downRows->where('type', 'line')->count(),
        ];
    }

    /** @return list<array{date: CarbonInterface, title: string, category: string, label: string, detail: ?string}> */
    private function changes(User $viewer, Employee $e): array
    {
        $hidden = TimelineCategories::hiddenFor($viewer, $e);

        return EmployeeTimelineEntry::query()->withoutGlobalScope(AccessScope::class)->where('employee_id', $e->id)->whereNotIn('category', $hidden)
            ->orderByDesc('occurred_on')->orderByDesc('id')->limit(8)->get()
            ->map(fn (EmployeeTimelineEntry $t) => ['date' => $t->occurred_on, 'title' => $t->title, 'category' => $t->category, 'label' => TimelineCategories::label($t->category),
                'detail' => TimelineCategories::showsDescription($viewer, $t->category) ? $t->description : null])->all();
    }

    private function safe(callable $callback, mixed $fallback): mixed
    {
        try {
            return $callback();
        } catch (Throwable $e) {
            report($e);

            return $fallback;
        }
    }
}
