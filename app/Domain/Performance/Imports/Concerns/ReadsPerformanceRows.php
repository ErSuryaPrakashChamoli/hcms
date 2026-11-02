<?php

namespace App\Domain\Performance\Imports\Concerns;

use App\Domain\Employment\Imports\EmployeeImport;
use App\Domain\Identity\Models\User;
use App\Domain\Performance\Models\Appraisal;
use App\Domain\Performance\Models\Goal;
use App\Domain\Performance\Models\PerformanceCycle;
use App\Domain\Performance\Models\RatingScale;

/**
 * Data import DI-35: what the performance uploads look up the same way — the uploader (the person the screens check), the
 * performance cycle by its code, an employee's appraisal in a cycle, a goal by its title (an employee's, or an organisation
 * goal when no employee code is given; only goals the uploader may see) and ratings as numbers.
 */
trait ReadsPerformanceRows
{
    protected function uploader(EmployeeImport $import): ?User
    {
        return User::query()->find($import->uploaded_by);
    }

    /** @param list<string> $errors */
    protected function cycleOf(?string $code, array &$context, array &$errors, bool $required = true): ?PerformanceCycle
    {
        $code = trim((string) $code);
        if ($code === '') {
            if ($required) {
                $errors[] = 'Cycle code is required (Performance → Cycles).';
            }

            return null;
        }
        $key = mb_strtolower($code);
        if (! array_key_exists($key, $context['cycles'] ?? [])) {
            $context['cycles'][$key] = PerformanceCycle::query()->whereRaw('lower(code) = ?', [$key])->first();
        }
        if ($context['cycles'][$key] === null) {
            $errors[] = "Cycle code '{$code}' does not exist.";
        }

        return $context['cycles'][$key];
    }

    protected function appraisalOf(PerformanceCycle $cycle, int $employeeId): ?Appraisal
    {
        return Appraisal::query()->where('performance_cycle_id', $cycle->id)->where('employee_id', $employeeId)->first();
    }

    /**
     * The open or completed goal of that title (case and spaces ignored): the employee's in the cycle, or — with no
     * employee — an organisation goal of the cycle. Only goals the uploader may see are found.
     */
    protected function goalOf(?int $employeeId, ?PerformanceCycle $cycle, string $title): ?Goal
    {
        return Goal::query()->where('status', '!=', 'cancelled')
            ->when($employeeId !== null, fn ($q) => $q->where('level', 'employee')->where('employee_id', $employeeId), fn ($q) => $q->where('level', '!=', 'employee'))
            ->when($cycle !== null, fn ($q) => $q->where('performance_cycle_id', $cycle->id), fn ($q) => $q->whereNull('performance_cycle_id'))
            ->whereRaw('lower(trim(title)) = ?', [mb_strtolower(trim($title))])->orderBy('id')->first();
    }

    /** A rating as a number, or null with an error. @param list<string> $errors */
    protected function ratingOf(?string $value, string $what, array &$errors): ?float
    {
        if ($value === null || ! is_numeric(trim($value))) {
            $errors[] = "{$what} must be a number on the rating scale.";

            return null;
        }

        return round((float) $value, 2);
    }

    /** The cycle's rating scale (for messages and examples). */
    protected function scaleOf(PerformanceCycle $cycle): RatingScale
    {
        return $cycle->ratingScale();
    }
}
