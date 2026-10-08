<?php

namespace App\Domain\Experience\Services;

use App\Domain\Bgv\Models\BgvCase;
use App\Domain\Documents\Models\EmployeeDocument;
use App\Domain\Employment\Models\Employee;
use App\Domain\Employment\Models\EmployeePosition;
use App\Domain\Employment\Models\ReportingRelationship;
use App\Domain\Exit\Models\ExitCase;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Models\UserAccessScope;
use App\Domain\Identity\Scopes\AccessScope;
use App\Domain\Learning\Models\LearningEnrolment;
use App\Domain\Leave\Models\LeaveRequest;
use App\Domain\Lifecycle\Enums\LifecycleState;
use App\Domain\Lifecycle\Models\EmployeeTimelineEntry;
use App\Domain\Lifecycle\Support\TimelineCategories;
use App\Domain\Onboarding\Models\OnboardingTask;
use App\Domain\ServiceDesk\Models\Ticket;
use App\Domain\ServiceDesk\Services\CaseAccess;
use App\Filament\Pages\Approvals;
use App\Filament\Pages\ChangeIntelligencePage;
use App\Filament\Pages\MyCareer;
use App\Filament\Pages\MyHr;
use App\Filament\Pages\MyTeam;
use App\Filament\Resources\Users\UserResource;
use App\Support\Tenancy\TenantContext;
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
            'viewer' => $this->safe(fn () => $this->viewer($viewer, $employee, $own, $state), null),
        ];
    }

    /**
     * UX.16: what this viewer is here for, as one panel in the Now view. One workspace, composed by relationship and
     * role: your own record, a person who reports to you, HR's operational view, or an administrator's identity and
     * access view. Every fact needs its own permission (or relationship) and the identity facts need user access;
     * the account must belong to the current tenant. Anyone else gets no panel.
     *
     * @return array{as: string, title: string, line: string, facts: list<array{label: string, value: string}>, links: list<array{label: string, url: string}>}|null
     */
    private function viewer(User $viewer, Employee $e, bool $own, ?LifecycleState $state): ?array
    {
        $first = $e->person?->preferred_name ?: ($e->person?->first_name ?? $e->display_name);
        $url = fn (callable $u) => rescue($u, null, false);
        $links = fn (array $pairs) => array_values(array_filter(array_map(fn ($p) => $p[1] ? ['label' => $p[0], 'url' => $p[1]] : null, $pairs)));
        if ($own) {
            return ['as' => 'self', 'title' => 'Your record', 'line' => 'This is you. Your requests, documents and pay are in My HR; your goals and growth in My career.', 'facts' => [],
                'links' => $links([['My HR', $url(fn () => MyHr::canAccess() ? MyHr::getUrl() : null)], ['My career', $url(fn () => MyCareer::canAccess() ? MyCareer::getUrl() : null)]])];
        }

        $lenses = app(RoleLens::class);
        $me = $lenses->employee($viewer);
        $line = $me ? ReportingRelationship::query()->where('manager_id', $me->id)->where('employee_id', $e->id)->currentlyEffective()->first() : null;
        if ($line !== null) {
            $pending = app(ApprovalCenter::class)->pendingAbout($viewer, (int) $e->id)->count();
            $facts = [['label' => 'Relationship', 'value' => (string) config("peopleos.people.reporting_types.{$line->type}", ucfirst((string) $line->type))]];
            $facts[] = ['label' => 'Decisions waiting', 'value' => (string) $pending];
            if ($state === LifecycleState::Probation && $e->probation_end_date) {
                $facts[] = ['label' => 'Probation ends', 'value' => $e->probation_end_date->format('j M Y').($e->probation_end_date->isPast() ? ' (passed)' : '')];
            }

            return ['as' => 'manager', 'title' => 'Your team', 'line' => $first.' reports to you. Decisions, probation, goals and reviews for '.$first.' come to you.', 'facts' => $facts,
                'links' => $links([['Approval Center', $pending > 0 ? $url(fn () => Approvals::canAccess() ? Approvals::getUrl() : null) : null], ['My team', $url(fn () => MyTeam::canAccess() ? MyTeam::getUrl() : null)]])];
        }

        $experience = RoleLens::experienceOf($lenses->primary($viewer, app(ExperiencePreferences::class)->for($viewer)['lens'] ?? null));
        if ($experience === 'admin' && UserResource::canAccess() && $e->user_id) {
            $account = User::query()->with('roles')->where('tenant_id', app(TenantContext::class)->id())->find($e->user_id);
            $facts = $account === null ? [['label' => 'Sign-in account', 'value' => 'None']] : [
                ['label' => 'Sign-in account', 'value' => ucfirst($account->status instanceof \BackedEnum ? (string) $account->status->value : (string) $account->status)],
                ['label' => 'Roles', 'value' => $account->roles->pluck('name')->implode(', ') ?: 'None'],
                ['label' => 'Last sign-in', 'value' => $account->last_login_at?->diffForHumans() ?? 'Never'],
                ['label' => 'Authenticator app', 'value' => filled($account->app_authentication_secret) ? 'Set up' : 'Not set up'],
                ['label' => 'Organisation scope', 'value' => ($n = UserAccessScope::query()->where('user_id', $account->id)->count()) > 0 ? $n.' '.($n === 1 ? 'rule' : 'rules') : 'None (their roles apply tenant-wide)'],
            ];

            return ['as' => 'admin', 'title' => 'Identity and access', 'line' => 'How '.$first.' signs in and what they can reach. Changes to access are audited.', 'facts' => $facts,
                'links' => $links([['Users', $url(fn () => UserResource::getUrl('index'))], ['All changes', $url(fn () => ChangeIntelligencePage::canAccess() ? ChangeIntelligencePage::getUrl(['employee' => $e->getKey()]) : null)]])];
        }

        if (in_array($experience, ['hr', 'payroll', 'admin'], true) && $viewer->hasPermission('employee.update')) {
            $facts = [['label' => 'Lifecycle', 'value' => $state?->getLabel() ?? 'Unknown']];
            if ($viewer->hasPermission('document.verify')) {
                $facts[] = ['label' => 'Documents to verify', 'value' => (string) EmployeeDocument::query()->where('employee_id', $e->id)->where('status', 'pending')->count()];
            }
            if ($viewer->hasPermission('servicedesk.agent') || $viewer->hasPermission('servicedesk.view')) {
                $facts[] = ['label' => 'Open HR requests', 'value' => (string) app(CaseAccess::class)->visible(Ticket::query(), $viewer)->where('tickets.employee_id', $e->id)->whereIn('tickets.status', Ticket::OPEN)->count()];
            }
            if ($viewer->hasPermission('bgv.view') && ($bgv = BgvCase::query()->where('employee_id', $e->id)->latest('id')->first())) {
                $facts[] = ['label' => 'Background check', 'value' => ucfirst(str_replace('_', ' ', (string) $bgv->status))];
            }

            return ['as' => 'hr', 'title' => 'People operations', 'line' => 'You look after '.$first.' as HR. Lifecycle, documents, requests and compliance are in the Records view.', 'facts' => $facts,
                'links' => $links([['Records', '#records']])];
        }

        return null;
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
