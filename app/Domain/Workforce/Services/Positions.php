<?php

namespace App\Domain\Workforce\Services;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Scopes\AccessScope;
use App\Domain\Organisation\Models\Designation;
use App\Domain\Workforce\Events\WorkforceEvent;
use App\Domain\Workforce\Models\Position;
use App\Domain\Workforce\Models\PositionChangeRequest;
use App\Domain\Workforce\Models\PositionVersion;
use Carbon\CarbonInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Phase 10 position management. A position is created as a draft (edited in place), then moves
 * through the configured lifecycle (peopleos.workforce.position_transitions); every change after
 * draft is a new effective-dated version, the previous one closed the day before. Approval is a second
 * person's act. "Occupied" is never set here — it is derived from employee assignments. Abolishing or
 * closing an occupied position is refused; nobody is moved or terminated.
 */
final class Positions
{
    /** Attribute groups, used for configurable change approval (peopleos.workforce.change_approval). */
    public const CATEGORIES = [
        'headcount' => ['headcount', 'occupancy_mode', 'fte_capacity'],
        'fte' => ['fte', 'standard_hours'],
        'organisation' => ['organisation_node_id', 'parent_position_id'],
        'location' => ['location_id', 'establishment_id'],
        'grade' => ['grade_id'],
        'definition' => ['title', 'designation_id', 'job_family_id', 'career_track_id', 'employment_type_id', 'worker_type', 'cost_centre_id'],
    ];

    /** Lifecycle moves that need a reason. */
    private const REASON_REQUIRED = ['frozen', 'on_hold', 'abolished', 'closed'];

    public function __construct(
        private readonly OrganisationDimensions $dimensions,
        private readonly PositionHierarchy $hierarchy,
        private readonly PositionOccupancy $occupancy,
        private readonly AuditRecorder $audit,
    ) {}

    /** @param  array<string, mixed>  $data */
    public function create(array $data, User $actor): Position
    {
        $this->authorise($actor, 'workforce.manage');
        $from = Carbon::parse($data['effective_from'] ?? now())->startOfDay();
        if (blank($data['code'] ?? null)) {
            throw new RuntimeException('A position needs a code.');
        }
        $definition = $this->definition($data, null);
        $this->hierarchy->assertParent(null, $definition['parent_position_id'], (int) $definition['company_id'], $from);

        try {
            return DB::transaction(function () use ($data, $definition, $from, $actor) {
                $position = Position::query()->create([
                    'company_id' => $definition['company_id'], 'code' => $data['code'], 'status' => 'draft', 'first_effective_from' => $from->toDateString(),
                    'source_plan_line_id' => $data['source_plan_line_id'] ?? null, 'created_by' => $actor->id, ...$this->mirrorAttributes($definition),
                ]);
                $version = PositionVersion::query()->create([
                    ...$definition, 'position_id' => $position->id, 'version' => 1, 'status' => 'draft', 'effective_from' => $from->toDateString(),
                    'change_type' => 'created', 'reason' => $data['reason'] ?? null, 'created_by' => $actor->id,
                ]);
                $position->update(['current_version_id' => $version->id]);
                WorkforceEvent::dispatch('workforce.position.created', null, $position, ['code' => $position->code, 'title' => $position->title]);

                return $position->refresh();
            });
        } catch (UniqueConstraintViolationException) {
            throw new RuntimeException("Position code {$data['code']} is already used.");
        }
    }

    /** Edit a draft in place (a draft is not yet workforce history). @param  array<string, mixed>  $data */
    public function updateDraft(Position $position, array $data, User $actor): Position
    {
        $this->authorise($actor, 'workforce.manage');

        return DB::transaction(function () use ($position, $data) {
            $locked = $this->lock($position);
            $latest = $this->latest($locked);
            if ($latest->status !== 'draft') {
                throw new RuntimeException('Only a draft position is edited in place; change an approved position from an effective date.');
            }
            $definition = $this->definition($data, $latest);
            if ((int) $definition['company_id'] !== (int) $locked->company_id) {
                throw new RuntimeException('A position keeps its company.');
            }
            $from = Carbon::parse($data['effective_from'] ?? $latest->effective_from)->startOfDay();
            $this->hierarchy->assertParent($locked, $definition['parent_position_id'], (int) $locked->company_id, $from);
            $latest->update([...$definition, 'effective_from' => $from->toDateString()]);
            $locked->update([...$this->mirrorAttributes($definition), 'first_effective_from' => $from->toDateString()]);
            $position->setRawAttributes($locked->getAttributes(), true);

            return $position;
        });
    }

    /**
     * Move the position through its lifecycle from an effective date (default: today, never before
     * its latest version). Approval needs workforce.approve and a different person from the proposer.
     */
    public function transition(Position $position, string $to, ?string $reason, User $actor, CarbonInterface|string|null $effectiveFrom = null, ?int $expectedLockVersion = null): PositionVersion
    {
        if ($to === 'occupied') {
            throw new RuntimeException('Occupancy is derived from employee assignments; assign an employee instead.');
        }
        if (! array_key_exists($to, config('peopleos.workforce.position_statuses'))) {
            throw new RuntimeException("Unknown position status '{$to}'.");
        }
        $approval = in_array($to, config('peopleos.workforce.approval_statuses', ['approved']), true);
        $this->authorise($actor, $approval ? 'workforce.approve' : 'workforce.manage');
        if ((in_array($to, self::REASON_REQUIRED, true) || $position->status === 'frozen') && trim((string) $reason) === '') {
            throw new RuntimeException("Moving a position to {$to} needs a reason.");
        }

        return DB::transaction(function () use ($position, $to, $reason, $actor, $effectiveFrom, $expectedLockVersion, $approval) {
            $locked = $this->lock($position);
            if ($expectedLockVersion !== null && (int) $locked->lock_version !== $expectedLockVersion) {
                throw new RuntimeException('The position was changed meanwhile. Reload and try again.');
            }
            $latest = $this->latest($locked);
            $from = $this->effectiveFrom($latest, $effectiveFrom);
            if (! in_array($to, config("peopleos.workforce.position_transitions.{$latest->status}", []), true)) {
                throw new RuntimeException("A position cannot move from {$latest->status} to {$to}.");
            }
            if ($approval && (int) $latest->created_by === (int) $actor->id) {
                throw new RuntimeException('The person who proposed a position cannot approve it.');
            }
            if (in_array($to, ['abolished', 'closed'], true)) {
                [$seats] = $this->occupancy->usedFrom($locked, $from, null, (float) $latest->fte);
                if ($seats > 0) {
                    throw new RuntimeException("Position {$locked->code} is occupied from {$from->toDateString()}; move or vacate the occupant through employment first. Nobody is moved automatically.");
                }
            }
            $version = $this->newVersion($locked, $latest, [], $to, 'status', $from, $reason, $actor);
            $this->audit->record(AuditAction::StatusChange, 'workforce', $locked, [['field' => 'status', 'before' => $latest->status, 'after' => $to]], $reason, actor: $actor, effectiveDate: $from, metadata: ['event' => 'position_'.$to, 'version' => $version->version]);
            $event = match (true) {
                $to === 'open' && $latest->status === 'frozen' => 'unfrozen',
                $to === 'open' => 'opened',
                default => $to,
            };
            WorkforceEvent::dispatch("workforce.position.{$event}", null, $locked, ['code' => $locked->code, 'title' => $locked->title, 'effective_date' => $from->toDateString()], array_filter([(int) $locked->created_by]));
            $position->setRawAttributes($locked->refresh()->getAttributes(), true);

            return $version;
        });
    }

    /**
     * Change a position's definition from an effective date. Changes in a category that configuration
     * marks for approval become a pending change request; the rest apply as a new version now.
     *
     * @param  array<string, mixed>  $changes
     */
    public function change(Position $position, array $changes, CarbonInterface|string|null $effectiveFrom, string $reason, User $actor): PositionVersion|PositionChangeRequest
    {
        $this->authorise($actor, 'workforce.manage');
        if (trim($reason) === '') {
            throw new RuntimeException('A position change needs a reason.');
        }
        $latest = $this->latest($position);
        if ($latest->status === 'draft') {
            $this->updateDraft($position, $changes, $actor);

            return $this->latest($position);
        }
        $categories = $this->categoriesOf($latest, $changes);
        if ($categories === []) {
            throw new RuntimeException('Nothing in the position definition changes.');
        }
        $from = $this->effectiveFrom($latest, $effectiveFrom);
        $needsApproval = collect($categories)->contains(fn ($c) => (bool) config("peopleos.workforce.change_approval.{$c}", true));
        if (! $needsApproval) {
            return $this->apply($position, $changes, $from, $reason, $actor);
        }
        $request = PositionChangeRequest::query()->create([
            'position_id' => $position->id, 'changes' => array_intersect_key($changes, array_flip($this->editable())), 'categories' => $categories,
            'effective_from' => $from->toDateString(), 'reason' => $reason, 'requested_by' => $actor->id,
        ]);
        WorkforceEvent::dispatch('workforce.position.change_requested', null, $request, ['code' => $position->code, 'categories' => implode(', ', $categories)]);

        return $request;
    }

    /** Approve (applies a new version) or reject a pending change request — never the requester's own. */
    public function decide(PositionChangeRequest $request, bool $approve, ?string $note, User $actor): PositionChangeRequest
    {
        $this->authorise($actor, 'workforce.approve');
        if ((int) $request->requested_by === (int) $actor->id) {
            throw new RuntimeException('The person who requested a position change cannot decide it.');
        }
        if (! $approve && trim((string) $note) === '') {
            throw new RuntimeException('Rejecting a position change needs a note.');
        }

        return DB::transaction(function () use ($request, $approve, $note, $actor) {
            $current = PositionChangeRequest::query()->whereKey($request->id)->lockForUpdate()->firstOrFail();
            if ($current->status !== 'pending') {
                throw new RuntimeException('This change request was already decided.');
            }
            $applied = $approve ? $this->apply(Position::query()->withoutGlobalScope(AccessScope::class)->findOrFail($current->position_id), $current->changes, Carbon::parse($current->effective_from), $current->reason, $actor) : null;
            $request->setRawAttributes($current->getAttributes(), true);
            $request->update(['status' => $approve ? 'approved' : 'rejected', 'decided_by' => $actor->id, 'decided_at' => now(), 'decision_note' => $note, 'applied_version_id' => $applied?->id]);
            $this->audit->record($approve ? AuditAction::Approved : AuditAction::Rejected, 'workforce', $request, [['field' => 'status', 'before' => 'pending', 'after' => $request->status]], $note, actor: $actor, metadata: ['event' => 'position_change_'.$request->status]);

            return $request;
        });
    }

    public function cancelRequest(PositionChangeRequest $request, User $actor): PositionChangeRequest
    {
        if ((int) $request->requested_by !== (int) $actor->id && ! $actor->hasPermission('workforce.approve')) {
            throw new RuntimeException('Only the requester or an approver cancels a change request.');
        }

        return DB::transaction(function () use ($request) {
            $current = PositionChangeRequest::query()->whereKey($request->id)->lockForUpdate()->firstOrFail();
            if ($current->status !== 'pending') {
                throw new RuntimeException('This change request was already decided.');
            }
            $request->setRawAttributes($current->getAttributes(), true);
            $request->update(['status' => 'cancelled']);

            return $request;
        });
    }

    /** The latest version (the lifecycle runs on it; it may start in the future). */
    public function latest(Position $position): PositionVersion
    {
        return PositionVersion::query()->withoutGlobalScope(AccessScope::class)->where('position_id', $position->id)->orderByDesc('version')->firstOrFail();
    }

    /** @return list<string> */
    public function editable(): array
    {
        return array_merge(...array_values(self::CATEGORIES));
    }

    /** @param  array<string, mixed>  $changes */
    private function apply(Position $position, array $changes, Carbon $from, string $reason, User $actor): PositionVersion
    {
        return DB::transaction(function () use ($position, $changes, $from, $reason, $actor) {
            $locked = $this->lock($position);
            $latest = $this->latest($locked);
            if (in_array($latest->status, ['abolished', 'closed'], true)) {
                throw new RuntimeException('An abolished or closed position is not changed.');
            }
            if ($from->toDateString() < $latest->effective_from->toDateString()) {
                throw new RuntimeException('A change cannot start before the position\'s latest version ('.$latest->effective_from->toDateString().').');
            }
            $definition = $this->definition($changes, $latest);
            if ((int) $definition['company_id'] !== (int) $locked->company_id) {
                throw new RuntimeException('A position keeps its company; abolish it and create another.');
            }
            $this->hierarchy->assertParent($locked, $definition['parent_position_id'], (int) $locked->company_id, $from);
            [$seats, $fte] = $this->occupancy->usedFrom($locked, $from, null, (float) $definition['fte']);
            if ($seats > (int) $definition['headcount'] || $fte > (float) $definition['fte_capacity'] + 0.0001) {
                throw new RuntimeException("The new capacity is below the current occupancy ({$seats} seat(s), {$fte} FTE) from {$from->toDateString()}.");
            }
            $version = $this->newVersion($locked, $latest, $definition, $latest->status, 'definition', $from, $reason, $actor);
            WorkforceEvent::dispatch('workforce.position.changed', null, $locked, ['code' => $locked->code, 'effective_date' => $from->toDateString()]);
            $position->setRawAttributes($locked->refresh()->getAttributes(), true);

            return $version;
        });
    }

    /** @param  array<string, mixed>  $definition */
    private function newVersion(Position $locked, PositionVersion $latest, array $definition, string $status, string $changeType, Carbon $from, ?string $reason, User $actor): PositionVersion
    {
        // A status change copies the latest definition unchanged; a definition change brings its own.
        $base = $definition !== [] ? $definition : collect([...$this->editable(), 'company_id', 'legal_entity_id', ...OrganisationDimensions::UNIT_COLUMNS])
            ->mapWithKeys(fn ($f) => [$f => $latest->getAttributes()[$f] ?? null])->all();
        $latest->update(['effective_to' => $from->copy()->subDay()->toDateString()]);
        $version = PositionVersion::query()->create([
            ...$base, 'position_id' => $locked->id, 'version' => $latest->version + 1, 'status' => $status, 'effective_from' => $from->toDateString(),
            'change_type' => $changeType, 'reason' => $reason, 'created_by' => $actor->id,
        ]);
        $locked->update([...$this->mirrorAttributes($version->getAttributes()), 'status' => $status, 'current_version_id' => $version->id]);

        return $version;
    }

    /**
     * The full definition: the base version's values, overlaid with the given changes, with the
     * organisation dimensions derived from the node and the establishment from the location.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function definition(array $data, ?PositionVersion $base): array
    {
        $fields = [...$this->editable(), 'company_id'];
        $definition = $base ? collect($fields)->mapWithKeys(fn ($f) => [$f => $base->getAttributes()[$f] ?? null])->all() : array_fill_keys($fields, null);
        foreach ($fields as $field) {
            if (array_key_exists($field, $data)) {
                $definition[$field] = $data[$field] === '' ? null : $data[$field];
            }
        }
        if (array_key_exists('designation_id', $data) && $definition['designation_id']) {
            $designation = Designation::query()->find($definition['designation_id']) ?? throw new RuntimeException('Unknown designation.');
            $definition['job_family_id'] = $data['job_family_id'] ?? $designation->job_family_id ?? $definition['job_family_id'];
            $definition['grade_id'] = $data['grade_id'] ?? $designation->grade_id ?? $definition['grade_id'];
            $definition['title'] = $definition['title'] ?: $designation->name;
        }
        $node = $this->dimensions->forNode($definition['organisation_node_id'] ? (int) $definition['organisation_node_id'] : null);
        if ($definition['organisation_node_id']) {
            if ($definition['company_id'] && $node['company_id'] && (int) $node['company_id'] !== (int) $definition['company_id']) {
                throw new RuntimeException('The organisation unit belongs to another company.');
            }
            $definition['company_id'] ??= $node['company_id'];
            if (! array_key_exists('location_id', $data) && ! $definition['location_id'] && $node['location_id']) {
                $definition['location_id'] = $node['location_id'];
            }
        }
        foreach (OrganisationDimensions::UNIT_COLUMNS as $column) {
            $definition[$column] = $node[$column];
        }
        $establishment = $this->dimensions->forLocation($definition['location_id'] ? (int) $definition['location_id'] : null, array_key_exists('establishment_id', $data) && $data['establishment_id'] ? (int) $data['establishment_id'] : null);
        $definition['establishment_id'] = $establishment['establishment_id'];
        $definition['legal_entity_id'] = $establishment['legal_entity_id'];
        if (! $definition['company_id']) {
            throw new RuntimeException('A position belongs to a company (choose a company or an organisation unit).');
        }
        if (blank($definition['title'])) {
            throw new RuntimeException('A position needs a title or a designation.');
        }
        $definition['worker_type'] ??= 'employee';
        $definition['occupancy_mode'] ??= 'single';
        $definition['headcount'] = $definition['occupancy_mode'] === 'single' ? 1 : max(1, (int) ($definition['headcount'] ?? 1));
        $definition['fte'] = (float) ($definition['fte'] ?? 1);
        $capacityGiven = array_key_exists('fte_capacity', $data) && $data['fte_capacity'] !== null && $data['fte_capacity'] !== '';
        $shapeChanged = $base === null || array_intersect(array_keys($data), ['headcount', 'fte', 'occupancy_mode']) !== [];
        if (! $capacityGiven && $shapeChanged) {
            $definition['fte_capacity'] = round($definition['headcount'] * $definition['fte'], 2);
        }
        $definition['fte_capacity'] = (float) $definition['fte_capacity'];
        $definition['parent_position_id'] = $definition['parent_position_id'] ? (int) $definition['parent_position_id'] : null;

        return $definition;
    }

    /** @return list<string> */
    private function categoriesOf(PositionVersion $latest, array $changes): array
    {
        $categories = [];
        foreach (self::CATEGORIES as $category => $fields) {
            foreach ($fields as $field) {
                if (array_key_exists($field, $changes) && (string) ($changes[$field] ?? '') !== (string) ($latest->getAttributes()[$field] ?? '')
                    && ! (is_numeric($changes[$field]) && is_numeric($latest->getAttributes()[$field] ?? null) && (float) $changes[$field] === (float) $latest->getAttributes()[$field])) {
                    $categories[] = $category;
                    break;
                }
            }
        }

        return $categories;
    }

    /** @param  array<string, mixed>  $definition */
    private function mirrorAttributes(array $definition): array
    {
        return array_intersect_key($definition, array_flip(['title', 'designation_id', 'organisation_node_id', 'business_unit_id', 'division_id', 'department_id', 'team_id', 'location_id', 'establishment_id']));
    }

    private function effectiveFrom(PositionVersion $latest, CarbonInterface|string|null $effectiveFrom): Carbon
    {
        $floor = $latest->effective_from->copy()->startOfDay();
        if ($effectiveFrom === null) {
            return now()->startOfDay()->max($floor);
        }
        $from = Carbon::parse($effectiveFrom)->startOfDay();
        if ($from->lt($floor)) {
            throw new RuntimeException('A change cannot start before the position\'s latest version ('.$floor->toDateString().').');
        }

        return $from;
    }

    private function lock(Position $position): Position
    {
        return Position::query()->withoutGlobalScope(AccessScope::class)->whereKey($position->id)->lockForUpdate()->firstOrFail();
    }

    private function authorise(User $actor, string $permission): void
    {
        if (! $actor->hasPermission($permission)) {
            throw new RuntimeException("This needs {$permission}.");
        }
    }
}
