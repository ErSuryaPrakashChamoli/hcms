<?php

namespace App\Domain\Organisation\Services;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Employment\Models\Employee;
use App\Domain\Employment\Models\EmployeePosition;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Scopes\AccessScope;
use App\Domain\Organisation\Models\EmployeeEstablishmentAssignment;
use App\Domain\Organisation\Models\Establishment;
use App\Domain\Organisation\Models\Location;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Employee ↔ establishment history (Phase 5 Part C). One assignment per employee per date, no
 * overlaps, history never rewritten: a new assignment closes the open one the day before and may
 * not start on or before any existing assignment.
 */
final class EstablishmentAssignments
{
    public function __construct(private readonly LegalEntities $entities) {}

    public function assign(Employee $employee, Establishment $establishment, CarbonInterface|string $from, string $reason, string $source = 'manual', ?User $actor = null): EmployeeEstablishmentAssignment
    {
        $from = Carbon::parse($from)->startOfDay();

        if (blank(trim($reason))) {
            throw new RuntimeException('A reason is required to assign an establishment.');
        }
        if (! in_array($source, EmployeeEstablishmentAssignment::SOURCES, true)) {
            throw new RuntimeException("Unknown assignment source [{$source}].");
        }
        if (! $establishment->isEffectiveOn($from) || ($establishment->status?->value ?? 'active') !== 'active') {
            throw new RuntimeException("{$establishment->name} is not an active establishment on {$from->toDateString()}.");
        }

        $companyId = $this->positionOn($employee, $from)?->company_id;
        if ($companyId !== null && (int) $companyId !== (int) $establishment->company_id) {
            throw new RuntimeException('The establishment belongs to a different company than the employee\'s position on that date.');
        }

        return DB::transaction(function () use ($employee, $establishment, $from, $reason, $source, $actor) {
            Employee::query()->withoutGlobalScope(AccessScope::class)->whereKey($employee->getKey())->lockForUpdate()->first();

            $rows = EmployeeEstablishmentAssignment::query()->withoutGlobalScope(AccessScope::class)
                ->where('employee_id', $employee->getKey())->orderBy('effective_from')->get();

            if ($rows->contains(fn ($row) => $row->effective_from->gte($from))) {
                throw new RuntimeException('An assignment already starts on or after '.$from->toDateString().'; establishment history cannot be rewritten.');
            }

            $open = $rows->first(fn ($row) => $row->effective_to === null || $row->effective_to->gte($from));
            if ($open !== null) {
                if ($open->effective_to !== null) {
                    throw new RuntimeException('The assignment ending '.$open->effective_to->toDateString().' overlaps; history cannot be rewritten.');
                }
                $this->close($open, $from->copy()->subDay(), 'Superseded: '.$reason, $actor);
            }

            $assignment = new EmployeeEstablishmentAssignment([
                'employee_id' => $employee->getKey(),
                'company_id' => $establishment->company_id,
                'establishment_id' => $establishment->getKey(),
                'effective_from' => $from->toDateString(),
                'assignment_reason' => $reason,
                'source' => $source,
                'created_by' => $actor?->getKey() ?? auth()->id(),
            ]);
            $assignment->withAuditReason($reason)->withAuditAction(AuditAction::EstablishmentAssigned)->save();

            return $assignment;
        });
    }

    public function close(EmployeeEstablishmentAssignment $assignment, CarbonInterface|string $to, string $reason, ?User $actor = null): EmployeeEstablishmentAssignment
    {
        $to = Carbon::parse($to)->startOfDay();

        if ($to->lt($assignment->effective_from)) {
            throw new RuntimeException('An assignment cannot end before it starts.');
        }

        $assignment->withAuditReason($reason)->update([
            'effective_to' => $to->toDateString(),
            'closed_by' => $actor?->getKey() ?? auth()->id(),
            'closed_at' => now(),
            'closure_reason' => $reason,
        ]);

        return $assignment;
    }

    /**
     * The establishment an employee works for on a date and how it was found: an explicit
     * assignment, the position's location, or the company's principal establishment.
     *
     * @return array{establishment: ?Establishment, source: ?string}
     */
    public function resolve(Employee|int $employee, CarbonInterface|string $on): array
    {
        $employeeId = $employee instanceof Employee ? $employee->getKey() : $employee;
        $day = Carbon::parse($on)->toDateString();

        $assignment = EmployeeEstablishmentAssignment::query()->withoutGlobalScope(AccessScope::class)
            ->with(['establishment' => fn ($q) => $q->withoutGlobalScope(AccessScope::class)])
            ->where('employee_id', $employeeId)->effectiveOn($day)->orderByDesc('effective_from')->first();

        if ($assignment?->establishment !== null) {
            return ['establishment' => $assignment->establishment, 'source' => 'assignment'];
        }

        $position = $this->positionOn($employeeId, $day);

        if ($position?->location_id) {
            $establishmentId = Location::query()->withoutGlobalScope(AccessScope::class)->whereKey($position->location_id)->value('establishment_id');
            if ($establishmentId && ($establishment = Establishment::query()->withoutGlobalScope(AccessScope::class)->find($establishmentId))) {
                return ['establishment' => $establishment, 'source' => 'location'];
            }
        }

        if ($position?->company_id && ($establishment = $this->entities->primaryEstablishment((int) $position->company_id))) {
            return ['establishment' => $establishment, 'source' => 'company_primary'];
        }

        return ['establishment' => null, 'source' => null];
    }

    /** Give an employee without history an assignment derived from their position (ADR-0001 transition). */
    public function backfill(Employee $employee): ?EmployeeEstablishmentAssignment
    {
        if (EmployeeEstablishmentAssignment::query()->withoutGlobalScope(AccessScope::class)->where('employee_id', $employee->getKey())->exists()) {
            return null;
        }

        $position = EmployeePosition::query()->withoutGlobalScope(AccessScope::class)
            ->where('employee_id', $employee->getKey())->orderBy('effective_from')->orderBy('id')->first();
        if ($position === null) {
            return null;
        }

        $from = $employee->joining_date && $employee->joining_date->lt($position->effective_from) ? $employee->joining_date : $position->effective_from;
        $resolved = $this->resolve($employee, $position->effective_from);

        if ($resolved['establishment'] === null) {
            return null;
        }

        $assignment = new EmployeeEstablishmentAssignment([
            'employee_id' => $employee->getKey(),
            'company_id' => $resolved['establishment']->company_id,
            'establishment_id' => $resolved['establishment']->getKey(),
            'effective_from' => $from->toDateString(),
            'assignment_reason' => 'ADR-0001 transition from the position ('.$resolved['source'].')',
            'source' => 'backfill',
        ]);
        $assignment->withAuditReason('ADR-0001 transition')->save();

        return $assignment;
    }

    private function positionOn(Employee|int $employee, CarbonInterface|string $on): ?EmployeePosition
    {
        return EmployeePosition::query()->withoutGlobalScope(AccessScope::class)
            ->where('employee_id', $employee instanceof Employee ? $employee->getKey() : $employee)
            ->effectiveOn($on)->orderByDesc('effective_from')->orderByDesc('id')->first();
    }
}
