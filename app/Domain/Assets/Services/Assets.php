<?php

namespace App\Domain\Assets\Services;

use App\Domain\Assets\Events\AssetEvent;
use App\Domain\Assets\Models\Asset;
use App\Domain\Assets\Models\AssetAssignment;
use App\Domain\Assets\Models\AssetDisposal;
use App\Domain\Assets\Models\AssetMovement;
use App\Domain\Assets\Models\AssetRepair;
use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Models\User;
use App\Domain\Lifecycle\Services\Timeline;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The asset lifecycle (§38): Procurement → Inventory → Assignment → Transfer → Repair → Return →
 * Disposal. Every transition writes a movement; custody is tracked in assignments.
 */
final class Assets
{
    public function __construct(private readonly AuditRecorder $audit, private readonly Timeline $timeline) {}

    /** @param  array<string, mixed>  $attributes */
    public function receive(array $attributes, ?User $actor = null): Asset
    {
        $attributes['status'] = 'in_stock';
        $attributes['custodian_id'] = null;

        return DB::transaction(function () use ($attributes, $actor) {
            $asset = Asset::create($attributes);
            $this->movement($asset, 'procured', ['to_location_id' => $asset->location_id, 'note' => $attributes['notes'] ?? null, 'occurred_at' => $asset->purchase_date ?? now()], $actor);

            return $asset;
        });
    }

    public function assign(Asset $asset, Employee $employee, CarbonInterface|string|null $on = null, ?string $condition = null, ?string $note = null, CarbonInterface|string|null $expectedReturn = null, ?User $actor = null): AssetAssignment
    {
        if (! $asset->isAssignable()) {
            throw new RuntimeException("Asset {$asset->asset_tag} is ".config("peopleos.assets.statuses.{$asset->status}", $asset->status).' and cannot be assigned.');
        }
        if (! $employee->lifecycle_state->isEmployed()) {
            throw new RuntimeException('Assets can only be assigned to current employees.');
        }

        return DB::transaction(function () use ($asset, $employee, $on, $condition, $note, $expectedReturn, $actor) {
            $assignment = AssetAssignment::create([
                'asset_id' => $asset->id, 'employee_id' => $employee->id, 'assigned_on' => Carbon::parse($on ?? now()),
                'expected_return_on' => $expectedReturn ? Carbon::parse($expectedReturn) : null,
                'condition_out' => $condition ?? $asset->condition, 'status' => 'active', 'note' => $note, 'assigned_by' => $actor?->id ?? auth()->id(),
            ]);
            $asset->update(['status' => 'assigned', 'custodian_id' => $employee->id, 'condition' => $condition ?? $asset->condition]);
            $this->movement($asset, 'assigned', ['to_employee_id' => $employee->id, 'note' => $note], $actor);
            $this->timeline->record($employee, 'assets', "Asset assigned: {$asset->name} [{$asset->asset_tag}]", $assignment->assigned_on, $note, $asset);
            AssetEvent::dispatch('asset.assigned', $employee, $assignment, ['asset' => $asset->name, 'tag' => $asset->asset_tag]);

            return $assignment;
        });
    }

    public function acknowledge(AssetAssignment $assignment): AssetAssignment
    {
        if (! $assignment->isActive()) {
            throw new RuntimeException('This assignment is no longer active.');
        }
        $assignment->update(['acknowledged_at' => now()]);

        return $assignment;
    }

    public function transfer(Asset $asset, Employee $to, ?string $note = null, ?User $actor = null): AssetAssignment
    {
        if ($asset->status !== 'assigned') {
            throw new RuntimeException('Only an assigned asset can be transferred.');
        }

        return DB::transaction(function () use ($asset, $to, $note, $actor) {
            $from = $asset->custodian_id;
            $this->closeAssignment($asset, $asset->condition, 'Transferred', $actor, false);
            $asset->update(['status' => 'in_stock', 'custodian_id' => null]);
            $assignment = $this->assign($asset->refresh(), $to, now(), $asset->condition, $note, null, $actor);
            $movement = $asset->movements()->first();
            $movement->update(['type' => 'transferred', 'from_employee_id' => $from]);
            AssetEvent::dispatch('asset.transferred', $to, $assignment, ['asset' => $asset->name, 'tag' => $asset->asset_tag, 'from_employee_id' => $from]);

            return $assignment;
        });
    }

    /** Take the asset back into stock (or straight into repair when returned damaged). */
    public function returnAsset(Asset $asset, ?string $conditionIn = null, ?string $note = null, ?User $actor = null, bool $toRepair = false): Asset
    {
        if ($asset->status !== 'assigned') {
            throw new RuntimeException('Only an assigned asset can be returned.');
        }

        return DB::transaction(function () use ($asset, $conditionIn, $note, $actor, $toRepair) {
            $employee = $asset->custodian()->with('person')->first();
            $this->closeAssignment($asset, $conditionIn, $note, $actor, true);
            $asset->update(['status' => $toRepair ? 'in_repair' : 'in_stock', 'custodian_id' => null, 'condition' => $conditionIn ?? $asset->condition]);
            if ($employee) {
                $this->timeline->record($employee, 'assets', "Asset returned: {$asset->name} [{$asset->asset_tag}]", now(), $note, $asset);
                AssetEvent::dispatch('asset.returned', $employee, $asset, ['asset' => $asset->name, 'tag' => $asset->asset_tag, 'condition' => $conditionIn]);
            }

            return $asset->refresh();
        });
    }

    private function closeAssignment(Asset $asset, ?string $conditionIn, ?string $note, ?User $actor, bool $recordMovement): void
    {
        $assignment = $asset->currentAssignment()->first();
        $assignment?->update(['status' => 'returned', 'returned_on' => now(), 'condition_in' => $conditionIn ?? $asset->condition, 'return_note' => $note]);

        if ($recordMovement) {
            $this->movement($asset, 'returned', ['from_employee_id' => $asset->custodian_id, 'to_location_id' => $asset->location_id, 'note' => $note], $actor);
        }
    }

    public function sendForRepair(Asset $asset, string $issue, ?string $vendor = null, ?User $actor = null): AssetRepair
    {
        if (! in_array($asset->status, ['in_stock', 'assigned'], true)) {
            throw new RuntimeException('Only stocked or assigned assets can be sent for repair.');
        }

        return DB::transaction(function () use ($asset, $issue, $vendor, $actor) {
            if ($asset->status === 'assigned') {
                $this->returnAsset($asset, 'not_working', "Sent for repair: {$issue}", $actor, true);
            }
            $repair = AssetRepair::create(['asset_id' => $asset->id, 'vendor' => $vendor, 'issue' => $issue, 'sent_on' => now(), 'status' => 'open']);
            $asset->update(['status' => 'in_repair', 'custodian_id' => null]);
            $this->movement($asset, 'repair_out', ['note' => $issue], $actor);
            AssetEvent::dispatch('asset.repair', null, $repair, ['asset' => $asset->name, 'tag' => $asset->asset_tag, 'issue' => $issue]);

            return $repair;
        });
    }

    public function repaired(AssetRepair $repair, ?float $cost = null, ?string $resolution = null, string $condition = 'good', ?User $actor = null): Asset
    {
        if ($repair->status !== 'open') {
            throw new RuntimeException('This repair is already closed.');
        }

        return DB::transaction(function () use ($repair, $cost, $resolution, $condition, $actor) {
            $repair->update(['status' => 'closed', 'returned_on' => now(), 'cost' => $cost, 'resolution' => $resolution]);
            $asset = $repair->asset()->firstOrFail();
            $asset->update(['status' => 'in_stock', 'condition' => $condition]);
            $this->movement($asset, 'repair_in', ['note' => $resolution], $actor);

            return $asset;
        });
    }

    public function markLost(Asset $asset, string $note, ?User $actor = null): Asset
    {
        return DB::transaction(function () use ($asset, $note, $actor) {
            $employee = $asset->custodian()->with('person')->first();
            if ($asset->status === 'assigned') {
                $this->closeAssignment($asset, 'not_working', $note, $actor, false);
            }
            $asset->update(['status' => 'lost', 'custodian_id' => null]);
            $this->movement($asset, 'lost', ['from_employee_id' => $employee?->id, 'note' => $note], $actor);
            $this->audit->record(AuditAction::Update, 'assets', $asset, [['field' => 'status', 'before' => 'assigned', 'after' => 'lost']], $note, actor: $actor);
            AssetEvent::dispatch('asset.lost', $employee, $asset, ['asset' => $asset->name, 'tag' => $asset->asset_tag]);

            return $asset;
        });
    }

    public function found(Asset $asset, ?string $note = null, ?User $actor = null): Asset
    {
        if ($asset->status !== 'lost') {
            throw new RuntimeException('Only a lost asset can be found.');
        }
        $asset->update(['status' => 'in_stock']);
        $this->movement($asset, 'found', ['note' => $note], $actor);

        return $asset;
    }

    public function retire(Asset $asset, string $reason, ?User $actor = null): Asset
    {
        if (! in_array($asset->status, ['in_stock', 'in_repair', 'lost'], true)) {
            throw new RuntimeException('Return the asset to stock before retiring it.');
        }
        $asset->withAuditReason($reason)->update(['status' => 'retired', 'custodian_id' => null]);
        $this->movement($asset, 'retired', ['note' => $reason], $actor);

        return $asset;
    }

    public function dispose(Asset $asset, string $method, CarbonInterface|string|null $on = null, ?float $value = null, ?string $reference = null, ?string $note = null, ?User $actor = null): AssetDisposal
    {
        if (! in_array($asset->status, ['in_stock', 'retired', 'in_repair', 'lost'], true)) {
            throw new RuntimeException('An assigned asset cannot be disposed of; return it first.');
        }

        return DB::transaction(function () use ($asset, $method, $on, $value, $reference, $note, $actor) {
            $disposal = AssetDisposal::create(['asset_id' => $asset->id, 'method' => $method, 'disposed_on' => Carbon::parse($on ?? now()), 'value' => $value, 'reference' => $reference, 'note' => $note, 'approved_by' => $actor?->id ?? auth()->id()]);
            $asset->update(['status' => 'disposed', 'custodian_id' => null]);
            $this->movement($asset, 'disposed', ['note' => $note ?? $method], $actor);
            $this->audit->record(AuditAction::Update, 'assets', $asset, [['field' => 'status', 'before' => 'in_stock', 'after' => 'disposed']], $note ?? $method, actor: $actor, metadata: ['method' => $method, 'value' => $value]);
            AssetEvent::dispatch('asset.disposed', null, $disposal, ['asset' => $asset->name, 'tag' => $asset->asset_tag, 'method' => $method]);

            return $disposal;
        });
    }

    public function relocate(Asset $asset, ?int $locationId, ?string $note = null, ?User $actor = null): Asset
    {
        $from = $asset->location_id;
        $asset->update(['location_id' => $locationId]);
        $this->movement($asset, 'location', ['from_location_id' => $from, 'to_location_id' => $locationId, 'note' => $note], $actor);

        return $asset;
    }

    /** Active custody for one employee: what exit clearance (Phase 13) must recover. */
    public function clearanceFor(Employee $employee): Collection
    {
        return AssetAssignment::query()->with(['asset.category'])->where('employee_id', $employee->id)->where('status', 'active')->get();
    }

    private function movement(Asset $asset, string $type, array $extra, ?User $actor): AssetMovement
    {
        return AssetMovement::create(array_merge([
            'asset_id' => $asset->id, 'type' => $type, 'status_after' => $asset->status, 'occurred_at' => now(), 'created_by' => $actor?->id ?? auth()->id(),
        ], array_filter($extra, fn ($v) => $v !== null)));
    }
}
