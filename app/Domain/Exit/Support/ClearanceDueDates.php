<?php

namespace App\Domain\Exit\Support;

use App\Domain\Configuration\Services\PolicyResolver;
use App\Domain\Employment\Models\Employee;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * HROne parity WO-11 (§23.6.3 #4): the due date of a clearance stage — the last working day less the stage's offset.
 * The offset comes from the mapped clearance checklist (the largest "due (days before the last day)" of the stage's
 * items) or else from the exit policy (`clearance_due_<stage>`, days before the last working day; a negative number is
 * after it). Blank everywhere = no due date, as before.
 */
final class ClearanceDueDates
{
    /** @param  list<array<string, mixed>>  $items */
    public static function offset(string $stage, array $items, ?Employee $employee, CarbonInterface|string|null $on = null): ?int
    {
        $fromChecklist = collect($items)->pluck('due_days')->filter(fn ($days) => is_numeric($days))->map(fn ($days) => (int) $days);
        if ($fromChecklist->isNotEmpty()) {
            return $fromChecklist->max();
        }
        if ($employee === null) {
            return null;
        }
        $days = app(PolicyResolver::class)->resolve('exit', $employee, $on ?? now())?->setting("clearance_due_{$stage}");

        return is_numeric($days) ? (int) $days : null;
    }

    public static function dueOn(CarbonInterface|string|null $lastWorkingDay, ?int $offset): ?Carbon
    {
        if ($lastWorkingDay === null || $offset === null) {
            return null;
        }

        return Carbon::parse($lastWorkingDay)->startOfDay()->subDays($offset);
    }
}
