<?php

namespace App\Domain\Employment\Actions;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Employment\Models\Employee;
use App\Domain\Employment\Models\ReportingRelationship;
use App\Domain\Lifecycle\Services\Timeline;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/** Opens a reporting line of a given type, closing the previous one of that type (blueprint §9). */
final class ChangeManagerAction
{
    public function __construct(
        private readonly AuditRecorder $audit,
        private readonly Timeline $timeline,
    ) {}

    public function handle(
        Employee $employee,
        Employee $manager,
        string $type = 'line',
        CarbonInterface|string|null $effectiveFrom = null,
        ?string $reason = null,
    ): ReportingRelationship {
        $from = Carbon::parse($effectiveFrom ?? now())->startOfDay();
        $isPrimary = $type === 'line';

        if ($manager->is($employee)) {
            throw new InvalidArgumentException('An employee cannot report to themselves.');
        }

        if ($isPrimary && $this->reportsTo($manager, $employee, $from)) {
            throw new InvalidArgumentException("{$manager->auditLabel()} already reports to this employee; that would create a loop.");
        }

        return DB::transaction(function () use ($employee, $manager, $type, $isPrimary, $from, $reason) {
            $current = $employee->reportingRelationships()->where('type', $type)->effectiveOn($from)->first();

            if ($current?->manager_id === $manager->getKey()) {
                return $current;
            }

            $current?->withAuditReason($reason)->update(['effective_to' => $from->copy()->subDay()]);

            $relationship = new ReportingRelationship([
                'employee_id' => $employee->getKey(),
                'manager_id' => $manager->getKey(),
                'type' => $type,
                'is_primary' => $isPrimary,
                'effective_from' => $from,
                'reason' => $reason,
            ]);
            $relationship->withAuditReason($reason)->save();

            $typeLabel = config("peopleos.people.reporting_types.{$type}", $type);
            $before = $current?->manager()->first()?->auditLabel();
            $after = $manager->auditLabel();

            $this->audit->record(
                action: AuditAction::ManagerChanged,
                module: 'employment',
                entity: $employee,
                changes: [['field' => $type.'_manager', 'before' => $before, 'after' => $after]],
                reason: $reason,
                effectiveDate: $from,
                metadata: ['type' => $type],
            );

            $this->timeline->record(
                $employee,
                'reporting',
                $current ? "{$typeLabel} changed" : "{$typeLabel} assigned",
                $from,
                ($before ? "{$before} → " : '').$after.($reason ? "\nReason: {$reason}" : ''),
                $relationship,
                ['type' => $type],
            );

            return $relationship;
        });
    }

    /** Walk the primary chain upwards from $start looking for $target. */
    private function reportsTo(Employee $start, Employee $target, Carbon $on): bool
    {
        $seen = [];
        $current = $start;

        while ($current !== null && ! isset($seen[$current->getKey()])) {
            $seen[$current->getKey()] = true;

            $managerId = $current->reportingRelationships()->where('is_primary', true)->effectiveOn($on)->value('manager_id');

            if ($managerId === null) {
                return false;
            }

            if ((int) $managerId === $target->getKey()) {
                return true;
            }

            $current = Employee::query()->find($managerId);
        }

        return false;
    }
}
