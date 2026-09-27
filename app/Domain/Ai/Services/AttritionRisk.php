<?php

namespace App\Domain\Ai\Services;

use App\Domain\Attendance\Models\AttendanceRecord;
use App\Domain\Employment\Models\Employee;
use App\Domain\Grievance\Models\Grievance;
use App\Domain\Learning\Models\LearningEnrolment;
use App\Domain\Payroll\Models\EmployeeSalaryAssignment;
use App\Domain\Performance\Models\Appraisal;
use App\Domain\Performance\Models\FeedbackEntry;
use App\Domain\Performance\Models\OneOnOne;
use Illuminate\Support\Collection;

/**
 * Attrition-risk signals (§94 Workforce Analyst, §95 governance): transparent, additive heuristics.
 * Every score lists its signals; it is labelled an inference and must not drive automated decisions.
 */
final class AttritionRisk
{
    /** @return array{employee_id: int, name: ?string, department: ?string, score: int, band: string, signals: array<int, string>} */
    public function score(Employee $employee): array
    {
        $employee->loadMissing(['person', 'currentPosition.department']);
        $config = config('peopleos.ai.attrition_risk.signals');
        $signals = [];
        $score = 0;
        $hit = function (string $key) use (&$signals, &$score, $config): void {
            $signals[] = $config[$key]['label'];
            $score += (int) $config[$key]['points'];
        };

        if ($employee->joining_date && $employee->joining_date->diffInMonths(now()) < 12) {
            $hit('short_tenure');
        }
        $lastRevision = EmployeeSalaryAssignment::query()->where('employee_id', $employee->id)->orderByDesc('effective_from')->first();
        if ($lastRevision && $lastRevision->effective_from->diffInMonths(now()) >= 24) {
            $hit('no_revision');
        }
        $latest = Appraisal::query()->where('employee_id', $employee->id)->whereNotNull('final_rating')->orderByDesc('finalized_at')->first();
        if ($latest && (float) $latest->final_rating <= 2) {
            $hit('low_rating');
        }
        if (LearningEnrolment::query()->where('employee_id', $employee->id)->where('is_mandatory', true)->where('status', 'overdue')->exists()) {
            $hit('learning_overdue');
        }
        if (OneOnOne::query()->where('employee_id', $employee->id)->where('status', 'held')->where('scheduled_at', '>=', now()->subDays(90))->doesntExist() && $employee->joining_date && $employee->joining_date->lt(now()->subDays(90))) {
            $hit('no_one_on_one');
        }
        if (AttendanceRecord::query()->where('employee_id', $employee->id)->where('status', 'absent')->whereDate('date', '>=', now()->subDays(30))->count() >= 3) {
            $hit('absences');
        }
        if (FeedbackEntry::query()->where('employee_id', $employee->id)->where('type', 'constructive')->where('created_at', '>=', now()->subDays(60))->exists()) {
            $hit('constructive_feedback');
        }
        if ($latest && (float) $latest->final_rating >= 4 && $employee->positions()->where('change_type', 'promotion')->where('effective_from', '>=', now()->subMonths(24))->doesntExist() && $employee->joining_date && $employee->joining_date->lt(now()->subMonths(24))) {
            $hit('stalled_high_performer');
        }
        if (! $employee->lifecycle_state->isEmployed()) {
            $signals = [];
            $score = 0;
        } elseif (Grievance::query()->where('employee_id', $employee->id)->whereIn('status', Grievance::OPEN)->exists()) {
            $hit('open_grievance');
        }

        $bands = config('peopleos.ai.attrition_risk.bands');

        return [
            'employee_id' => $employee->id,
            'name' => $employee->person?->full_name,
            'department' => $employee->currentPosition?->department?->name,
            'score' => $score,
            'band' => $score <= $bands['low'] ? 'low' : ($score <= $bands['medium'] ? 'medium' : 'high'),
            'signals' => $signals,
        ];
    }

    /** All employed people ranked by score, highest first. */
    public function rank(?int $limit = null): Collection
    {
        $ranked = Employee::query()->with(['person', 'currentPosition.department'])->employed()->get()->map(fn (Employee $e) => $this->score($e))->sortByDesc('score')->values();

        return $limit ? $ranked->take($limit) : $ranked;
    }
}
