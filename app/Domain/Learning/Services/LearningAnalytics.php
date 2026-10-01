<?php

namespace App\Domain\Learning\Services;

use App\Domain\Development\Models\DevelopmentPlan;
use App\Domain\Learning\Models\LearningCertificate;
use App\Domain\Learning\Models\LearningCompletion;
use App\Domain\Learning\Models\LearningCost;
use App\Domain\Learning\Models\LearningEnrolment;
use App\Domain\Learning\Models\LearningProvider;
use App\Domain\People\Models\Skill;
use App\Domain\Skills\Models\EmployeeSkill;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Phase 8 L&D analytics, computed with database aggregates (no population loaded into memory) and
 * the caller's access scope (the models' global scopes). Per-department figures and skill-gap
 * counts under `learning.analytics_min_group` are suppressed. Costs appear only when the caller is
 * allowed to see them. Nothing here exposes individual performance information.
 */
final class LearningAnalytics
{
    public function minGroup(): int
    {
        return max(1, (int) config('peopleos.learning.analytics_min_group', 5));
    }

    /** @return array<string, mixed> */
    public function summary(bool $includeCosts = false, CarbonInterface|string|null $from = null, CarbonInterface|string|null $to = null): array
    {
        $from = $from ? Carbon::parse($from)->startOfDay() : now()->subYear()->startOfDay();
        $to = $to ? Carbon::parse($to)->endOfDay() : now()->endOfDay();

        $enrolments = LearningEnrolment::query()->whereBetween('learning_enrolments.created_at', [$from, $to]);
        $byStatus = (clone $enrolments)->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status')->map(fn ($v) => (int) $v)->all();
        $total = array_sum($byStatus);
        $completed = ($byStatus['completed'] ?? 0) + ($byStatus['expired'] ?? 0);

        $mandatory = LearningEnrolment::query()->where('is_mandatory', true)->whereNotIn('status', ['cancelled', 'rejected', 'withdrawn']);
        $mandatoryTotal = (clone $mandatory)->count();
        $mandatoryDone = (clone $mandatory)->where('status', 'completed')->count();
        $mandatoryOverdue = (clone $mandatory)->where('status', 'overdue')->count();

        $completions = LearningCompletion::query()->where('status', 'final')->whereBetween('completed_at', [$from, $to]);

        return [
            'period' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'min_group' => $this->minGroup(),
            'enrolments' => ['total' => $total, 'by_status' => $byStatus],
            'completions' => (clone $completions)->count(),
            'completion_rate' => $total === 0 ? null : round($completed / $total * 100, 1),
            'mandatory' => ['assigned' => $mandatoryTotal, 'completed' => $mandatoryDone, 'overdue' => $mandatoryOverdue, 'compliance_rate' => $mandatoryTotal === 0 ? null : round($mandatoryDone / $mandatoryTotal * 100, 1)],
            'learning_hours' => round((float) (clone $completions)->sum('hours'), 1),
            'certificates' => [
                'valid' => LearningCertificate::query()->whereIn('status', ['valid', 'expiring'])->count(),
                'expiring_30' => LearningCertificate::query()->whereIn('status', ['valid', 'expiring'])->whereBetween('expires_on', [now()->toDateString(), now()->addDays(30)->toDateString()])->count(),
                'expiring_90' => LearningCertificate::query()->whereIn('status', ['valid', 'expiring'])->whereBetween('expires_on', [now()->toDateString(), now()->addDays(90)->toDateString()])->count(),
                'expired' => LearningCertificate::query()->where('status', 'expired')->count(),
                'revoked' => LearningCertificate::query()->where('status', 'revoked')->count(),
            ],
            'popular_courses' => (clone $enrolments)->join('courses', 'courses.id', '=', 'learning_enrolments.course_id')
                ->selectRaw('courses.code as code, courses.title as title, count(*) as enrolments')->groupBy('courses.code', 'courses.title')
                ->orderByDesc('enrolments')->limit(10)->get()->map(fn ($r) => ['code' => $r->code, 'title' => $r->title, 'enrolments' => (int) $r->enrolments])->all(),
            'providers' => LearningProvider::query()->get(['id', 'code', 'name'])->map(fn (LearningProvider $p) => [
                'code' => $p->code, 'name' => $p->name,
                'completions' => (clone $completions)->where('learning_provider_id', $p->id)->count(),
            ])->filter(fn ($row) => $row['completions'] > 0)->values()->all(),
            'skill_gaps' => $this->skillGaps(),
            'development_plans' => $this->plans(),
            'by_department' => $this->byDepartment($from, $to),
            'costs' => $includeCosts ? LearningCost::query()->whereBetween('incurred_on', [$from->toDateString(), $to->toDateString()])
                ->selectRaw('currency, cost_type, sum(amount) as total')->groupBy('currency', 'cost_type')->get()
                ->map(fn ($r) => ['currency' => $r->currency, 'type' => $r->cost_type, 'total' => round((float) $r->total, 2)])->all() : null,
        ];
    }

    /** Employees per skill with a positive gap (target above best current level); small counts suppressed. */
    public function skillGaps(): array
    {
        $targets = EmployeeSkill::query()->where('status', 'current')->whereNotNull('target_level')
            ->selectRaw('employee_id, skill_id, max(target_level) as target')->groupBy('employee_id', 'skill_id');
        $levels = EmployeeSkill::query()->where('status', 'current')->whereNotNull('current_level')
            ->selectRaw('employee_id, skill_id, max(current_level) as level')->groupBy('employee_id', 'skill_id');

        $rows = DB::query()->fromSub($targets, 't')
            ->leftJoinSub($levels, 'l', fn ($j) => $j->on('l.employee_id', '=', 't.employee_id')->on('l.skill_id', '=', 't.skill_id'))
            ->whereRaw('t.target > coalesce(l.level, 0)')
            ->selectRaw('t.skill_id, count(*) as employees, avg(t.target - coalesce(l.level, 0)) as average_gap')
            ->groupBy('t.skill_id')->get();
        $names = Skill::query()->whereIn('id', $rows->pluck('skill_id'))->pluck('name', 'id');

        return $rows->map(fn ($r) => (int) $r->employees < $this->minGroup()
            ? ['skill' => $names[$r->skill_id] ?? null, 'suppressed' => true]
            : ['skill' => $names[$r->skill_id] ?? null, 'suppressed' => false, 'employees' => (int) $r->employees, 'average_gap' => round((float) $r->average_gap, 2)])->values()->all();
    }

    private function plans(): array
    {
        $byStatus = DevelopmentPlan::query()->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status')->map(fn ($v) => (int) $v)->all();
        $closed = ($byStatus['completed'] ?? 0) + ($byStatus['cancelled'] ?? 0);

        return ['by_status' => $byStatus, 'completion_rate' => $closed === 0 ? null : round(($byStatus['completed'] ?? 0) / $closed * 100, 1)];
    }

    /** Completion rate by current department; departments with fewer enrolled employees than the minimum are suppressed. */
    private function byDepartment(CarbonInterface $from, CarbonInterface $to): array
    {
        $rows = LearningEnrolment::query()->whereBetween('learning_enrolments.created_at', [$from, $to])
            ->join('employee_positions as p', function ($j) {
                $j->on('p.employee_id', '=', 'learning_enrolments.employee_id')
                    ->where('p.effective_from', '<=', now()->toDateString())
                    ->where(fn ($q) => $q->whereNull('p.effective_to')->orWhere('p.effective_to', '>=', now()->toDateString()));
            })
            ->leftJoin('departments as d', 'd.id', '=', 'p.department_id')
            ->selectRaw("coalesce(d.name, 'Unassigned') as department, count(distinct learning_enrolments.employee_id) as employees, count(*) as enrolments, sum(case when learning_enrolments.status in ('completed', 'expired') then 1 else 0 end) as completed")
            ->groupByRaw("coalesce(d.name, 'Unassigned')")->get();

        return $rows->map(fn ($r) => (int) $r->employees < $this->minGroup()
            ? ['department' => $r->department, 'suppressed' => true]
            : ['department' => $r->department, 'suppressed' => false, 'employees' => (int) $r->employees, 'enrolments' => (int) $r->enrolments, 'completion_rate' => round((int) $r->completed / max(1, (int) $r->enrolments) * 100, 1)])->values()->all();
    }

    /**
     * Mandatory-training compliance per course: everyone currently assigned mandatory learning, and how
     * many hold a completed (not expired) enrolment. Query-level counts only.
     *
     * @return list<array{course: string, assigned: int, completed: int, overdue: int, rate: ?float}>
     */
    public function mandatoryReport(): array
    {
        return LearningEnrolment::query()->where('learning_enrolments.is_mandatory', true)->whereNotIn('learning_enrolments.status', ['cancelled', 'rejected', 'withdrawn'])
            ->join('courses', 'courses.id', '=', 'learning_enrolments.course_id')
            ->selectRaw("courses.code as code, count(*) as assigned, sum(case when learning_enrolments.status = 'completed' then 1 else 0 end) as completed, sum(case when learning_enrolments.status = 'overdue' then 1 else 0 end) as overdue")
            ->groupBy('courses.code')->orderBy('courses.code')->get()
            ->map(fn ($r) => ['course' => $r->code, 'assigned' => (int) $r->assigned, 'completed' => (int) $r->completed, 'overdue' => (int) $r->overdue, 'rate' => (int) $r->assigned === 0 ? null : round((int) $r->completed / (int) $r->assigned * 100, 1)])
            ->all();
    }
}
