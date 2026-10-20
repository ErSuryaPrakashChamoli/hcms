<?php

namespace App\Domain\Talent\Services;

use App\Domain\Career\Models\CareerAspirationEntry;
use App\Domain\Career\Models\MobilityInterest;
use App\Domain\Career\Models\RoleRequirementVersion;
use App\Domain\Career\Services\CareerArchitecture;
use App\Domain\Career\Services\RoleGaps;
use App\Domain\Employment\Models\Employee;
use App\Domain\Employment\Models\EmployeePosition;
use App\Domain\Exit\Models\ExitCase;
use App\Domain\Identity\Scopes\AccessScope;
use App\Domain\Succession\Models\CriticalPosition;
use App\Domain\Succession\Models\ReadinessAssessment;
use App\Domain\Succession\Models\SuccessionPlan;
use App\Domain\Succession\Models\Successor;
use App\Domain\Succession\Services\CriticalPositions;
use App\Domain\Talent\Models\TalentDevelopmentAction;
use App\Domain\Talent\Models\TalentPool;
use App\Domain\Talent\Models\TalentPoolMembership;
use App\Domain\Talent\Models\TalentReviewItem;
use Illuminate\Support\Collection;

/**
 * Phase 9 talent and succession analytics: database aggregates under the caller's access scope
 * (the models' global scopes). Facts only — coverage, bench strength, review dates, recorded exits —
 * never a flight-risk, promotion or suitability inference and never a ranking of people. Counts of
 * people below `talent.analytics_min_group` are suppressed.
 */
final class TalentAnalytics
{
    public function __construct(private readonly CriticalPositions $positions) {}

    public function minGroup(): int
    {
        return max(1, (int) config('peopleos.talent.analytics_min_group', 5));
    }

    /** @return array<string, mixed> */
    public function summary(): array
    {
        $coverage = $this->coverage();

        return [
            'min_group' => $this->minGroup(),
            'critical_positions' => [
                'active' => count($coverage),
                'by_criticality' => collect($coverage)->countBy('criticality')->all(),
                'review_overdue' => collect($coverage)->where('review_overdue', true)->count(),
            ],
            'succession' => [
                'with_open_plan' => collect($coverage)->where('open_plan', true)->count(),
                'with_successors' => collect($coverage)->where('successors', '>', 0)->count(),
                'with_ready_now' => collect($coverage)->where('ready_now', '>', 0)->count(),
                'coverage_rate' => $coverage === [] ? null : round(collect($coverage)->where('successors', '>', 0)->count() / count($coverage) * 100, 1),
                'ready_now_rate' => $coverage === [] ? null : round(collect($coverage)->where('ready_now', '>', 0)->count() / count($coverage) * 100, 1),
                'without_ready_now' => collect($coverage)->where('ready_now', 0)->pluck('title')->values()->all(),
            ],
            'readiness' => $this->bucket(ReadinessAssessment::query()->where('readiness_assessments.status', 'current')->whereNotNull('readiness_assessments.critical_position_id')
                ->selectRaw('readiness_level as k, count(*) as total')->groupBy('readiness_level')->pluck('total', 'k')->all()),
            'pools' => $this->pools(),
            'development_actions' => $this->bucket(TalentDevelopmentAction::query()->join('development_plan_items as i', 'i.id', '=', 'talent_development_actions.development_plan_item_id')
                ->selectRaw('i.status as k, count(*) as total')->groupBy('i.status')->pluck('total', 'k')->all()),
            'review_decisions' => $this->bucket(TalentReviewItem::query()->where('talent_review_items.status', 'decided')
                ->selectRaw('decision as k, count(*) as total')->groupBy('decision')->pluck('total', 'k')->all()),
            'aspirations' => $this->bucket(CareerAspirationEntry::query()->where('career_aspiration_entries.status', 'current')
                ->selectRaw('term as k, count(*) as total')->groupBy('term')->pluck('total', 'k')->all()),
            'mobility_interest' => $this->bucket(MobilityInterest::query()->where('mobility_interests.status', 'active')
                ->selectRaw('interest_type as k, count(*) as total')->groupBy('interest_type')->pluck('total', 'k')->all()),
        ];
    }

    /**
     * Per active critical position: criticality, open plan, bench strength (active successors on
     * open plans), current non-expired "ready now" labels, review due date and any recorded incumbent
     * exit. All facts recorded by people or read from employment.
     *
     * @return list<array<string, mixed>>
     */
    public function coverage(): array
    {
        $today = now()->toDateString();
        $bench = Successor::query()->where('successors.status', 'active')
            ->join('succession_plans as p', 'p.id', '=', 'successors.succession_plan_id')->where('p.status', '!=', 'closed')
            ->selectRaw('p.critical_position_id as position_id, count(distinct successors.employee_id) as total')->groupBy('p.critical_position_id')
            ->pluck('total', 'position_id')->map(fn ($v) => (int) $v);
        $ready = ReadinessAssessment::query()->where('readiness_assessments.status', 'current')->where('readiness_assessments.readiness_level', 'ready_now')
            ->where(fn ($q) => $q->whereNull('readiness_assessments.effective_to')->orWhere('readiness_assessments.effective_to', '>=', $today))
            ->join('successors as s', fn ($j) => $j->on('s.employee_id', '=', 'readiness_assessments.employee_id')->where('s.status', 'active'))
            ->join('succession_plans as p', fn ($j) => $j->on('p.id', '=', 's.succession_plan_id')->on('p.critical_position_id', '=', 'readiness_assessments.critical_position_id')->where('p.status', '!=', 'closed'))
            ->selectRaw('readiness_assessments.critical_position_id as position_id, count(distinct readiness_assessments.employee_id) as total')
            ->groupBy('readiness_assessments.critical_position_id')->pluck('total', 'position_id')->map(fn ($v) => (int) $v);
        $openPlans = SuccessionPlan::query()->where('status', '!=', 'closed')->pluck('critical_position_id')->flip();

        $exits = $this->roleWideIncumbentExits($today);

        $rows = [];
        CriticalPosition::query()->with('currentAssessment:id,criticality')->where('status', 'active')->orderBy('id')
            ->chunkById(200, function ($chunk) use (&$rows, $bench, $ready, $openPlans, $today, $exits) {
                foreach ($chunk as $position) {
                    $rows[] = [
                        'id' => $position->id, 'title' => $position->title, 'criticality' => $position->currentAssessment?->criticality,
                        'open_plan' => $openPlans->has($position->id), 'successors' => $bench[$position->id] ?? 0, 'ready_now' => $ready[$position->id] ?? 0,
                        'next_review_on' => $position->next_review_on?->toDateString(),
                        'review_overdue' => $position->next_review_on !== null && $position->next_review_on->toDateString() < $today,
                        // Role-wide positions come from one grouped query; unit-scoped ones (few) are read individually.
                        'incumbent_exit_on' => $position->organisation_node_id === null ? ($exits[$position->designation_id] ?? null) : $this->positions->upcomingIncumbentExit($position),
                    ];
                }
            });

        return $rows;
    }

    /**
     * Earliest recorded last working day (open exit case) among the current holders of each role, in
     * one grouped query — the same fact CriticalPositions::upcomingIncumbentExit reads per position.
     *
     * @return Collection<int|string, string>
     */
    private function roleWideIncumbentExits(string $today): Collection
    {
        return EmployeePosition::query()->withoutGlobalScope(AccessScope::class)->effectiveOn()
            ->whereNotNull('employee_positions.designation_id')
            ->whereIn('employee_positions.designation_id', CriticalPosition::query()->where('status', 'active')->whereNull('organisation_node_id')->select('designation_id'))
            ->whereIn('employee_positions.employee_id', Employee::query()->withoutGlobalScope(AccessScope::class)->employed()->select('id'))
            ->join('exit_cases', 'exit_cases.employee_id', '=', 'employee_positions.employee_id')
            ->whereIn('exit_cases.status', ExitCase::OPEN)->whereNotNull('exit_cases.last_working_day')->whereDate('exit_cases.last_working_day', '>=', $today)
            ->groupBy('employee_positions.designation_id')
            ->selectRaw('employee_positions.designation_id as designation, min(exit_cases.last_working_day) as last_day')
            ->pluck('last_day', 'designation');
    }

    /**
     * Required-skill gaps across active successors, per skill (people counts suppressed below the
     * minimum group). Requirements are resolved once per position; each successor's skills come from
     * the Phase 8 SkillProfiles contract (one profile per successor, chunked — never all at once).
     */
    public function successorGaps(): array
    {
        $gaps = [];
        $roleGaps = app(RoleGaps::class);
        $this->eachSuccessorAgainstRequirements(function (Employee $employee, RoleRequirementVersion $requirements) use (&$gaps, $roleGaps) {
            foreach ($roleGaps->skillGaps($employee, $requirements) as $row) {
                if (($row['gap'] ?? 0) > 0) {
                    $gaps[$row['skill']] = ($gaps[$row['skill']] ?? 0) + 1;
                }
            }
        });
        arsort($gaps);

        return $this->bucket($gaps);
    }

    /** Required certifications that active successors do not hold (missing or expired), per certification; small counts suppressed. */
    public function successorCertificationGaps(): array
    {
        $gaps = [];
        $roleGaps = app(RoleGaps::class);
        $this->eachSuccessorAgainstRequirements(function (Employee $employee, RoleRequirementVersion $requirements) use (&$gaps, $roleGaps) {
            foreach ($roleGaps->certificationGaps($employee, $requirements) as $row) {
                if (in_array($row['status'], ['missing', 'expired'], true)) {
                    $gaps[(string) $row['title']] = ($gaps[(string) $row['title']] ?? 0) + 1;
                }
            }
        });
        arsort($gaps);

        return $this->bucket($gaps);
    }

    /** Walk active successors in chunks with the current requirements of their plan's position, resolved once per position. */
    private function eachSuccessorAgainstRequirements(callable $callback): void
    {
        $requirements = [];
        $architecture = app(CareerArchitecture::class);
        Successor::query()->with('plan.position:id,designation_id,organisation_node_id')->where('successors.status', 'active')->orderBy('id')
            ->chunkById(200, function ($chunk) use (&$requirements, $architecture, $callback) {
                $employees = Employee::query()->withoutGlobalScope(AccessScope::class)->whereIn('id', $chunk->pluck('employee_id'))->get()->keyBy('id');
                foreach ($chunk as $successor) {
                    $position = $successor->plan?->position;
                    $employee = $employees->get($successor->employee_id);
                    if ($position === null || $employee === null) {
                        continue;
                    }
                    $key = $position->designation_id.':'.$position->organisation_node_id;
                    $requirements[$key] ??= $architecture->requirementsFor($position->designation_id, $position->organisation_node_id) ?? false;
                    if ($requirements[$key] !== false) {
                        $callback($employee, $requirements[$key]);
                    }
                }
            });
    }

    private function pools(): array
    {
        $counts = TalentPoolMembership::query()->where('talent_pool_memberships.status', 'active')
            ->selectRaw('talent_pool_id as k, count(*) as total')->groupBy('talent_pool_id')->pluck('total', 'k');

        return TalentPool::query()->where('status', 'active')->orderBy('name')->get(['id', 'name'])
            ->map(fn (TalentPool $p) => ['pool' => $p->name, ...$this->suppress((int) ($counts[$p->id] ?? 0))])->all();
    }

    /** @param  array<string|int, int|string>  $counts */
    private function bucket(array $counts): array
    {
        return collect($counts)->map(fn ($v, $k) => ['key' => (string) $k, ...$this->suppress((int) $v)])->values()->all();
    }

    private function suppress(int $count): array
    {
        return $count > 0 && $count < $this->minGroup() ? ['suppressed' => true] : ['count' => $count, 'suppressed' => false];
    }
}
