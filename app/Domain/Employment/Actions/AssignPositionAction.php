<?php

namespace App\Domain\Employment\Actions;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Services\AuditRecorder;
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

        return DB::transaction(function () use ($employee, $dimensions, $changeType, $from, $reason) {
            $current = $employee->positions()->effectiveOn($from)->first();

            if ($current !== null && $current->effective_from->gte($from)) {
                throw new InvalidArgumentException('The new position must start after the current one ('.$current->effective_from->toDateString().').');
            }

            // Carry forward unchanged dimensions so a transfer only needs to state what changed.
            $merged = $current
                ? array_merge(array_intersect_key($current->getAttributes(), EmployeePosition::DIMENSIONS), $dimensions)
                : $dimensions;

            if (empty($merged['company_id'])) {
                throw new InvalidArgumentException('A position must belong to a company.');
            }

            $current?->withAuditReason($reason)->update(['effective_to' => $from->copy()->subDay()]);

            $position = new EmployeePosition($merged + [
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

            return $position;
        });
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
