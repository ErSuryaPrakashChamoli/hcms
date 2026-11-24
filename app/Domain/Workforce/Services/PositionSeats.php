<?php

namespace App\Domain\Workforce\Services;

use App\Domain\Employment\Contracts\PositionAssignmentGuard;
use App\Domain\Employment\Exceptions\PositionUnavailableException;
use App\Domain\Employment\Models\Employee;
use App\Domain\Employment\Models\EmployeePosition;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Scopes\AccessScope;
use App\Domain\Identity\Services\AccessScopes;
use App\Domain\Organisation\Models\Company;
use App\Domain\Workforce\Events\WorkforceEvent;
use App\Domain\Workforce\Models\Position;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * Phase 10 implementation of the employment boundary: assigning an employee to a position locks the
 * position row, re-validates it (in force, open, not frozen / abolished, capacity and FTE left from
 * the effective date) and returns its dimensions; vacating is explicit; any other employment change
 * carries the seat forward unchanged. Nobody is moved, promoted or terminated here.
 */
final class PositionSeats implements PositionAssignmentGuard
{
    public function __construct(private readonly PositionOccupancy $occupancy) {}

    public function resolve(Employee $employee, ?EmployeePosition $current, array $attributes, CarbonInterface $from): array
    {
        $from = Carbon::parse($from)->startOfDay();
        $previousPositionId = $current?->position_id ? (int) $current->position_id : null;

        if (filled($attributes['position_id'] ?? null)) {
            $positionId = (int) $attributes['position_id'];
            $fte = filled($attributes['fte'] ?? null) ? (float) $attributes['fte'] : null;
            $position = Position::query()->withoutGlobalScope(AccessScope::class)->whereKey($positionId)->lockForUpdate()->first();
            if ($position === null) {
                throw new PositionUnavailableException('That position does not exist in this organisation.');
            }
            // Defence in depth: a scoped user assigns only to positions inside their organisation scope.
            $actor = auth()->user();
            if ($actor instanceof User && ! app(AccessScopes::class)->allows($actor, $position)) {
                throw new PositionUnavailableException('That position is outside your organisation scope.');
            }
            $version = $this->occupancy->assertCanAssign($position, $employee, $from, $fte);
            $this->assertSameCompany($position, $version->company_id !== null ? (int) $version->company_id : ($position->company_id !== null ? (int) $position->company_id : null), $current, $attributes);
            $dimensions = collect(['company_id', 'location_id', 'business_unit_id', 'division_id', 'department_id', 'team_id', 'designation_id', 'grade_id', 'employment_type_id', 'cost_centre_id'])
                ->mapWithKeys(fn ($column) => [$column => $version->getAttribute($column) !== null ? (int) $version->getAttribute($column) : null])->filter()->all();
            if ($previousPositionId !== $positionId) {
                WorkforceEvent::dispatch('workforce.position.occupied', $employee, $position, ['code' => $position->code, 'effective_date' => $from->toDateString()], array_filter([(int) $position->created_by]));
                $this->announceVacated($previousPositionId, $employee, $from);
            }

            return ['position_id' => $positionId, 'fte' => $fte, 'dimensions' => $dimensions];
        }

        if (! empty($attributes['vacate_position'])) {
            $this->announceVacated($previousPositionId, $employee, $from);

            return ['position_id' => null, 'fte' => null, 'dimensions' => []];
        }

        $this->assertHeldSeatMoves($previousPositionId, $attributes, $from);

        return ['position_id' => $previousPositionId, 'fte' => $current?->fte !== null ? (float) $current->fte : null, 'dimensions' => []];
    }

    /**
     * Master-relationships R1: a seat belongs to one company. A person is placed only in a seat of the company they are
     * placed in (the one chosen, else the one they work for), so a move to another company is always stated, never
     * implied by picking a seat. A new hire has no company yet: the seat's company is theirs.
     */
    private function assertSameCompany(Position $position, ?int $seatCompany, ?EmployeePosition $current, array $attributes): void
    {
        $chosen = filled($attributes['company_id'] ?? null) ? (int) $attributes['company_id'] : null;
        $target = $chosen ?? ($current?->company_id !== null ? (int) $current->company_id : null);
        if ($seatCompany === null || $target === null || $target === $seatCompany) {
            return;
        }
        [$seat, $theirs] = [self::companyName($seatCompany), self::companyName($target)];
        throw new PositionUnavailableException($chosen !== null
            ? "Position {$position->code} belongs to {$seat}, not {$theirs}. Choose a position of {$theirs}, or choose {$seat} as the company."
            : "Position {$position->code} belongs to {$seat}, but this person works for {$theirs}. A move to another company is never implied by a position: choose {$seat} as the company too.");
    }

    /** Master-relationships R1: a move to another company does not carry the old company's seat along. */
    private function assertHeldSeatMoves(?int $heldPositionId, array $attributes, Carbon $from): void
    {
        if ($heldPositionId === null || blank($attributes['company_id'] ?? null)) {
            return;
        }
        $held = Position::query()->withoutGlobalScope(AccessScope::class)->find($heldPositionId);
        $heldCompany = $held?->versionOn($from)?->company_id ?? $held?->company_id;
        $target = (int) $attributes['company_id'];
        if ($held === null || $heldCompany === null || (int) $heldCompany === $target) {
            return;
        }
        [$seat, $theirs] = [self::companyName((int) $heldCompany), self::companyName($target)];
        throw new PositionUnavailableException("This person holds position {$held->code} of {$seat}. To move them to {$theirs}, vacate it or choose a position of {$theirs}.");
    }

    /** A company the reader may see, by name; otherwise only that it is another company (scopes are respected). */
    private static function companyName(int $id): string
    {
        return (string) (Company::query()->whereKey($id)->value('name') ?? 'another company');
    }

    private function announceVacated(?int $positionId, Employee $employee, Carbon $from): void
    {
        if ($positionId === null) {
            return;
        }
        $position = Position::query()->withoutGlobalScope(AccessScope::class)->find($positionId);
        if ($position !== null) {
            WorkforceEvent::dispatch('workforce.position.vacated', null, $position, ['code' => $position->code, 'effective_date' => $from->toDateString()], array_filter([(int) $position->created_by]));
        }
    }
}
