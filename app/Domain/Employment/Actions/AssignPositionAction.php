<?php

namespace App\Domain\Employment\Actions;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Employment\Contracts\PositionAssignmentGuard;
use App\Domain\Employment\Events\EmploymentEvent;
use App\Domain\Employment\Exceptions\OverlappingAssignmentException;
use App\Domain\Employment\Models\Employee;
use App\Domain\Employment\Models\EmployeePosition;
use App\Domain\Lifecycle\Services\Timeline;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Opens a new effective-dated position and closes the current one the day before.
 * Never edits history (blueprint §70, §100). Timeline gets one line per changed dimension.
 */
final class AssignPositionAction
{
    public function __construct(
        private readonly AuditRecorder $audit,
        private readonly Timeline $timeline,
        // Phase 10: validates a named position (seat) under its row lock; absent when Workforce is not bound.
        private readonly ?PositionAssignmentGuard $positionGuard = null,
    ) {}

    /** @param  array<string, mixed>  $attributes  dimension ids (company_id, department_id, ...) */
    public function handle(
        Employee $employee,
        array $attributes,
        string $changeType,
        CarbonInterface|string|null $effectiveFrom = null,
        ?string $reason = null,
    ): EmployeePosition {
        $from = Carbon::parse($effectiveFrom ?? now())->startOfDay();
        // Blank selections mean "unchanged", never "clear".
        $dimensions = array_filter(
            array_intersect_key($attributes, EmployeePosition::DIMENSIONS),
            fn ($value) => $value !== null && $value !== '',
        );

        return DB::transaction(function () use ($employee, $attributes, $dimensions, $changeType, $from, $reason) {
            $current = $employee->positions()->effectiveOn($from)->first();

            if ($current !== null && $current->effective_from->gte($from)) {
                throw new OverlappingAssignmentException('The new position must start after the current one ('.$current->effective_from->toDateString().').');
            }

            // A future-dated position after this date would overlap the new open-ended one.
            $future = $employee->positions()->where('effective_from', '>', $from->toDateString())->orderBy('effective_from')->first();
            if ($future !== null) {
                throw new OverlappingAssignmentException('A position already starts on '.$future->effective_from->toDateString().'; positions cannot overlap. Use an effective date after it, or correct that position first.');
            }

            // Phase 10: a named position is validated and locked here, inside this transaction, and
            // supplies its dimensions; explicit dimensions still win. Without one the seat carries forward.
            $seat = $this->positionGuard?->resolve($employee, $current, $attributes, $from)
                ?? ['position_id' => $current?->position_id, 'fte' => $current?->fte, 'dimensions' => []];

            // Carry forward unchanged dimensions so a transfer only needs to state what changed.
            $merged = $current
                ? array_merge(array_intersect_key($current->getAttributes(), EmployeePosition::DIMENSIONS), $seat['dimensions'], $dimensions)
                : array_merge($seat['dimensions'], $dimensions);

            if (empty($merged['company_id'])) {
                throw new InvalidArgumentException('A position must belong to a company.');
            }

            $current?->withAuditReason($reason)->update(['effective_to' => $from->copy()->subDay()]);

            $position = new EmployeePosition($merged + [
                'position_id' => $seat['position_id'],
                'fte' => $seat['fte'],
                'employee_id' => $employee->getKey(),
                'change_type' => $changeType,
                'effective_from' => $from,
                'reason' => $reason,
            ]);
            $position->withAuditReason($reason)->save();

            $this->audit->record(
                action: match ($changeType) {
                    'promotion' => AuditAction::Promoted,
                    'transfer' => AuditAction::Transferred,
                    default => AuditAction::Update,
                },
                module: 'employment',
                entity: $employee,
                changes: $this->diff($current, $position),
                reason: $reason,
                effectiveDate: $from,
                metadata: ['change_type' => $changeType, 'position_id' => $position->getKey()],
            );

            $this->writeTimeline($employee, $current, $position, $changeType, $from, $reason);
            $this->dispatchEvents($employee, $current, $position, $changeType, $from, $reason);

            return $position;
        });
    }

    /** Reserved employment events (contract §7): one per business change plus one per changed key dimension. */
    private function dispatchEvents(Employee $employee, ?EmployeePosition $before, EmployeePosition $after, string $changeType, Carbon $from, ?string $reason): void
    {
        $context = ['change_type' => $changeType, 'effective_date' => $from->toDateString(), 'reason' => $reason, 'position_id' => $after->getKey()];

        if ($changeType === 'promotion') {
            EmploymentEvent::dispatch('employee.promoted', $employee, $after, $context);
        } elseif ($changeType === 'transfer') {
            EmploymentEvent::dispatch('employee.transferred', $employee, $after, $context);
        }

        if ($before === null) {
            return;
        }

        foreach (['department_id' => 'employee.department_changed', 'designation_id' => 'employee.designation_changed', 'location_id' => 'employee.location_changed', 'company_id' => 'employee.company_changed'] as $column => $event) {
            if ((int) $before->getAttribute($column) !== (int) $after->getAttribute($column)) {
                EmploymentEvent::dispatch($event, $employee, $after, $context + ['before' => $before->getAttribute($column), 'after' => $after->getAttribute($column)]);
            }
        }
    }

    /** @return array<int, array{field: string, before: mixed, after: mixed}> */
    private function diff(?EmployeePosition $before, EmployeePosition $after): array
    {
        $changes = [];

        foreach (EmployeePosition::DIMENSIONS as $column => [$relation]) {
            $old = $before?->getAttribute($column);
            $new = $after->getAttribute($column);

            if ((int) $old !== (int) $new) {
                $changes[] = ['field' => $relation, 'before' => $this->nameOf($before, $relation), 'after' => $this->nameOf($after, $relation)];
            }
        }

        return $changes;
    }

    private function writeTimeline(Employee $employee, ?EmployeePosition $before, EmployeePosition $after, string $changeType, Carbon $from, ?string $reason): void
    {
        $label = config("peopleos.people.position_change_types.{$changeType}", ucfirst($changeType));
        $lines = array_map(
            fn (array $c) => sprintf('%s: %s → %s', $this->labelFor($c['field']), $c['before'] ?? '—', $c['after'] ?? '—'),
            $this->diff($before, $after),
        );

        $this->timeline->record(
            $employee,
            'position',
            $before === null ? 'Position assigned' : $label,
            $from,
            $lines === [] ? $reason : implode("\n", $lines).($reason ? "\nReason: {$reason}" : ''),
            $after,
            ['change_type' => $changeType],
        );
    }

    private function nameOf(?EmployeePosition $position, string $relation): ?string
    {
        return $position?->{$relation}()->value('name');
    }

    private function labelFor(string $relation): string
    {
        foreach (EmployeePosition::DIMENSIONS as [$rel, $label]) {
            if ($rel === $relation) {
                return $label;
            }
        }

        return $relation;
    }
}
